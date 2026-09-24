import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const cli = fs.readFileSync(path.join(root, 'extensions/PikaCatalogHub/bin/category-icons.php'), 'utf8');

// These first eight tests are static contracts. The final opt-in test runs the
// separate installed CLI gate; a default skip is explicitly NOT RUN.
test('CLI is explicit, bounded and rejects unknown, duplicate or mixed-mode arguments before bootstrap', () => {
    const parser = cli.slice(cli.indexOf('$parseArguments ='), cli.indexOf('$assertPrivateParent ='));
    assert.match(parser, /array_key_exists\(\$name, \$arguments\)/);
    assert.match(parser, /\$keys !== \$required/);
    assert.match(parser, /count\(\$ids\) > 16/);
    assert.match(parser, /\(int\)\$id <= \$previous/);
    assert.match(parser, /\(int\)\$arguments\['source'\] > 2147483647/);
    assert.match(parser, /\(int\)\$id > 2147483647/);
    assert.match(parser, /'preview' => \['mode', 'root', 'source', 'ids', 'plan-file'\]/);
    assert.match(parser, /'apply' => \['mode', 'root', 'source', 'plan-file', 'plan-sha', 'receipt-file'\]/);
    assert.match(parser, /'rollback' => \['mode', 'root', 'source', 'receipt-file', 'receipt-sha'\]/);
    assert.ok(cli.indexOf('$arguments = $parseArguments(') < cli.indexOf("require $siteRoot . '/kernel/Console.php'"));
    assert.ok(cli.indexOf("if (isset($arguments['help']))") < cli.indexOf("require $siteRoot . '/kernel/Console.php'"));
});

test('trusted installed CLI requires non-root runtime owner and only Hub enabled', () => {
    assert.match(cli, /PHP_SAPI !== 'cli'/);
    assert.match(cli, /!function_exists\('posix_geteuid'\)/);
    assert.match(cli, /posix_geteuid\(\) === 0/);
    assert.match(cli, /posix_geteuid\(\) !== \$owner/);
    assert.match(cli, /PathGuard::runtimeOwner\(\)/);
    assert.match(cli, /use Pika\\LocalExtensions\\PikaSupplySync\\Service\\CategoryIcons;/);
    assert.match(cli, /realpath\(dirname\(__DIR__\)\) !== \$extensionRoot/);
    assert.match(cli, /ManagerState::isEnabled\('PikaCatalogHub'\)/);
    assert.doesNotMatch(cli, /isEnabled\('PikaSupplySync'\)/);
});

test('private files are canonical, outside the site, exact modes and same runtime owner', () => {
    assert.match(cli, /realpath\(\$parent\) !== \$parent/);
    assert.match(cli, /\$parent === \$siteRoot \|\| str_starts_with\(\$parent, \$siteRoot \. '\/'\)/);
    assert.match(cli, /\(\$metadata\['mode'\] & 07777\) !== 0700/);
    assert.match(cli, /\(\$metadata\['mode'\] & 07777\) !== 0600/);
    assert.match(cli, /\(int\)\$metadata\['uid'\] !== \$owner/);
    assert.match(cli, /\(int\)\$metadata\['nlink'\] !== 1/);
    assert.match(cli, /\$metadata\['dev'\] !== \$pathMetadata\['dev'\]/);
    assert.match(cli, /\$metadata\['ino'\] !== \$pathMetadata\['ino'\]/);
    assert.match(cli, /realpath\(\$path\) !== \$path/);
    assert.match(cli, /is_link\(\$path\)/);
});

test('frozen input is bounded and hash-checked; outputs are exclusive and fsynced', () => {
    assert.match(cli, /CATEGORY_ICONS_MAX_FILE_BYTES = 131072/);
    assert.match(cli, /stream_get_contents\(\$handle, CATEGORY_ICONS_MAX_FILE_BYTES \+ 1\)/);
    assert.match(cli, /hash_equals\(\$sha, hash\('sha256', \$bytes\)\)/);
    assert.match(cli, /json_decode\(\$bytes, true, 32, JSON_THROW_ON_ERROR\)/);
    assert.match(cli, /umask\(0177\)/);
    assert.match(cli, /fopen\(\$path, 'x\+b'\)/);
    assert.match(cli, /!fflush\(\$handle\) \|\| !fsync\(\$handle\)/);
    assert.match(cli, /fopen\(\$parent, 'rb'\)/);
    assert.match(cli, /\$directoryMetadata\['dev'\] !== \$parentMetadata\['dev'\]/);
    assert.match(cli, /\$directoryMetadata\['ino'\] !== \$parentMetadata\['ino'\]/);
    assert.match(cli, /!fsync\(\$directory\)/);
    assert.ok(cli.indexOf('!fsync($handle)') < cli.indexOf('!fsync($directory)'));
    assert.ok(cli.indexOf('!fsync($directory)') < cli.indexOf("return hash('sha256', $bytes)"));
    assert.doesNotMatch(cli, /unlink\(|rename\(|file_put_contents\(/);
});

test('one shared source lock and budget surround metadata, download and CAS operations', () => {
    assert.match(cli, /new SourceLock\(\)/);
    assert.match(cli, /new SafeHttpClient\(\$policy, null, \$budget\)/);
    assert.match(cli, /new ImageCache\(\$http, \$budget\)/);
    assert.ok(cli.indexOf('$lock->acquire($sourceId)') < cli.indexOf('$budget->beginSource($sourceId)'));
    assert.ok(cli.indexOf('$budget->beginSource($sourceId)') < cli.indexOf('$service->preview('));
    assert.match(cli, /finally \{\s+if \(\$sourceStarted\) \{\s+\$budget->endSource\(\);\s+\}\s+\$lock->release\(\);/);
    assert.doesNotMatch(cli, /new JobService|new JobWorker|createAnalysis|confirmImport|CommodityImporter|loadImportSnapshot/);
});

test('apply prepares once and durably freezes receipt before mutation; rollback never prepares', () => {
    const start = cli.indexOf("if ($mode === 'apply') {", cli.indexOf('$service = new CategoryIcons('));
    const block = cli.slice(start, cli.indexOf('$applied =', start));
    assert.match(block, /\$frozen = \$service->prepare\(\$source, \$frozen\)/);
    assert.match(block, /\$receiptSha = \$writeFrozen\(\$arguments\['receipt-file'\], \$frozen/);
    assert.match(block, /\$receiptSaved = true/);
    assert.match(cli, /\$service->apply\(\$source, \$frozen, \$mode === 'rollback'\)/);
    assert.equal((cli.match(/->preview\(/g) ?? []).length, 1);
    assert.equal((cli.match(/->prepare\(/g) ?? []).length, 1);
    assert.equal((cli.match(/->apply\(/g) ?? []).length, 1);
});

test('CLI returns only sanitized counts and receipt identity, not sensitive service values', () => {
    assert.doesNotMatch(cli, /getMessage\(|getTrace|var_dump\(|print_r\(/);
    assert.doesNotMatch(cli, /\$writeResult\(\$(?:plan|frozen|applied|source|arguments),/);
    assert.match(cli, /fwrite\(STDERR, "CATEGORY_ICONS_FAILED\\n"\)/);
    assert.match(cli, /'receipt_saved' => \$receiptSaved/);
    assert.match(cli, /'receipt_sha256' => \$receiptSha/);
    assert.match(cli, /\$counts\['changed'\] = \$applied\['changed'\]/);
    assert.match(cli, /\$counts\['total'\] = \$applied\['total'\]/);
    assert.match(cli, /'eligible' => \$requested - \$manual - \$default/);
    assert.match(cli, /'skipped_manual' => \$manual/);
    assert.match(cli, /'skipped_upstream_default' => \$default/);
    assert.match(cli, /'applied', 'already_applied', 'rolled_back', 'already_rolled_back', 'no_changes'/);
});

test('help documents three distinct bounded modes and retained cache behavior', () => {
    assert.match(cli, /Usage: php bin\/category-icons\.php --mode=preview/);
    assert.match(cli, /--mode=apply[^\n]+--plan-sha=SHA256[^\n]+--receipt-file=/);
    assert.match(cli, /--mode=rollback[^\n]+--receipt-sha=SHA256/);
    assert.match(cli, /At most 16 IDs; non-root runtime owner; private parent 0700 outside site; files 0600/);
    assert.match(cli, /No automatic retry or cache deletion/);
});

test('installed category-icons CLI uses official Console and SQLite with durable receipts and bounded CAS', {
    skip: process.env.PIKA_CATEGORY_ICONS_DYNAMIC !== '1'
        ? 'NOT RUN: opt in with PIKA_CATEGORY_ICONS_DYNAMIC=1 and pinned PHP image/official root' : false,
    timeout: 240_000,
}, () => {
    const image = process.env.PIKA_CATEGORY_ICONS_PHP_IMAGE;
    const official = process.env.ACG_FAKA_OFFICIAL_ROOT;
    assert.match(image ?? '', /^sha256:[a-f0-9]{64}$/, 'use the already-present immutable PHP image ID');
    assert.ok(official && path.isAbsolute(official) && fs.realpathSync(official) === official,
        'ACG_FAKA_OFFICIAL_ROOT must be a canonical pinned checkout');
    // Match the existing worker-contract container pattern. No web server,
    // worker, install script, credentials, external transport or production DB.
    const script = String.raw`
set -euo pipefail
mkdir -p /tmp/site /tmp/private /tmp/wrong-owner /tmp/site/runtime /tmp/site/assets/cache/pika-supply-sync
cp -a /official/kernel /official/vendor /official/config /official/app /tmp/site/
cp -a /repo/manager/site/local-extensions /tmp/site/local-extensions
mkdir -p /tmp/site/local-extensions/extensions
cp -a /repo/extensions/. /tmp/site/local-extensions/extensions/
mkdir -p /tmp/site/app/View/User/Theme/Pika
cp -a /repo/themes/Pika/. /tmp/site/app/View/User/Theme/Pika/
find /tmp/site/local-extensions /tmp/site/app/View/User/Theme/Pika -exec chown 0:0 {} +
find /tmp/site/local-extensions /tmp/site/app/View/User/Theme/Pika -type d -exec chmod 0755 {} +
find /tmp/site/local-extensions /tmp/site/app/View/User/Theme/Pika -type f -exec chmod 0644 {} +
php /repo/scripts/build-registry.php --site-root /tmp/site --release /repo/release.json --output /tmp/site/local-extensions/registry.json >/dev/null
cat > /tmp/category-icons-installed.php <<'PHP'
<?php
declare(strict_types=1);
use App\Model\Category;
use App\Model\Shared;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;
use Pika\LocalExtensions\PikaCatalogHub\Service\SnapshotStore;
use Pika\LocalExtensions\PikaSupplySync\Service\PlannedCategoryMapper;
use Pika\LocalExtensions\PikaSupplySync\Service\SourceIdentity;
use Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree;

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function privateJson(string $path, array $value): string {
    $bytes = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    expect(file_put_contents($path, $bytes) === strlen($bytes) && chmod($path, 0600), 'fixture private write');
    return hash('sha256', $bytes);
}
function identity(string $path): array {
    clearstatcache(true, $path);
    $stat = lstat($path);
    return ['mode'=>$stat['mode'] & 07777, 'uid'=>$stat['uid'], 'gid'=>$stat['gid'],
        'dev'=>$stat['dev'], 'ino'=>$stat['ino'], 'nlink'=>$stat['nlink']];
}
$web = posix_getpwnam('www-data');
expect(is_array($web) && $web['uid'] > 0 && posix_geteuid() === 0, 'fixture root and web identity');
$site = '/tmp/site'; $private = '/tmp/private';
$state = '/var/lib/pika-local-extensions/sites/' . hash('sha256', $site);
foreach (['/var/lib/pika-local-extensions', '/var/lib/pika-local-extensions/sites', $state] as $directory) {
    if (!is_dir($directory)) mkdir($directory, 0755);
    chown($directory, 0); chgrp($directory, 0); chmod($directory, 0755);
}
foreach ([$state . '/runtime'=>0750, $site . '/runtime'=>0750, $private=>0700,
    $site . '/assets'=>0755, $site . '/assets/cache'=>0755, $site . '/assets/cache/pika-supply-sync'=>0755] as $directory=>$mode) {
    if (!is_dir($directory)) mkdir($directory, $mode);
    chown($directory, $web['uid']); chgrp($directory, $web['gid']); chmod($directory, $mode);
}
chmod('/tmp/wrong-owner', 0700);
privateJson($private . '/root-owned.json', ['schema'=>1]);
privateJson($state . '/runtime/state.json', ['schema'=>1, 'extensions'=>[
    'PikaCatalogHub'=>['enabled'=>true, 'updated_at'=>'2026-09-22T00:00:00Z'],
    'PikaSupplySync'=>['enabled'=>false, 'updated_at'=>'2026-09-22T00:00:00Z'],
]]);
chown($state . '/runtime/state.json', $web['uid']); chgrp($state . '/runtime/state.json', $web['gid']);
$database = $site . '/runtime/cli.sqlite';
touch($database); chown($database, $web['uid']); chgrp($database, $web['gid']); chmod($database, 0600);
file_put_contents($site . '/config/database.php', '<?php return ' . var_export([
    'driver'=>'sqlite', 'database'=>$database, 'prefix'=>'',
], true) . ';');
file_put_contents($site . '/config/app.php', '<?php return [];');
file_put_contents($site . '/kernel/Install/Lock', 'synthetic-category-icons-cli');
foreach (['config/database.php', 'config/app.php', 'kernel/Install/Lock'] as $file) chmod($site . '/' . $file, 0644);
// The official Console is byte-for-byte unchanged; only synthetic config selects SQLite.
expect(hash_file('sha256', $site . '/kernel/Console.php') === hash_file('sha256', '/official/kernel/Console.php'), 'Console changed');
chdir($site);
require $site . '/kernel/Console.php';
require $site . '/local-extensions/bootstrap.php';
require $site . '/local-extensions/extensions/PikaSupplySync/bootstrap.php';
require $site . '/local-extensions/extensions/PikaCatalogHub/bootstrap.php';
$schema = DB::connection()->getSchemaBuilder();
$schema->create('shared', static function (Blueprint $table): void {
    $table->increments('id'); $table->integer('type');
    foreach (['name','domain','app_id','app_key','currency','currency_rate'] as $field) $table->string($field);
});
$schema->create('category', static function (Blueprint $table): void {
    $table->increments('id'); $table->string('name'); $table->integer('sort'); $table->string('create_time');
    $table->integer('owner'); $table->string('icon'); $table->integer('status'); $table->integer('hide');
    $table->unsignedInteger('pid')->nullable();
});
$schema->create('commodity', static function (Blueprint $table): void {
    $table->increments('id'); $table->integer('category_id'); $table->decimal('price', 10, 2);
    $table->decimal('shared_premium', 10, 2); $table->string('sentinel');
});
DB::table('shared')->insert(['id'=>1, 'type'=>0, 'name'=>'synthetic-source',
    'domain'=>'https://127.0.0.1', 'app_id'=>'synthetic-app', 'app_key'=>'synthetic-secret', 'currency'=>'CNY','currency_rate'=>'1']);
expect(posix_setgid($web['gid']) && posix_setuid($web['uid']), 'fixture identity drop');
expect(posix_geteuid() === $web['uid'], 'not runtime owner');
$source = Shared::query()->findOrFail(1);
$mapper = new PlannedCategoryMapper();
$nodes = [
    ['id'=>10,'pid'=>0,'name'=>'synthetic-root','sort'=>1],
    ['id'=>20,'pid'=>10,'name'=>'synthetic-child','sort'=>2],
    ['id'=>30,'pid'=>0,'name'=>'synthetic-manual','sort'=>3],
    ['id'=>40,'pid'=>0,'name'=>'synthetic-default','sort'=>4],
];
$leaf = $mapper->resolve($source, 'synthetic', ['mode'=>'mirror','path'=>[$nodes[0],$nodes[1]]], $nodes[1]['name'], str_repeat('a',64));
foreach (array_slice($nodes, 2) as $node) $mapper->resolve($source, 'synthetic', ['mode'=>'mirror','path'=>[$node]], $node['name'], str_repeat('a',64));
$targets = $mapper->mirrorIconTargets($source, [10,20,30,40]);
Category::query()->where('id', $targets[2]['local_id'])->update(['icon'=>'/manual.png']);
$targets = $mapper->mirrorIconTargets($source, [10,20,30,40]);
DB::table('commodity')->insert(['category_id'=>$leaf->id, 'price'=>'120.00', 'shared_premium'=>'20.00', 'sentinel'=>'unchanged-product']);
$bitmap = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jAusAAAAASUVORK5CYII=', true);
expect(is_string($bitmap) && getimagesizefromstring($bitmap) !== false, 'fixture bitmap invalid');
$cachePaths = [];
foreach ($targets as $index=>&$target) {
    $target['source_icon'] = $index === 3 ? '/favicon.ico' : '/synthetic-' . $index . '.png';
    if ($index > 1) continue;
    $path = '/assets/cache/pika-supply-sync/' . hash('sha256', 'https://127.0.0.1' . $target['source_icon']) . '.png';
    file_put_contents($site . $path, $bitmap); chmod($site . $path, 0644); $cachePaths[] = $site . $path;
}
unset($target);
$plan = ['schema'=>1, 'source_id'=>1, 'source_fingerprint'=>SourceIdentity::fingerprint($source),
    'ids'=>[10,20,30,40], 'targets'=>$targets, 'skipped'=>['manual'=>1,'upstream_default'=>1]];
$planPath = $private . '/plan.json'; $planHash = privateJson($planPath, $plan);
$mapPath = $state . '/runtime/extensions/PikaCatalogHub/category-map.json';
expect(is_file($mapPath), 'fixture map path missing');
// Use the real jobs path and a valid old mirror snapshot without icon capability.
// Fixture setup writes these once; the CLI must not queue work or rewrite them.
$jobsPath = $state . '/runtime/extensions/PikaCatalogHub/jobs';
mkdir($jobsPath, 0750);
privateJson($jobsPath . '/jobs.json', ['schema'=>4,'jobs'=>[],'snapshot_gc'=>null]);
$snapshotTask = str_repeat('b',48);
$oldItems = [[
    'code'=>'synthetic-old-snapshot', 'category'=>$nodes[1]['name'], 'stock'=>1,
    'target'=>['mode'=>'mirror','path'=>[$nodes[0],$nodes[1]]],
]];
$oldPlanHash = (new UpstreamCategoryTree())->suggest($oldItems)['plan_hash'];
(new SnapshotStore())->write($snapshotTask, SourceIdentity::fingerprint($source), $oldPlanHash, $oldItems, 'mirror');
$snapshotPath = $state . '/runtime/extensions/PikaCatalogHub/snapshots/' . $snapshotTask . '.json';
$protected = [$mapPath, $state . '/runtime/state.json', $jobsPath . '/jobs.json', $snapshotPath, $planPath, ...$cachePaths];
$protectedBefore = [];
foreach ($protected as $path) $protectedBefore[$path] = [identity($path), hash_file('sha256', $path)];
$rowsBefore = Category::query()->orderBy('id')->get()->toArray();
$productsBefore = DB::table('commodity')->orderBy('id')->get()->toJson();
$sharedBefore = DB::table('shared')->orderBy('id')->get()->toJson();
$cli = $site . '/local-extensions/extensions/PikaCatalogHub/bin/category-icons.php';
$invocations = 0;
$run = static function (array $arguments, int $exit, ?string $status = null) use ($cli, $site, &$invocations): array {
    $invocations++;
    $process = proc_open([PHP_BINARY, $cli, ...$arguments], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $site);
    expect(is_resource($process), 'CLI process unavailable'); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    expect(proc_close($process) === $exit, 'CLI exit mismatch #' . $invocations);
    if ($status === 'help') {
        expect(str_starts_with($stdout, 'Usage: php bin/category-icons.php') && $stderr === '', 'CLI help mismatch');
        return [];
    }
    expect(strlen($stdout) < 1024 && ($stderr === '' || $stderr === "CATEGORY_ICONS_FAILED\n"), 'CLI unsafe output');
    $value = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
    expect(is_array($value) && ($status === null || $value['status'] === $status), 'CLI status mismatch #' . $invocations);
    expect(!preg_match('~synthetic|127\\.0\\.0\\.1|/tmp/|app_key|upstream_id|local_id~', $stdout . $stderr), 'CLI leaked fixture values');
    return $value;
};
$applyArgs = static fn(string $receipt, string $file = '/tmp/private/plan.json', ?string $sha = null): array => [
    '--mode=apply','--root=/tmp/site','--source=1','--plan-file=' . $file,'--plan-sha=' . ($sha ?? $planHash),'--receipt-file=' . $receipt,
];
$unchanged = static function () use ($rowsBefore, $productsBefore, $sharedBefore, $protectedBefore): void {
    expect(Category::query()->orderBy('id')->get()->toArray() === $rowsBefore, 'category changed on rejected/rolled back CLI');
    expect(DB::table('commodity')->orderBy('id')->get()->toJson() === $productsBefore
        && DB::table('shared')->orderBy('id')->get()->toJson() === $sharedBefore, 'unrelated business row changed');
    foreach ($protectedBefore as $path=>$before) expect([identity($path), hash_file('sha256', $path)] === $before, 'protected file changed');
};
$run(['--help'], 0, 'help');
foreach ([['--unknown=x'], ['--help','--help'], ['--mode=preview','--root=/tmp/site','--source=2147483648','--ids=10','--plan-file=/tmp/private/no.json'],
    ['--mode=preview','--root=/tmp/site','--source=1','--ids=20,10','--plan-file=/tmp/private/no.json'],
    ['--mode=preview','--root=/tmp/site','--source=1','--ids=10,10','--plan-file=/tmp/private/no.json']] as $arguments) $run($arguments, 1, 'failed');
$badSource = $applyArgs('/tmp/private/bad-source-receipt.json'); $badSource[2] = '--source=2'; $run($badSource,1,'failed');
$run($applyArgs('/tmp/private/hash-receipt.json', $planPath, str_repeat('0',64)),1,'failed');
$run($applyArgs('/tmp/private/owner-receipt.json', $private . '/root-owned.json', str_repeat('0',64)),1,'failed');
$run($applyArgs('/tmp/wrong-owner/receipt.json'),1,'failed');
$run($applyArgs('/tmp/site/receipt.json'),1,'failed');
symlink($planPath, $private . '/plan-link.json'); $run($applyArgs('/tmp/private/link-receipt.json', $private . '/plan-link.json'),1,'failed');
link($planPath, $private . '/plan-hard.json'); $run($applyArgs('/tmp/private/hard-receipt.json', $planPath),1,'failed'); unlink($private . '/plan-hard.json');
chmod($planPath,0644); $run($applyArgs('/tmp/private/mode-receipt.json'),1,'failed'); chmod($planPath,0600);
privateJson($private . '/existing.json', ['sentinel'=>'unchanged']); $existingHash=hash_file('sha256',$private . '/existing.json');
$run($applyArgs($private . '/existing.json'),1,'failed'); expect(hash_file('sha256',$private . '/existing.json')===$existingHash,'existing receipt overwritten');
$run(['--mode=preview','--root=/tmp/site','--source=1','--ids=10,20','--plan-file=/tmp/private/preview.json'],1,'failed');
expect(!file_exists($private . '/preview.json'), 'rejected metadata created plan'); $unchanged();

// The second conditional UPDATE aborts after the first was attempted. SQLite
// must roll back both; the actual CLI has already durably saved its receipt.
$second = $targets[1]['local_id'];
DB::unprepared('CREATE TRIGGER stop_second_icon BEFORE UPDATE OF icon ON category WHEN NEW.id = ' . $second
    . " BEGIN SELECT RAISE(ABORT, 'SYNTHETIC_SECOND_UPDATE'); END");
$failedReceipt = $private . '/failed-receipt.json';
$failed = $run($applyArgs($failedReceipt),1,'failed');
expect($failed['receipt_saved'] === true && $failed['receipt_sha256'] === hash_file('sha256',$failedReceipt), 'failed CAS receipt not retained');
$receiptIdentity = identity($failedReceipt);
expect($receiptIdentity['mode'] === 0600 && $receiptIdentity['uid'] === $web['uid'] && $receiptIdentity['nlink'] === 1, 'receipt identity invalid');
$failedBytes = file_get_contents($failedReceipt); $unchanged();
DB::unprepared('DROP TRIGGER stop_second_icon');
$receipt = $private . '/receipt.json';
$applied = $run($applyArgs($receipt),0,'applied');
expect($applied['counts'] === ['requested'=>4,'eligible'=>2,'skipped_manual'=>1,'skipped_upstream_default'=>1,'changed'=>2,'total'=>2], 'CLI apply counts');
$receiptValue = json_decode(file_get_contents($receipt),true,32,JSON_THROW_ON_ERROR);
$rowsAfter = Category::query()->orderBy('id')->get()->toArray();
foreach ($receiptValue['changes'] as $change) {
    foreach ($rowsAfter as &$row) if ($row['id'] === $change['local_id']) {
        expect($row['icon'] === $change['new_icon'], 'CLI icon not applied'); $row['icon'] = $change['old_icon'];
    }
    unset($row);
}
expect($rowsAfter === $rowsBefore, 'CLI changed category fields beyond two icons');
$run($applyArgs($receipt),1,'failed');
$rollback = ['--mode=rollback','--root=/tmp/site','--source=1','--receipt-file=' . $receipt,'--receipt-sha=' . $applied['receipt_sha256']];
$firstChange = $receiptValue['changes'][0];
Category::query()->where('id',$firstChange['local_id'])->update(['icon'=>'/later-manual.png']);
$manualRows = Category::query()->orderBy('id')->get()->toArray();
$manualRejected = $run($rollback,1,'failed');
expect($manualRejected['receipt_saved'] === true
    && Category::query()->orderBy('id')->get()->toArray() === $manualRows
    && hash_file('sha256',$receipt) === $applied['receipt_sha256'], 'rollback overwrote later manual icon or partly reverted batch');
// Restore only this synthetic test mutation before exercising ordinary rollback.
Category::query()->where('id',$firstChange['local_id'])->where('icon','/later-manual.png')->update(['icon'=>$firstChange['new_icon']]);
$rolled = $run($rollback,0,'rolled_back'); expect($rolled['counts']['changed']===2,'rollback count'); $unchanged();
$again = $run($rollback,0,'already_rolled_back'); expect($again['counts']['changed']===0,'duplicate rollback wrote'); $unchanged();
expect(file_get_contents($failedReceipt) === $failedBytes && identity($failedReceipt) === $receiptIdentity, 'failed receipt changed');
expect(hash_file('sha256',$receipt) === $applied['receipt_sha256'] && identity($receipt)['mode'] === 0600, 'successful receipt changed');
echo 'CATEGORY_ICONS_INSTALLED_CLI_PASS calls=' . $invocations
    . ' database=sqlite console=official services=real cache=preseeded network=none preview=safety-rejection hub=true supply=false web=not-started' . PHP_EOL;
PHP
php /tmp/category-icons-installed.php
`;
    const output = execFileSync('docker', ['run', '--rm', '--pull', 'never', '-i', '--read-only',
        '--network', 'none', '--log-driver', 'none', '--entrypoint', 'bash',
        '--tmpfs', '/tmp:rw,nosuid,nodev,mode=1777',
        '--tmpfs', '/var/lib/pika-local-extensions:rw,nosuid,nodev,mode=0755',
        '-v', `${root}:/repo:ro`, '-v', `${official}:/official:ro`, image, '-s'],
    {input: script, encoding: 'utf8', timeout: 210_000, maxBuffer: 262_144});
    assert.match(output, /CATEGORY_ICONS_INSTALLED_CLI_PASS calls=\d+ database=sqlite console=official services=real cache=preseeded network=none preview=safety-rejection hub=true supply=false web=not-started/);
});
