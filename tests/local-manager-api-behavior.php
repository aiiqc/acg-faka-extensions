<?php
declare(strict_types=1);

namespace Kernel\Exception {
    final class JSONException extends \Exception {}
}

namespace Kernel\Annotation {
    final class Interceptor
    {
        public const TYPE_API = 1;
        public function __construct(mixed ...$arguments) {}
    }
}

namespace Kernel\Context\Interface {
    interface Request
    {
        public function method(): string;
        public function unsafePost(?string $key = null): mixed;
    }
}

namespace App\Interceptor {
    final class ManageSession {}
}

namespace App\Model {
    final class ManageLog
    {
        /** @var list<string> */
        public static array $entries = [];
        public static function log(object $manage, string $content): void
        {
            self::$entries[] = $content;
        }
    }
}

namespace App\Controller\Base\API {
    class Manage
    {
        protected function getManage(): object
        {
            return (object)['type' => 0];
        }

        protected function json(int $code = 200, ?string $message = null, ?array $data = []): array
        {
            return ['code' => $code, 'msg' => $message, 'data' => $data];
        }
    }
}

namespace Pika\LocalExtensions\Manager {
    final class Csrf
    {
        public static ?string $renewed = 'synthetic-renewed-token';
        public static function draftScope(): string { return str_repeat('c', 64); }
        public static function renew(mixed $token): ?string { return self::$renewed; }
    }

    final class RequestGuard
    {
        public const CSRF_FAILURE_MESSAGE = '本地扩展 CSRF 校验失败，请刷新页面';
        public static ?\Throwable $failure = null;
        public static function csrfRenewal(object $request, object $manage): void { self::mutation($request, $manage); }
        public static function mutation(object $request, object $manage): void
        {
            if (self::$failure !== null) {
                throw self::$failure;
            }
        }
    }

    final class PathGuard
    {
        public static function extensionId(string $id): string
        {
            return $id;
        }

        public static function immutableFileWithin(string $root, string $candidate): string
        {
            return __FILE__;
        }
    }

    final class Registry
    {
        /** @return array<string,mixed> */
        public static function extension(string $id): array
        {
            return ['root' => __DIR__, 'bootstrap' => $id . '/bootstrap.php'];
        }
    }

    final class ManagerService
    {
        public static ?\Throwable $failure = null;
        public static function listing(): array
        {
            if (self::$failure !== null) {
                throw self::$failure;
            }
            return [];
        }
    }

    final class StateStore
    {
        public static ?\Throwable $failure = null;
        public static bool $enabled = true;
        public static function isEnabled(string $id): bool
        {
            return self::$enabled;
        }
        public static function setEnabled(string $id, bool $enabled): void
        {
            if (self::$failure !== null) {
                throw self::$failure;
            }
        }
    }

    final class ConfigStore
    {
        public static ?\Throwable $failure = null;
        /** @var array<string,mixed>|null */
        public static ?array $saved = null;
        public static function save(string $id, array $settings): void
        {
            if (self::$failure !== null) {
                throw self::$failure;
            }
            self::$saved = $settings;
        }
    }
}

namespace Pika\LocalExtensions\PikaCatalogHub\Service {
    final class AdminService
    {
        public static ?\Throwable $failure = null;
        /** @var array<string,mixed>|null */
        public static ?array $updatedInput = null;

        public function bootstrap(): array
        {
            if (self::$failure !== null) throw self::$failure;
            return ['sources' => [], 'settings' => ['schema' => 1, 'aliases' => [], 'rules' => []]];
        }

        public function save(array $settings): array
        {
            if (self::$failure !== null) throw self::$failure;
            return $settings;
        }

        public function connect(array $input): array
        {
            if (self::$failure !== null) throw self::$failure;
            if (($input['app_key'] ?? null) !== 'secret-value') {
                throw new \RuntimeException('missing transient key');
            }
            return ['source_id' => 7];
        }

        public function update(int $sourceId, array $input): array
        {
            if (self::$failure !== null) throw self::$failure;
            self::$updatedInput = $input;
            return ['source' => [
                'id' => $sourceId,
                'name' => 'Remote Store',
                'alias' => $input['alias'],
                'type' => (int)$input['type'],
                'domain' => $input['domain'],
                'app_id' => $input['app_id'],
                'currency' => $input['currency'],
                'currency_rate' => $input['currency_rate'] === '' ? '0.000000' : $input['currency_rate'],
            ]];
        }

        public function preview(int $sourceId): array
        {
            if (self::$failure !== null) throw self::$failure;
            return ['source_id' => $sourceId];
        }

        public function analyze(int $sourceId, string $alias): array
        {
            if (self::$failure !== null) throw self::$failure;
            return ['task_id' => str_repeat('a', 48), 'source_id' => $sourceId, 'source_alias' => $alias, 'revision' => 1];
        }

        public function tasks(): array
        {
            if (self::$failure !== null) throw self::$failure;
            return [['task_id' => str_repeat('a', 48), 'revision' => 1]];
        }

        public function confirm(
            string $taskId,
            int $revision,
            string $planHash,
            string $premiumPercent,
            array $mappings,
        ): array {
            if (self::$failure !== null) throw self::$failure;
            return ['task_id' => $taskId, 'revision' => $revision + 1, 'premium_percent' => $premiumPercent, 'mappings' => $mappings];
        }

        public function control(string $taskId, int $revision, string $action): array
        {
            if (self::$failure !== null) throw self::$failure;
            return ['task_id' => $taskId, 'revision' => $revision + 1, 'action' => $action];
        }

        public function categoryInfo(int $categoryId): ?array
        {
            if (self::$failure !== null) throw self::$failure;
            return $categoryId === 8 ? null : ['category_id' => $categoryId, 'source_id' => 7, 'alias' => 'Demo'];
        }

        public function renameCategory(int $categoryId, string $alias): array
        {
            if (self::$failure !== null) throw self::$failure;
            return ['category_id' => $categoryId, 'source_id' => 7, 'alias' => $alias];
        }
    }
}

namespace {
    use App\Controller\Admin\Api\LocalExtensions;
    use App\Model\ManageLog;
    use Kernel\Context\Interface\Request;
    use Kernel\Exception\JSONException;
    use Pika\LocalExtensions\Manager\ConfigStore;
    use Pika\LocalExtensions\Manager\ManagerService;
    use Pika\LocalExtensions\Manager\RequestGuard;
    use Pika\LocalExtensions\Manager\StateStore;
    use Pika\LocalExtensions\PikaCatalogHub\Service\AdminService;

    final class DummyRequest implements Request
    {
        /** @param array<string,mixed> $post */
        public function __construct(private array $post = []) {}
        public function method(): string { return 'POST'; }
        public function unsafePost(?string $key = null): mixed { return $key === null ? $this->post : ($this->post[$key] ?? null); }
    }

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function expectSafe(callable $operation, string $expected): void
    {
        try {
            $operation();
            throw new RuntimeException('expected JSONException was not thrown');
        } catch (JSONException $exception) {
            check($exception->getMessage() === $expected, 'unexpected public error message');
            check(!str_contains($exception->getMessage(), '/srv/private'), 'private path leaked');
            check(!str_contains($exception->getMessage(), 'token=secret'), 'secret leaked');
        }
    }

    $requestPost = ['source_id' => '7', 'alias' => 'Fixture source'];
    $request = new DummyRequest($requestPost);
    check($request->unsafePost() === $requestPost, 'request without a key must return the complete post');
    check($request->unsafePost(null) === $requestPost, 'request with a null key must return the complete post');
    check($request->unsafePost('source_id') === '7', 'request with a key must preserve the posted value');
    check($request->unsafePost('missing') === null, 'request with a missing key must return null');

    require dirname(__DIR__) . '/manager/site/app/Controller/Admin/Api/LocalExtensions.php';
    $controller = new LocalExtensions();
    $internal = new RuntimeException('failure at /srv/private/config token=secret');

    ManagerService::$failure = $internal;
    expectSafe(
        static fn() => $controller->listing(new DummyRequest()),
        '本地扩展列表读取失败，请联系管理员检查安装状态'
    );
    ManagerService::$failure = null;

    StateStore::$failure = $internal;
    expectSafe(
        static fn() => $controller->setStatus(new DummyRequest(['id' => 'PikaSupplySync', 'enabled' => '1'])),
        '本地扩展状态更新失败，请检查扩展安装与运行状态'
    );
    StateStore::$failure = null;

    ConfigStore::$failure = $internal;
    expectSafe(
        static fn() => $controller->saveSettings(new DummyRequest(['id' => 'PikaSupplySync', 'settings_json' => '{}'])),
        '本地扩展配置保存失败，请检查输入或安装状态'
    );
    ConfigStore::$failure = null;

    $result = $controller->saveSettings(new DummyRequest([
        'id' => 'PikaSupplySync',
        'settings_json' => '{}',
    ]));
    check(($result['code'] ?? null) === 200, 'empty JSON object was rejected');
    check(ConfigStore::$saved === [], 'empty JSON object was not converted to an empty settings map');

    RequestGuard::$failure = new JSONException('本地扩展 CSRF 校验失败，请刷新页面');
    try {
        $controller->listing(new DummyRequest());
        throw new RuntimeException('expected guard JSONException was not thrown');
    } catch (JSONException $exception) {
        check($exception->getMessage() === '本地扩展 CSRF 校验失败，请刷新页面', 'guard error was replaced');
    }
    RequestGuard::$failure = null;

    RequestGuard::$failure = new JSONException(RequestGuard::CSRF_FAILURE_MESSAGE);
    $expiredPoll = $controller->catalogHubTasks(new DummyRequest());
    check(($expiredPoll['code'] ?? null) === 419
        && ($expiredPoll['data']['error_code'] ?? null) === 'LOCAL_EXTENSIONS_CSRF_INVALID', 'read-only poll omitted its explicit CSRF failure code');
    expectSafe(static fn() => $controller->catalogHubRefreshCsrf(new DummyRequest()), RequestGuard::CSRF_FAILURE_MESSAGE);
    RequestGuard::$failure = null;
    $logsBeforeRenewal = ManageLog::$entries;
    AdminService::$failure = $internal;
    $renewed = $controller->catalogHubRefreshCsrf(new DummyRequest(['csrf_token' => 'synthetic-old-token']));
    check(($renewed['data']['csrf_token'] ?? null) === 'synthetic-renewed-token', 'renewal receipt missing');
    check(ManageLog::$entries === $logsBeforeRenewal, 'renewal wrote an administrator audit entry');
    AdminService::$failure = null;
    \Pika\LocalExtensions\Manager\Csrf::$renewed = null;
    expectSafe(static fn() => $controller->catalogHubRefreshCsrf(new DummyRequest()), '页面会话已变化或凭证无效，请刷新页面或重新登录');
    \Pika\LocalExtensions\Manager\Csrf::$renewed = 'synthetic-renewed-token';

    $bootstrap = $controller->catalogHubBootstrap(new DummyRequest());
    check(($bootstrap['data']['settings']['schema'] ?? null) === 1, 'catalog hub bootstrap result was not returned');
    check(($bootstrap['data']['draft_scope'] ?? null) === str_repeat('c', 64), 'login-bound draft namespace was missing');

    $saved = $controller->catalogHubSave(new DummyRequest([
        'settings_json' => '{"schema":1,"aliases":[],"rules":[]}',
    ]));
    check(($saved['data']['settings']['schema'] ?? null) === 1, 'catalog hub settings were not returned');

    $savedNonEmpty = $controller->catalogHubSave(new DummyRequest([
        'settings_json' => json_encode([
            'schema' => 1,
            'aliases' => [['source_id' => 7, 'alias' => '货源A']],
            'rules' => [[
                'priority' => 100,
                'mode' => 'contains',
                'keywords' => ['GPT'],
                'target' => ['group' => 'AI工具', 'family' => 'GPT'],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]));
    check(
        is_array($savedNonEmpty['data']['settings']['aliases'][0] ?? null)
            && is_array($savedNonEmpty['data']['settings']['rules'][0]['target'] ?? null),
        'catalog hub nested JSON objects were not converted to associative arrays',
    );
    AdminService::$failure = new RuntimeException('请从货源管理的编辑入口修改货源名称。');
    expectSafe(
        static fn() => $controller->catalogHubSave(new DummyRequest([
            'settings_json' => '{"schema":1,"aliases":[],"rules":[]}',
        ])),
        '请从货源管理的编辑入口修改货源名称。',
    );
    AdminService::$failure = null;

    $preview = $controller->catalogHubPreview(new DummyRequest(['source_id' => '7']));
    check(($preview['data']['preview']['source_id'] ?? null) === 7, 'catalog hub source id was not normalized');
    expectSafe(
        static fn() => $controller->catalogHubPreview(new DummyRequest(['source_id' => '01'])),
        '共享店铺 ID 不正确'
    );
    expectSafe(
        static fn() => $controller->catalogHubPreview(new DummyRequest(['source_id' => '2147483648'])),
        '共享店铺 ID 不正确'
    );

    AdminService::$failure = new RuntimeException('已有分类预览正在运行，请稍后重试。');
    expectSafe(
        static fn() => $controller->catalogHubPreview(new DummyRequest(['source_id' => '7'])),
        '已有分类预览正在运行，请稍后重试。'
    );

    AdminService::$failure = $internal;
    expectSafe(
        static fn() => $controller->catalogHubPreview(new DummyRequest(['source_id' => '7'])),
        '货源预览失败，请检查共享店铺连接与分类规则'
    );
    require_once dirname(__DIR__) . '/extensions/PikaCatalogHub/Service/PreviewFailure.php';
    AdminService::$failure = \Pika\LocalExtensions\PikaCatalogHub\Service\PreviewFailure::fromThrowable($internal, 'catalog_request');
    expectSafe(static fn() => $controller->catalogHubPreview(new DummyRequest(['source_id' => '7'])), AdminService::$failure->getMessage());
    AdminService::$failure = null;

    $taskId = str_repeat('a', 48);
    $planHash = str_repeat('b', 64);
    $connected = $controller->catalogHubConnect(new DummyRequest([
        'type' => '0',
        'domain' => 'https://upstream.example',
        'app_id' => 'merchant-a',
        'app_key' => 'secret-value',
        'currency' => 'CNY',
        'currency_rate' => '',
    ]));
    check(($connected['data']['source_id'] ?? null) === 7, 'catalog hub connect did not return the source id');
    check(
        !str_contains(json_encode($connected, JSON_THROW_ON_ERROR), 'secret-value'),
        'catalog hub connect response leaked the key',
    );
    check(
        !str_contains(implode("\n", \App\Model\ManageLog::$entries), 'secret-value'),
        'catalog hub connect log leaked the key',
    );
    expectSafe(
        static fn() => $controller->catalogHubConnect(new DummyRequest([
            'type' => '0',
            'domain' => 'https://upstream.example',
            'app_id' => 'merchant-a',
            'app_key' => str_repeat('x', 65),
            'currency' => 'CNY',
            'currency_rate' => '',
        ])),
        '货源接入参数不正确',
    );
    AdminService::$failure = $internal;
    expectSafe(
        static fn() => $controller->catalogHubConnect(new DummyRequest([
            'type' => '0',
            'domain' => 'https://upstream.example',
            'app_id' => 'merchant-a',
            'app_key' => 'secret-value',
            'currency' => 'CNY',
            'currency_rate' => '',
        ])),
        '货源连接失败，请检查 HTTPS 地址、协议、货币与商户凭据',
    );
    AdminService::$failure = null;

    $updatedSource = $controller->catalogHubSourceUpdate(new DummyRequest([
        'source_id' => '7',
        'alias' => '货源B',
        'type' => '2',
        'domain' => 'https://upstream.example',
        'app_id' => 'merchant-a',
        'app_key' => '',
        'currency' => 'CNY',
        'currency_rate' => '',
    ]));
    check(($updatedSource['data']['source']['id'] ?? null) === 7, 'catalog hub source update did not return the source');
    check(($updatedSource['msg'] ?? null) === '货源修改成功', 'source update response incorrectly claimed an upstream test');
    check((AdminService::$updatedInput['alias'] ?? null) === '货源B', 'source alias was dropped before the service layer');
    check((AdminService::$updatedInput['app_key'] ?? null) === '', 'blank edit key was not preserved for the service layer');
    check(
        !array_key_exists('app_key', $updatedSource['data']['source'] ?? []),
        'catalog hub source update response returned a key field',
    );
    expectSafe(
        static fn() => $controller->catalogHubSourceUpdate(new DummyRequest([
            'source_id' => '07',
            'alias' => '货源B',
            'type' => '2',
            'domain' => 'https://upstream.example',
            'app_id' => 'merchant-a',
            'app_key' => '',
            'currency' => 'CNY',
            'currency_rate' => '',
        ])),
        '共享店铺 ID 不正确',
    );
    AdminService::$failure = new RuntimeException('协议、店铺地址和商户 ID 不支持原地改绑，请按备份维护流程处理。');
    expectSafe(
        static fn() => $controller->catalogHubSourceUpdate(new DummyRequest([
            'source_id' => '7',
            'alias' => '货源B',
            'type' => '1',
            'domain' => 'https://replacement.example',
            'app_id' => 'merchant-b',
            'app_key' => '',
            'currency' => 'CNY',
            'currency_rate' => '',
        ])),
        '协议、店铺地址和商户 ID 不支持原地改绑，请按备份维护流程处理。',
    );
    AdminService::$failure = null;

    AdminService::$failure = new RuntimeException('货源名称与密钥、货币或汇率的修改不能在一次请求中合并，请分两次保存。');
    expectSafe(
        static fn() => $controller->catalogHubSourceUpdate(new DummyRequest([
            'source_id' => '7',
            'alias' => '货源B',
            'type' => '2',
            'domain' => 'https://upstream.example',
            'app_id' => 'merchant-a',
            'app_key' => '',
            'currency' => 'CNY',
            'currency_rate' => '',
        ])),
        '货源名称与密钥、货币或汇率的修改不能在一次请求中合并，请分两次保存。',
    );
    AdminService::$failure = null;

    $analyzed = $controller->catalogHubAnalyze(new DummyRequest([
        'source_id' => '7',
        'alias' => '货源A',
    ]));
    check(($analyzed['data']['task']['source_alias'] ?? null) === '货源A', 'catalog hub analyze did not return the task');
    AdminService::$failure = new RuntimeException('请先保存货源显示名称，再开始智能分析。');
    expectSafe(
        static fn() => $controller->catalogHubAnalyze(new DummyRequest(['source_id' => '7', 'alias' => '未保存名称'])),
        '请先保存货源显示名称，再开始智能分析。',
    );
    AdminService::$failure = new RuntimeException('该货源的分类映射包含不一致的历史名称，已停止分析。');
    expectSafe(
        static fn() => $controller->catalogHubAnalyze(new DummyRequest(['source_id' => '7', 'alias' => '货源A'])),
        '该货源的分类映射包含不一致的历史名称，已停止分析。',
    );
    AdminService::$failure = new RuntimeException('该货源的分类映射不完整，已停止分析。');
    expectSafe(
        static fn() => $controller->catalogHubAnalyze(new DummyRequest(['source_id' => '7', 'alias' => '货源A'])),
        '该货源的分类映射不完整，已停止分析。',
    );
    AdminService::$failure = new RuntimeException('任务历史已满，请先完成或取消未完成及可继续的任务，再创建新分析。');
    expectSafe(
        static fn() => $controller->catalogHubAnalyze(new DummyRequest(['source_id' => '7', 'alias' => '货源A'])),
        '任务历史已满，请先完成或取消未完成及可继续的任务，再创建新分析。',
    );
    AdminService::$failure = null;
    expectSafe(
        static fn() => $controller->catalogHubAnalyze(new DummyRequest(['source_id' => '07', 'alias' => '货源A'])),
        '共享店铺 ID 不正确',
    );

    $tasks = $controller->catalogHubTasks(new DummyRequest());
    check(count($tasks['data']['tasks'] ?? []) === 1, 'catalog hub tasks did not return the list');

    $mapping = [[
        'source_category' => 'AI Chat-GPT',
        'target' => ['group' => 'AI工具', 'family' => 'GPT'],
        'confidence' => 'high',
    ]];
    $confirmed = $controller->catalogHubConfirm(new DummyRequest([
        'task_id' => $taskId,
        'revision' => '1',
        'plan_hash' => $planHash,
        'premium_percent' => '0',
        'mappings_json' => json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]));
    check(($confirmed['data']['task']['mappings'] ?? null) === $mapping, 'catalog hub confirm did not preserve the mapping list');
    expectSafe(
        static fn() => $controller->catalogHubConfirm(new DummyRequest([
            'task_id' => $taskId,
            'revision' => '1',
            'plan_hash' => $planHash,
            'premium_percent' => '0',
            'mappings_json' => '{}',
        ])),
        '分类映射必须是列表',
    );

    $controlled = $controller->catalogHubTaskControl(new DummyRequest([
        'task_id' => $taskId,
        'revision' => '2',
        'action' => 'pause',
    ]));
    check(($controlled['data']['task']['action'] ?? null) === 'pause', 'catalog hub task control did not return the updated task');

    $category = $controller->catalogHubCategoryInfo(new DummyRequest(['category_id' => '7']));
    check(($category['data']['category']['source_id'] ?? null) === 7, 'managed category lookup was not returned');
    $unmanaged = $controller->catalogHubCategoryInfo(new DummyRequest(['category_id' => '8']));
    check(array_key_exists('category', $unmanaged['data']) && $unmanaged['data']['category'] === null, 'ordinary category was not distinguished');
    $renamed = $controller->catalogHubCategoryRename(new DummyRequest(['category_id' => '7', 'alias' => '任意合法名称']));
    check(($renamed['data']['category']['alias'] ?? null) === '任意合法名称', 'category rename hardcoded an alias');
    AdminService::$failure = new RuntimeException('该货源还有可继续的导入任务，请先继续或取消任务，再修改名称。');
    expectSafe(static fn() => $controller->catalogHubCategoryRename(new DummyRequest(['category_id' => '7', 'alias' => 'Demo'])), AdminService::$failure->getMessage());
    expectSafe(static fn() => $controller->catalogHubSourceUpdate(new DummyRequest([
        'source_id' => '7', 'alias' => 'Demo', 'type' => '0', 'domain' => 'https://upstream.example',
        'app_id' => 'merchant-a', 'app_key' => '', 'currency' => 'CNY', 'currency_rate' => '',
    ])), AdminService::$failure->getMessage());
    AdminService::$failure = null;
    check(!str_contains(implode("\n", \App\Model\ManageLog::$entries), '任意合法名称'), 'rename audit recorded raw alias');
    expectSafe(static fn() => $controller->catalogHubCategoryInfo(new DummyRequest(['category_id' => '07'])), '分类 ID 不正确');
    RequestGuard::$failure = new JSONException('本地扩展 CSRF 校验失败，请刷新页面');
    foreach (['catalogHubCategoryInfo', 'catalogHubCategoryRename', 'catalogHubBootstrap'] as $endpoint) {
        expectSafe(static fn() => $controller->$endpoint(new DummyRequest(['category_id' => '7', 'alias' => 'Demo'])), '本地扩展 CSRF 校验失败，请刷新页面');
    }
    RequestGuard::$failure = null;

    $confirmRequest = new DummyRequest([
        'task_id' => $taskId, 'revision' => '1', 'plan_hash' => $planHash,
        'premium_percent' => '10', 'mappings_json' => json_encode($mapping, JSON_THROW_ON_ERROR),
    ]);
    AdminService::$failure = new RuntimeException('后台任务加价百分比超出 0-1000 范围。');
    expectSafe(static fn() => $controller->catalogHubConfirm($confirmRequest), '后台任务加价百分比超出 0-1000 范围。');
    AdminService::$failure = $internal;
    expectSafe(static fn() => $controller->catalogHubConfirm($confirmRequest), '分类方案确认结果尚未核实，请先刷新任务列表；不要重复提交');
    expectSafe(static fn() => $controller->catalogHubTaskControl(new DummyRequest(['task_id' => $taskId, 'revision' => '2', 'action' => 'resume'])), '后台任务控制结果尚未核实，请先刷新任务列表；不要重复提交');
    expectSafe(static fn() => $controller->catalogHubCategoryRename(new DummyRequest(['category_id' => '7', 'alias' => 'Demo'])), '货源改名未完成，请先刷新名称与任务状态；不要重复提交或手动修改受管分类');
    AdminService::$failure = null;
    expectSafe(
        static fn() => $controller->catalogHubTaskControl(new DummyRequest([
            'task_id' => $taskId,
            'revision' => '2',
            'action' => 'delete',
        ])),
        '后台任务控制动作不正确',
    );

    AdminService::$failure = $internal;
    expectSafe(
        static fn() => $controller->catalogHubTasks(new DummyRequest()),
        '后台任务读取失败，请检查智能货源中心运行状态',
    );
    AdminService::$failure = null;

    StateStore::$enabled = false;
    expectSafe(
        static fn() => $controller->catalogHubBootstrap(new DummyRequest()),
        '请先在本地扩展页面启动智能货源中心'
    );
    StateStore::$enabled = true;

    fwrite(STDOUT, "local-manager-api-behavior: PASS\n");
}
