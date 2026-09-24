<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager;

use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Util\Context;
use Kernel\Waf\Filter;
use Pika\LocalExtensions\PikaCatalogHub\Service\SourceAliasService;

/** An operation-specific guard; protected mappings outlive the enabled switch. */
final class NativeCategoryGuard
{
    public static function before(mixed $controller, mixed $action): void
    {
        if (!is_object($controller) || get_class($controller) !== 'App\\Controller\\Admin\\Api\\Category'
            || !is_string($action) || strtolower($action) !== 'save') {
            return;
        }
        try {
            $extensions = Registry::extensions();
            if (!isset($extensions['PikaCatalogHub'])) {
                return;
            }
            foreach (['PikaCatalogHub', 'PikaSupplySync'] as $id) {
                $extension = $extensions[$id] ?? null;
                if (!is_array($extension)) {
                    throw new \RuntimeException('Required source extension is unavailable.');
                }
                require_once PathGuard::immutableFileWithin($extension['root'], $extension['root'] . '/bootstrap.php');
            }
            $request = Context::get(Request::class);
            if (!$request instanceof Request) {
                throw new \RuntimeException('Category request is unavailable.');
            }
            $post = $request->post(flags: Filter::NORMAL);
            if (!is_array($post) || !array_key_exists('name', $post) || (int)($post['id'] ?? 0) < 1) {
                return;
            }
            $managed = (new SourceAliasService())->managedCategory((int)$post['id']);
            if ($managed === null) {
                return;
            }
            if (!is_string($post['name']) || $post['name'] !== $managed['category_name']) {
                throw new JSONException('请使用分类名称旁的“修改货源显示名（同步所有分类）”入口保存名称。');
            }
            // Request copied POST during construction. Removing only this field
            // also prevents an unchanged, stale form from overwriting a later rename.
            unset($post['name']);
            $request->setProperty('post', $post);
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new JSONException('无法核对受管货源分类，已阻止本次保存，请检查分类映射。');
        }
    }
}
