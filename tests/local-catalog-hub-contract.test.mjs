import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const extension = path.join(root, 'extensions/PikaCatalogHub');
const read = relative => fs.readFileSync(path.join(extension, relative), 'utf8');

function phpSources(directory) {
    return fs.readdirSync(directory, {withFileTypes: true}).flatMap(entry => {
        const file = path.join(directory, entry.name);
        return entry.isDirectory() ? phpSources(file) : file.endsWith('.php') ? [file] : [];
    });
}

test('declares a local CatalogHub workflow without taking over the official plugin lifecycle or credentials', () => {
    const manifest = JSON.parse(read('local-extension.json'));
    assert.equal(manifest.schema, 1);
    assert.equal(manifest.id, 'PikaCatalogHub');
    assert.equal(manifest.name, '智能货源中心');
    assert.equal(manifest.description, '管理共享货源、建议商品分类，确认后分批后台入库，支持暂停、继续和取消任务。');
    assert.equal(manifest.version, '0.6.9');
    assert.equal(manifest.namespace, 'Pika\\LocalExtensions\\PikaCatalogHub\\');
    assert.equal(manifest.bootstrap, 'bootstrap.php');
    assert.deepEqual(manifest.hooks, [
        {point: 1793, class: 'Pika\\LocalExtensions\\PikaCatalogHub\\Hook\\NativeCategoryRename', method: 'toolbar', priority: 100},
        {point: 36920, class: 'Pika\\LocalExtensions\\PikaCatalogHub\\Hook\\NativeCategoryRename', method: 'form', priority: 100},
        {point: 50, class: 'Pika\\LocalExtensions\\PikaCatalogHub\\Hook\\SharedCategoryTree', method: 'after', priority: 100},
    ]);
    assert.deepEqual(manifest.assets, [{source: 'Assets', target: 'assets/local-extensions/PikaCatalogHub'}]);
    assert.deepEqual(manifest.settings, []);

    const manifestText = JSON.stringify(manifest);
    const combined = phpSources(extension).map(file => fs.readFileSync(file, 'utf8')).join('\n');
    assert.doesNotMatch(combined, /namespace App\\Plugin|Kernel\\Annotation\\Plugin|_plugin_start|PLUGIN_CONFIG/);
    assert.doesNotMatch(manifestText, /app[_-]?id|app[_-]?key|merchant|password|secret|token|https?:\/\//i);
    const persistedState = [
        read('Service/ConfigRepository.php'),
        read('Service/JobStore.php'),
        read('Service/SnapshotStore.php'),
    ].join('\n');
    assert.doesNotMatch(persistedState, /app_key|merchant_id|password|secret|token/i);
    assert.doesNotMatch(combined, /App\\Model\\(?:Card|Order)|SyncService/);
});

test('secure source management uses the Pika HTTPS boundary and fail-closed edit guards', () => {
    const connector = read('Service/SafeSourceConnector.php');
    const aliases = read('Service/SourceAliasService.php');
    const lock = read('Service/SourceConnectLock.php');
    assert.match(connector, /new SourcePolicy/);
    assert.match(connector, /new SafeHttpClient/);
    assert.match(connector, /new RunBudget/);
    assert.match(connector, /->beginSource\(1\)/);
    assert.match(connector, /\/shared\/authentication\/connect/);
    assert.match(connector, /\/plugin\/SharedStock\/api\/connect/);
    assert.match(connector, /\/plugin\/open-api\/connect/);
    assert.match(connector, /Str::generateSignature/);
    assert.match(connector, /DB::transaction/);
    assert.match(connector, /->lockForUpdate\(\)/);
    assert.match(connector, /new Shared\(\)/);
    assert.match(connector, /containsSecret/);
    assert.match(connector, /function update\(/);
    assert.doesNotMatch(connector, /function delete\(/);
    assert.match(connector, /function syncContextChanged\(/);
    assert.match(connector, /SourceIdentity::lockAndVerify/);
    assert.match(connector, /协议、店铺地址和商户 ID 不支持原地改绑/);
    assert.match(connector, /assertSourceIdle/);
    assert.match(connector, /new SourceLock\(\)/);
    assert.match(connector, /->resetProgress\(\$sourceId\)/);
    assert.match(connector, /\$public\['alias'\] = \$alias/);
    assert.match(aliases, /!\$jobs->isTerminal/);
    assert.match(aliases, /->upsertAlias\(\$sourceId, \$alias\)/);
    const displayRename = aliases.match(/public function rename[\s\S]+?public function assertNoPendingRename/)?.[0] || '';
    assert.doesNotMatch(displayRename, /Commodity::query|DB::transaction/);
    assert.match(displayRename, /new PlannedCategoryMapper/);
    assert.match(displayRename, /->renameSource\(/);
    assert.match(aliases, /sourceNeedsRename\(\$sourceId, \$alias\)/);
    assert.match(aliases, /hasManagedCommodity[\s\S]+Commodity::query/);
    assert.doesNotMatch(aliases, /DB::transaction/);
    assert.match(aliases, /->hasSourceMapping\(\$sourceId\)/);
    assert.match(aliases, /classificationAlias/);
    assert.match(read('Service/ConfigRepository.php'), /saveRulesWithUnchangedAliases[\s\S]+AtomicJson::update/);
    assert.match(connector, /if \(!\$aliasChanged && \$rawAppKey === '' && !\$sourceSettingsChanged\)/);
    const sameValueReturn = connector.match(/if \(!\$aliasChanged[\s\S]+?if \(\$aliasChanged\)/)?.[0] || '';
    assert.doesNotMatch(sameValueReturn, /SourcePolicy|remoteIdentity|DB::transaction|aliases->rename/);
    assert.match(connector, /if \(\$aliasChanged\)[\s\S]+\$this->aliases->rename/);
    assert.match(connector, /货源名称与密钥、货币或汇率的修改不能在一次请求中合并/);
    assert.doesNotMatch(connector, /\/admin\/api\/store\/save|App\\Service\\Bind|->connect\(\$domain/);
    assert.match(lock, /LOCK_EX \| LOCK_NB/);
    assert.match(lock, /@lstat\(\$path\)/);
    assert.match(lock, /@fopen\(\$path, 'r\+b'\)/);
    assert.match(lock, /@fopen\(\$path, 'x\+b'\)/);
    assert.match(lock, /umask\(0o177\)/);
    assert.match(lock, /finally[\s\S]+umask\(\$previousUmask\)/);
    assert.match(lock, /assertSafeExistingPath\(\$path, \$pathMetadata\)/);
    assert.ok((lock.match(/assertSafeHandle\(\$handle, \$path\)/g) ?? []).length >= 2);
    assert.match(lock, /fstat\(\$handle\)/);
    assert.match(lock, /lstat\(\$path\)/);
    assert.match(lock, /\['dev'\]/);
    assert.match(lock, /\['ino'\]/);
    assert.match(lock, /\['nlink'\]\s*!==\s*1/);
    assert.doesNotMatch(lock, /chmod\(\$path|fchmod\(/);
});

test('source binding identity is immutable without database-level referential integrity', () => {
    const connector = read('Service/SafeSourceConnector.php');
    assert.match(connector, /\$bindingChanged = \$this->bindingChanged/);
    assert.match(connector, /if \(\$bindingChanged\) \{[\s\S]*?throw new RuntimeException/);
    assert.doesNotMatch(connector, /Commodity::query|PlannedCategoryMapper/);
});

test('admin requests only queue work while the bounded worker reuses the safe SupplySync services', () => {
    const admin = read('Service/AdminService.php');
    const worker = read('Service/JobWorker.php');
    assert.match(admin, /SharedGateway/);
    assert.match(admin, /SafeHttpClient/);
    assert.match(admin, /SourcePolicy/);
    assert.match(admin, /CatalogPlanner/);
    assert.match(admin, /->items\(\$source\)/);
    assert.match(admin, /->flatten\(/);
    assert.match(admin, /->createAnalysis\(/);
    assert.doesNotMatch(admin, /CommodityImporter|PlannedCategoryMapper|DB::|->import\(/);
    assert.doesNotMatch(admin, /curl_|file_get_contents\s*\(\s*\$source|Str::generateSignature/);

    assert.match(worker, /MAX_BATCH\s*=\s*20/);
    assert.match(worker, /new SourceLock\(\)/);
    assert.match(worker, /SourceIdentity::fingerprint/);
    assert.match(worker, /new CommodityImporter\(/);
    assert.match(worker, /new RemoteItem\(/);
    assert.match(worker, /new PlannedCategoryMapper\(/);
    assert.match(worker, /->checkpoint\(/);
    assert.match(worker, /->yieldImport\(/);
    assert.doesNotMatch(worker, /curl_|file_get_contents\s*\(\s*\$source|Str::generateSignature/);
});

test('bounds literal rules, aliases, source reads and issue samples', () => {
    const schema = read('Service/ConfigSchema.php');
    const planner = read('Service/PreviewPlanner.php');
    const matcher = read('Service/LiteralMatcher.php');
    const admin = read('Service/AdminService.php');
    assert.match(schema, /MAX_ALIASES\s*=\s*16/);
    assert.match(schema, /MAX_RULES\s*=\s*128/);
    assert.match(schema, /MAX_KEYWORDS\s*=\s*16/);
    assert.match(schema, /MAX_CONFIG_BYTES\s*=\s*131072/);
    assert.match(schema, /\['exact', 'contains'\]/);
    assert.match(planner, /MAX_ITEMS\s*=\s*10000/);
    assert.match(planner, /MAX_ISSUE_ROWS\s*=\s*100/);
    assert.match(planner, /MAX_TARGETS_PER_ITEM\s*=\s*16/);
    assert.match(planner, /hash_update\(/);
    assert.doesNotMatch(planner, /\$audit\[\]/);
    assert.match(admin, /MAX_SOURCES\s*=\s*16/);
    assert.doesNotMatch(matcher, /preg_match\s*\(\s*\$|eval\s*\(|new Function/);
});

test('uses one non-blocking external-state preview slot with cooldown and exact file identity checks', () => {
    const lock = read('Service/PreviewLock.php');
    const config = read('Service/ConfigRepository.php');
    const admin = read('Service/AdminService.php');
    assert.match(lock, /LOCK_EX \| LOCK_NB/);
    assert.match(lock, /COOLDOWN_SECONDS\s*=\s*30/);
    assert.match(lock, /extensions\/PikaCatalogHub/);
    assert.match(lock, /0o600/);
    assert.match(lock, /\['nlink'\]\s*!==\s*1/);
    assert.match(config, /PathGuard::stateDirectory\('extensions\/PikaCatalogHub', 0o700\)/);
    assert.match(admin, /try\s*\{[\s\S]+finally\s*\{[\s\S]+->release\(\)/);
    assert.doesNotMatch([lock, config].join('\n'), /siteRoot\(\).*runtime\/local-extensions/);
});

test('bounds durable jobs and snapshots outside the public document root', () => {
    const jobs = read('Service/JobStore.php');
    const snapshots = read('Service/SnapshotStore.php');
    const cli = read('bin/worker.php');
    assert.match(jobs, /MAX_JOBS\s*=\s*64/);
    assert.match(jobs, /SCHEMA\s*=\s*4/);
    assert.match(jobs, /snapshot_gc/);
    assert.match(jobs, /retirementCandidate/);
    assert.match(jobs, /foreach \(\$candidates as \$candidate\)/);
    assert.match(jobs, /requireSnapshotGcClear/);
    assert.match(jobs, /快照清理尚未完成，拒绝开始新工作/);
    assert.match(jobs, /pendingSnapshotGc/);
    assert.match(jobs, /clearSnapshotGc/);
    assert.match(snapshots, /retireBoundTerminal/);
    assert.match(snapshots, /retireUnboundTerminal/);
    assert.match(snapshots, /MAX_BYTES\s*=\s*16777216/);
    assert.match(snapshots, /MAX_ITEMS\s*=\s*10000/);
    assert.match(cli, /worker\.run\.lock/);
    assert.match(cli, /LOCK_EX \| LOCK_NB/);
    assert.match(cli, /ManagerState::isEnabled\(CATALOG_EXTENSION_ID\)/);
    assert.match(cli, /ManagerState::isEnabled\(SUPPLY_EXTENSION_ID\)/);
    assert.doesNotMatch([jobs, snapshots].join('\n'), /siteRoot\(\).*runtime\/local-extensions/);
});

test('public defaults contain only generic zero-write classification examples', () => {
    const schema = read('Service/ConfigSchema.php');
    assert.match(schema, /'aliases'\s*=>\s*\[\]/);
    for (const keyword of ['GPT', 'Claude', 'Facebook', 'BM', 'Instagram']) {
        assert.match(schema, new RegExp(`['"]${keyword}['"]`));
    }
    assert.doesNotMatch(schema, /货源A|货源B|premium|10%/);
});

test('retrying unimported items reuses the guarded task control without accepting a public compatibility proof', () => {
    const controller = fs.readFileSync(path.join(root, 'manager/site/app/Controller/Admin/Api/LocalExtensions.php'), 'utf8');
    const control = controller.match(/public function catalogHubTaskControl[\s\S]+?private function positiveInteger/)?.[0] || '';
    assert.match(control, /RequestGuard::mutation\(\$request, \$this->getManage\(\)\)/);
    assert.match(control, /\['pause', 'resume', 'retry_failed', 'cancel'\]/);
    assert.match(control, /->control\(\$taskId, \$revision, \$action\)/);
    assert.match(control, /只有有未解决清单的异常结束或失败上限任务可以补处理。/);
    assert.deepEqual([...control.matchAll(/unsafePost\('([^']+)'\)/g)].map(match => match[1]).sort(), ['action', 'revision', 'task_id']);
    assert.doesNotMatch(control, /proof|unsafe.*compat|->import\(|->items\(|->item\(/i);
});

test('release payload registers CatalogHub without changing the official bridge boundary', () => {
    const release = JSON.parse(fs.readFileSync(path.join(root, 'release.json'), 'utf8'));
    assert.deepEqual(release.extensions, ['PikaSupplySync', 'PikaCatalogHub', 'PikaSharedAccess', 'PikaOrderReturnWait']);
    const bridge = fs.readFileSync(path.join(root, 'bridge/3.6.4/local-extensions.patch'), 'utf8');
    assert.doesNotMatch(bridge, /PikaCatalogHub/);
});
