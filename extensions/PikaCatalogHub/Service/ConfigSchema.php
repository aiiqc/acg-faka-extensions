<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use RuntimeException;

final class ConfigSchema
{
    private const MAX_ALIASES = 16;
    private const MAX_RULES = 128;
    private const MAX_KEYWORDS = 16;
    private const MAX_TEXT_LENGTH = 64;
    private const MAX_CONFIG_BYTES = 131072;

    /** @return array{schema:int,aliases:list<array{source_id:int,alias:string}>,rules:list<array{priority:int,mode:string,keywords:list<string>,target:array{group:string,family:string}>>} */
    public static function defaults(): array
    {
        return self::normalize([
            'schema' => 1,
            'aliases' => [],
            'rules' => [
                [
                    'priority' => 100,
                    'mode' => 'contains',
                    'keywords' => ['GPT', 'ChatGPT', 'OpenAI'],
                    'target' => ['group' => 'AI工具', 'family' => 'GPT'],
                ],
                [
                    'priority' => 100,
                    'mode' => 'contains',
                    'keywords' => ['Claude'],
                    'target' => ['group' => 'AI工具', 'family' => 'Claude'],
                ],
                [
                    'priority' => 100,
                    'mode' => 'contains',
                    'keywords' => ['BM', 'FB', 'Facebook'],
                    'target' => ['group' => 'Facebook', 'family' => ''],
                ],
                [
                    'priority' => 100,
                    'mode' => 'contains',
                    'keywords' => ['IG', 'Instagram'],
                    'target' => ['group' => 'Instagram', 'family' => ''],
                ],
            ],
        ]);
    }

    /** @return array{schema:int,aliases:list<array{source_id:int,alias:string}>,rules:list<array{priority:int,mode:string,keywords:list<string>,target:array{group:string,family:string}>>} */
    public static function normalize(array $input): array
    {
        self::onlyKeys($input, ['schema', 'aliases', 'rules'], '配置');
        if (($input['schema'] ?? 1) !== 1) {
            throw new RuntimeException('智能货源中心配置版本不受支持。');
        }

        $aliases = $input['aliases'] ?? [];
        if (!is_array($aliases) || !array_is_list($aliases) || count($aliases) > self::MAX_ALIASES) {
            throw new RuntimeException('货源别名必须是最多 16 项的列表。');
        }
        $normalizedAliases = [];
        $seenSourceIds = [];
        $seenAliases = [];
        foreach ($aliases as $alias) {
            if (!is_array($alias)) {
                throw new RuntimeException('货源别名记录格式不正确。');
            }
            self::onlyKeys($alias, ['source_id', 'alias', 'category_mode'], '货源别名');
            $categoryMode = self::categoryMode(array_key_exists('category_mode', $alias) ? $alias['category_mode'] : 'smart');
            $sourceId = $alias['source_id'] ?? null;
            if (!is_int($sourceId) || $sourceId < 1 || $sourceId > 0x7fffffff) {
                throw new RuntimeException('货源别名 source_id 必须是有效正整数。');
            }
            $name = self::boundedText($alias['alias'] ?? null, '货源别名', true);
            $folded = self::fold($name);
            if (isset($seenSourceIds[$sourceId]) || isset($seenAliases[$folded])) {
                throw new RuntimeException('货源 ID 与货源别名都必须唯一。');
            }
            $seenSourceIds[$sourceId] = true;
            $seenAliases[$folded] = true;
            $entry = ['source_id' => $sourceId, 'alias' => $name];
            // Keep legacy smart config bytes and hashes unchanged.
            if ($categoryMode === 'mirror') {
                $entry['category_mode'] = 'mirror';
            }
            $normalizedAliases[] = $entry;
        }
        usort($normalizedAliases, static fn(array $left, array $right): int => $left['source_id'] <=> $right['source_id']);

        $rules = $input['rules'] ?? [];
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > self::MAX_RULES) {
            throw new RuntimeException('分类规则必须是最多 128 项的列表。');
        }
        $normalizedRules = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                throw new RuntimeException('分类规则格式不正确。');
            }
            self::onlyKeys($rule, ['priority', 'mode', 'keywords', 'target'], '分类规则');
            $priority = $rule['priority'] ?? null;
            if (!is_int($priority) || $priority < 0 || $priority > 1000) {
                throw new RuntimeException('分类规则优先级必须是 0-1000 的整数。');
            }
            $mode = $rule['mode'] ?? null;
            if (!is_string($mode) || !in_array($mode, ['exact', 'contains'], true)) {
                throw new RuntimeException('分类规则只能使用 exact 或 contains 字面匹配。');
            }
            $keywords = $rule['keywords'] ?? null;
            if (!is_array($keywords) || !array_is_list($keywords)
                || $keywords === [] || count($keywords) > self::MAX_KEYWORDS) {
                throw new RuntimeException('每条规则必须包含 1-16 个字面关键词。');
            }
            $normalizedKeywords = [];
            foreach ($keywords as $keyword) {
                $value = self::boundedText($keyword, '分类关键词', false);
                $normalizedKeywords[self::fold($value)] = $value;
            }
            $normalizedKeywords = array_values($normalizedKeywords);
            usort($normalizedKeywords, self::compareText(...));

            $target = $rule['target'] ?? null;
            if (!is_array($target)) {
                throw new RuntimeException('分类规则目标格式不正确。');
            }
            self::onlyKeys($target, ['group', 'family'], '分类规则目标');
            $normalizedRules[] = [
                'priority' => $priority,
                'mode' => $mode,
                'keywords' => $normalizedKeywords,
                'target' => [
                    'group' => self::boundedText($target['group'] ?? null, '一级分类名称', true),
                    'family' => self::boundedText($target['family'] ?? null, '二级分类名称', true, true),
                ],
            ];
        }

        usort($normalizedRules, static function (array $left, array $right): int {
            $priority = $right['priority'] <=> $left['priority'];
            return $priority !== 0
                ? $priority
                : strcmp(CanonicalJson::encode($left), CanonicalJson::encode($right));
        });

        $uniqueRules = [];
        foreach ($normalizedRules as $rule) {
            $uniqueRules[CanonicalJson::encode($rule)] = $rule;
        }

        $normalized = [
            'schema' => 1,
            'aliases' => $normalizedAliases,
            'rules' => array_values($uniqueRules),
        ];
        if (strlen(CanonicalJson::encode($normalized)) > self::MAX_CONFIG_BYTES) {
            throw new RuntimeException('智能货源中心规范配置超过 131072 字节安全上限。');
        }
        return $normalized;
    }

    public static function categoryMode(mixed $mode): string
    {
        if (!is_string($mode) || !in_array($mode, ['smart', 'mirror'], true)) {
            throw new RuntimeException('分类模式必须是 smart 或 mirror。');
        }
        return $mode;
    }

    private static function onlyKeys(array $value, array $allowed, string $label): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) {
            throw new RuntimeException("{$label}包含不支持的字段。");
        }
    }

    private static function boundedText(
        mixed $value,
        string $label,
        bool $rejectSlashes,
        bool $allowEmpty = false,
    ): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException("{$label}必须是有效 UTF-8 文本。");
        }
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) === 1
            || ($rejectSlashes && preg_match('#[\\\\/]#u', $value) === 1)) {
            throw new RuntimeException("{$label}必须是 1-64 个字符，且不能包含控制字符或路径分隔符。");
        }
        $value = trim($value);
        if ($value === '' && $allowEmpty) {
            return '';
        }
        if ($value === '' || mb_strlen($value, 'UTF-8') > self::MAX_TEXT_LENGTH) {
            throw new RuntimeException("{$label}必须是 1-64 个字符，且不能包含控制字符或路径分隔符。");
        }
        return $value;
    }

    private static function fold(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    private static function compareText(string $left, string $right): int
    {
        $folded = strcmp(self::fold($left), self::fold($right));
        return $folded !== 0 ? $folded : strcmp($left, $right);
    }
}
