<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager {
    final class PathGuard {
        public static string $root;
        public static function stateRoot(): string { return self::$root; }
        public static function runtimeOwner(): int { return posix_geteuid(); }
    }
}

namespace {
    use Pika\LocalExtensions\Manager\PathGuard;
    use Pika\LocalExtensions\Manager\SupplySyncStatus;
    require dirname(__DIR__) . '/manager/site/local-extensions/src/SupplySyncStatus.php';
    $root = sys_get_temp_dir() . '/pika-status-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    PathGuard::$root = $root;
    function expect(bool $value, string $message): void { if (!$value) throw new \RuntimeException($message); }
    function put(string $path, string $bytes): void { file_put_contents($path, $bytes); chmod($path, 0600); }
    $empty = SupplySyncStatus::snapshot();
    expect($empty['availability'] === 'available' && $empty['sources'] === [], 'missing history must be empty');
    expect(scandir($root) === ['.', '..'], 'reading missing history created directories');
    $directory = $root . '/extensions/PikaSupplySync';
    mkdir($directory, 0700, true);
    $counts = ['sync' => 2, 'import' => 0, 'zero' => 0, 'held_race' => 0, 'already_managed' => 0, 'held_existing_unmanaged' => 0];
    $entry = ['source_id' => 1, 'status' => 'ok', 'mode' => 'basic', 'dry_run' => false,
        'planned' => ['sync' => 2, 'import' => 0, 'zero' => 0, 'hold_zero' => 0],
        'applied' => $counts, 'failed' => 0, 'message' => 'secret-sentinel',
        'errors' => [['message' => 'secret-sentinel']], 'url' => 'secret-sentinel'];
    $log = static fn(array $row, string $time): string => $time . ' ' . json_encode($row, JSON_THROW_ON_ERROR) . "\n";
    put($directory . '/sync.log', $log($entry, '2026-09-10T00:00:00+00:00')
        . $log(array_replace($entry, ['dry_run' => true, 'applied' => array_fill_keys(array_keys($counts), 0)]), '2026-09-11T00:00:00+00:00')
        . $log(['source_id' => 2, 'status' => 'error'], '2026-09-11T01:00:00+00:00'));
    put($directory . '/source-1.json', json_encode(['last_run' => '2026-09-11 10:00:00',
        'last_result' => array_replace($entry, ['applied' => array_replace($counts, ['sync' => 3])])], JSON_THROW_ON_ERROR));
    $state = SupplySyncStatus::snapshot();
    expect($state['sources'][0]['actual']['applied']['sync'] === 2, 'actual history lost');
    expect($state['sources'][0]['preview']['kind'] === 'preview', 'preview became actual');
    expect($state['sources'][0]['saved_batch']['applied']['sync'] === 3, 'newer persisted state hidden by old log');
    expect($state['sources'][0]['saved_batch']['timezone'] === 'unrecorded', 'legacy timezone invented');
    expect($state['sources'][1]['unknown']['kind'] === 'unknown', 'legacy error misclassified');
    expect($state['sources'][1]['unknown']['catalog_diagnostic'] === null, 'legacy history invented request evidence');
    expect(!str_contains(json_encode($state), 'secret-sentinel'), 'secret escaped whitelist');
    expect($state['scheduler'] === 'unverified' && $state['running'] === 'unverified', 'live status invented');
    put($directory . '/sync.log', $log(['source_id' => 1, 'status' => 'locked', 'dry_run' => false], '2026-09-11T11:00:00+00:00') . 'truncated');
    $locked = SupplySyncStatus::snapshot();
    expect($locked['incomplete'] && $locked['sources'][0]['actual']['status'] === 'locked', 'lock/truncation missing');
    expect($locked['sources'][0]['saved_batch']['applied']['sync'] === 3, 'locked log hid saved batch');
    put($directory . '/sync.log', "bad json\n" . $log(array_replace($entry, ['planned' => array_fill_keys(['sync','import','zero','hold_zero'], 0)]), '2026-09-11T12:00:00+00:00'));
    expect(SupplySyncStatus::snapshot()['sources'][0]['actual']['planned'] === 0, 'zero actions not preserved');
    put($directory . '/sync.log', $log(array_replace($entry, ['status' => 'partial', 'failed' => 1, 'selection_held' => 1]), '2026-09-11T12:01:00+00:00'));
    expect(SupplySyncStatus::snapshot()['sources'][0]['actual']['selection_held'] === 1, 'selection hold count missing');
    foreach ([-1, 10001, '1', []] as $invalidHold) {
        put($directory . '/sync.log', $log(array_replace($entry, ['selection_held' => $invalidHold]), '2026-09-11T12:02:00+00:00'));
        $invalid = SupplySyncStatus::snapshot();
        expect($invalid['incomplete'] && $invalid['sources'][0]['actual'] === null, 'unsafe selection hold count accepted');
    }
    $diagnostic = ['category' => 'response_size', 'http_status' => 200, 'curl_code' => 23, 'elapsed_ms' => 2635, 'attempts' => 1];
    put($directory . '/sync.log', $log(array_replace($entry, ['status' => 'error',
        'catalog_diagnostic' => $diagnostic + ['url' => 'secret-sentinel', 'app_key' => 'secret-sentinel',
            'body' => 'secret-sentinel', 'headers' => ['secret-sentinel']]]), '2026-09-22T05:00:00+00:00'));
    $diagnosed = SupplySyncStatus::snapshot();
    expect($diagnosed['sources'][0]['actual']['catalog_diagnostic'] === $diagnostic
        && !str_contains(json_encode($diagnosed), 'secret-sentinel'), 'directory diagnosis escaped its fixed projection');
    put($directory . '/sync.log', $log(array_replace($entry, ['status' => 'error', 'catalog_diagnostic' => [
        'category' => 'secret-sentinel', 'http_status' => 600, 'curl_code' => '23', 'elapsed_ms' => 480001, 'attempts' => -1,
    ]]), '2026-09-22T05:00:00+00:00'));
    expect(SupplySyncStatus::snapshot()['sources'][0]['actual']['catalog_diagnostic'] === [
        'category' => 'unknown', 'http_status' => 0, 'curl_code' => 0, 'elapsed_ms' => 0, 'attempts' => 0,
    ], 'invalid diagnosis fabricated boundary measurements');
    foreach (['secret-sentinel', false, 1] as $invalid) {
        put($directory . '/sync.log', $log(array_replace($entry, ['status' => 'error', 'catalog_diagnostic' => $invalid]),
            '2026-09-22T05:00:00+00:00'));
        expect(SupplySyncStatus::snapshot()['sources'][0]['actual']['catalog_diagnostic'] === null,
            'non-object diagnosis accepted');
    }
    put($directory . '/sync.log', $log($entry + ['catalog_diagnostic' => $diagnostic], '2026-09-22T05:00:00+00:00'));
    expect(SupplySyncStatus::snapshot()['sources'][0]['actual']['catalog_diagnostic'] === null,
        'successful history displayed a stale directory failure');
    chmod($directory . '/sync.log', 0644);
    expect(SupplySyncStatus::snapshot()['availability'] === 'unavailable', 'unsafe mode accepted');
    chmod($directory . '/sync.log', 0600);
    rename($directory . '/sync.log', $directory . '/saved.log');
    symlink($directory . '/saved.log', $directory . '/sync.log');
    expect(SupplySyncStatus::snapshot()['availability'] === 'unavailable', 'symlink accepted');
    unlink($directory . '/sync.log');
    link($directory . '/saved.log', $directory . '/sync.log');
    expect(SupplySyncStatus::snapshot()['availability'] === 'unavailable', 'hardlink accepted');
    unlink($directory . '/sync.log');
    put($directory . '/sync.log', str_repeat('x', 2162689));
    expect(SupplySyncStatus::snapshot()['availability'] === 'unavailable', 'oversized log accepted');
    echo "PASS: missing/read-only, actual/preview/legacy, newer state, locked, zero, selection-held, redaction, unsafe files, bounds\n";
}
