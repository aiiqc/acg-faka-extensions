<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service {
    final class PreviewDiagnosticFixture
    {
        public static array $faults = [];
        public static array $calls = [];
        public static array $logs = [];
        public static bool $loggerFails = false;
        public static bool $sourceMissing = false;

        public static function reset(): void
        {
            self::$faults = self::$calls = self::$logs = [];
            self::$loggerFails = self::$sourceMissing = false;
        }

        public static function hit(string $stage): void
        {
            self::$calls[$stage] = (self::$calls[$stage] ?? 0) + 1;
            if (isset(self::$faults[$stage])) {
                throw self::$faults[$stage];
            }
        }
    }

    function class_exists(string $class): bool
    {
        PreviewDiagnosticFixture::hit('dependency');
        return \class_exists($class);
    }

    function error_log(string $message): bool
    {
        PreviewDiagnosticFixture::$logs[] = $message;
        if (PreviewDiagnosticFixture::$loggerFails) {
            throw new \RuntimeException('LOGGER_SECRET');
        }
        return true;
    }

    final class ConfigRepository
    {
        public function get(): array { return []; }
    }

    final class SourceAliasService
    {
        public function __construct(ConfigRepository $config) {}
    }

    final class PreviewLock
    {
        public function acquire(): void { PreviewDiagnosticFixture::hit('lock'); }
        public function release(): void { PreviewDiagnosticFixture::hit('release'); }
    }

    final class PreviewPlanner
    {
        public function preview(int $id, array $catalog, array $config): array
        {
            PreviewDiagnosticFixture::hit('rule_classify');
            return ['ready' => true, 'counts' => ['total' => 1]];
        }
    }
}

namespace App\Model {
    use Pika\LocalExtensions\PikaCatalogHub\Service\PreviewDiagnosticFixture as Fixture;

    final class Shared
    {
        public int $id = 1;
        public static function query(): self
        {
            Fixture::hit('source_lookup');
            return new self();
        }
        public function find(int $id): ?self { return Fixture::$sourceMissing ? null : $this; }
    }
}

namespace Pika\LocalExtensions\PikaSupplySync\Service {
    use Pika\LocalExtensions\PikaCatalogHub\Service\PreviewDiagnosticFixture as Fixture;

    final class CatalogPlanner
    {
        public function flatten(array $items): array
        {
            Fixture::hit('catalog_normalize');
            return $items;
        }
    }

    final class RunBudget
    {
        public function beginSource(int $id): void { Fixture::hit('budget_begin'); }
        public function checkpoint(): void { Fixture::hit('checkpoint'); }
        public function endSource(): void { Fixture::hit('budget_end'); }
    }

    final class SourcePolicy {}
    final class SafeHttpClient
    {
        public function __construct(SourcePolicy $policy, mixed $transport, RunBudget $budget) {}
    }
    final class SharedGateway
    {
        public function __construct(SafeHttpClient $client, SourcePolicy $policy) {}
        public function items(object $source): array
        {
            Fixture::hit('catalog_request');
            return [['fixture' => true]];
        }
    }
}

namespace {
    use Pika\LocalExtensions\PikaCatalogHub\Service\AdminService;
    use Pika\LocalExtensions\PikaCatalogHub\Service\PreviewDiagnosticFixture as Fixture;
    use Pika\LocalExtensions\PikaCatalogHub\Service\PreviewFailure;

    $root = dirname(__DIR__);
    require $root . '/extensions/PikaCatalogHub/Service/AdminService.php';
    $failureFile = $root . '/extensions/PikaCatalogHub/Service/PreviewFailure.php';
    if (is_file($failureFile)) {
        require $failureFile;
    }
    $checks = 0;

    function expect(bool $condition, string $message): void
    {
        global $checks;
        $checks++;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function failure(int $sourceId = 1): Throwable
    {
        try {
            (new AdminService())->preview($sourceId);
        } catch (Throwable $exception) {
            return $exception;
        }
        throw new RuntimeException('expected a preview failure');
    }

    function secretFailure(): Throwable
    {
        return new class('UPSTREAM_SECRET https://private.invalid/?key=SECRET 商品名_SECRET') extends RuntimeException {
            public function __toString(): string
            {
                throw new RuntimeException('exception stringification must never happen');
            }
        };
    }

    function assertSafe(PreviewFailure $failure, string $stage, string $code): void
    {
        expect($failure->stage() === $stage, 'wrong diagnostic stage');
        expect($failure->safeCode() === $code, 'wrong fixed diagnostic code');
        expect(preg_match('/^[a-f0-9]{16}$/D', $failure->correlationId()) === 1, 'diagnostic id is not random-format');
        expect($failure->getPrevious() === null, 'raw previous exception was retained');
        expect($failure->nextStep() !== '', 'safe next step is missing');
        $public = $failure->getMessage() . $failure->nextStep();
        expect(str_contains($public, $failure->correlationId()), 'public error lost diagnostic id');
        expect(!preg_match('/SECRET|https?:|private\.invalid|商品名/', $public), 'public error leaked input');
        foreach (Fixture::$logs as $line) {
            expect(!preg_match('/SECRET|https?:|private\.invalid|商品名/', $line), 'diagnostic log leaked input');
            $entry = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            expect(array_keys($entry) === ['code', 'stage', 'correlation'], 'diagnostic log contains non-allowlisted fields');
            expect($entry['correlation'] === $failure->correlationId(), 'one failure acquired multiple diagnostic ids');
        }
    }

    Fixture::reset();
    $result = (new AdminService())->preview(1);
    expect($result === ['ready' => true, 'counts' => ['total' => 1]], 'successful preview changed');
    expect((Fixture::$calls['catalog_request'] ?? 0) === 1, 'successful preview requested catalog more than once');
    expect((Fixture::$calls['budget_end'] ?? 0) === 1, 'successful preview did not end budget once');
    expect((Fixture::$calls['release'] ?? 0) === 1, 'successful preview did not release once');
    expect(Fixture::$logs === [], 'successful preview logged an error');

    $codes = [
        'lock' => 'PREVIEW_LOCK_FAILED',
        'dependency' => 'PREVIEW_DEPENDENCY_FAILED',
        'source_lookup' => 'PREVIEW_SOURCE_LOOKUP_FAILED',
        'catalog_request' => 'PREVIEW_CATALOG_REQUEST_FAILED',
        'catalog_normalize' => 'PREVIEW_CATALOG_NORMALIZE_FAILED',
        'rule_classify' => 'PREVIEW_RULE_CLASSIFY_FAILED',
        'release' => 'PREVIEW_RELEASE_FAILED',
    ];
    $ids = [];
    foreach ($codes as $stage => $code) {
        Fixture::reset();
        Fixture::$faults[$stage] = secretFailure();
        $caught = failure();
        expect($caught instanceof PreviewFailure, 'preview failure is not a safe typed diagnostic: ' . $stage);
        assertSafe($caught, $stage, $code);
        expect(count(Fixture::$logs) === 1, 'one failure did not produce exactly one bounded log');
        expect((Fixture::$calls['catalog_request'] ?? 0) <= 1, 'failed preview retried catalog');
        expect((Fixture::$calls['release'] ?? 0) === ($stage === 'lock' ? 0 : 1), 'failed preview changed lock cleanup');
        $ids[] = $caught->correlationId();
    }
    expect(count(array_unique($ids)) === count($ids), 'independent failures reused diagnostic ids');

    $safeCases = [
        ['lock', '已有分类预览正在运行，请稍后重试。', 'PREVIEW_BUSY'],
        ['lock', '分类预览处于 30 秒冷却期，请稍后重试。', 'PREVIEW_COOLDOWN'],
        ['source_lookup', '共享店铺不存在。', 'PREVIEW_SOURCE_MISSING'],
        ['rule_classify', '请先为该货源设置唯一别名，再生成分类预览。', 'PREVIEW_ALIAS_MISSING'],
        ['rule_classify', '远端商品目录为空，无法生成可信分类预览。', 'PREVIEW_CATALOG_EMPTY'],
    ];
    foreach ($safeCases as [$stage, $message, $code]) {
        Fixture::reset();
        Fixture::$faults[$stage] = new RuntimeException($message);
        $caught = failure();
        expect($caught instanceof PreviewFailure, 'safe legacy error lost diagnostic type');
        assertSafe($caught, $stage, $code);
        expect(str_contains($caught->getMessage(), $message), 'safe legacy text was not preserved');
        expect(count(Fixture::$logs) === (in_array($code, ['PREVIEW_BUSY', 'PREVIEW_COOLDOWN'], true) ? 0 : 1), 'expected contention flooded diagnostic log');
    }

    Fixture::reset();
    Fixture::$faults['catalog_request'] = new RuntimeException($safeCases[0][1]);
    $caught = failure();
    assertSafe($caught, 'catalog_request', 'PREVIEW_CATALOG_REQUEST_FAILED');
    expect(!str_contains($caught->getMessage(), $safeCases[0][1]), 'upstream text impersonated a lock error');

    Fixture::reset();
    Fixture::$faults['catalog_normalize'] = new TypeError('TYPE_SECRET');
    assertSafe(failure(), 'catalog_normalize', 'PREVIEW_CATALOG_NORMALIZE_FAILED');

    Fixture::reset();
    Fixture::$sourceMissing = true;
    assertSafe(failure(), 'source_lookup', 'PREVIEW_SOURCE_MISSING');
    expect(!isset(Fixture::$calls['catalog_request']), 'missing source still requested catalog');

    Fixture::reset();
    assertSafe(failure(0), 'source_lookup', 'PREVIEW_SOURCE_LOOKUP_FAILED');
    expect(!isset(Fixture::$calls['lock']), 'invalid source acquired lock');

    foreach (['release', 'budget_end'] as $cleanupStage) {
        Fixture::reset();
        Fixture::$faults['catalog_request'] = secretFailure();
        Fixture::$faults[$cleanupStage] = new RuntimeException('CLEANUP_SECRET');
        $caught = failure();
        assertSafe($caught, 'catalog_request', 'PREVIEW_CATALOG_REQUEST_FAILED');
        expect(count(Fixture::$logs) === 2, 'secondary cleanup error was not safely diagnosed');
        $secondary = json_decode(Fixture::$logs[1], true, 16, JSON_THROW_ON_ERROR);
        expect($secondary['stage'] === 'release' && $secondary['code'] === 'PREVIEW_RELEASE_FAILED', 'secondary cleanup mislabeled');
        expect((Fixture::$calls['release'] ?? 0) === 1, 'budget cleanup failure prevented lock release');
    }

    Fixture::reset();
    Fixture::$faults['budget_end'] = secretFailure();
    assertSafe(failure(), 'release', 'PREVIEW_RELEASE_FAILED');
    expect((Fixture::$calls['release'] ?? 0) === 1, 'standalone budget cleanup failure prevented lock release');

    Fixture::reset();
    Fixture::$faults['checkpoint'] = secretFailure();
    assertSafe(failure(), 'rule_classify', 'PREVIEW_RULE_CLASSIFY_FAILED');

    Fixture::reset();
    Fixture::$faults['catalog_request'] = secretFailure();
    Fixture::$loggerFails = true;
    assertSafe(failure(), 'catalog_request', 'PREVIEW_CATALOG_REQUEST_FAILED');
    expect((Fixture::$calls['release'] ?? 0) === 1, 'logger failure prevented lock release');

    echo 'Catalog preview diagnostics behavior checks passed (' . $checks . " assertions).\n";
}
