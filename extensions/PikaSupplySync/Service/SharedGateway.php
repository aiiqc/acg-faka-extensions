<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use App\Model\Shared;
use App\Util\Ini;
use App\Util\SharedCurrency;
use App\Util\Str;
use RuntimeException;

final class SharedGateway
{
    private ?array $responseStructure = null;
    private bool $categoryIconsSupported = false;

    public function __construct(
        private SafeHttpClient $http,
        private SourcePolicy $policy,
    ) {
    }

    public function items(Shared $source): array
    {
        $this->responseStructure = null;
        $this->http->clearDetailDiagnostics();
        $factor = SharedCurrency::factor($source);
        $this->policy->assertSafe($source);
        if ((int)$source->type === 1) {
            $rows = $this->signedHeaders($source, '/plugin/open-api/items');
            $groups = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !is_array($row['category'] ?? null)) {
                    throw new RuntimeException('远端 V4 商品目录格式不正确');
                }
                $name = $row['category']['name'] ?? null;
                if (!is_scalar($name) || trim((string)$name) === '') {
                    throw new RuntimeException('远端 V4 商品分类名称不正确');
                }
                $name = trim((string)$name);
                $groups[$name] ??= ['name' => $name, 'id' => 0, 'children' => []];
                $groups[$name]['children'][] = $this->v4Item($row);
            }
            return SharedCurrency::tree(array_values($groups), $factor);
        }

        $path = (int)$source->type === 2
            ? '/plugin/SharedStock/api/items'
            : '/shared/commodity/items';
        return SharedCurrency::tree($this->legacy($source, $path), $factor);
    }

    public function resetRequestDiagnostics(): void
    {
        $this->http->resetRequestDiagnostics();
    }

    public function requestDiagnostics(): array
    {
        return $this->http->requestDiagnostics();
    }

    /** One opt-in catalog response; never infer support from the native flat list. */
    public function categoryTree(Shared $source): array
    {
        $this->categoryIconsSupported = false;
        $this->responseStructure = null;
        $this->http->clearDetailDiagnostics();
        $this->policy->assertSafe($source);
        if ((int)$source->type !== 0) {
            throw new RuntimeException('PIKA_TREE_UNSUPPORTED');
        }
        [$appId, $appKey] = $this->credentials($source);
        // The secret signs this request but is not transmitted as an app_key field.
        $data = ['app_id' => $appId, 'pika_category_tree' => '2'];
        $data['sign'] = Str::generateSignature($data, $appKey);
        $response = $this->http->postJson(
            rtrim((string)$source->domain, '/') . '/shared/commodity/items',
            ['Accept: application/json'], $data,
        );
        $snapshot = $this->responseData($response, $appKey);
        if (($snapshot['schema'] ?? null) !== 2 || ($snapshot['capability'] ?? null) !== 'pika_category_tree') {
            throw new RuntimeException('PIKA_TREE_UNSUPPORTED');
        }
        (new UpstreamCategoryTree())->flatten($snapshot);
        if (array_key_exists('pika_category_icons', $response) && $response['pika_category_icons'] !== 1) {
            throw new RuntimeException('PIKA_TREE_UNSUPPORTED');
        }
        $this->categoryIconsSupported = ($response['pika_category_icons'] ?? null) === 1;
        return $snapshot;
    }

    public function supportsCategoryIcons(): bool
    {
        return $this->categoryIconsSupported;
    }

    /** One bounded metadata request; no product response, fallback or import action. */
    public function categoryIcons(Shared $source, array $ids): array
    {
        $ids = UpstreamCategoryTree::iconIds($ids);
        $this->responseStructure = null;
        $this->http->clearDetailDiagnostics();
        $this->policy->assertSafe($source);
        if ((int)$source->type !== 0) {
            throw new RuntimeException('PIKA_TREE_UNSUPPORTED');
        }
        [$appId, $appKey] = $this->credentials($source);
        $data = ['app_id' => $appId, 'pika_category_tree' => '3', 'pika_category_ids' => implode(',', $ids)];
        $data['sign'] = Str::generateSignature($data, $appKey);
        $snapshot = $this->responseData($this->http->postJson(
            rtrim((string)$source->domain, '/') . '/shared/commodity/items',
            ['Accept: application/json'], $data,
        ), $appKey);
        return UpstreamCategoryTree::iconNodes($snapshot, $ids);
    }

    public function item(Shared $source, string $code): array
    {
        $this->responseStructure = null;
        $this->http->clearDetailDiagnostics();
        if ($code === '' || strlen($code) > 64 || preg_match('/[\x00-\x20\x7F]/', $code)) {
            throw new RuntimeException('远端商品编号格式不正确');
        }
        $factor = SharedCurrency::factor($source);
        $this->policy->assertSafe($source);
        if ((int)$source->type === 1) {
            $item = $this->v4Item($this->signedHeaders($source, '/plugin/open-api/item', ['id' => $code], true));
        } elseif ((int)$source->type === 2) {
            $tree = $this->legacy($source, '/plugin/SharedStock/api/item', ['code' => $code], true);
            $item = $this->legacyTreeItem($tree);
        } else {
            $item = $this->legacy($source, '/shared/commodity/item', ['code' => $code], true);
        }
        if (!is_array($item)) {
            throw new UpstreamFailure('schema', $this->responseDiagnostics());
        }
        try {
            if (isset($item['config']) && !is_array($item['config'])) {
                $item['config'] = Ini::toArray((string)$item['config']);
            }
            return SharedCurrency::item($item, $factor);
        } catch (\Throwable) {
            throw new UpstreamFailure('schema', $this->responseDiagnostics());
        }
    }

    public function detailDiagnostics(): ?array
    {
        $diagnostics = $this->http->detailDiagnostics();
        if ($diagnostics !== null && $this->responseStructure !== null) {
            $diagnostics['response_structure'] = $this->responseStructure;
        }
        return $diagnostics;
    }

    /** Perform one read-only diagnostic request without loading site models or state. */
    public function diagnoseItem(array $source, string $code): array
    {
        $this->responseStructure = null;
        $this->http->clearDetailDiagnostics();
        try {
            if (count($source) !== 4
                || array_diff(array_keys($source), ['domain', 'app_id', 'app_key', 'type']) !== []
                || !is_string($source['domain'] ?? null)
                || !is_string($source['app_id'] ?? null)
                || !is_string($source['app_key'] ?? null)
                || !is_int($source['type'] ?? null)
                || !in_array($source['type'], [0, 1, 2], true)
                || $code === '' || strlen($code) > 64 || preg_match('/[\x00-\x20\x7F]/', $code)) {
                return SafeHttpClient::diagnosticUnavailable();
            }
            [$appId, $appKey] = $this->credentials($source);
            $this->policy->resolve($source['domain'], true, false);
            if ($source['type'] === 1) {
                $path = '/plugin/open-api/item';
                $data = ['id' => $code];
                $headers = $this->signatureHeaders($data, $appId, $appKey);
            } else {
                $path = $source['type'] === 2 ? '/plugin/SharedStock/api/item' : '/shared/commodity/item';
                $data = $this->legacyForm(['code' => $code], $appId, $appKey);
                $headers = ['Accept: application/json'];
            }
            return $this->http->diagnosePostJson(
                rtrim($source['domain'], '/') . $path, $headers, $data, $source['type'],
            );
        } catch (\Throwable) {
            return SafeHttpClient::diagnosticUnavailable();
        }
    }

    private function legacyTreeItem(array $tree): array
    {
        // Preserve the existing successful detail path before classifying only
        // formerly rejected responses. Wrapper metadata is not a new import gate.
        if (is_array($tree[0]['children'][0] ?? null)) {
            return $tree[0]['children'][0];
        }
        if (!array_is_list($tree)) {
            throw new UpstreamFailure('schema', $this->responseDiagnostics());
        }
        // The transport decodes associative arrays: an empty JSON object and
        // list are indistinguishable here. Neither observation proves delisting.
        if ($tree === []) {
            throw new UpstreamFailure('item_unavailable', $this->responseDiagnostics());
        }
        foreach ($tree as $category) {
            if (!is_array($category)
                || !is_scalar($category['name'] ?? null)
                || !is_array($category['children'] ?? null)
                || !array_is_list($category['children'])) {
                throw new UpstreamFailure('schema', $this->responseDiagnostics());
            }
            $name = trim((string)$category['name']);
            if ($name === '' || !mb_check_encoding($name, 'UTF-8')
                || mb_strlen($name, 'UTF-8') > 128
                || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
                throw new UpstreamFailure('schema', $this->responseDiagnostics());
            }
        }
        $children = $tree[0]['children'];
        if ($children === []) {
            throw new UpstreamFailure('item_unavailable', $this->responseDiagnostics());
        }
        throw new UpstreamFailure('item_invalid', $this->responseDiagnostics());
    }

    private function legacy(Shared $source, string $path, array $data = [], bool $detail = false): array
    {
        [$appId, $appKey] = $this->credentials($source);
        $data = $this->legacyForm($data, $appId, $appKey);
        return $this->responseData($this->http->postJson(
            rtrim((string)$source->domain, '/') . $path,
            ['Accept: application/json'],
            $data
        ), $appKey, $detail, (int)$source->type === 2);
    }

    private function signedHeaders(Shared $source, string $path, array $data = [], bool $detail = false): array
    {
        [$appId, $appKey] = $this->credentials($source);
        return $this->responseData($this->http->postJson(
            rtrim((string)$source->domain, '/') . $path,
            $this->signatureHeaders($data, $appId, $appKey),
            $data
        ), $appKey, $detail);
    }

    private function legacyForm(array $data, string $appId, string $appKey): array
    {
        $data = array_merge($data, ['app_id' => $appId, 'app_key' => $appKey]);
        $data['sign'] = Str::generateSignature($data, $appKey);
        return $data;
    }

    private function signatureHeaders(array $data, string $appId, string $appKey): array
    {
        return [
            'Accept: application/json',
            'Api-Id: ' . $appId,
            'Api-Signature: ' . Str::generateSignature($data, $appKey),
        ];
    }

    /** @return array{0:string,1:string} */
    private function credentials(Shared|array $source): array
    {
        $appId = trim((string)(is_array($source) ? $source['app_id'] : $source->app_id));
        $appKey = trim((string)(is_array($source) ? $source['app_key'] : $source->app_key));
        if (preg_match('/^[A-Za-z0-9._:@-]{1,32}$/D', $appId) !== 1
            || preg_match('/^[^\s\x00-\x1F\x7F]{8,64}$/uD', $appKey) !== 1) {
            throw new UpstreamFailure('credentials');
        }
        return [$appId, $appKey];
    }

    private function responseData(array $response, string $appKey, bool $detail = false, bool $tree = false): array
    {
        $this->responseStructure = null;
        if ($this->containsSecret($response, $appKey)) {
            throw new UpstreamFailure('schema', $this->responseDiagnostics());
        }
        if ($detail) {
            $this->responseStructure = $this->summarizeResponseStructure($response, $tree);
        }
        if (!array_key_exists('code', $response)) {
            throw new UpstreamFailure('schema', $this->responseDiagnostics());
        }
        if ((int)$response['code'] !== 200) {
            // The peer has seen the credentials and may echo them in `msg`.
            // Never surface untrusted remote error text into CLI output or logs.
            throw new UpstreamFailure('business', $this->responseDiagnostics());
        }
        if (!is_array($response['data'] ?? null)) {
            throw new UpstreamFailure('schema', $this->responseDiagnostics());
        }
        return $response['data'];
    }

    private function responseDiagnostics(): array
    {
        $diagnostics = $this->http->diagnostics();
        if ($this->responseStructure !== null) {
            $diagnostics['response_structure'] = $this->responseStructure;
        }
        return $diagnostics;
    }

    /** Observe decoded shape and bounded business code, never content or synthesized counts. */
    private function summarizeResponseStructure(array $response, bool $tree): array
    {
        $structure = ['data_type' => $this->responseValueType($response, 'data')];
        if (array_key_exists('code', $response)) {
            $structure['business_code'] = $response['code'];
        }
        if (is_array($response['data'] ?? null)) {
            $data = $response['data'];
            $structure['data_count'] = count($data);
            if ($tree && is_array($data[0] ?? null)) {
                $category = $data[0];
                $structure['first_children_type'] = $this->responseValueType($category, 'children');
                if (is_array($category['children'] ?? null)) {
                    $structure['first_children_count'] = count($category['children']);
                    $structure['first_item_type'] = $this->responseValueType($category['children'], 0);
                }
            }
        }
        return UpstreamFailure::sanitizeResponseStructure($structure);
    }

    private function responseValueType(array $values, int|string $key): string
    {
        if (!array_key_exists($key, $values)) {
            return 'missing';
        }
        $value = $values[$key];
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            $value === [] => 'empty_array_or_object',
            is_array($value) && array_is_list($value) => 'list',
            default => 'object',
        };
    }

    private function containsSecret(array $value, string $secret): bool
    {
        $stack = [$value];
        while ($stack !== []) {
            $current = array_pop($stack);
            foreach ($current as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                    continue;
                }
                if (is_scalar($child) && str_contains((string)$child, $secret)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function v4Item(array $item): array
    {
        try {
            return $this->normalizeV4Item($item);
        } catch (\Throwable) {
            throw new UpstreamFailure('schema', $this->responseDiagnostics());
        }
    }

    private function normalizeV4Item(array $item): array
    {
        $sku = $item['sku'] ?? null;
        if (!is_array($sku) || $sku === [] || count($sku) > 1000 || !is_array($sku[0] ?? null)) {
            throw new RuntimeException('远端 V4 商品规格格式不正确');
        }
        $id = $item['id'] ?? null;
        $name = $item['name'] ?? null;
        if (!is_scalar($id) || !is_scalar($name)) {
            throw new RuntimeException('远端 V4 商品字段格式不正确');
        }
        $price = $sku[0]['stock_price'] ?? null;
        if (!is_numeric($price)) {
            throw new RuntimeException('远端 V4 商品价格格式不正确');
        }
        $config = ['category' => [], 'shared_mapping' => []];
        $stock = 0;
        $stockFields = 0;
        $seenNames = [];
        $seenIds = [];
        foreach ($sku as $entry) {
            if (!is_array($entry) || !is_scalar($entry['name'] ?? null) || !is_scalar($entry['id'] ?? null)) {
                throw new RuntimeException('远端 V4 商品规格字段不正确');
            }
            $stockPrice = $entry['stock_price'] ?? null;
            if (!is_numeric($stockPrice)) {
                throw new RuntimeException('远端 V4 商品规格价格不正确');
            }
            $key = trim((string)$entry['name']);
            if ($key === '' || strlen($key) > 128) {
                throw new RuntimeException('远端 V4 商品规格名称不正确');
            }
            $skuId = trim((string)$entry['id']);
            if ($skuId === '' || strlen($skuId) > 128) {
                throw new RuntimeException('远端 V4 商品规格编号不正确');
            }
            if (isset($seenNames[$key]) || isset($seenIds[$skuId])) {
                throw new RuntimeException('远端 V4 商品规格名称或编号重复');
            }
            $seenNames[$key] = true;
            $seenIds[$skuId] = true;
            $config['category'][$key] = $stockPrice;
            $config['shared_mapping'][$key] = $skuId;
            if (array_key_exists('stock', $entry)) {
                $stockFields++;
                $entryStock = $this->v4Stock($entry['stock']);
                if ($stock > 2147483647 - $entryStock) {
                    throw new RuntimeException('远端 V4 商品库存总数超出有效范围');
                }
                $stock += $entryStock;
            }
        }
        if ($stockFields === 0) {
            $stock = 10000000;
        } elseif ($stockFields !== count($sku)) {
            throw new RuntimeException('远端 V4 商品库存字段不完整');
        }

        return [
            'id' => (string)$id,
            'code' => (string)$id,
            'name' => (string)$name,
            'description' => is_scalar($item['introduce'] ?? null) ? (string)$item['introduce'] : '',
            'price' => $price,
            'user_price' => $price,
            'cover' => is_scalar($item['picture_url'] ?? null) ? (string)$item['picture_url'] : '',
            'factory_price' => $price,
            'delivery_way' => 0,
            'contact_type' => 0,
            'password_status' => 0,
            'sort' => 0,
            'seckill_status' => 0,
            'draft_status' => 0,
            'inventory_hidden' => 0,
            'only_user' => 0,
            'purchase_count' => 0,
            'minimum' => 0,
            'maximum' => 0,
            'stock' => $stock,
            'widget' => $this->v4Widgets($item['widget'] ?? '[]'),
            'config' => $config,
        ];
    }

    private function v4Stock(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > 2147483647) {
            throw new RuntimeException('远端 V4 商品库存必须是有效整数');
        }
        return $value;
    }

    private function v4Widgets(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 65535) {
            throw new RuntimeException('远端 V4 商品控件配置格式不正确');
        }
        try {
            $widgets = json_decode($value === '' ? '[]' : $value, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('远端 V4 商品控件配置格式不正确');
        }
        if (!is_array($widgets) || count($widgets) > 32) {
            throw new RuntimeException('远端 V4 商品控件数量过多');
        }
        $result = [];
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                throw new RuntimeException('远端 V4 商品控件字段不正确');
            }
            $data = $widget['data'] ?? '';
            if (!is_scalar($data)) {
                throw new RuntimeException('远端 V4 商品控件选项不正确');
            }
            $result[] = [
                'cn' => is_scalar($widget['title'] ?? null) ? (string)$widget['title'] : '',
                'name' => is_scalar($widget['name'] ?? null) ? (string)$widget['name'] : '',
                'placeholder' => is_scalar($widget['placeholder'] ?? null) ? (string)$widget['placeholder'] : '',
                'type' => is_scalar($widget['type'] ?? null) ? (string)$widget['type'] : '',
                'regex' => is_scalar($widget['regex'] ?? null) ? (string)$widget['regex'] : '',
                'error' => is_scalar($widget['error'] ?? null) ? (string)$widget['error'] : '',
                'dict' => str_replace(["\r\n", "\r", "\n"], ',', (string)$data),
            ];
        }
        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
