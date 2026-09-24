<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Hook;

use App\Consts\Shared;
use App\Model\User;
use App\Model\UserGroup;
use App\Util\Context as AppContext;
use App\Util\Str;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Util\Context;
use Pika\LocalExtensions\Manager\PathGuard;
use Pika\LocalExtensions\Manager\Registry;
use Pika\LocalExtensions\PikaCatalogHub\Service\SharedCategoryTree as Exporter;
use Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree;

final class SharedCategoryTree
{
    public function after(mixed $controller, mixed $action, mixed &$result): void
    {
        if (!is_object($controller) || get_class($controller) !== 'App\\Controller\\Shared\\Commodity'
            || !is_string($action) || strtolower($action) !== 'items') {
            return;
        }
        try {
            $request = Context::get(Request::class);
            if (!$request instanceof Request) {
                throw new \RuntimeException('Request unavailable.');
            }
            $post = $request->unsafePost();
            if (!is_array($post) || !array_key_exists('pika_category_tree', $post)) {
                return;
            }
            $icons = $post['pika_category_tree'] === '3';
            $allowed = ['app_id', 'app_key', 'sign', 'pika_category_tree'];
            if ($icons) $allowed[] = 'pika_category_ids';
            if (strtoupper($request->method()) !== 'POST' || !in_array($post['pika_category_tree'], ['1', '2', '3'], true)
                || array_diff(array_keys($post), $allowed) !== []
                || ($icons && (!is_string($post['pika_category_ids'] ?? null)
                    || strlen($post['pika_category_ids']) > 1099
                    || preg_match('/^[1-9][0-9]{0,9}(?:,[1-9][0-9]{0,9}){0,99}$/D', $post['pika_category_ids']) !== 1))
                || !is_string($post['app_id'] ?? null) || !is_string($post['sign'] ?? null)
                || preg_match('/^[1-9][0-9]{0,9}$/D', $post['app_id']) !== 1
                || preg_match('/^[a-f0-9]{32}$/D', $post['sign']) !== 1
                || (array_key_exists('app_key', $post) && !is_string($post['app_key']))) {
                throw new \RuntimeException('Invalid tree request.');
            }
            $user = AppContext::get(Shared::SESSION);
            if (!$user instanceof User || (string)$user->id !== $post['app_id']
                || !is_string($user->app_key) || $user->app_key === ''
                // Do not reconstruct POST: this is exactly the native signature input,
                // including the opt-in flag and optional legacy app_key field.
                || !hash_equals(Str::generateSignature($post, $user->app_key), $post['sign'])) {
                throw new \RuntimeException('Invalid tree authentication.');
            }
            if (!is_array($result) || ($result['code'] ?? null) !== 200 || !is_array($result['data'] ?? null)) {
                throw new \RuntimeException('Native catalog unavailable.');
            }
            $dependency = Registry::extension('PikaSupplySync');
            require_once PathGuard::immutableFileWithin($dependency['root'], $dependency['root'] . '/bootstrap.php');
            $exporter = new Exporter(UserGroup::get($user->recharge));
            if ($icons) {
                $ids = UpstreamCategoryTree::iconIds(array_map('intval', explode(',', $post['pika_category_ids'])));
                if (implode(',', $ids) !== $post['pika_category_ids']) {
                    throw new \RuntimeException('Invalid icon identities.');
                }
                $data = $exporter->exportIcons($result['data'], $ids);
            } else {
                $data = $exporter->export($result['data'], (int)$post['pika_category_tree']);
            }
            // Replace the referenced response only after every auth/visibility/tree gate.
            $result = ['code' => 200, 'data' => $data];
            if ($post['pika_category_tree'] === '2') {
                $result['pika_category_icons'] = 1;
            }
        } catch (\Throwable) {
            // Local hook dispatch propagates this JSON exception to Kernel before
            // response encoding. Never fall back to the unprojected native models.
            throw new JSONException('PIKA_TREE_UNAVAILABLE');
        }
    }
}
