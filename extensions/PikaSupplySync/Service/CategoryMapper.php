<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use App\Model\Category;
use App\Model\Shared;
use App\Util\Date;
use Illuminate\Database\Capsule\Manager as DB;
use RuntimeException;

final class CategoryMapper
{
    /** @param array<string,int> $mapping */
    public function resolve(Shared $source, string $remoteName, array &$mapping): Category
    {
        return DB::transaction(function () use ($source, $remoteName, &$mapping): Category {
            SourceIdentity::lockAndVerify($source);
            $sourceId = (int)$source->id;
            $rootMarker = $this->rootMarker($sourceId);
            $root = $this->mapped($mapping, '__root__', null, $rootMarker);
            if ($root === null) {
                // Category has no ownership column for an extension. Never
                // adopt a same-name administrator category: create a root with
                // a durable source-ID marker and trust only the persisted ID.
                $root = $this->create($this->rootName($sourceId, (string)$source->name), null);
                $mapping['__root__'] = (int)$root->id;
            }

            $key = 'category:' . hash('sha256', $remoteName);
            $category = $this->mapped($mapping, $key, (int)$root->id, null);
            if ($category !== null) {
                return $category;
            }
            // The same rule applies below the root. A child is owned only by
            // its persisted mapping, never by a coincidentally equal name.
            $category = $this->create($remoteName, (int)$root->id);
            $mapping[$key] = (int)$category->id;
            return $category;
        });
    }

    private function mapped(
        array &$mapping,
        string $key,
        ?int $pid,
        ?string $requiredNamePrefix
    ): ?Category
    {
        $id = (int)($mapping[$key] ?? 0);
        if ($id < 1) {
            return null;
        }
        $category = Category::query()->whereKey($id)->first();
        if (!$category) {
            unset($mapping[$key]);
            return null;
        }
        $actualPid = $category->pid === null ? null : (int)$category->pid;
        if (!$this->mappingMatches(
            (int)$category->owner,
            $actualPid,
            $pid,
            (string)$category->name,
            $requiredNamePrefix,
        )) {
            throw new RuntimeException('插件分类映射已偏离对应货源隔离根，已停止同步');
        }
        return $category;
    }

    private function rootName(int $sourceId, string $sourceName): string
    {
        $marker = $this->rootMarker($sourceId);
        $sourceName = trim(strip_tags($sourceName));
        if (!mb_check_encoding($sourceName, 'UTF-8')) {
            $sourceName = '';
        }
        $sourceName = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $sourceName));
        if ($sourceName === '') {
            return $marker;
        }
        $available = 128 - mb_strlen($marker, 'UTF-8') - 1;
        return $marker . ' ' . mb_substr($sourceName, 0, max(0, $available), 'UTF-8');
    }

    private function rootMarker(int $sourceId): string
    {
        if ($sourceId < 1 || $sourceId > 4294967295) {
            throw new RuntimeException('共享店铺 ID 不正确');
        }
        return '[PikaSupplySync:S' . $sourceId . ']';
    }

    private function mappingMatches(
        int $owner,
        ?int $actualPid,
        ?int $expectedPid,
        string $name,
        ?string $requiredNamePrefix,
    ): bool {
        if ($owner !== 0 || $actualPid !== $expectedPid) {
            return false;
        }
        return $requiredNamePrefix === null || str_starts_with($name, $requiredNamePrefix);
    }

    private function create(string $name, ?int $pid): Category
    {
        if ($name === '' || mb_strlen($name, 'UTF-8') > 128) {
            throw new RuntimeException('远端分类名称不正确');
        }
        $category = new Category();
        $category->name = $name;
        $category->sort = 0;
        $category->create_time = Date::current();
        $category->owner = 0;
        $category->icon = '/favicon.ico';
        $category->status = 1;
        $category->hide = 0;
        $category->pid = $pid;
        if (!$category->save()) {
            throw new RuntimeException('商品分类创建失败');
        }
        return $category;
    }
}
