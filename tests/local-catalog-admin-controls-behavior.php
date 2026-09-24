<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service {
    final class AdminControlsFixture
    {
        public const TASK_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        public const PENDING = '货源联动改名尚未完成，请先在对应货源重新保存名称以恢复。';
        public static array $events = [];
        public static array $faults = [];
        public static array $jobs = [];
        public static ?array $category = null;
        public static array $categoryReadOverrides = [];
        public static int $categoryReads = 0;
        public static int $renameWrites = 0;
        public static bool $lockBlocked = false;
        public static bool $lockHeld = false;
        public static bool $pending = false;

        public static function reset(): void
        {
            self::$events = self::$faults = self::$categoryReadOverrides = [];
            self::$categoryReads = self::$renameWrites = 0;
            self::$lockBlocked = self::$lockHeld = self::$pending = false;
            self::$jobs = [self::TASK_ID => [
                'task_id' => self::TASK_ID, 'source_id' => 7, 'revision' => 5, 'state' => 'paused',
            ]];
            self::$category = [
                'source_id' => 7, 'category_id' => 41, 'alias' => 'Before',
                'category_name' => 'Before', 'source_node_count' => 3,
            ];
        }

        public static function event(string $name, array $arguments = []): void
        {
            self::$events[] = ['name' => $name, 'arguments' => $arguments, 'locked' => self::$lockHeld];
            if (isset(self::$faults[$name])) {
                throw self::$faults[$name];
            }
        }

        public static function calls(string $name): array
        {
            return array_values(array_filter(self::$events, static fn(array $event): bool => $event['name'] === $name));
        }
    }

    final class ConfigRepository
    {
        public function categoryMode(int $sourceId): string { return 'smart'; }
        public function setCategoryMode(int $sourceId, string $mode): array
        {
            AdminControlsFixture::event('mode_save', [$sourceId, $mode]);
            if (!AdminControlsFixture::$lockHeld) throw new \LogicException('mode save ran outside source lock');
            return [];
        }
    }

    final class SourceAliasService
    {
        public function __construct(ConfigRepository $config) {}

        public function assertCategoryMode(int $sourceId, string $mode): void
        {
            AdminControlsFixture::event('mode_check', [$sourceId, $mode]);
            if (!AdminControlsFixture::$lockHeld) throw new \LogicException('mode check ran outside source lock');
        }

        public function assertNoPendingRename(): void
        {
            AdminControlsFixture::event('pending_check');
            if (!AdminControlsFixture::$lockHeld) {
                throw new \LogicException('pending rename check ran outside source lock');
            }
            if (AdminControlsFixture::$pending) {
                throw new \RuntimeException(AdminControlsFixture::PENDING);
            }
        }

        public function managedCategory(int $categoryId): ?array
        {
            AdminControlsFixture::$categoryReads++;
            AdminControlsFixture::event('category_read', [$categoryId]);
            AdminControlsFixture::event('category_read_' . AdminControlsFixture::$categoryReads);
            return array_key_exists(AdminControlsFixture::$categoryReads, AdminControlsFixture::$categoryReadOverrides)
                ? AdminControlsFixture::$categoryReadOverrides[AdminControlsFixture::$categoryReads]
                : AdminControlsFixture::$category;
        }

        public function rename(int $sourceId, string $alias): array
        {
            AdminControlsFixture::event('rename', [$sourceId, $alias]);
            if (!AdminControlsFixture::$lockHeld) {
                throw new \LogicException('rename ran outside source lock');
            }
            AdminControlsFixture::$renameWrites++;
            AdminControlsFixture::$category['alias'] = trim($alias);
            AdminControlsFixture::$category['category_name'] = trim($alias);
            return ['aliases' => [['source_id' => $sourceId, 'alias' => trim($alias)]]];
        }

        public function classificationAlias(int $sourceId, string $alias): string
        {
            AdminControlsFixture::event('classification_alias', [$sourceId, $alias]);
            return 'Frozen';
        }
    }

    final class JobService
    {
        public function get(string $taskId): array
        {
            AdminControlsFixture::event('job_get', [$taskId]);
            return AdminControlsFixture::$jobs[$taskId];
        }

        public function list(): array
        {
            AdminControlsFixture::event('job_list');
            return array_values(AdminControlsFixture::$jobs);
        }

        public function control(string $taskId, int $revision, string $action): array
        {
            AdminControlsFixture::event('job_control', [$taskId, $revision, $action]);
            if (in_array($action, ['resume', 'retry_failed'], true) && !AdminControlsFixture::$lockHeld) {
                throw new \LogicException('resume or retry reached the store without source lock');
            }
            $job = AdminControlsFixture::$jobs[$taskId];
            if ($job['revision'] !== $revision) {
                throw new \RuntimeException('fixture revision mismatch');
            }
            $job['revision']++;
            $job['state'] = match ($action) {
                'resume', 'retry_failed' => 'queued_import',
                'pause' => 'pause_requested', 'cancel' => 'cancel_requested',
                default => throw new \RuntimeException('fixture control action invalid'),
            };
            AdminControlsFixture::$jobs[$taskId] = $job;
            return $job;
        }

        public function createAnalysis(int $sourceId, string $alias, string $fingerprint, string $mode = 'smart'): array
        {
            AdminControlsFixture::event('job_create', [$sourceId, $alias, $fingerprint, $mode]);
            if (!AdminControlsFixture::$lockHeld) {
                throw new \LogicException('analysis creation ran outside source lock');
            }
            return AdminControlsFixture::$jobs[AdminControlsFixture::TASK_ID] = [
                'task_id' => AdminControlsFixture::TASK_ID, 'source_id' => $sourceId,
                'source_alias' => $alias, 'revision' => 1, 'state' => 'queued_analysis',
            ];
        }
    }
}

namespace Pika\LocalExtensions\PikaSupplySync\Service {
    use Pika\LocalExtensions\PikaCatalogHub\Service\AdminControlsFixture as Fixture;

    final class CatalogPlanner {}
    final class SharedGateway {}

    final class SourceLock
    {
        public function __construct() { Fixture::event('lock_construct'); }

        public function acquire(int $sourceId): bool
        {
            Fixture::event('lock_acquire', [$sourceId]);
            if (Fixture::$lockBlocked) {
                return false;
            }
            if (Fixture::$lockHeld) {
                throw new \LogicException('fixture source lock acquired twice');
            }
            Fixture::$lockHeld = true;
            return true;
        }

        public function release(): void
        {
            Fixture::event('lock_release');
            Fixture::$lockHeld = false;
        }
    }

    final class SourceIdentity
    {
        public static function fingerprint(object $source): string
        {
            Fixture::event('fingerprint', [$source->id]);
            return str_repeat('f', 64);
        }
    }
}

namespace App\Model {
    use Pika\LocalExtensions\PikaCatalogHub\Service\AdminControlsFixture as Fixture;

    final class Shared
    {
        public static function query(): self
        {
            Fixture::event('source_query');
            return new self();
        }

        public function find(int $sourceId): object
        {
            Fixture::event('source_find', [$sourceId]);
            return (object)['id' => $sourceId];
        }
    }
}

namespace {
    use Pika\LocalExtensions\PikaCatalogHub\Service\AdminControlsFixture as Fixture;
    use Pika\LocalExtensions\PikaCatalogHub\Service\AdminService;

    require dirname(__DIR__) . '/extensions/PikaCatalogHub/Service/ConfigSchema.php';
    require dirname(__DIR__) . '/extensions/PikaCatalogHub/Service/AdminService.php';
    $assertions = 0;

    function expect(bool $condition, string $message): void
    {
        global $assertions;
        $assertions++;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function rejects(callable $action, string $message): void
    {
        try {
            $action();
        } catch (Throwable $exception) {
            expect($exception->getMessage() === $message, 'unexpected orchestration failure');
            return;
        }
        throw new RuntimeException('expected orchestration rejection');
    }

    function releasedOnce(): void
    {
        expect(count(Fixture::calls('lock_release')) === 1, 'source lock was not released exactly once');
        expect(Fixture::$lockHeld === false, 'source lock remained held');
    }

    foreach (['resume', 'retry_failed'] as $lockedAction) {
        Fixture::reset();
        Fixture::$lockBlocked = true;
        $before = Fixture::$jobs;
        rejects(static fn() => (new AdminService())->control(Fixture::TASK_ID, 5, $lockedAction),
            '该货源正在同步或修改，请刷新任务状态后再继续。');
        expect(Fixture::$jobs === $before, 'blocked resume or retry changed job revision or state');
        expect(Fixture::calls('job_control') === [], 'blocked resume or retry reached mutation');
        expect(Fixture::calls('lock_release') === [], 'failed acquisition was released as if held');
        expect(Fixture::calls('lock_acquire')[0]['arguments'] === [7], 'resume or retry locked the wrong source');
    }

    foreach (['pause', 'cancel'] as $action) {
        Fixture::reset();
        Fixture::$lockBlocked = Fixture::$pending = true;
        $result = (new AdminService())->control(Fixture::TASK_ID, 5, $action);
        expect($result['revision'] === 6, 'pause or cancel did not reach task control');
        expect(Fixture::calls('lock_construct') === [] && Fixture::calls('lock_acquire') === [], 'pause or cancel requested source lock');
        expect(Fixture::calls('job_get') === [] && Fixture::calls('pending_check') === [], 'pause or cancel depended on resume-only checks');
        expect(Fixture::calls('job_control')[0]['arguments'] === [Fixture::TASK_ID, 5, $action], 'pause or cancel changed control parameters');
        expect(Fixture::calls('lock_release') === [], 'pause or cancel released an unowned lock');
    }

    foreach (['resume', 'retry_failed'] as $lockedAction) {
        Fixture::reset();
        Fixture::$pending = true;
        $before = Fixture::$jobs;
        rejects(static fn() => (new AdminService())->control(Fixture::TASK_ID, 5, $lockedAction), Fixture::PENDING);
        expect(Fixture::calls('pending_check')[0]['locked'] === true, 'resume or retry pending check ran outside lock');
        expect(Fixture::$jobs === $before && Fixture::calls('job_control') === [], 'pending resume or retry mutated task');
        releasedOnce();

        Fixture::reset();
        $resumed = (new AdminService())->control(Fixture::TASK_ID, 5, $lockedAction);
        expect($resumed === Fixture::$jobs[Fixture::TASK_ID] && $resumed['revision'] === 6,
            'resume or retry did not return committed store result');
        expect($resumed['state'] === 'queued_import', 'resume or retry changed action semantics');
        expect(Fixture::calls('job_control') === [[
            'name' => 'job_control', 'arguments' => [Fixture::TASK_ID, 5, $lockedAction], 'locked' => true,
        ]], 'resume or retry was replayed or changed its exact parameters');
        releasedOnce();

        Fixture::reset();
        Fixture::$faults['job_control'] = new RuntimeException('fixture control failure');
        $before = Fixture::$jobs;
        rejects(static fn() => (new AdminService())->control(Fixture::TASK_ID, 5, $lockedAction), 'fixture control failure');
        expect(Fixture::$jobs === $before, 'failed store call changed fixture revision');
        expect(count(Fixture::calls('job_control')) === 1, 'failed resume or retry was replayed');
        releasedOnce();
    }

    Fixture::reset();
    $info = (new AdminService())->categoryInfo(41);
    expect($info === Fixture::$category, 'category info lost stored metadata');
    expect(Fixture::calls('category_read')[0]['arguments'] === [41], 'category info read the wrong id');
    expect(Fixture::calls('lock_construct') === [] && Fixture::$renameWrites === 0, 'category info initiated a rename');
    Fixture::$category = null;
    expect((new AdminService())->categoryInfo(41) === null, 'ordinary category was not distinguished');

    Fixture::reset();
    Fixture::$category = null;
    rejects(static fn() => (new AdminService())->renameCategory(41, 'After'),
        '该分类不是插件管理的货源名称，请使用普通分类编辑。');
    expect(Fixture::calls('lock_construct') === [] && Fixture::$renameWrites === 0, 'unmanaged category acquired lock or renamed');

    Fixture::reset();
    Fixture::$lockBlocked = true;
    rejects(static fn() => (new AdminService())->renameCategory(41, 'After'), '该货源正在同步或入库，请稍后重试。');
    expect(Fixture::$categoryReads === 1 && Fixture::calls('rename') === [], 'blocked rename continued after acquisition failure');
    expect(Fixture::calls('lock_acquire')[0]['arguments'] === [7], 'category rename locked wrong source');
    expect(Fixture::calls('lock_release') === [], 'blocked category rename released an unowned lock');

    foreach ([null, ['source_id' => 8, 'category_id' => 41]] as $changedOwner) {
        Fixture::reset();
        Fixture::$categoryReadOverrides[2] = $changedOwner;
        $before = Fixture::$category;
        rejects(static fn() => (new AdminService())->renameCategory(41, 'After'), '分类归属已变化，请刷新分类列表。');
        expect(Fixture::$categoryReads === 2, 'rename skipped or replayed prewrite identity check');
        expect(Fixture::calls('category_read')[1]['locked'] === true, 'second identity check was outside source lock');
        expect(Fixture::calls('rename') === [] && Fixture::$category === $before, 'changed ownership still renamed category');
        releasedOnce();
    }

    Fixture::reset();
    Fixture::$faults['rename'] = new RuntimeException('fixture rename failure');
    $before = Fixture::$category;
    rejects(static fn() => (new AdminService())->renameCategory(41, 'After'), 'fixture rename failure');
    expect(Fixture::$category === $before && Fixture::$renameWrites === 0, 'failed rename changed fixture state');
    expect(count(Fixture::calls('rename')) === 1 && Fixture::$categoryReads === 2, 'failed rename was replayed or post-read');
    releasedOnce();

    Fixture::reset();
    $saved = (new AdminService())->renameCategory(41, '  After  ');
    expect($saved === Fixture::$category && $saved['alias'] === 'After', 'rename returned request echo instead of stored result');
    expect($saved['category_name'] === 'After' && $saved['source_node_count'] === 3, 'saved category metadata was lost');
    expect(Fixture::$categoryReads === 3 && Fixture::$renameWrites === 1, 'successful rename skipped verification or replayed write');
    expect(Fixture::calls('rename') === [[
        'name' => 'rename', 'arguments' => [7, '  After  '], 'locked' => true,
    ]], 'rename changed service inputs or ran outside lock');
    expect(array_column(Fixture::calls('category_read'), 'locked') === [false, true, true], 'rename read/lock ordering changed');
    releasedOnce();

    foreach ([null, ['source_id' => 8, 'category_id' => 41]] as $unverifiedResult) {
        Fixture::reset();
        Fixture::$categoryReadOverrides[3] = $unverifiedResult;
        rejects(static fn() => (new AdminService())->renameCategory(41, 'After'), '分类名称保存结果尚未核实，请刷新分类列表。');
        expect(Fixture::$renameWrites === 1 && count(Fixture::calls('rename')) === 1, 'uncertain commit was replayed');
        expect(Fixture::$category['alias'] === 'After', 'uncertain commit was blindly rolled back');
        releasedOnce();
    }

    foreach ([2, 3] as $readNumber) {
        Fixture::reset();
        Fixture::$faults['category_read_' . $readNumber] = new RuntimeException('fixture lookup failure');
        rejects(static fn() => (new AdminService())->renameCategory(41, 'After'), 'fixture lookup failure');
        expect(Fixture::$renameWrites === ($readNumber === 2 ? 0 : 1), 'lookup failure changed write boundary');
        expect(Fixture::$categoryReads === $readNumber, 'failed lookup was replayed');
        releasedOnce();
    }

    Fixture::reset();
    Fixture::$pending = true;
    $before = Fixture::$jobs;
    rejects(static fn() => (new AdminService())->analyze(7, 'Display'), Fixture::PENDING);
    expect(Fixture::$jobs === $before && Fixture::calls('job_create') === [], 'pending rename allowed analysis creation');
    expect(Fixture::calls('job_list') === [] && Fixture::calls('classification_alias') === [], 'analysis ran after pending rejection');
    expect(Fixture::calls('pending_check')[0]['locked'] === true, 'analysis pending check was outside lock');
    releasedOnce();

    Fixture::reset();
    rejects(static fn() => (new AdminService())->analyze(7, 'Display'), '该货源已有未完成的后台任务。');
    expect(Fixture::calls('job_create') === [], 'analysis ignored active job');
    releasedOnce();

    Fixture::reset();
    Fixture::$jobs = [];
    $created = (new AdminService())->analyze(7, 'Display');
    expect($created === Fixture::$jobs[Fixture::TASK_ID], 'analysis did not return created task');
    expect(Fixture::calls('job_create') === [[
        'name' => 'job_create', 'arguments' => [7, 'Frozen', str_repeat('f', 64), 'smart'], 'locked' => true,
    ]], 'analysis creation lost frozen alias/fingerprint or was replayed');
    releasedOnce();

    Fixture::reset();
    Fixture::$jobs = [];
    (new AdminService())->analyze(7, 'Display', 'mirror');
    expect(Fixture::calls('mode_check')[0]['arguments'] === [7, 'mirror'], 'mirror mode was not checked under source lock');
    expect(Fixture::calls('mode_save')[0]['arguments'] === [7, 'mirror'], 'explicit mirror mode was not saved');
    expect(Fixture::calls('job_create')[0]['arguments'][3] === 'mirror', 'analysis did not freeze explicit mirror mode');
    releasedOnce();
    Fixture::reset();
    Fixture::$jobs = [];
    Fixture::$faults['mode_check'] = new RuntimeException('incompatible existing map');
    rejects(static fn() => (new AdminService())->analyze(7, 'Display', 'mirror'), 'incompatible existing map');
    expect(Fixture::calls('mode_save') === [] && Fixture::calls('job_create') === [], 'incompatible mode was saved or queued');
    releasedOnce();

    echo 'Catalog admin controls behavior checks passed (' . $assertions . " assertions).\n";
}
