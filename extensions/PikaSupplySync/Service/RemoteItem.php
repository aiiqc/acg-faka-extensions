<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use App\Model\Shared;
use App\Util\Ini;
use Pika\LocalExtensions\Manager\PathGuard;
use RuntimeException;

final class RemoteItem
{
    private const MAX_CONFIG_BYTES = 131072;
    private const MAX_CONFIG_LINES = 2048;
    private const MAX_CONFIG_NODES = 4096;
    private const MAX_CONFIG_DEPTH = 8;
    private const MAX_CONFIG_KEY_BYTES = 255;
    private const MAX_CONFIG_VALUE_BYTES = 4096;
    private const MAX_WIDGET_BYTES = 65535;
    private const MAX_WIDGET_DEPTH = 8;
    private const MAX_QUANTIFIERS = 1;
    private const MAX_FIXED_REPETITION = 256;

    private ?\HTMLPurifier $purifier = null;
    private RunBudget $budget;

    public function __construct(private ImageCache $images, ?RunBudget $budget = null)
    {
        $this->budget = $budget ?? new RunBudget();
    }

    /** @return array<string,mixed> */
    public function normalize(Shared $source, array $item, string $requestedCode,
        bool $localizeCover = true, bool $refreshCover = false): array
    {
        $requestedCode = $this->code($requestedCode);
        if (array_key_exists('code', $item)) {
            $returnedCode = $this->code($item['code']);
            if (!hash_equals($requestedCode, $returnedCode)) {
                throw new RuntimeException('远端商品详情编号与请求不一致');
            }
        }
        $name = $this->plain($item['name'] ?? null, 255, '商品名称', true, true);
        $config = $this->config($item['config'] ?? '');
        $widget = $this->widget($item['widget'] ?? '[]');
        $seckillStatus = $this->integer($item['seckill_status'] ?? 0, 0, 1, 'seckill_status');
        $seckillStart = $this->plain($item['seckill_start_time'] ?? '', 32, 'seckill_start_time');
        $seckillEnd = $this->plain($item['seckill_end_time'] ?? '', 32, 'seckill_end_time');
        if ($seckillStatus === 1) {
            $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $seckillStart);
            $end = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $seckillEnd);
            if (!$start || !$end || $end <= $start) {
                throw new RemoteItemDataInvalid('远端商品秒杀时间不正确');
            }
        }

        $description = $this->description($item['description'] ?? '');
        $coverUnavailable = false;
        try {
            $cover = $localizeCover ? $this->cover($source, $item['cover'] ?? '', $refreshCover) : '';
        } catch (RemoteCoverUnavailable $exception) {
            if (!$refreshCover) throw $exception;
            // A normal remote image failure must not gate valid stock/prices.
            // This marker is local normalization metadata, never a DB column.
            $cover = '';
            $coverUnavailable = true;
        }

        return [
            'name' => $name,
            'description' => $description,
            'cover' => $cover,
            'code' => $requestedCode,
            'contact_type' => $this->integer($item['contact_type'] ?? 0, 0, 3, 'contact_type'),
            'password_status' => $this->integer($item['password_status'] ?? 0, 0, 1, 'password_status'),
            'seckill_status' => $seckillStatus,
            'seckill_start_time' => $seckillStart,
            'seckill_end_time' => $seckillEnd,
            'draft_status' => $this->integer($item['draft_status'] ?? 0, 0, 1, 'draft_status'),
            'draft_premium' => $this->amount($item['draft_premium'] ?? 0, 'draft_premium'),
            'inventory_hidden' => $this->integer($item['inventory_hidden'] ?? 0, 0, 1, 'inventory_hidden'),
            'only_user' => $this->integer($item['only_user'] ?? 0, 0, 1, 'only_user'),
            'purchase_count' => $this->integer($item['purchase_count'] ?? 0, 0, 4294967295, 'purchase_count'),
            'minimum' => $this->integer($item['minimum'] ?? 0, 0, 4294967295, 'minimum'),
            'maximum' => $this->integer($item['maximum'] ?? 0, 0, 4294967295, 'maximum'),
            'stock' => $this->integer($item['stock'] ?? 0, 0, 2147483647, 'stock'),
            'widget' => $widget,
            'config' => $config,
            'price' => $this->amount($item['price'] ?? 0, 'price'),
            'user_price' => $this->amount($item['user_price'] ?? 0, 'user_price'),
        ] + ($coverUnavailable ? ['cover_unavailable' => true] : []);
    }

    private function description(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new RemoteItemDataInvalid('远端商品说明格式不正确');
        }
        $html = (string)$value;
        if (strlen($html) > 1048576) {
            throw new RemoteItemDataInvalid('远端商品说明超过 1MB');
        }
        if ($this->purifier === null) {
            $cache = LocalPath::directory(
                'runtime/local-extensions/extensions/PikaSupplySync/purifier',
                0700
            );
            $cacheDirectories = [];
            $cacheOwner = PathGuard::runtimeOwner();
            // Older releases passed a file mode to Serializer's directory mkdir.
            // Validate every existing cache type before repairing any of them.
            foreach (['HTML', 'CSS', 'URI'] as $type) {
                $directory = $cache . '/' . $type;
                clearstatcache(true, $directory);
                if (is_link($directory)) {
                    throw new RuntimeException('净化缓存目录不能包含符号链接');
                }
                if (!file_exists($directory)) {
                    continue;
                }
                if (!is_dir($directory) || fileowner($directory) !== $cacheOwner) {
                    throw new RuntimeException('净化缓存目录类型或所有者不正确');
                }
                $cacheDirectories[] = 'extensions/PikaSupplySync/purifier/' . $type;
            }
            foreach ($cacheDirectories as $directory) {
                PathGuard::stateDirectory($directory, 0700);
            }
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('Cache.SerializerPath', $cache);
            // Serializer masks cache files with 0666, keeping them at 0600.
            $config->set('Cache.SerializerPermissions', 0700);
            // Remote images are deliberately not allowed in descriptions. A
            // buyer must never resolve an upstream-controlled image hostname;
            // the product cover is downloaded through ImageCache instead.
            $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,s,del,blockquote,ul,ol,li,h1,h2,h3,h4,h5,h6,hr,pre,code[class],a[href|title|target|rel],table,thead,tbody,tr,th,td,div,span');
            $config->set('URI.AllowedSchemes', ['https' => true]);
            $config->set('HTML.TargetBlank', true);
            $config->set('HTML.Nofollow', true);
            $config->set('Attr.EnableID', false);
            $this->purifier = new \HTMLPurifier($config);
        }
        $clean = $this->purifier->purify($html);
        if (!is_string($clean) || strlen($clean) > 1048576) {
            throw new RuntimeException('净化后的远端商品说明超过 1MB');
        }
        $this->budget->consumeText(max(strlen($html), strlen($clean)));
        return $clean;
    }

    /** Validate a deferred value without retaining an unbounded remote field. */
    public function coverValue(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new RemoteItemDataInvalid('远端商品封面格式不正确');
        }
        $cover = trim((string)$value);
        if (strlen($cover) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $cover)) {
            throw new RemoteCoverUnavailable('远端封面地址不正确');
        }
        return $cover;
    }

    public function refreshCover(Shared $source, mixed $value): string
    {
        return $this->cover($source, $value, true);
    }

    private function cover(Shared $source, mixed $value, bool $refresh = false): string
    {
        if (!is_scalar($value)) {
            throw new RemoteItemDataInvalid('远端商品封面格式不正确');
        }
        $cover = trim((string)$value);
        if ($cover === '') {
            if ($refresh) throw new RemoteCoverUnavailable('远端封面为空，保留原图');
            return '/favicon.ico';
        }
        try {
            return $this->images->localize($source, $cover, $refresh);
        } catch (BudgetExceeded $exception) {
            throw $exception;
        } catch (RemoteCoverUnavailable $exception) {
            if ($refresh) throw $exception;
            return '/favicon.ico';
        }
    }

    private function config(mixed $value): string
    {
        if (is_array($value)) {
            $this->validateConfigTree($value);
            $config = Ini::toConfig($value);
            $this->validateConfigText($config);
            $this->budget->consumeText(strlen($config));
            return $config;
        }
        if (!is_scalar($value)) {
            throw new RuntimeException('远端商品价格配置格式不正确');
        }
        $config = (string)$value;
        if (strlen($config) > self::MAX_CONFIG_BYTES) {
            throw new RuntimeException('远端商品价格配置过大');
        }
        if (trim($config) === '') {
            return '';
        }
        $this->budget->consumeText(strlen($config));
        $this->validateConfigText($config);
        $parsed = Ini::toArray($config);
        $this->validateConfigTree($parsed);
        $canonical = Ini::toConfig($parsed);
        $this->validateConfigText($canonical);
        return $canonical;
    }

    private function widget(mixed $value): string
    {
        if (is_string($value)) {
            if (strlen($value) > self::MAX_WIDGET_BYTES) {
                throw new RemoteItemDataInvalid('远端商品控件配置过大');
            }
            try {
                $widgets = json_decode(
                    $value === '' ? '[]' : $value,
                    true,
                    self::MAX_WIDGET_DEPTH,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException) {
                throw new RemoteItemDataInvalid('远端商品控件配置不是有效 JSON');
            }
        } elseif (is_array($value)) {
            $widgets = $value;
        } else {
            throw new RemoteItemDataInvalid('远端商品控件配置格式不正确');
        }
        if (!is_array($widgets) || count($widgets) > 32) {
            throw new RemoteItemDataInvalid('远端商品控件配置不是有效 JSON 或数量过多');
        }
        $clean = [];
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                throw new RemoteItemDataInvalid('远端商品控件字段格式不正确');
            }
            $name = $widget['name'] ?? null;
            $type = $widget['type'] ?? null;
            if (!is_string($name)
                || !mb_check_encoding($name, 'UTF-8')
                || mb_strlen($name, 'UTF-8') > 32
                || preg_match('/^\p{L}[\p{L}\p{N}_]{0,31}$/uD', $name) !== 1
                || !is_string($type)
                || !in_array($type, ['text', 'password', 'number', 'select', 'checkbox', 'radio', 'textarea'], true)) {
                throw new RemoteItemDataInvalid('远端商品控件名称或类型不正确');
            }
            $regex = $this->normalizeWidgetRegex($this->plain($widget['regex'] ?? '', 128, '控件正则'));
            $clean[] = [
                'cn' => htmlspecialchars($this->plain($widget['cn'] ?? '', 64, '控件标题'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'name' => $name,
                'placeholder' => htmlspecialchars($this->plain($widget['placeholder'] ?? '', 128, '控件提示'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'type' => $type,
                'regex' => $regex,
                'error' => htmlspecialchars($this->plain($widget['error'] ?? '', 128, '控件错误提示'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'dict' => htmlspecialchars($this->plain($widget['dict'] ?? '', 4096, '控件选项'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ];
        }
        $encoded = (string)json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_WIDGET_BYTES) {
            throw new RemoteItemDataInvalid('远端商品控件配置过大');
        }
        $this->budget->consumeText(strlen($encoded));
        return $encoded;
    }

    private function validateConfigText(string $config): void
    {
        if (
            strlen($config) > self::MAX_CONFIG_BYTES
            || preg_match('/[\x00\x0B\x0C\x0E-\x1F\x7F]/', $config)
        ) {
            throw new RuntimeException('远端商品价格配置大小或字符不正确');
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($config));
        if (!is_array($lines) || count($lines) > self::MAX_CONFIG_LINES) {
            throw new RuntimeException('远端商品价格配置行数过多');
        }
        $nodes = 0;
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (strlen($line) > self::MAX_CONFIG_VALUE_BYTES + self::MAX_CONFIG_KEY_BYTES + 1) {
                throw new RuntimeException('远端商品价格配置单行过长');
            }
            if (preg_match('/^\[[^\[\]\r\n]{1,255}\]$/D', $line) === 1) {
                $nodes++;
                continue;
            }
            if (substr_count($line, '=') !== 1) {
                throw new RuntimeException('远端商品价格配置赋值格式不正确');
            }
            [$key, $value] = explode('=', $line, 2);
            $plainKey = str_replace('[]', '', $key);
            $segments = explode('.', $plainKey);
            if (
                $key === ''
                || strlen($key) > self::MAX_CONFIG_KEY_BYTES
                || strlen($value) > self::MAX_CONFIG_VALUE_BYTES
                || count($segments) > self::MAX_CONFIG_DEPTH
                || in_array('', $segments, true)
                || preg_match('/[\[\]\r\n=]/', $plainKey)
                || preg_match('/\[\](?!\.|$)/', $key)
            ) {
                throw new RuntimeException('远端商品价格配置键值不正确');
            }
            $nodes += count($segments);
            if ($nodes > self::MAX_CONFIG_NODES) {
                throw new RuntimeException('远端商品价格配置节点过多');
            }
        }
    }

    private function validateConfigTree(array $config): void
    {
        $nodes = 0;
        $walk = function (array $branch, int $depth) use (&$walk, &$nodes): void {
            if ($depth > self::MAX_CONFIG_DEPTH) {
                throw new RuntimeException('远端商品价格配置层级过深');
            }
            foreach ($branch as $key => $value) {
                $nodes++;
                if ($nodes > self::MAX_CONFIG_NODES) {
                    throw new RuntimeException('远端商品价格配置节点过多');
                }
                $key = (string)$key;
                if (
                    $key === ''
                    || strlen($key) > self::MAX_CONFIG_KEY_BYTES
                    || preg_match('/[\x00-\x1F\x7F\[\]=]/', $key)
                ) {
                    throw new RuntimeException('远端商品价格配置键不正确');
                }
                if (is_array($value)) {
                    $walk($value, $depth + 1);
                    continue;
                }
                if (!is_scalar($value) || strlen((string)$value) > self::MAX_CONFIG_VALUE_BYTES) {
                    throw new RuntimeException('远端商品价格配置值不正确');
                }
            }
        };
        $walk($config, 1);
    }

    private function normalizeWidgetRegex(string $regex): string
    {
        if ($regex === '') {
            return '';
        }
        // Official shared stores commonly serialize a browser expression as
        // `/pattern/`. Normalize that delimiter form before validating and
        // persisting it because the storefront consumes a bare RegExp source.
        if (str_starts_with($regex, '/')) {
            $last = strrpos($regex, '/');
            if ($last === 0 || $last !== strlen($regex) - 1 || $regex[$last - 1] === '\\') {
                throw new RemoteItemDataInvalid('远端商品控件正则分隔符不正确');
            }
            $regex = substr($regex, 1, -1);
            if ($regex === '') {
                throw new RemoteItemDataInvalid('远端商品控件正则不能为空表达式');
            }
        }
        // The expression is consumed by both browsers and PHP, so a PCRE-only
        // LIMIT verb cannot be persisted. Keep a small cross-runtime subset:
        // no groups, alternation, backreferences, lookarounds or embedded modes.
        if (
            str_contains($regex, '~')
            || preg_match('/[()|]/', $regex)
            || preg_match('/\\\\(?:[1-9]|g|k)|\(\?/', $regex)
            || preg_match('/(?<!\\\\)[*+?]{2,}/', $regex)
            || preg_match('/(?<!\\\\)\{\d+(?:,\d*)?\}[*+?]/', $regex)
            || preg_match('/(?<!\\\\)\.\*(?:[^$]{0,32})(?<!\\\\)\.\*/', $regex)
        ) {
            throw new RemoteItemDataInvalid('远端商品控件正则表达式不在安全子集内');
        }
        $quantifiers = $this->quantifiers($regex);
        if ($quantifiers > self::MAX_QUANTIFIERS) {
            throw new RemoteItemDataInvalid('远端商品控件正则包含过多可变量词');
        }
        // Compile the upstream expression before adding anchors so malformed
        // syntax such as a dangling escape cannot become valid by accident.
        $this->assertWidgetRegexCompiles($regex);
        if ($quantifiers > 0) {
            if (!str_starts_with($regex, '^')) {
                $regex = '^' . $regex;
            }
            if (!$this->hasUnescapedEndAnchor($regex)) {
                $regex .= '$';
            }
            if (strlen($regex) > 128) {
                throw new RemoteItemDataInvalid('远端商品控件正则超过长度上限');
            }
            // The normalized browser expression must remain valid under the
            // same bounded PCRE gate before it is persisted.
            $this->assertWidgetRegexCompiles($regex);
        }

        return $regex;
    }

    private function assertWidgetRegexCompiles(string $regex): void
    {
        $pattern = '~(*LIMIT_MATCH=10000)(*LIMIT_DEPTH=100)' . $regex . '~u';
        if (@preg_match($pattern, '') === false) {
            throw new RemoteItemDataInvalid('远端商品控件正则表达式不正确');
        }
    }

    private function hasUnescapedEndAnchor(string $regex): bool
    {
        $length = strlen($regex);
        if ($length === 0 || $regex[$length - 1] !== '$') {
            return false;
        }
        $backslashes = 0;
        for ($index = $length - 2; $index >= 0 && $regex[$index] === '\\'; $index--) {
            $backslashes++;
        }
        return $backslashes % 2 === 0;
    }

    private function quantifiers(string $regex): int
    {
        $count = 0;
        $escaped = false;
        $characterClass = false;
        $length = strlen($regex);
        for ($index = 0; $index < $length; $index++) {
            $character = $regex[$index];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($character === '\\') {
                $escaped = true;
                continue;
            }
            if ($character === '[') {
                $characterClass = true;
                continue;
            }
            if ($character === ']' && $characterClass) {
                $characterClass = false;
                continue;
            }
            if ($characterClass) {
                continue;
            }
            if ($character === '*' || $character === '+' || $character === '?') {
                $count++;
                continue;
            }
            if ($character !== '{') {
                continue;
            }
            $tail = substr($regex, $index);
            if (preg_match('/^\{(\d+),(\d*)\}/', $tail, $match) === 1) {
                if ((int)$match[1] > self::MAX_FIXED_REPETITION
                    || ($match[2] !== '' && (int)$match[2] > self::MAX_FIXED_REPETITION)) {
                    return self::MAX_QUANTIFIERS + 1;
                }
                $count++;
                $index += strlen($match[0]) - 1;
            } elseif (preg_match('/^\{(\d+)\}/', $tail, $match) === 1) {
                if ((int)$match[1] > self::MAX_FIXED_REPETITION) {
                    return self::MAX_QUANTIFIERS + 1;
                }
                $count++;
                $index += strlen($match[0]) - 1;
            }
        }
        return $count;
    }

    private function code(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new RuntimeException('远端商品编号格式不正确');
        }
        $code = trim((string)$value);
        if ($code === '' || strlen($code) > 64 || preg_match('/[\x00-\x20\x7F]/', $code)) {
            throw new RuntimeException('远端商品编号必须是 1-64 位且不能包含空白或控制字符');
        }
        return $code;
    }

    private function plain(
        mixed $value,
        int $max,
        string $label,
        bool $required = false,
        bool $normalizeNameWhitespace = false
    ): string
    {
        if (!is_scalar($value)) {
            throw new RemoteItemDataInvalid("远端{$label}格式不正确");
        }

        $raw = (string)$value;
        if ($normalizeNameWhitespace) {
            if (
                !mb_check_encoding($raw, 'UTF-8')
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $raw)
            ) {
                throw new RemoteItemDataInvalid("远端{$label}内容不正确");
            }
        }
        $text = strip_tags($raw);
        if ($normalizeNameWhitespace) {
            $normalized = preg_replace('/[\t\r\n]+/', ' ', $text);
            if (!is_string($normalized) || preg_match('/[\x00-\x1F\x7F]/u', $normalized)) {
                throw new RuntimeException("远端{$label}内容不正确");
            }
            $text = $normalized;
        }
        $text = trim($text);
        if (($required && $text === '') || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text, 'UTF-8') > $max || preg_match('/[\x00-\x1F\x7F]/u', $text)) {
            throw new RemoteItemDataInvalid("远端{$label}内容不正确");
        }
        return $text;
    }

    private function integer(mixed $value, int $min, int $max, string $field): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/D', trim($value))) {
            $integer = (int)trim($value);
        } elseif (is_float($value) && floor($value) === $value) {
            $integer = (int)$value;
        } else {
            throw new RemoteItemDataInvalid("远端商品字段 {$field} 必须是整数");
        }
        if ($integer < $min || $integer > $max) {
            throw new RemoteItemDataInvalid("远端商品字段 {$field} 超出有效范围");
        }
        return $integer;
    }

    private function amount(mixed $value, string $field): float
    {
        if (!is_numeric($value)) {
            throw new RemoteItemDataInvalid("远端商品字段 {$field} 必须是数字");
        }
        $amount = (float)$value;
        if (!is_finite($amount) || $amount < 0 || $amount > 99999999.99) {
            throw new RemoteItemDataInvalid("远端商品字段 {$field} 超出有效范围");
        }
        return $amount;
    }
}
