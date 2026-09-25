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
    foreach (['actual', 'preview', 'saved_batch'] as $kind) {
        expect($state['sources'][0][$kind]['catalog_unknown'] === 0
            && $state['sources'][0][$kind]['planned_held_unknown'] === 0
            && $state['sources'][0][$kind]['applied']['held_unknown'] === 0
            && $state['sources'][0][$kind]['status'] === 'ok', 'legacy result changed when new counters were absent');
    }
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
    $unknownEntry = array_replace($entry, ['status' => 'partial', 'catalog_unknown' => 4,
        'planned' => $entry['planned'] + ['held_unknown' => 1],
        'applied' => $counts + ['held_unknown' => 2]]);
    put($directory . '/sync.log', $log($unknownEntry, '2026-09-25T00:00:00+00:00'));
    $unknown = SupplySyncStatus::snapshot()['sources'][0]['actual'];
    expect($unknown['status'] === 'partial' && $unknown['planned'] === 3
        && $unknown['catalog_unknown'] === 4 && $unknown['planned_held_unknown'] === 1
        && $unknown['applied']['held_unknown'] === 2 && $unknown['applied']['zero'] === 0
        && $unknown['applied']['held_race'] === 0 && $unknown['failed'] === 0,
        'unknown stock protection was merged with failure, zero or race counts');
    put($directory . '/sync.log', $log(array_replace($unknownEntry, ['dry_run' => true,
        'applied' => array_fill_keys(array_keys($unknownEntry['applied']), 0)]), '2026-09-25T00:00:00+00:00'));
    $unknownPreview = SupplySyncStatus::snapshot()['sources'][0]['preview'];
    expect($unknownPreview['status'] === 'partial' && $unknownPreview['planned'] === 3
        && $unknownPreview['planned_held_unknown'] === 1 && $unknownPreview['applied']['held_unknown'] === 0,
        'unknown-stock preview became a completed protection action');
    put($directory . '/sync.log', $log(array_replace($entry, ['catalog_unknown' => 4]), '2026-09-25T00:00:00+00:00'));
    $catalogOnly = SupplySyncStatus::snapshot()['sources'][0]['actual'];
    expect($catalogOnly['status'] === 'ok' && $catalogOnly['catalog_unknown'] === 4
        && $catalogOnly['planned_held_unknown'] === 0 && $catalogOnly['applied']['held_unknown'] === 0,
        'unselected catalog unknowns changed the recorded outcome');
    foreach (['catalog_unknown', 'planned', 'applied'] as $counter) {
        foreach ([-1, 10001, '1', null, []] as $invalidCount) {
            $invalidEntry = $unknownEntry;
            if ($counter === 'catalog_unknown') $invalidEntry[$counter] = $invalidCount;
            else $invalidEntry[$counter]['held_unknown'] = $invalidCount;
            put($directory . '/sync.log', $log($invalidEntry, '2026-09-25T00:00:00+00:00'));
            $invalid = SupplySyncStatus::snapshot();
            expect($invalid['incomplete'] && $invalid['sources'][0]['actual'] === null,
                'unsafe unknown stock count accepted: ' . $counter);
        }
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
        'category' => 'unknown',
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
    expect(!class_exists('Pika\\LocalExtensions\\PikaSupplySync\\Service\\UpstreamFailure', false),
        'manager diagnostic fixture unexpectedly loaded Supply classes');
    $observation = ['category' => 'business', 'stage' => 'detail', 'remote_reason' => 'not_shared', 'http_status' => 200, 'curl_code' => 0,
        'elapsed_ms' => 63, 'attempts' => 1, 'received_bytes' => 85,
        'timings_ms' => ['dns' => 1, 'connect' => 8, 'tls' => 17, 'first_byte' => 44, 'total' => 62],
        'response_structure' => ['business_code' => -1, 'data_type' => 'null']];
    $diagnosticRow = ['code_hash' => 'abcdef123456', 'message' => '远端返回业务失败', 'diagnostics' => $observation];
    $request = $observation + ['attempt_history' => [$observation, $observation, $observation]];
    $detailedEntry = array_replace($entry, ['status' => 'partial', 'failed' => 2, 'phase' => 'actions',
        'error_total' => 23, 'errors_truncated' => true, 'errors' => array_fill(0, 20, $diagnosticRow),
        'failure_diagnostic' => $observation,
        'request_diagnostics' => ['detail' => ['count' => 9, 'last' => $request, 'last_failure' => $request]]]);
    put($directory . '/sync.log', $log($detailedEntry, '2026-09-25T01:00:00+00:00'));
    $detailed = SupplySyncStatus::snapshot()['sources'][0]['actual'];
    expect($detailed['failed'] === 2 && $detailed['error_total'] === 23 && $detailed['errors_truncated'] === true
        && count($detailed['errors']) === 20 && $detailed['errors'][0] === $diagnosticRow
        && $detailed['failure_diagnostic'] === $observation && $detailed['phase'] === 'actions'
        && $detailed['request_diagnostics']['detail']['count'] === 9
        && count($detailed['request_diagnostics']['detail']['last']['attempt_history']) === 3,
        'bounded diagnostics lost the exact total or confused failed products with events');
    foreach (['transport', 'http_retryable', 'http_rejected', 'credentials', 'business', 'content_type',
        'json', 'schema', 'response_size', 'budget', 'unknown', 'item_unavailable', 'item_invalid'] as $category) {
        $row = ['message' => '货源同步失败，未执行商品写入', 'diagnostics' => ['category' => $category, 'stage' => 'catalog']];
        put($directory . '/sync.log', $log(array_replace($entry, ['status' => 'error', 'error_total' => 1,
            'errors_truncated' => false, 'errors' => [$row]]), '2026-09-25T01:00:00+00:00'));
        $result = SupplySyncStatus::snapshot()['sources'][0]['actual'];
        expect($result['errors'] === [$row] && $result['error_total'] === 1 && $result['errors_truncated'] === false,
            'source-level diagnostic category or missing product hash was lost');
    }
    $unsafeObservation = ['category' => 'secret-sentinel', 'stage' => 'secret-sentinel', 'remote_reason' => 'secret-sentinel',
        'http_status' => '200', 'curl_code' => 1000, 'elapsed_ms' => -1, 'attempts' => 4, 'received_bytes' => 16842753,
        'timings_ms' => ['dns' => 0, 'connect' => -1, 'tls' => '17', 'first_byte' => 480001, 'total' => null, 'secret' => 'secret-sentinel'],
        'response_structure' => ['business_code' => 'secret-sentinel', 'data_type' => 'secret-sentinel',
            'data_count' => 10001, 'first_children_type' => [], 'first_children_count' => -1, 'secret' => 'secret-sentinel'],
        'url' => 'secret-sentinel', 'headers' => ['secret-sentinel'], 'body' => 'secret-sentinel'];
    $unsafeEntry = array_replace($entry, ['status' => 'partial', 'failed' => 4, 'error_total' => 1,
        'errors_truncated' => false, 'errors' => [['code_hash' => 'secret-sentinel', 'message' => 'secret-sentinel',
            'diagnostics' => $unsafeObservation]], 'failure_diagnostic' => $unsafeObservation,
        'request_diagnostics' => ['secret-sentinel' => ['count' => 1], 'detail' => ['count' => '9',
            'last' => $unsafeObservation, 'last_failure' => ['stage' => 'image', 'url' => 'secret-sentinel']]]]);
    put($directory . '/sync.log', $log($unsafeEntry, '2026-09-25T01:00:00+00:00'));
    $safe = SupplySyncStatus::snapshot()['sources'][0]['actual'];
    expect(!str_contains(json_encode($safe), 'secret-sentinel') && $safe['failed'] === 4
        && $safe['errors'] === [['diagnostics' => ['category' => 'unknown', 'remote_reason' => 'unknown']]]
        && $safe['request_diagnostics'] === [], 'diagnostic whitelist leaked secrets or fabricated missing observations');
    foreach ([null, false, 'secret-sentinel', 1] as $invalidDiagnostics) {
        put($directory . '/sync.log', $log(array_replace($entry, ['status' => 'partial', 'failed' => 4,
            'error_total' => '1', 'errors_truncated' => 'true', 'errors' => $invalidDiagnostics,
            'failure_diagnostic' => $invalidDiagnostics, 'request_diagnostics' => $invalidDiagnostics]), '2026-09-25T01:00:00+00:00'));
        $safe = SupplySyncStatus::snapshot()['sources'][0]['actual'];
        expect($safe['failed'] === 4 && $safe['errors'] === [] && $safe['error_total'] === null
            && $safe['errors_truncated'] === null && $safe['failure_diagnostic'] === null
            && $safe['request_diagnostics'] === [], 'malformed diagnostic erased legacy result or invented zero');
    }
    put($directory . '/sync.log', $log(array_replace($detailedEntry, ['error_total' => 21, 'errors_truncated' => false,
        'errors' => array_fill(0, 21, $diagnosticRow), 'request_diagnostics' => ['detail' => ['count' => 9,
            'last' => $observation + ['attempt_history' => array_fill(0, 4, $observation)]]]]), '2026-09-25T01:00:00+00:00'));
    $bounded = SupplySyncStatus::snapshot()['sources'][0]['actual'];
    expect($bounded['error_total'] === 21 && count($bounded['errors']) === 20 && $bounded['errors_truncated'] === true
        && count($bounded['request_diagnostics']['detail']['last']['attempt_history']) === 3,
        'oversized diagnostics escaped the fixed item or attempt limit');
    put($directory . '/sync.log', $log(array_replace($entry, ['status' => 'partial', 'failed' => 4]), '2026-09-25T01:00:00+00:00'));
    $legacyDiagnostics = SupplySyncStatus::snapshot()['sources'][0]['actual'];
    expect($legacyDiagnostics['failed'] === 4 && $legacyDiagnostics['error_total'] === null
        && $legacyDiagnostics['errors'] === [] && $legacyDiagnostics['request_diagnostics'] === [],
        'old count-only history acquired inferred item causes');
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

    // Exercise the real bounded state writer in a separate disposable runtime.
    PathGuard::$root = $root . '/state-roundtrip';
    mkdir(PathGuard::$root, 0700);
    define('BASE_PATH', $root);
    foreach (['LocalPath', 'StateStore', 'UpstreamFailure', 'ExtensionLogger'] as $service) {
        require dirname(__DIR__) . '/extensions/PikaSupplySync/Service/' . $service . '.php';
    }
    $store = new \Pika\LocalExtensions\PikaSupplySync\Service\StateStore();
    $legacyResult = ['status' => 'ok', 'catalog_total' => 5, 'planned' => $entry['planned'],
        'applied' => $counts, 'failed' => 0, 'mass_zero_fuse' => false];
    $stored = ['cursor' => 'code', 'categories' => [], 'catalog_hash' => '',
        'last_run' => '2026-09-25 00:00:00', 'last_result' => $legacyResult];
    $storePath = \Pika\LocalExtensions\PikaSupplySync\Service\LocalPath::directory(
        'runtime/local-extensions/extensions/PikaSupplySync', 0700) . '/source-1.json';
    put($storePath, json_encode($stored, JSON_THROW_ON_ERROR));
    $legacyRead = $store->read(1);
    expect($legacyRead['priority_cursor'] === '' && $legacyRead['last_result']['status'] === 'ok'
        && $legacyRead['last_result']['catalog_unknown'] === 0
        && $legacyRead['last_result']['planned']['held_unknown'] === 0
        && $legacyRead['last_result']['applied']['held_unknown'] === 0,
        'legacy state failed to default missing unknown counters');
    $newState = $legacyRead;
    $newState['last_result'] = array_replace($legacyRead['last_result'], ['status' => 'partial', 'catalog_unknown' => 4,
        'planned' => $unknownEntry['planned'], 'applied' => $unknownEntry['applied']]);
    $store->write(1, $newState);
    expect($store->read(1) === $newState, 'new unknown-stock state did not roundtrip');
    $normalize = new \ReflectionMethod($store, 'normalize');
    foreach (['last_result', 'planned', 'applied'] as $location) {
        $invalidState = $newState;
        if ($location === 'last_result') $invalidState['last_result']['unrecognized'] = 0;
        else $invalidState['last_result'][$location]['unrecognized'] = 0;
        $rejected = false;
        try { $normalize->invoke($store, $invalidState); } catch (\RuntimeException) { $rejected = true; }
        expect($rejected, 'new state keys loosened unknown-field rejection: ' . $location);
    }
    foreach (['catalog_unknown', 'planned', 'applied'] as $counter) {
        foreach ([-1, 10001, '1', null] as $invalidCount) {
            $invalidState = $newState;
            if ($counter === 'catalog_unknown') $invalidState['last_result'][$counter] = $invalidCount;
            else $invalidState['last_result'][$counter]['held_unknown'] = $invalidCount;
            $rejected = false;
            try { $normalize->invoke($store, $invalidState); } catch (\RuntimeException) { $rejected = true; }
            expect($rejected, 'state accepted invalid unknown-stock count: ' . $counter);
        }
    }
    $logger = new \Pika\LocalExtensions\PikaSupplySync\Service\ExtensionLogger();
    $safeResult = new \ReflectionMethod($logger, 'safeResult');
    $safe = $safeResult->invoke($logger, $unknownEntry + ['unrecognized' => 'secret-sentinel']);
    expect($safe['catalog_unknown'] === 4 && $safe['planned']['held_unknown'] === 1
        && $safe['applied']['held_unknown'] === 2 && !str_contains(json_encode($safe), 'secret-sentinel'),
        'logger omitted unknown-stock counters or leaked arbitrary values');
    $invalidLog = $unknownEntry;
    $invalidLog['catalog_unknown'] = 10001;
    $invalidLog['planned']['held_unknown'] = '1';
    $invalidLog['applied']['held_unknown'] = -1;
    $safe = $safeResult->invoke($logger, $invalidLog);
    expect(!array_key_exists('catalog_unknown', $safe) && !array_key_exists('held_unknown', $safe['planned'])
        && !array_key_exists('held_unknown', $safe['applied']),
        'logger accepted invalid unknown-stock counters');
    echo "PASS: missing/read-only, actual/preview/legacy, newer state, locked, zero, selection-held, unknown-stock, state roundtrip/strictness, logger whitelist, redaction, unsafe files, bounds\n";
}
