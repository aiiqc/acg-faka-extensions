<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use App\Model\Category;
use App\Model\Commodity;
use App\Model\PriceTemplate;
use App\Model\Shared;
use App\Util\Date;
use App\Util\Ini;
use Illuminate\Database\Capsule\Manager as DB;
use RuntimeException;
use Throwable;

final class CommodityImporter
{
    public const OUTCOME_CREATED = 'created';
    public const OUTCOME_REATTACHED = 'reattached';
    public const OUTCOME_ALREADY_MANAGED = 'already_managed';
    public const OUTCOME_HELD_EXISTING_UNMANAGED = 'held_existing_unmanaged';

    private ?array $detailDiagnostics = null;

    public function detailDiagnostics(): ?array
    {
        return $this->detailDiagnostics;
    }

    public function __construct(
        private SharedGateway $gateway,
        private PriceAdjuster $prices,
        private RemoteItem $remoteItem,
        private SourcePolicy $sourcePolicy,
    ) {
    }

    /** @param array{code:string,category:string,stock:int} $catalogItem */
    public function import(Shared $source, array $catalogItem, int $categoryId, Options $options): string
    {
        $this->detailDiagnostics = null;
        $prepared = $this->prepare($source, $catalogItem, $options);

        return DB::transaction(fn(): string => $this->persist(
            $source,
            $categoryId,
            $prepared,
        ));
    }

    /**
     * Resolve existing commodities locally inside the mapper transaction. Only
     * new commodities need remote preparation, always before database locks.
     *
     * @param array{code:string,category:string,stock:int} $catalogItem
     * @param array{group:string,family:string}|array{mode:string,path:list<array>} $target
     */
    public function importPlanned(
        Shared $source,
        array $catalogItem,
        PlannedCategoryMapper $mapper,
        string $alias,
        array $target,
        string $planHash,
        Options $options,
    ): string {
        $this->detailDiagnostics = null;
        if (($target['mode'] ?? null) === 'mirror') {
            // Both the existing-row and new-item paths use the same frozen
            // ancestry; reject an inconsistent leaf before any remote detail.
            $target = $this->stage(CommodityImportFailure::CATEGORY_TRANSACTION_FAILED, true,
                static function () use ($target, $catalogItem): array {
                    $normalized = UpstreamCategoryTree::normalizeTarget($target);
                    if (($catalogItem['category'] ?? null) !== $normalized['path'][array_key_last($normalized['path'])]['name']) {
                        throw new RuntimeException('上游分类名称与确认层级不一致。');
                    }
                    return $normalized;
                });
        }
        $code = (string)$catalogItem['code'];
        // Invalid codes retain the original gateway validation/failure path.
        if ($code !== '' && strlen($code) <= 64 && !preg_match('/[\x00-\x20\x7F]/', $code)
            && $this->stage(CommodityImportFailure::PERSISTENCE_FAILED, true,
                fn(): bool => Commodity::query()->where('owner', 0)
                    ->where('shared_id', (int)$source->id)->where('shared_code', $code)->exists())) {
            $this->stage(CommodityImportFailure::SOURCE_POLICY_FAILED, true,
                fn() => $this->sourcePolicy->assertSafe($source));
            $missing = new RuntimeException('Existing planned commodity disappeared');
            $outcome = $this->stage(
                CommodityImportFailure::CATEGORY_TRANSACTION_FAILED,
                true,
                function () use ($source, $catalogItem, $mapper, $alias, $target, $planHash, $code, $missing): ?string {
                    try {
                        return $mapper->withResolvedCategory(
                            $source, $alias, $target, $catalogItem['category'], $planHash,
                            function (Category $category) use ($source, $code, $missing): string {
                                $outcome = $this->stage(CommodityImportFailure::PERSISTENCE_FAILED, true,
                                    fn(): ?string => $this->persistExisting($source, (int)$category->id, $code, true));
                                if ($outcome === null) {
                                    // Throw before map publication so the mapper rolls back
                                    // new categories. Catch only this invocation's sentinel.
                                    throw $missing;
                                }
                                return $outcome;
                            },
                        );
                    } catch (Throwable $failure) {
                        if ($failure !== $missing) {
                            throw $failure;
                        }
                        return null;
                    }
                },
            );
            if ($outcome !== null) {
                return $outcome;
            }
            // The failed mapper transaction and its file lock are now closed.
        }
        $prepared = $this->prepare($source, $catalogItem, $options, true);

        return $this->stage(
            CommodityImportFailure::CATEGORY_TRANSACTION_FAILED,
            true,
            fn(): mixed => $mapper->withResolvedCategory(
                $source,
                $alias,
                $target,
                $catalogItem['category'],
                $planHash,
                fn(Category $category): string => $this->stage(
                    CommodityImportFailure::PERSISTENCE_FAILED,
                    true,
                    fn(): string => $this->persist(
                        $source,
                        (int)$category->id,
                        $prepared,
                        true,
                    ),
                ),
            ),
        );
    }

    /**
     * @param array{code:string,category:string,stock:int} $catalogItem
     * @return array{item:array<string,mixed>,factor:float,prices:array{config:array,price:string,user_price:string}}
     */
    private function prepare(
        Shared $source,
        array $catalogItem,
        Options $options,
        bool $classifyFailures = false,
    ): array
    {
        $code = (string)$catalogItem['code'];
        $this->stage(
            CommodityImportFailure::SOURCE_POLICY_FAILED,
            $classifyFailures,
            function () use ($source): void {
                $this->sourcePolicy->assertSafe($source);
            },
        );
        $remote = $this->stage(
            CommodityImportFailure::DETAIL_FETCH_FAILED,
            $classifyFailures,
            function () use ($source, $code): array {
                try {
                    return $this->gateway->item($source, $code);
                } catch (UpstreamFailure $failure) {
                    if ($this->gateway->detailDiagnostics() !== null) {
                        $this->detailDiagnostics = $failure->diagnostics;
                    }
                    throw $failure;
                } finally {
                    $this->detailDiagnostics ??= $this->gateway->detailDiagnostics();
                }
            },
        );
        $item = $this->stage(
            CommodityImportFailure::DETAIL_NORMALIZATION_FAILED,
            $classifyFailures,
            fn(): array => $this->remoteItem->normalize($source, $remote, $code),
        );
        $this->stage(
            CommodityImportFailure::STOCK_VALIDATION_FAILED,
            $classifyFailures,
            function () use ($catalogItem, $item): void {
                $this->assertImportableStock($catalogItem, $item);
            },
        );
        $factor = $this->stage(
            CommodityImportFailure::PRICE_ADJUSTMENT_FAILED,
            $classifyFailures,
            fn(): float => $options->premiumFactor(),
        );
        $prices = $this->stage(
            CommodityImportFailure::PRICE_ADJUSTMENT_FAILED,
            $classifyFailures,
            fn(): array => $this->prices->adjustPrice(
                $item['config'],
                (string)$item['price'],
                (string)$item['user_price'],
                PriceTemplate::TYPE_PERCENT,
                $factor,
            ),
        );

        $remoteConfig = $this->stage(
            CommodityImportFailure::CONFIG_EXTRACTION_FAILED,
            $classifyFailures,
            fn(): array => $item['config'] === '' ? [] : Ini::toArray($item['config']),
        );
        if (!empty($remoteConfig['sku'])) {
            $prices['config']['sku_cost'] = $remoteConfig['sku'];
        }
        if (!empty($remoteConfig['category'])) {
            $prices['config']['category_cost'] = $remoteConfig['category'];
        }

        return ['item' => $item, 'factor' => $factor, 'prices' => $prices];
    }

    private function stage(string $safeCode, bool $classifyFailures, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (CommodityImportFailure $failure) {
            throw $failure;
        } catch (Throwable $exception) {
            if (!$classifyFailures) {
                throw $exception;
            }
            if ($exception instanceof RemoteItemDataInvalid
                && in_array($safeCode, [CommodityImportFailure::DETAIL_FETCH_FAILED, CommodityImportFailure::DETAIL_NORMALIZATION_FAILED], true)) {
                $safeCode = CommodityImportFailure::ITEM_DATA_INVALID;
            } elseif ($safeCode === CommodityImportFailure::DETAIL_FETCH_FAILED) {
                // Keep the legacy resumable code only for already-persisted jobs.
                $safeCode = CommodityImportFailure::DETAIL_UNKNOWN_FAILED;
                if ($exception instanceof BudgetExceeded) {
                    $safeCode = CommodityImportFailure::DETAIL_BUDGET_EXCEEDED;
                } elseif ($exception instanceof UpstreamFailure) {
                    $diagnostics = $exception->diagnostics;
                    $jsonErrorCode = $diagnostics['json_error_code'] ?? null;
                    $safeCode = match ($diagnostics['category']) {
                        'transport' => CommodityImportFailure::DETAIL_TRANSPORT_FAILED,
                        'http_retryable' => CommodityImportFailure::DETAIL_HTTP_RETRYABLE,
                        'item_unavailable' => CommodityImportFailure::DETAIL_ITEM_UNAVAILABLE,
                        'item_invalid' => CommodityImportFailure::ITEM_DATA_INVALID,
                        'http_rejected' => CommodityImportFailure::DETAIL_HTTP_REJECTED,
                        'credentials' => CommodityImportFailure::DETAIL_CREDENTIALS_INVALID,
                        'business' => CommodityImportFailure::DETAIL_BUSINESS_REJECTED,
                        'budget' => CommodityImportFailure::DETAIL_BUDGET_EXCEEDED,
                        'json' => $diagnostics['http_status'] === 200 && $diagnostics['curl_code'] === 0
                            && $diagnostics['attempts'] >= 1 && ($diagnostics['json_valid'] ?? null) === false
                            && is_int($jsonErrorCode) && UpstreamFailure::jsonErrorKind($jsonErrorCode) !== null
                            && ($diagnostics['json_error'] ?? null) === UpstreamFailure::jsonErrorKind($jsonErrorCode)
                            ? CommodityImportFailure::DETAIL_JSON_INVALID : CommodityImportFailure::DETAIL_RESPONSE_INVALID,
                        'content_type', 'schema', 'response_size' => CommodityImportFailure::DETAIL_RESPONSE_INVALID,
                        default => CommodityImportFailure::DETAIL_UNKNOWN_FAILED,
                    };
                }
            }
            throw new CommodityImportFailure($safeCode, $exception);
        }
    }

    /**
     * The caller must already be inside the transaction that owns the final
     * category decision. The ordinary import() entrypoint supplies that
     * transaction itself; importPlanned() reuses the mapper transaction.
     *
     * @param array{item:array<string,mixed>,factor:float,prices:array{config:array,price:string,user_price:string}} $prepared
     */
    private function persist(
        Shared $source,
        int $categoryId,
        array $prepared,
        bool $allowReattach = false,
    ): string
    {
        $item = $prepared['item'];
        $factor = $prepared['factor'];
        $prices = $prepared['prices'];
        $outcome = $this->persistExisting($source, $categoryId, $item['code'], $allowReattach);
        if ($outcome !== null) {
            return $outcome;
        }

        $commodity = new Commodity();
        $commodity->category_id = $categoryId;
        $commodity->name = $item['name'];
        $commodity->description = $item['description'];
        $commodity->cover = $item['cover'];
        $commodity->factory_price = 0;
        $commodity->price = $prices['price'];
        $commodity->user_price = $prices['user_price'];
        $commodity->status = 1;
        $commodity->owner = 0;
        $commodity->create_time = Date::current();
        $commodity->api_status = 1;
        $commodity->code = $this->localCode();
        $commodity->delivery_way = 1;
        $commodity->contact_type = $item['contact_type'];
        $commodity->password_status = $item['password_status'];
        $commodity->sort = 0;
        $commodity->coupon = 0;
        $commodity->shared_id = (int)$source->id;
        $commodity->shared_code = $item['code'];
        $commodity->shared_premium = $factor;
        $commodity->shared_premium_type = PriceTemplate::TYPE_PERCENT;
        $commodity->shared_premium_template = 0;
        // PikaSupplySync owns the periodic update path. Leaving the core
        // page-view synchronizer enabled would bypass RemoteItem cleanup.
        $commodity->shared_sync = 0;
        $commodity->shared_amount_sync = 1;
        $commodity->shared_config_sync = 1;
        $commodity->inventory_sync = 1;
        $commodity->seckill_status = $item['seckill_status'];
        $commodity->seckill_start_time = $item['seckill_status'] === 1 ? $item['seckill_start_time'] : null;
        $commodity->seckill_end_time = $item['seckill_status'] === 1 ? $item['seckill_end_time'] : null;
        $commodity->draft_status = $item['draft_status'];
        $commodity->draft_premium = $item['draft_premium'] > 0
            ? $this->prices->adjustAmount(PriceTemplate::TYPE_PERCENT, $factor, $item['draft_premium'])
            : 0;
        $commodity->inventory_hidden = $item['inventory_hidden'];
        $commodity->only_user = $item['only_user'];
        $commodity->purchase_count = $item['purchase_count'];
        $commodity->widget = $item['widget'];
        $commodity->minimum = $item['minimum'];
        $commodity->maximum = $item['maximum'];
        $commodity->stock = $item['stock'];
        $commodity->shared_stock = [];
        $commodity->config = Ini::toConfig($prices['config']);
        $commodity->hide = 0;
        if (!$commodity->save()) {
            throw new RuntimeException('远端商品保存失败');
        }
        return self::OUTCOME_CREATED;
    }

    /** Recheck identity and ownership while the caller's transaction owns the category. */
    private function persistExisting(Shared $source, int $categoryId, string $code, bool $allowReattach): ?string
    {
        SourceIdentity::lockAndVerify($source);
        $category = Category::query()->whereKey($categoryId)->lockForUpdate()->first();
        if ($category === null || (int)$category->owner !== 0) {
            throw new RuntimeException('商品分类已不存在或不再属于系统商品');
        }
        $existing = Commodity::query()
            ->where('owner', 0)
            ->where('shared_id', (int)$source->id)
            ->where('shared_code', $code)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            $outcome = $this->existingOutcome($existing);
            if ($allowReattach
                && $outcome === self::OUTCOME_ALREADY_MANAGED
                && (int)$existing->category_id !== $categoryId) {
                $existing->category_id = $categoryId;
                if (!$existing->save()) {
                    throw new RuntimeException('既有 Pika 商品分类更新失败');
                }
                return self::OUTCOME_REATTACHED;
            }
            return $outcome;
        }
        return null;
    }

    /** @param array<string,mixed> $catalogItem @param array<string,mixed> $item */
    private function assertImportableStock(array $catalogItem, array $item): void
    {
        $catalogStock = $catalogItem['stock'] ?? null;
        $detailStock = $item['stock'] ?? null;
        if (!is_int($catalogStock) || $catalogStock < 0 || $catalogStock > 0x7fffffff
            || !is_int($detailStock) || $detailStock < 0 || $detailStock > 0x7fffffff) {
            throw new RuntimeException('远端商品库存格式不正确');
        }
    }

    private function existingOutcome(Commodity $existing): string
    {
        return $this->classifyExisting(
            (string)$existing->code,
            (int)$existing->shared_sync,
            (int)$existing->shared_premium_type,
        );
    }

    private function classifyExisting(string $localCode, int $sharedSync, int $premiumType): string
    {
        if (
            $sharedSync === 0
            && preg_match('/^PKS1[A-F0-9]{20}$/D', $localCode) === 1
            && in_array($premiumType, [PriceTemplate::TYPE_FIXED, PriceTemplate::TYPE_PERCENT], true)
        ) {
            return self::OUTCOME_ALREADY_MANAGED;
        }
        return self::OUTCOME_HELD_EXISTING_UNMANAGED;
    }

    private function localCode(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            // The prefix is the durable, local ownership marker for v1. The
            // extension never adopts or mutates pre-existing Acg-Faka goods.
            $code = 'PKS1' . strtoupper(bin2hex(random_bytes(10)));
            if (!Commodity::query()->where('owner', 0)->where('code', $code)->exists()) {
                return $code;
            }
        }
        throw new RuntimeException('无法生成唯一的本地商品编号');
    }
}
