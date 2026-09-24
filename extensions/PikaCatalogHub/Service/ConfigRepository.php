<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use Pika\LocalExtensions\Manager\AtomicJson;
use Pika\LocalExtensions\Manager\PathGuard;

final class ConfigRepository
{
    /** @return array{schema:int,aliases:list<array{source_id:int,alias:string}>,rules:list<array>} */
    public function get(): array
    {
        return ConfigSchema::normalize(AtomicJson::read($this->path(), ConfigSchema::defaults()));
    }

    /** @return array{schema:int,aliases:list<array{source_id:int,alias:string}>,rules:list<array>} */
    public function save(array $input): array
    {
        $normalized = ConfigSchema::normalize($input);
        return AtomicJson::update(
            $this->path(),
            ConfigSchema::defaults(),
            static fn(array $current): array => $normalized,
        );
    }

    /** @return array{schema:int,aliases:list<array{source_id:int,alias:string}>,rules:list<array>} */
    public function saveRulesWithUnchangedAliases(array $input): array
    {
        $normalized = ConfigSchema::normalize($input);
        return AtomicJson::update(
            $this->path(),
            ConfigSchema::defaults(),
            static function (array $current) use ($normalized): array {
                $current = ConfigSchema::normalize($current);
                if ($normalized['aliases'] !== $current['aliases']) {
                    throw new \RuntimeException('请从货源管理的编辑入口修改货源名称。');
                }
                $current['rules'] = $normalized['rules'];
                return ConfigSchema::normalize($current);
            },
        );
    }

    /** @return array{schema:int,aliases:list<array{source_id:int,alias:string}>,rules:list<array>} */
    public function upsertAlias(int $sourceId, string $alias): array
    {
        return AtomicJson::update(
            $this->path(),
            ConfigSchema::defaults(),
            static function (array $current) use ($sourceId, $alias): array {
                $current = ConfigSchema::normalize($current);
                $mode = 'smart';
                foreach ($current['aliases'] as $entry) {
                    if ($entry['source_id'] === $sourceId) {
                        $mode = $entry['category_mode'] ?? 'smart';
                    }
                }
                $aliases = array_values(array_filter(
                    $current['aliases'],
                    static fn(array $entry): bool => $entry['source_id'] !== $sourceId,
                ));
                $aliases[] = ['source_id' => $sourceId, 'alias' => $alias, 'category_mode' => $mode];
                $current['aliases'] = $aliases;
                return ConfigSchema::normalize($current);
            },
        );
    }

    public function categoryMode(int $sourceId): string
    {
        foreach ($this->get()['aliases'] as $entry) {
            if ($entry['source_id'] === $sourceId) {
                return $entry['category_mode'] ?? 'smart';
            }
        }
        return 'smart';
    }

    /** Caller holds the source lock and has verified no active job or incompatible mapping. */
    public function setCategoryMode(int $sourceId, string $mode): array
    {
        $mode = ConfigSchema::categoryMode($mode);
        return AtomicJson::update($this->path(), ConfigSchema::defaults(), static function (array $current) use ($sourceId, $mode): array {
            $current = ConfigSchema::normalize($current);
            foreach ($current['aliases'] as &$entry) {
                if ($entry['source_id'] === $sourceId) {
                    $entry['category_mode'] = $mode;
                    unset($entry);
                    return ConfigSchema::normalize($current);
                }
            }
            unset($entry);
            throw new \RuntimeException('请先保存货源显示名称。');
        });
    }

    private function path(): string
    {
        return PathGuard::stateDirectory('extensions/PikaCatalogHub', 0o700) . '/config.json';
    }
}
