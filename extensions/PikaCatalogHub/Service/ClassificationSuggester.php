<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use RuntimeException;

final class ClassificationSuggester
{
    private const SCHEMA = 1;
    private const MAX_ITEMS = 10000;
    private const MAX_CATEGORIES = 200;
    private const MAX_CATEGORY_LENGTH = 128;

    /**
     * Build a deterministic category plan from upstream category names only.
     * Product names and other item fields are deliberately ignored.
     *
     * @param array<array-key,mixed> $catalog
     * @return array{
     *     schema:int,
     *     categories:list<array{
     *         name:string,
     *         count:int,
     *         target:array{group:string,family:string},
     *         confidence:string
     *     }>,
     *     counts:array{items:int,categories:int,high:int,low:int},
     *     plan_hash:string
     * }
     */
    public function suggest(array $catalog): array
    {
        if ($catalog === []) {
            throw new RuntimeException('上游商品目录为空，无法生成自动分类建议。');
        }
        if (count($catalog) > self::MAX_ITEMS) {
            throw new RuntimeException('自动分类商品超过 10000 项安全上限。');
        }

        $countsByCategory = [];
        foreach ($catalog as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('自动分类商品记录格式不正确。');
            }
            $category = $this->category($row['category'] ?? null);
            // The prefix prevents PHP from coercing a numeric-only category name to an integer key.
            $categoryKey = 'category:' . $category;
            if (!isset($countsByCategory[$categoryKey])) {
                $countsByCategory[$categoryKey] = ['name' => $category, 'count' => 0];
            }
            $countsByCategory[$categoryKey]['count']++;
            if (count($countsByCategory) > self::MAX_CATEGORIES) {
                throw new RuntimeException('自动分类的上游分类超过 200 项安全上限。');
            }
        }

        $categoryCounts = array_values($countsByCategory);
        usort(
            $categoryCounts,
            static fn(array $left, array $right): int => self::compareText($left['name'], $right['name']),
        );

        $categories = [];
        $confidenceCounts = ['high' => 0, 'low' => 0];
        foreach ($categoryCounts as $categoryCount) {
            $name = $categoryCount['name'];
            $suggestion = $this->classify($name);
            $confidenceCounts[$suggestion['confidence']]++;
            $categories[] = [
                'name' => $name,
                'count' => $categoryCount['count'],
                'target' => $suggestion['target'],
                'confidence' => $suggestion['confidence'],
            ];
        }

        $counts = [
            'items' => count($catalog),
            'categories' => count($categories),
            'high' => $confidenceCounts['high'],
            'low' => $confidenceCounts['low'],
        ];
        $payload = [
            'schema' => self::SCHEMA,
            'categories' => $categories,
            'counts' => $counts,
        ];

        return $payload + [
            'plan_hash' => hash('sha256', CanonicalJson::encode($payload)),
        ];
    }

    /** @return array{target:array{group:string,family:string},confidence:string} */
    private function classify(string $category): array
    {
        $folded = mb_strtolower($category, 'UTF-8');
        $words = preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words)) {
            throw new RuntimeException('自动分类无法解析上游分类名称。');
        }

        if ($this->containsAny($folded, ['claude'])) {
            return $this->high('AI工具', 'Claude');
        }
        if ($this->containsAny($folded, ['gemini'])) {
            return $this->high('AI工具', 'Gemini');
        }
        if ($this->containsAny($folded, ['chat-gpt', 'chatgpt', 'openai', 'gpt'])) {
            return $this->high('AI工具', 'GPT');
        }
        if ($this->containsAny($folded, ['threads'])) {
            return $this->high('Threads');
        }
        if ($this->containsAny($folded, ['twitter'])) {
            return $this->high('Twitter X');
        }
        if ($this->containsAny($folded, ['tiktok']) || $this->hasWord($words, 'tk')) {
            return $this->high('TikTok');
        }
        if ($this->containsAny($folded, ['whatsapp', 'whats app'])) {
            return $this->high('WhatsApp');
        }
        if ($this->containsAny($folded, ['telegram', '电报'])
            || $this->hasWord($words, 'tg')
            || str_contains($folded, '飞机')) {
            return $this->high('Telegram');
        }
        if ($this->containsAny($folded, ['facebook', '脸书'])
            || $this->hasWord($words, 'fb')
            || $this->hasWord($words, 'bm')) {
            return $this->high('Facebook');
        }
        if ($this->containsAny($folded, ['instagram']) || $this->hasWord($words, 'ig')) {
            return $this->high('Instagram');
        }
        if ($this->hasWord($words, 'line')) {
            return $this->high('LINE');
        }
        if ($this->containsAny($folded, ['zalo'])) {
            return $this->high('Zalo');
        }
        if ($this->containsAny($folded, ['kakao'])) {
            return $this->high('Kakao');
        }
        if ($this->containsAny($folded, ['linkedin', '领英'])) {
            return $this->high('LinkedIn');
        }
        if ($this->containsAny($folded, ['reddit'])) {
            return $this->high('Reddit');
        }
        if ($this->containsAny($folded, ['snapchat'])) {
            return $this->high('Snapchat');
        }
        if ($this->containsAny($folded, ['discord'])) {
            return $this->high('Discord');
        }
        if ($this->containsAny($folded, ['apple id', 'appleid', '苹果id', '苹果 id'])) {
            return $this->high('Apple ID');
        }
        if ($this->containsAny($folded, ['gmail', '谷歌邮箱'])) {
            return $this->high('邮箱', 'Gmail');
        }
        if ($this->containsAny($folded, ['outlook', '微软邮箱'])) {
            return $this->high('邮箱', 'Outlook');
        }
        if ($this->containsAny($folded, ['短信接码', 'api接码'])
            || $this->hasWord($words, 'sms')) {
            return $this->high('短信接码');
        }
        if ($this->containsAny($folded, ['esim'])) {
            return $this->high('eSIM');
        }
        if ($this->containsAny($folded, ['tron', '波场'])) {
            return $this->high('TRON');
        }
        if ($this->containsAny($folded, ['邮箱', 'email', 'e-mail'])) {
            return $this->high('邮箱');
        }

        if ($this->containsAny($folded, [
            '千粉',
            '橱窗',
            '直播伴侣',
            '粉丝号',
            '双权号',
            '伴侣强开',
            '涨粉后',
        ])) {
            return $this->low('TikTok');
        }

        return $this->low('其他');
    }

    private function category(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException('上游分类名称必须是有效 UTF-8 文本。');
        }
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) === 1) {
            throw new RuntimeException('上游分类名称不能包含控制字符。');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > self::MAX_CATEGORY_LENGTH) {
            throw new RuntimeException('上游分类名称必须是 1-128 个字符。');
        }
        return $value;
    }

    /** @param list<string> $needles */
    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $words */
    private function hasWord(array $words, string $word): bool
    {
        return in_array($word, $words, true);
    }

    /** @return array{target:array{group:string,family:string},confidence:string} */
    private function high(string $group, string $family = ''): array
    {
        return ['target' => ['group' => $group, 'family' => $family], 'confidence' => 'high'];
    }

    /** @return array{target:array{group:string,family:string},confidence:string} */
    private function low(string $group, string $family = ''): array
    {
        return ['target' => ['group' => $group, 'family' => $family], 'confidence' => 'low'];
    }

    private static function compareText(string $left, string $right): int
    {
        $folded = strcmp(mb_strtolower($left, 'UTF-8'), mb_strtolower($right, 'UTF-8'));
        return $folded !== 0 ? $folded : strcmp($left, $right);
    }
}
