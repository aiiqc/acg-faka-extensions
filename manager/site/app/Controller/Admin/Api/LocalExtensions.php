<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Pika\LocalExtensions\Manager\ConfigStore;
use Pika\LocalExtensions\Manager\Csrf;
use Pika\LocalExtensions\Manager\ManagerService;
use Pika\LocalExtensions\Manager\PathGuard;
use Pika\LocalExtensions\Manager\Registry;
use Pika\LocalExtensions\Manager\RequestGuard;
use Pika\LocalExtensions\Manager\StateStore;

#[Interceptor([ManageSession::class], Interceptor::TYPE_API)]
final class LocalExtensions extends Manage
{
    public function listing(Request $request): array
    {
        try {
            RequestGuard::mutation($request, $this->getManage());
            return $this->json(200, 'success', ['list' => ManagerService::listing()]);
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new JSONException('本地扩展列表读取失败，请联系管理员检查安装状态');
        }
    }

    public function setStatus(Request $request): array
    {
        try {
            RequestGuard::mutation($request, $this->getManage());
            $id = PathGuard::extensionId((string)$request->unsafePost('id'));
            $rawEnabled = $request->unsafePost('enabled');
            if (!in_array($rawEnabled, ['0', '1', 0, 1], true)) {
                throw new JSONException('扩展状态参数无效');
            }
            $enabled = (string)$rawEnabled === '1';
            StateStore::setEnabled($id, $enabled);
            ManageLog::log($this->getManage(), ($enabled ? '启动' : '停止') . "了本地扩展({$id})");
            return $this->json(200, $enabled ? '本地扩展已启动' : '本地扩展已停止');
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new JSONException('本地扩展状态更新失败，请检查扩展安装与运行状态');
        }
    }

    public function saveSettings(Request $request): array
    {
        try {
            RequestGuard::mutation($request, $this->getManage());
            $id = PathGuard::extensionId((string)$request->unsafePost('id'));
            $settingsJson = $request->unsafePost('settings_json');
            if (!is_string($settingsJson) || strlen($settingsJson) > 131072) {
                throw new JSONException('扩展配置请求无效');
            }
            try {
                $settingsObject = json_decode($settingsJson, false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new JSONException('扩展配置 JSON 无效');
            }
            if (!$settingsObject instanceof \stdClass) {
                throw new JSONException('扩展配置必须是对象');
            }
            $settings = get_object_vars($settingsObject);
            ConfigStore::save($id, $settings);
            ManageLog::log($this->getManage(), "修改了本地扩展配置({$id})");
            return $this->json(200, '本地扩展配置已保存');
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new JSONException('本地扩展配置保存失败，请检查输入或安装状态');
        }
    }

    public function catalogHubBootstrap(Request $request): array
    {
        try {
            RequestGuard::mutation($request, $this->getManage());
            $data = $this->catalogHubService()->bootstrap();
            $data['draft_scope'] = Csrf::draftScope();
            return $this->json(200, 'success', $data);
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new JSONException('智能货源中心读取失败，请检查扩展与共享店铺状态');
        }
    }

    public function catalogHubSave(Request $request): array
    {
        try {
            RequestGuard::mutation($request, $this->getManage());
            $settingsJson = $request->unsafePost('settings_json');
            if (!is_string($settingsJson) || strlen($settingsJson) > 262144) {
                throw new JSONException('智能货源中心配置请求无效');
            }
            try {
                $settingsObject = json_decode($settingsJson, false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new JSONException('智能货源中心配置 JSON 无效');
            }
            if (!$settingsObject instanceof \stdClass) {
                throw new JSONException('智能货源中心配置必须是对象');
            }
            $settings = json_decode($settingsJson, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($settings)) {
                throw new JSONException('智能货源中心配置必须是对象');
            }
            $settings = $this->catalogHubService()->save($settings);
            return $this->json(200, '智能货源中心配置已保存', ['settings' => $settings]);
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === '请从货源管理的编辑入口修改货源名称。') {
                throw new JSONException($exception->getMessage());
            }
            throw new JSONException('智能货源中心配置内容不符合安全限制');
        } catch (\Throwable) {
            throw new JSONException('智能货源中心配置保存失败，请检查输入或安装状态');
        }
    }

    public function catalogHubPreview(Request $request): array
    {
        try {
            RequestGuard::mutation($request, $this->getManage());
            $rawSourceId = $request->unsafePost('source_id');
            if (!is_string($rawSourceId)
                || preg_match('/^[1-9]\d{0,9}$/D', $rawSourceId) !== 1
                || (int)$rawSourceId > 0x7fffffff) {
                throw new JSONException('共享店铺 ID 不正确');
            }
            $preview = $this->catalogHubService()->preview((int)$rawSourceId);
            return $this->json(200, 'success', ['preview' => $preview]);
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\Pika\LocalExtensions\PikaCatalogHub\Service\PreviewFailure $exception) {
            throw new JSONException($exception->getMessage());
        } catch (\RuntimeException $exception) {
            $safeMessages = [
                '已有分类预览正在运行，请稍后重试。',
                '分类预览处于 30 秒冷却期，请稍后重试。',
                '请先为该货源设置唯一别名，再生成分类预览。',
                '镜像模式请使用后台分析与冻结快照确认，旧规则预览不适用。',
                '共享店铺不存在。',
                '远端商品目录为空，无法生成可信分类预览。',
            ];
            if (in_array($exception->getMessage(), $safeMessages, true)) {
                throw new JSONException($exception->getMessage());
            }
            throw new JSONException('货源预览失败，请检查共享店铺连接与分类规则');
        } catch (\Throwable) {
            throw new JSONException('货源预览失败，请检查共享店铺连接与分类规则');
        }
    }

    public function catalogHubAnalyze(Request $request): array
    {
        try {
            RequestGuard::mutation($request, $this->getManage());
            $sourceId = $this->positiveInteger($request->unsafePost('source_id'), '共享店铺 ID 不正确');
            $alias = $request->unsafePost('alias');
            if (!is_string($alias) || strlen($alias) > 256) {
                throw new JSONException('货源名称不正确');
            }
            $post = $request->unsafePost();
            $categoryMode = is_array($post) && array_key_exists('category_mode', $post) ? $post['category_mode'] : null;
            if (is_array($post) && array_key_exists('category_mode', $post)
                && (!is_string($categoryMode) || !in_array($categoryMode, ['smart', 'mirror'], true))) {
                throw new JSONException('分类模式必须是 smart 或 mirror。');
            }
            $task = $this->catalogHubService()->analyze($sourceId, $alias, $categoryMode);
            ManageLog::log($this->getManage(), "启动了智能货源分析任务({$sourceId})");
            return $this->json(200, '智能分析任务已创建', ['task' => $task]);
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            if (in_array($exception->getMessage(), [
                '共享店铺不存在。',
                '该货源已有未完成的后台任务。',
                '该货源正在同步或入库，请稍后重试智能分析。',
                '任务历史已满，请先完成或取消未完成及可继续的任务，再创建新分析。',
                '货源联动改名尚未完成，请先在对应货源重新保存名称以恢复。',
                '请先保存货源显示名称，再开始智能分析。',
                '该货源的分类映射包含不一致的历史名称，已停止分析。',
                '该货源的分类映射不完整，已停止分析。',
                '该货源已有另一分类模式的映射，已停止入库。',
            ], true)) {
                throw new JSONException($exception->getMessage());
            }
            throw new JSONException('智能分析任务创建失败，请检查货源名称与共享店铺状态');
        } catch (\Throwable) {
            throw new JSONException('智能分析任务创建失败，请检查货源名称与共享店铺状态');
        }
    }

    public function catalogHubConnect(Request $request): array
    {
        try {
            RequestGuard::mutation($request, $this->getManage());
            $input = $this->catalogHubSourceInput($request, false);
            $result = $this->catalogHubService()->connect($input);
            $sourceId = (int)($result['source_id'] ?? 0);
            if ($sourceId < 1 || $sourceId > 0x7fffffff) {
                throw new \RuntimeException('Invalid source identifier after connection.');
            }
            ManageLog::log($this->getManage(), "通过智能货源中心接入了共享店铺({$sourceId})");
            return $this->json(200, '货源测试并保存成功', ['source_id' => $sourceId]);
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            if (in_array($exception->getMessage(), [
                '该店铺地址已经存在。',
                '智能货源中心最多管理 16 个共享店铺。',
                '已有货源接入操作正在保存，请稍后重试。',
                '该货币组合需要填写有效结算汇率。',
            ], true)) {
                throw new JSONException($exception->getMessage());
            }
            throw new JSONException('货源连接失败，请检查 HTTPS 地址、协议、货币与商户凭据');
        } catch (\Throwable) {
            throw new JSONException('货源连接失败，请检查 HTTPS 地址、协议、货币与商户凭据');
        }
    }

    public function catalogHubSourceUpdate(Request $request): array
    {
        try {
            RequestGuard::mutation($request, $this->getManage());
            $sourceId = $this->positiveInteger($request->unsafePost('source_id'), '共享店铺 ID 不正确');
            $result = $this->catalogHubService()->update(
                $sourceId,
                $this->catalogHubSourceInput($request, true, true),
            );
            ManageLog::log($this->getManage(), "通过智能货源中心修改了共享店铺({$sourceId})");
            return $this->json(200, '货源修改成功', $result);
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            if (in_array($exception->getMessage(), [
                '共享店铺不存在。',
                '该店铺地址已经存在。',
                '已有货源接入操作正在保存，请稍后重试。',
                '该货源正在同步或入库，请稍后重试。',
                '该货源已有未完成的后台任务。',
                '该货源还有可继续的导入任务，请先继续或取消任务，再修改名称。',
                '货源联动改名尚未完成，请先在对应货源重新保存名称以恢复。',
                '该货源的分类映射不完整，已停止联动改名。',
                '协议、店铺地址和商户 ID 不支持原地改绑，请按备份维护流程处理。',
                '货源名称与密钥、货币或汇率的修改不能在一次请求中合并，请分两次保存。',
                '该货币组合需要填写有效结算汇率。',
            ], true)) {
                throw new JSONException($exception->getMessage());
            }
            throw new JSONException('货源修改结果尚未核实，请先刷新名称与任务状态；不要重复提交');
        } catch (\Throwable) {
            throw new JSONException('货源修改结果尚未核实，请先刷新名称与任务状态；不要重复提交');
        }
    }

    public function catalogHubTasks(Request $request): array
    {
        try {
            RequestGuard::mutation($request, $this->getManage());
            return $this->json(200, 'success', ['tasks' => $this->catalogHubService()->tasks()]);
        } catch (JSONException $exception) {
            if ($exception->getMessage() === RequestGuard::CSRF_FAILURE_MESSAGE) {
                return $this->json(419, RequestGuard::CSRF_FAILURE_MESSAGE, ['error_code' => 'LOCAL_EXTENSIONS_CSRF_INVALID']);
            }
            throw $exception;
        } catch (\Throwable) {
            throw new JSONException('后台任务读取失败，请检查智能货源中心运行状态');
        }
    }

    public function catalogHubRefreshCsrf(Request $request): array
    {
        try {
            RequestGuard::csrfRenewal($request, $this->getManage());
            $token = Csrf::renew($request->unsafePost('csrf_token'));
            if ($token === null) {
                throw new JSONException('页面会话已变化或凭证无效，请刷新页面或重新登录');
            }
            header('Cache-Control: no-store');
            return $this->json(200, 'success', ['csrf_token' => $token]);
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new JSONException('页面凭证更新失败，请刷新页面或重新登录');
        }
    }

    public function catalogHubCategoryInfo(Request $request): array
    {
        try {
            RequestGuard::mutation($request, $this->getManage());
            $categoryId = $this->positiveInteger($request->unsafePost('category_id'), '分类 ID 不正确');
            return $this->json(200, 'success', ['category' => $this->catalogHubService()->categoryInfo($categoryId)]);
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new JSONException('货源分类信息读取失败，请刷新分类列表或检查插件映射状态');
        }
    }

    public function catalogHubCategoryRename(Request $request): array
    {
        try {
            RequestGuard::mutation($request, $this->getManage());
            $categoryId = $this->positiveInteger($request->unsafePost('category_id'), '分类 ID 不正确');
            $alias = $request->unsafePost('alias');
            if (!is_string($alias) || strlen($alias) > 256) {
                throw new JSONException('货源名称不正确');
            }
            $category = $this->catalogHubService()->renameCategory($categoryId, $alias);
            ManageLog::log($this->getManage(), "联动修改了货源分类显示名称({$categoryId})");
            return $this->json(200, '货源显示名称与受管分类已同步', ['category' => $category]);
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            if (in_array($exception->getMessage(), [
                '该分类不是插件管理的货源名称，请使用普通分类编辑。',
                '该货源正在同步或入库，请稍后重试。',
                '该货源已有未完成的后台任务。',
                '该货源还有可继续的导入任务，请先继续或取消任务，再修改名称。',
                '货源联动改名尚未完成，请先在对应货源重新保存名称以恢复。',
                '该货源的分类映射不完整，已停止联动改名。',
                '分类归属已变化，请刷新分类列表。',
            ], true)) {
                throw new JSONException($exception->getMessage());
            }
            throw new JSONException('货源改名未完成，请先刷新名称与任务状态；不要重复提交或手动修改受管分类');
        } catch (\Throwable) {
            throw new JSONException('货源改名结果尚未核实，请先刷新名称与任务状态；不要重复提交或手动修改受管分类');
        }
    }

    public function catalogHubConfirm(Request $request): array
    {
        try {
            RequestGuard::mutation($request, $this->getManage());
            $taskId = $this->taskId($request->unsafePost('task_id'));
            $revision = $this->positiveInteger($request->unsafePost('revision'), '后台任务 revision 不正确');
            $planHash = $request->unsafePost('plan_hash');
            if (!is_string($planHash) || preg_match('/^[a-f0-9]{64}$/D', $planHash) !== 1) {
                throw new JSONException('分类方案哈希不正确');
            }
            $premiumPercent = $request->unsafePost('premium_percent');
            if (!is_string($premiumPercent) || strlen($premiumPercent) > 32) {
                throw new JSONException('加价百分比不正确');
            }
            $mappingsJson = $request->unsafePost('mappings_json');
            if (!is_string($mappingsJson) || strlen($mappingsJson) > 131072) {
                throw new JSONException('分类映射请求无效');
            }
            try {
                $mappingsRoot = json_decode($mappingsJson, false, 16, JSON_THROW_ON_ERROR);
                $mappings = json_decode($mappingsJson, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new JSONException('分类映射 JSON 无效');
            }
            if (!is_array($mappingsRoot) || !is_array($mappings) || !array_is_list($mappings)) {
                throw new JSONException('分类映射必须是列表');
            }
            $task = $this->catalogHubService()->confirm(
                $taskId,
                $revision,
                $planHash,
                $premiumPercent,
                $mappings,
            );
            ManageLog::log($this->getManage(), '确认了智能货源分类方案');
            return $this->json(200, '分类方案已确认', ['task' => $task]);
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            $safeMessages = [
                '后台任务已被其他操作更新，请刷新后重试。',
                '后台任务尚未等待分类确认。',
                '分类方案已变化，请重新分析后确认。',
                '确认映射必须完整覆盖本次上游分类。',
                '后台任务加价百分比必须是数字。',
                '后台任务加价百分比格式不正确。',
                '后台任务加价百分比超出 0-1000 范围。',
                '重复确认参数与原任务不一致。',
            ];
            if (in_array($exception->getMessage(), $safeMessages, true)) {
                throw new JSONException($exception->getMessage());
            }
            throw new JSONException('分类方案确认结果尚未核实，请先刷新任务列表；不要重复提交');
        } catch (\Throwable) {
            throw new JSONException('分类方案确认结果尚未核实，请先刷新任务列表；不要重复提交');
        }
    }

    public function catalogHubTaskControl(Request $request): array
    {
        try {
            RequestGuard::mutation($request, $this->getManage());
            $taskId = $this->taskId($request->unsafePost('task_id'));
            $revision = $this->positiveInteger($request->unsafePost('revision'), '后台任务 revision 不正确');
            $action = $request->unsafePost('action');
            if (!is_string($action) || !in_array($action, ['pause', 'resume', 'retry_failed', 'cancel'], true)) {
                throw new JSONException('后台任务控制动作不正确');
            }
            $task = $this->catalogHubService()->control($taskId, $revision, $action);
            ManageLog::log($this->getManage(), '执行了智能货源后台任务控制');
            return $this->json(200, '后台任务状态已更新', ['task' => $task]);
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            $safeMessages = [
                '后台任务已被其他操作更新，请刷新后重试。',
                '后台任务当前状态不能暂停。',
                '后台任务当前状态不能取消。',
                '只有已暂停任务或详情读取失败的未完成导入任务可以继续。',
                '只有有未解决清单的异常结束或失败上限任务可以补处理。',
                '该货源正在同步或修改，请刷新任务状态后再继续。',
                '货源联动改名尚未完成，请先在对应货源重新保存名称以恢复。',
                '导入任务快照摘要绑定不一致，拒绝继续。',
            ];
            if (in_array($exception->getMessage(), $safeMessages, true)) {
                throw new JSONException($exception->getMessage());
            }
            throw new JSONException('后台任务控制结果尚未核实，请先刷新任务列表；不要重复提交');
        } catch (\Throwable) {
            throw new JSONException('后台任务控制结果尚未核实，请先刷新任务列表；不要重复提交');
        }
    }

    private function positiveInteger(mixed $value, string $message): int
    {
        if (!is_string($value)
            || preg_match('/^[1-9]\d{0,9}$/D', $value) !== 1
            || (int)$value > 0x7fffffff) {
            throw new JSONException($message);
        }
        return (int)$value;
    }

    /** @return array{alias?:string,type:string,domain:string,app_id:string,app_key:string,currency:string,currency_rate:string} */
    private function catalogHubSourceInput(Request $request, bool $allowEmptyKey, bool $includeAlias = false): array
    {
        $input = [];
        $fields = [
            'type' => 1,
            'domain' => 128,
            'app_id' => 32,
            'app_key' => 64,
            'currency' => 8,
            'currency_rate' => 16,
        ];
        if ($includeAlias) {
            $fields = ['alias' => 256] + $fields;
        }
        foreach ($fields as $field => $maxLength) {
            $value = $request->unsafePost($field);
            if (!is_string($value)
                || strlen($value) > $maxLength
                || ($field === 'app_key' && !$allowEmptyKey && $value === '')) {
                throw new JSONException('货源接入参数不正确');
            }
            $input[$field] = $value;
        }
        return $input;
    }

    private function taskId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{48}$/D', $value) !== 1) {
            throw new JSONException('后台任务编号不正确');
        }
        return $value;
    }

    private function catalogHubService(): object
    {
        if (!StateStore::isEnabled('PikaCatalogHub')) {
            throw new JSONException('请先在本地扩展页面启动智能货源中心');
        }

        foreach (['PikaSupplySync', 'PikaCatalogHub'] as $extensionId) {
            $extension = Registry::extension($extensionId);
            $bootstrap = PathGuard::immutableFileWithin(
                $extension['root'],
                $extension['root'] . '/' . $extension['bootstrap']
            );
            $result = require_once $bootstrap;
            if ($result !== 1 && $result !== true && $result !== null) {
                throw new \RuntimeException('Trusted local extension bootstrap returned an invalid result.');
            }
        }

        $serviceClass = 'Pika\\LocalExtensions\\PikaCatalogHub\\Service\\AdminService';
        if (!class_exists($serviceClass)) {
            throw new \RuntimeException('Trusted catalog hub service is unavailable.');
        }
        return new $serviceClass();
    }
}
