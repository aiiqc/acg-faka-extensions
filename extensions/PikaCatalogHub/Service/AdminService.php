<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use App\Model\Shared;
use Pika\LocalExtensions\PikaSupplySync\Service\CatalogPlanner;
use Pika\LocalExtensions\PikaSupplySync\Service\RunBudget;
use Pika\LocalExtensions\PikaSupplySync\Service\SafeHttpClient;
use Pika\LocalExtensions\PikaSupplySync\Service\SharedGateway;
use Pika\LocalExtensions\PikaSupplySync\Service\SourceIdentity;
use Pika\LocalExtensions\PikaSupplySync\Service\SourceLock;
use Pika\LocalExtensions\PikaSupplySync\Service\SourcePolicy;
use RuntimeException;

final class AdminService
{
    private const MAX_SOURCES = 16;
    private ConfigRepository $config;
    private SourceAliasService $aliases;

    public function __construct()
    {
        $this->config = new ConfigRepository();
        $this->aliases = new SourceAliasService($this->config);
    }

    /** @return array{sources:list<array{id:int,name:string,type:int,alias:string,domain:string,app_id:string,currency:string,currency_rate:string}>,settings:array<string,mixed>} */
    public function bootstrap(): array
    {
        $settings = $this->config->get();
        $aliasMap = [];
        $modeMap = [];
        foreach ($settings['aliases'] as $entry) {
            $aliasMap[$entry['source_id']] = $entry['alias'];
            $modeMap[$entry['source_id']] = $entry['category_mode'] ?? 'smart';
        }

        $rows = Shared::query()
            ->orderBy('id')
            ->limit(self::MAX_SOURCES + 1)
            ->get(['id', 'name', 'type', 'domain', 'app_id', 'currency', 'currency_rate']);
        if (count($rows) > self::MAX_SOURCES) {
            throw new RuntimeException('智能货源中心最多管理 16 个共享店铺。');
        }
        $sources = [];
        foreach ($rows as $row) {
            $id = (int)($row->id ?? 0);
            if ($id < 1) {
                throw new RuntimeException('共享店铺 ID 格式不正确。');
            }
            $sources[] = [
                'id' => $id,
                'name' => $this->sourceName($row->name ?? null),
                'type' => (int)($row->type ?? 0),
                'alias' => $aliasMap[$id] ?? '',
                'category_mode' => $modeMap[$id] ?? 'smart',
                'domain' => $this->sourceField($row->domain ?? null, 128, '共享店铺地址'),
                'app_id' => $this->sourceAppId($row->app_id ?? null),
                'currency' => $this->sourceCurrency($row->currency ?? 'CNY'),
                'currency_rate' => $this->sourceCurrencyRate($row->currency_rate ?? 0),
            ];
        }
        return ['sources' => $sources, 'settings' => $settings];
    }

    /** @return array<string,mixed> */
    public function save(array $input): array
    {
        return $this->config->saveRulesWithUnchangedAliases($input);
    }

    /** @param array<string,mixed> $input @return array{source_id:int} */
    public function connect(array $input): array
    {
        $this->loadSupplyDependency();
        return (new SafeSourceConnector())->connect($input);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{source:array{id:int,name:string,alias:string,type:int,domain:string,app_id:string,currency:string,currency_rate:string}}
     */
    public function update(int $sourceId, array $input): array
    {
        $this->loadSupplyDependency();
        return (new SafeSourceConnector())->update($sourceId, $input);
    }

    /** @return array<string,mixed> */
    public function analyze(int $sourceId, string $alias, ?string $categoryMode = null): array
    {
        if ($sourceId < 1 || $sourceId > 0x7fffffff) {
            throw new RuntimeException('共享店铺 ID 不正确。');
        }
        $this->loadSupplyDependency();
        $source = Shared::query()->find($sourceId);
        if (!$source || (int)($source->id ?? 0) !== $sourceId) {
            throw new RuntimeException('共享店铺不存在。');
        }

        $lock = new SourceLock();
        if (!$lock->acquire($sourceId)) {
            throw new RuntimeException('该货源正在同步或入库，请稍后重试智能分析。');
        }
        try {
            $categoryMode = ConfigSchema::categoryMode($categoryMode ?? $this->config->categoryMode($sourceId));
            $this->aliases->assertNoPendingRename();
            $jobs = new JobService();
            foreach ($jobs->list() as $job) {
                if ((int)$job['source_id'] === $sourceId
                    && !in_array($job['state'], ['cancelled', 'completed', 'failed'], true)) {
                    throw new RuntimeException('该货源已有未完成的后台任务。');
                }
            }

            $classificationAlias = $this->aliases->classificationAlias($sourceId, $alias);
            $this->aliases->assertCategoryMode($sourceId, $categoryMode);
            $this->config->setCategoryMode($sourceId, $categoryMode);
            return $jobs->createAnalysis(
                $sourceId,
                $classificationAlias,
                SourceIdentity::fingerprint($source),
                $categoryMode,
            );
        } finally {
            $lock->release();
        }
    }

    /** @return list<array<string,mixed>> */
    public function tasks(): array
    {
        return (new JobService())->list();
    }

    /** @param list<array<string,mixed>> $mappings @return array<string,mixed> */
    public function confirm(
        string $taskId,
        int $revision,
        string $planHash,
        string $premiumPercent,
        array $mappings,
    ): array {
        return (new JobService())->confirm(
            $taskId,
            $revision,
            $planHash,
            $premiumPercent,
            $mappings,
        );
    }

    /** @return array<string,mixed> */
    public function control(string $taskId, int $revision, string $action): array
    {
        $jobs = new JobService();
        if (!in_array($action, ['resume', 'retry_failed'], true)) {
            // A running worker holds SourceLock. Pause/cancel must remain usable.
            return $jobs->control($taskId, $revision, $action);
        }
        $this->loadSupplyDependency();
        $job = $jobs->get($taskId);
        $lock = new SourceLock();
        if (!$lock->acquire($job['source_id'])) {
            throw new RuntimeException('该货源正在同步或修改，请刷新任务状态后再继续。');
        }
        try {
            $this->aliases->assertNoPendingRename();
            return $jobs->control($taskId, $revision, $action);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string,mixed>|null */
    public function categoryInfo(int $categoryId): ?array
    {
        $this->loadSupplyDependency();
        return $this->aliases->managedCategory($categoryId);
    }

    /** @return array<string,mixed> */
    public function renameCategory(int $categoryId, string $alias): array
    {
        $current = $this->categoryInfo($categoryId);
        if ($current === null) {
            throw new RuntimeException('该分类不是插件管理的货源名称，请使用普通分类编辑。');
        }
        $lock = new SourceLock();
        if (!$lock->acquire($current['source_id'])) {
            throw new RuntimeException('该货源正在同步或入库，请稍后重试。');
        }
        try {
            $locked = $this->aliases->managedCategory($categoryId);
            if ($locked === null || $locked['source_id'] !== $current['source_id']) {
                throw new RuntimeException('分类归属已变化，请刷新分类列表。');
            }
            $this->aliases->rename($locked['source_id'], $alias);
            $result = $this->aliases->managedCategory($categoryId);
            if ($result === null || $result['source_id'] !== $locked['source_id']) {
                throw new RuntimeException('分类名称保存结果尚未核实，请刷新分类列表。');
            }
            return $result;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string,mixed> */
    public function preview(int $sourceId): array
    {
        if ($this->config->categoryMode($sourceId) === 'mirror') {
            throw new RuntimeException('镜像模式请使用后台分析与冻结快照确认，旧规则预览不适用。');
        }
        $stage = 'source_lookup';
        $lock = null;
        $lockAcquired = false;
        $budget = null;
        $budgetActive = false;
        $primaryFailure = null;
        try {
            if ($sourceId < 1 || $sourceId > 0x7fffffff) {
                throw new RuntimeException('预览货源 ID 不正确。');
            }
            $stage = 'lock';
            $lock = new PreviewLock();
            $lock->acquire();
            $lockAcquired = true;

            $stage = 'dependency';
            $this->loadSupplyDependency();
            $stage = 'source_lookup';
            $source = Shared::query()->find($sourceId);
            if (!$source || (int)($source->id ?? 0) !== $sourceId) {
                throw new RuntimeException('共享店铺不存在。');
            }

            $stage = 'catalog_request';
            $budget = new RunBudget();
            $budget->beginSource($sourceId);
            $budgetActive = true;
            $policy = new SourcePolicy();
            $gateway = new SharedGateway(new SafeHttpClient($policy, null, $budget), $policy);
            $items = $gateway->items($source);
            $stage = 'catalog_normalize';
            $catalog = (new CatalogPlanner())->flatten($items);
            $stage = 'rule_classify';
            $result = (new PreviewPlanner())->preview($sourceId, $catalog, $this->config->get());
            $budget->checkpoint();
            return $result;
        } catch (\Throwable $exception) {
            $primaryFailure = PreviewFailure::fromThrowable($exception, $stage);
            throw $primaryFailure;
        } finally {
            $cleanupFailure = null;
            if ($budgetActive && $budget !== null) {
                try {
                    $budget->endSource();
                } catch (\Throwable $releaseFailure) {
                    $cleanupFailure = PreviewFailure::fromThrowable(
                        $releaseFailure,
                        'release',
                        $primaryFailure?->correlationId(),
                    );
                }
            }
            if ($lockAcquired && $lock !== null) {
                try {
                    $lock->release();
                } catch (\Throwable $releaseFailure) {
                    $failure = PreviewFailure::fromThrowable(
                        $releaseFailure,
                        'release',
                        $primaryFailure?->correlationId() ?? $cleanupFailure?->correlationId(),
                    );
                    $cleanupFailure ??= $failure;
                }
            }
            if ($primaryFailure === null && $cleanupFailure !== null) {
                throw $cleanupFailure;
            }
        }
    }

    private function loadSupplyDependency(): void
    {
        if (class_exists(CatalogPlanner::class) && class_exists(SharedGateway::class)) {
            return;
        }
        $bootstrap = dirname(__DIR__, 2) . '/PikaSupplySync/bootstrap.php';
        if (!is_file($bootstrap) || is_link($bootstrap)) {
            throw new RuntimeException('PikaSupplySync 依赖未安装。');
        }
        require_once $bootstrap;
        if (!class_exists(CatalogPlanner::class) || !class_exists(SharedGateway::class)) {
            throw new RuntimeException('PikaSupplySync 依赖无法加载。');
        }
    }

    private function sourceName(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException('共享店铺名称格式不正确。');
        }
        if (preg_match('/\p{Cc}/u', $value) === 1) {
            throw new RuntimeException('共享店铺名称格式不正确。');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > 128) {
            throw new RuntimeException('共享店铺名称格式不正确。');
        }
        return $value;
    }

    private function sourceField(mixed $value, int $maxLength, string $label): string
    {
        if (!is_string($value)
            || $value === ''
            || strlen($value) > $maxLength
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw new RuntimeException("{$label}格式不正确。");
        }
        return $value;
    }

    private function sourceAppId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9._:@-]{1,32}$/D', $value) !== 1) {
            throw new RuntimeException('共享店铺商户 ID 格式不正确。');
        }
        return $value;
    }

    private function sourceCurrency(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('共享店铺货币格式不正确。');
        }
        $currency = strtoupper(trim($value));
        if (preg_match('/^[A-Z0-9]{1,8}$/D', $currency) !== 1) {
            throw new RuntimeException('共享店铺货币格式不正确。');
        }
        return $currency;
    }

    private function sourceCurrencyRate(mixed $value): string
    {
        if (!is_numeric($value)) {
            throw new RuntimeException('共享店铺结算汇率格式不正确。');
        }
        $rate = (float)$value;
        if (!is_finite($rate) || $rate < 0 || $rate > 999999999) {
            throw new RuntimeException('共享店铺结算汇率格式不正确。');
        }
        return sprintf('%.6f', $rate);
    }
}
