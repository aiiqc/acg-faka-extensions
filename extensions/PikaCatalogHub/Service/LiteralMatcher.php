<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

final class LiteralMatcher
{
    /** @param array{mode:string,keywords:list<string>} $rule */
    public function matches(array $rule, string $category, string $name): bool
    {
        $fields = [
            mb_strtolower($category, 'UTF-8'),
            mb_strtolower($name, 'UTF-8'),
        ];

        foreach ($rule['keywords'] as $keyword) {
            $needle = mb_strtolower($keyword, 'UTF-8');
            foreach ($fields as $field) {
                if ($rule['mode'] === 'exact') {
                    if (hash_equals($needle, $field)) {
                        return true;
                    }
                    continue;
                }
                if ($this->contains($field, $needle)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function contains(string $field, string $needle): bool
    {
        if (preg_match('/^[a-z0-9]{1,3}$/D', $needle) === 1) {
            $words = preg_split('/[^a-z0-9]+/', $field, -1, PREG_SPLIT_NO_EMPTY);
            return is_array($words) && in_array($needle, $words, true);
        }
        return str_contains($field, $needle);
    }
}
