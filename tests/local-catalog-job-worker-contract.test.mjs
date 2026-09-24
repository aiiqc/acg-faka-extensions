import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const worker = fs.readFileSync(path.join(root, 'extensions/PikaCatalogHub/Service/JobWorker.php'), 'utf8');
const jobs = fs.readFileSync(path.join(root, 'extensions/PikaCatalogHub/Service/JobService.php'), 'utf8');
const store = fs.readFileSync(path.join(root, 'extensions/PikaCatalogHub/Service/JobStore.php'), 'utf8');
const cli = fs.readFileSync(path.join(root, 'extensions/PikaCatalogHub/bin/worker.php'), 'utf8');

test('uses a bounded durable worker and the shared per-source lock', () => {
    assert.match(worker, /MAX_BATCH\s*=\s*20/);
    assert.match(worker, /lock_source[\s\S]+beginWork/);
    assert.match(worker, /new SourceLock\(\)/);
    assert.match(worker, /SourceIdentity::fingerprint/);
    assert.match(worker, /loadImportSnapshot/);
    assert.match(worker, /->checkpoint\(/);
    assert.match(worker, /->yieldImport\(/);
    assert.match(worker, /STATE_PAUSE_REQUESTED/);
    assert.match(worker, /STATE_CANCEL_REQUESTED/);
    assert.match(worker, /OUTCOME_ALREADY_MANAGED/);
    assert.match(worker, /OUTCOME_REATTACHED/);
    assert.match(worker, /OUTCOME_HELD_EXISTING_UNMANAGED/);
    assert.match(worker, /assert_plan_capacity/);
    assert.ok(
        worker.indexOf("$this->runtime['assert_plan_capacity']")
            < worker.indexOf("$this->runtime['import_planned_item']"),
        'complete-plan capacity must be checked before the first item import',
    );
    assert.match(worker, /import_planned_item/);
    assert.match(worker, /->importPlanned\(/);
    assert.match(worker, /catch \(CommodityImportFailure \$failure\)/);
    assert.match(worker, /new JobWorkerFailure\(\$failure->safeCode, \$failure\)/);
    assert.match(worker, /catch \(\\Throwable \$exception\)[\s\S]+new JobWorkerFailure\('ITEM_IMPORT_FAILED', \$exception\)/);
    const plannedImportBlock = worker.slice(
        worker.indexOf("$this->runtime['import_planned_item']"),
        worker.indexOf("if ($isolatedFailure === null && (!is_string($outcome)"),
    );
    assert.ok(
        plannedImportBlock.indexOf('catch (JobWorkerFailure $failure)')
            < plannedImportBlock.indexOf('catch (CommodityImportFailure $failure)')
            && plannedImportBlock.indexOf('catch (CommodityImportFailure $failure)')
                < plannedImportBlock.indexOf('catch (\\Throwable $exception)'),
        'worker-native failures must pass through before import failures are classified',
    );
    assert.doesNotMatch(worker, /\$failure->getMessage|\$exception->getMessage|getTrace/);
    assert.doesNotMatch(worker, /['"]resolve_category['"]|['"]import_item['"]/);
});

test('manual resume is limited to a bound import detail-fetch checkpoint', () => {
    assert.match(jobs, /return JobStore::canResumeFailedImport\(\$job\)/);
    assert.match(store, /STATE_FAILED[\s\S]+phase[^\n]+import[\s\S]+isResumableDetailCode/);
    assert.match(jobs, /readMetadata\([\s\S]+\$job\['snapshot'\]\['plan_hash'\][\s\S]+\$job\['snapshot'\]\['sha256'\]/);
    assert.match(jobs, /'state'\s*=>\s*JobStore::STATE_QUEUED_IMPORT/);
    assert.match(jobs, /'error_code'\s*=>\s*null/);
    assert.match(store, /STATE_FAILED[\s\S]+STATE_QUEUED_IMPORT/);
    assert.doesNotMatch(jobs, /ITEM_DETAIL_NORMALIZATION_FAILED[\s\S]+STATE_QUEUED_IMPORT/);
});

test('item isolation uses schema 4, bounded atomic records and explicit issue termination', () => {
    assert.match(store, /SCHEMA\s*=\s*4/);
    assert.match(store, /MAX_ITEM_FAILURES\s*=\s*100/);
    assert.match(store, /MAX_CONSECUTIVE_ITEM_FAILURES\s*=\s*5/);
    assert.match(store, /count\(\$failures\) !== \$progress\['failed'\]/);
    assert.match(store, /array_slice\(\$newFailures, 0, count\(\$oldFailures\)\) !== \$oldFailures/);
    assert.match(store, /\$job\['progress'\]\['failed'\] === 0 \? \[\] : null/);
    assert.match(worker, /isIsolatableItemCode\(\$failure->safeCode\)/);
    assert.match(worker, /'index' => \$progress\['processed'\]/);
    assert.match(worker, /'code' => \$failure->safeCode/);
    assert.match(worker, /'attempts' => \$failure->safeDiagnostics\['attempts'\] \?\? 0/);
    assert.match(worker, /checkpointAfterItem\(\$task\['task_id'\], \$current, \$progress, \$itemFailures\)/);
    assert.match(jobs, /\$changes = \['progress' => \$progress, 'item_failures' => \$failures\]/);
    assert.match(jobs, /IMPORT_ITEM_FAILURE_LIMIT/);
    assert.match(jobs, /IMPORT_FINISHED_WITH_ISSUES/);
    assert.match(jobs, /\$progress\['processed'\] !== \$progress\['total'\] \|\| \$progress\['failed'\] !== 0/);
    assert.match(jobs, /'can_cancel' => \$this->canCancelFailedImport\(\$job\)/);
    const checkpoint = jobs.slice(jobs.indexOf('public function checkpoint('), jobs.indexOf('public function yieldImport('));
    assert.ok(checkpoint.indexOf('STATE_PAUSE_REQUESTED) {') < checkpoint.indexOf('itemFailureLimitReached('));
    assert.ok(checkpoint.indexOf('STATE_CANCEL_REQUESTED) {') < checkpoint.indexOf('itemFailureLimitReached('));
});

test('repair reuses the immutable plan and settles only its durable unresolved cursor', () => {
    assert.match(worker, /JobStore::hasActiveRetry\(\$task\)/);
    assert.match(worker, /array_slice\(\$task\['retry'\]\['indices'\], \$task\['retry'\]\['cursor'\]\)/);
    const repair = worker.slice(worker.indexOf('private function runRetryImport('), worker.indexOf('private function observeDetail('));
    assert.match(repair, /\$current\['retry'\]\['indices'\]\[\$current\['retry'\]\['cursor'\]\]/);
    assert.match(repair, /array_column\(\$current\['item_failures'\], 'index'\)/);
    assert.match(repair, /->checkpointRetry\(/);
    assert.match(repair, /->finishRetry\(/);
    assert.match(repair, /isIsolatableItemCode\(\$failure->safeCode\)/);
    assert.doesNotMatch(repair, /\['processed'\]\+\+|fetch_catalog|confirmImport|createAnalysis/);
    const reconcile = repair.slice(repair.indexOf('private function checkpointAfterRetryItem('));
    assert.doesNotMatch(reconcile, /import_planned_item/);
    assert.match(worker, /'detail_diagnostics' => static fn\(\): \?array => \$importer->detailDiagnostics\(\)/);
    assert.match(worker, /UpstreamFailure::sanitize\(\$safe\)/);
});

test('actual PHP CLI exit gate distinguishes finished issues from worker failure', () => {
    const start = cli.indexOf("    $finishedWithIssues = $result['status'] === 'failed'");
    const endMarker = "    $writeResult($result, $result['status'] === 'failed' && !$finishedWithIssues ? 1 : 0);";
    const end = cli.indexOf(endMarker, start);
    assert.ok(start >= 0 && end > start, 'actual worker exit gate was not found');
    const gate = cli.slice(start, end + endMarker.length);
    const cases = [
        ['failed', 'IMPORT_FINISHED_WITH_ISSUES', [3, 3, 2, 1, 0], 0],
        ['failed', 'IMPORT_FINISHED_WITH_ISSUES', [5, 5, 2, 1, 2], 0],
        ['failed', 'IMPORT_FINISHED_WITH_ISSUES', [3, 2, 1, 1, 0], 1],
        ['failed', 'IMPORT_FINISHED_WITH_ISSUES', [3, 3, 3, 0, 0], 1],
        ['failed', 'IMPORT_FINISHED_WITH_ISSUES', [3, 3, 3, 1, 0], 1],
        ['failed', 'IMPORT_ITEM_FAILURE_LIMIT', [3, 3, 0, 3, 0], 1],
        ['failed', 'ITEM_IMPORT_FAILED', [3, 1, 1, 0, 0], 1],
        ['completed', null, [3, 3, 3, 0, 0], 0],
    ];
    const input = `<?php
declare(strict_types=1);
$cases = json_decode('${JSON.stringify(cases)}', true, 8, JSON_THROW_ON_ERROR);
foreach ($cases as [$state, $code, $counts, $expected]) {
    $result = ['status'=>$state, 'error_code'=>$code, 'counts'=>array_combine(['items','processed','succeeded','failed','skipped'], $counts)];
    $actual = null;
    $writeResult = static function (array $value, int $exitCode) use (&$actual): void { $actual = $exitCode; };
${gate}
    if ($actual !== $expected) { throw new RuntimeException('worker exit gate mismatch'); }
}
echo "WORKER_EXIT_GATE_PASS\\n";
`;
    const output = execFileSync('docker', ['run', '--rm', '--pull', 'never', '-i', '--read-only',
        '--network', 'none', '--log-driver', 'none', '--user', '65534:65534',
        'acg-faka-php83-integration:20260828', 'php'], {input, encoding: 'utf8'});
    assert.match(output, /WORKER_EXIT_GATE_PASS/);
});

test('persists only a sanitized immutable catalog snapshot', () => {
    const snapshotBlock = worker.match(/\$snapshot\[\]\s*=\s*\[([\s\S]*?)\n\s*\];/)?.[1] ?? '';
    assert.match(snapshotBlock, /'code'/);
    assert.match(snapshotBlock, /'category'/);
    assert.match(snapshotBlock, /'stock'/);
    assert.match(snapshotBlock, /'target'/);
    assert.doesNotMatch(snapshotBlock, /name|domain|url|app[_-]?id|app[_-]?key|secret|token/i);
    assert.match(worker, /storeAnalysisData/);
    assert.doesNotMatch(worker, /error.*getMessage|message.*exception|getTrace/i);
});

test('CLI has one global non-blocking lock and emits only bounded JSON fields', () => {
    assert.match(cli, /worker\.run\.lock/);
    assert.match(cli, /LOCK_EX \| LOCK_NB/);
    assert.match(cli, /@lstat\(\$path\)/);
    assert.match(cli, /@fopen\(\$path, 'r\+b'\)/);
    assert.match(cli, /@fopen\(\$path, 'x\+b'\)/);
    assert.match(cli, /umask\(0177\)/);
    assert.match(cli, /finally[\s\S]+umask\(\$previousUmask\)/);
    assert.match(cli, /\$assertWorkerLockPath\(\$path, \$pathMetadata\)/);
    assert.ok((cli.match(/\$assertWorkerLockHandle\(\$lock, \$lockPath/g) ?? []).length >= 2);
    assert.ok(
        cli.indexOf('LOCK_EX | LOCK_NB') < cli.lastIndexOf('$assertWorkerLockHandle($lock, $lockPath'),
    );
    assert.match(cli, /worker lock changed while acquiring it/);
    assert.match(cli, /->drainSnapshotGc\(\)/);
    assert.match(cli, /->recoverInterrupted\(\)/);
    assert.ok(
        cli.indexOf('worker lock changed while acquiring it') < cli.indexOf('->drainSnapshotGc()')
            && cli.indexOf('->drainSnapshotGc()') < cli.indexOf('->recoverInterrupted()')
            && cli.indexOf('->recoverInterrupted()') < cli.indexOf('->runOne('),
        'snapshot GC and interrupted jobs must recover only after the global lock and before normal work',
    );
    assert.match(cli, /\['nlink'\]\s*!==\s*1/);
    assert.match(cli, /\['dev'\]/);
    assert.match(cli, /\['ino'\]/);
    assert.match(cli, /PathGuard::runtimeOwner\(\)/);
    assert.doesNotMatch(cli, /fchmod\(|chmod\(\$lockPath/);
    assert.match(cli, /ManagerState::isEnabled\(CATALOG_EXTENSION_ID\)/);
    assert.match(cli, /ManagerState::isEnabled\(SUPPLY_EXTENSION_ID\)/);
    assert.match(cli, /'status'\s*=>\s*'disabled'[\s\S]+?\],\s*0\);/);
    assert.ok(
        cli.indexOf("'status' => 'disabled'") < cli.indexOf('worker.run.lock'),
        'a disabled extension must exit cleanly before acquiring the worker lock',
    );
    assert.match(cli, /posix_geteuid\(\)\s*===\s*0/);
    for (const field of ['status', 'task_hash', 'counts', 'error_code']) {
        assert.match(cli, new RegExp(`['"]${field}['"]`));
    }
    assert.doesNotMatch(cli, /getMessage|getTrace|app[_-]?key|merchant[_-]?id|password/i);
});

test('PHP 8.3 creates the first worker lock as one web-owned 0600 file', () => {
    const official = path.resolve(root, '../faka-s0-next-private');
    const script = String.raw`
set -euo pipefail
mkdir -p /tmp/site
cp -a /official/kernel /official/vendor /official/config /tmp/site/
cp -a /repo/manager/site/local-extensions /tmp/site/local-extensions
mkdir -p /tmp/site/local-extensions/extensions
cp -a /repo/extensions/. /tmp/site/local-extensions/extensions/
mkdir -p /tmp/site/app/View/User/Theme/Pika
cp -a /repo/themes/Pika/. /tmp/site/app/View/User/Theme/Pika/
find /tmp/site/local-extensions /tmp/site/app/View/User/Theme/Pika -type d -exec chmod 0755 {} +
find /tmp/site/local-extensions /tmp/site/app/View/User/Theme/Pika -type f -exec chmod 0644 {} +
php /repo/scripts/build-registry.php \
  --site-root /tmp/site \
  --release /repo/release.json \
  --output /tmp/site/local-extensions/registry.json >/dev/null
web_uid="$(id -u www-data)"
web_gid="$(id -g www-data)"
test "$web_uid" -gt 0
site_hash="$(printf %s /tmp/site | sha256sum | awk '{print $1}')"
state_site="/var/lib/pika-local-extensions/sites/$site_hash"
mkdir -p "$state_site/runtime"
chown 0:0 /var/lib/pika-local-extensions /var/lib/pika-local-extensions/sites "$state_site"
chmod 0755 /var/lib/pika-local-extensions /var/lib/pika-local-extensions/sites "$state_site"
chown "$web_uid:$web_gid" "$state_site/runtime"
chmod 0750 "$state_site/runtime"
STATE_PATH="$state_site/runtime/state.json" php -r '$p=getenv("STATE_PATH"); $v=["schema"=>1,"extensions"=>["PikaCatalogHub"=>["enabled"=>true,"updated_at"=>"2026-09-01T00:00:00Z"],"PikaSupplySync"=>["enabled"=>true,"updated_at"=>"2026-09-01T00:00:00Z"]]]; file_put_contents($p,json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");'
chown "$web_uid:$web_gid" "$state_site/runtime/state.json"
chmod 0600 "$state_site/runtime/state.json"
output="$(cd /tmp/site && runuser -u www-data -- php /tmp/site/local-extensions/extensions/PikaCatalogHub/bin/worker.php --root=/tmp/site --batch=1)"
printf '%s\n' "$output" | grep -q '"status":"idle"'
lock="$state_site/runtime/extensions/PikaCatalogHub/worker.run.lock"
test -f "$lock" && test ! -L "$lock"
test "$(stat -c '%u:%g:%a:%h' "$lock")" = "$web_uid:$web_gid:600:1"
first_identity="$(stat -c '%d:%i' "$lock")"
output="$(cd /tmp/site && runuser -u www-data -- php /tmp/site/local-extensions/extensions/PikaCatalogHub/bin/worker.php --root=/tmp/site --batch=1)"
printf '%s\n' "$output" | grep -q '"status":"idle"'
test "$(stat -c '%d:%i' "$lock")" = "$first_identity"

runuser -u www-data -- flock -x "$lock" -c 'sleep 10' >/dev/null 2>&1 &
holder_pid=$!
busy_ready=0
for _ in $(seq 1 100); do
  if ! flock -n "$lock" -c true; then
    busy_ready=1
    break
  fi
  sleep 0.02
done
test "$busy_ready" -eq 1
output="$(cd /tmp/site && runuser -u www-data -- php /tmp/site/local-extensions/extensions/PikaCatalogHub/bin/worker.php --root=/tmp/site --batch=1)"
printf '%s\n' "$output" | grep -q '"status":"busy"'
kill "$holder_pid" 2>/dev/null || true
wait "$holder_pid" 2>/dev/null || true

expect_failed_worker() {
  set +e
  failed_output="$(cd /tmp/site && runuser -u www-data -- php /tmp/site/local-extensions/extensions/PikaCatalogHub/bin/worker.php --root=/tmp/site --batch=1)"
  failed_status=$?
  set -e
  test "$failed_status" -eq 1
  printf '%s\n' "$failed_output" | grep -q '"error_code":"WORKER_START_FAILED"'
}

rm -- "$lock"
hardlink_target="$(dirname "$lock")/hardlink-target"
runuser -u www-data -- touch "$hardlink_target"
chmod 0644 "$hardlink_target"
ln "$hardlink_target" "$lock"
expect_failed_worker
test "$(stat -c '%a' "$hardlink_target")" = 644
rm -- "$lock" "$hardlink_target"

ln -s /dev/null "$lock"
expect_failed_worker
rm -- "$lock"

mkdir "$lock"
expect_failed_worker
rmdir "$lock"

runuser -u www-data -- touch "$lock"
chmod 0644 "$lock"
expect_failed_worker
test "$(stat -c '%a' "$lock")" = 644
rm -- "$lock"

mkfifo "$lock"
chown "$web_uid:$web_gid" "$lock"
chmod 0600 "$lock"
expect_failed_worker
rm -- "$lock"
printf 'WORKER_FRESH_LOCK_PASS\n'
`;
    const output = execFileSync('docker', [
        'run', '--rm', '--network', 'none', '--pull', 'never',
        '--volume', `${root}:/repo:ro`,
        '--volume', `${official}:/official:ro`,
        '--tmpfs', '/tmp:rw,nosuid,nodev,exec,mode=1777,size=512m',
        '--tmpfs', '/var/lib/pika-local-extensions:rw,nosuid,nodev,size=256m,mode=0755,uid=0,gid=0',
        'acg-faka-php83-integration:20260828',
        'bash', '-lc', script,
    ], {encoding: 'utf8'});
    assert.match(output, /WORKER_FRESH_LOCK_PASS/);
});
