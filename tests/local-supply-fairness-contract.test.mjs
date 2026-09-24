import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const extensionRoot = path.join(root, 'extensions/PikaSupplySync');
const service = file => fs.readFileSync(
    path.join(extensionRoot, 'Service', file),
    'utf8',
);

test('applies independent source budgets under the retained round hard cap', () => {
    const budget = service('RunBudget.php');
    const sync = service('SyncService.php');
    const state = service('StateStore.php');
    const http = service('SafeHttpClient.php');
    const cli = fs.readFileSync(path.join(extensionRoot, 'bin/sync.php'), 'utf8');

    assert.match(budget, /MAX_WALL_SECONDS\s*=\s*300/);
    assert.match(budget, /MAX_IMAGE_DOWNLOADS\s*=\s*100/);
    assert.match(budget, /MAX_SOURCE_IMAGE_DOWNLOADS\s*=\s*25/);
    assert.match(budget, /function beginSource\(int \$sourceId\)/);
    assert.match(budget, /function endSource\(\)/);
    assert.match(budget, /function remainingMilliseconds\(int \$minimum = 1\): int/);
    assert.match(sync, /foreach \(\$sourceIds as \$sourceId\)[\s\S]+beginSource\(\$sourceId\)/);
    assert.match(sync, /catch \(BudgetExceeded \$exception\)[\s\S]+\$stopRound = !\$exception->isSource\(\)/);
    assert.match(sync, /if \(\$sourceBudgetActive && \$targetHashes === null\) \{\s*\$stateStore->markSourceAttempted\(\$sourceId\);/);
    assert.match(state, /function orderSources\(array \$sourceIds\)/);
    assert.match(state, /rotation\.json/);
    assert.match(http, /\[\$connectTimeoutMs, \$requestTimeoutMs\]\s*=\s*\$this->attemptTimeouts\(\)/);
    assert.match(http, /CURLOPT_CONNECTTIMEOUT_MS\s*=>\s*\$connectTimeoutMs/);
    assert.match(http, /CURLOPT_TIMEOUT_MS\s*=>\s*\$requestTimeoutMs/);
    assert.match(http, /catch \(BudgetExceeded \$exception\) \{\s*throw \$exception;/);
    assert.match(http, /function backoff\(int \$attempt, int \$retryAfterMs\): void[\s\S]+remainingMilliseconds\(\$milliseconds \+ 1\)[\s\S]+\$this->sleep/);
    assert.match(http, /\$this->budget->checkpoint\(\)/);
    assert.match(cli, /new SafeHttpClient\(\$policy, null, \$budget\)/);
    assert.match(cli, /\$images\s*=\s*new ImageCache\(\$http, \$budget\)/);
    assert.match(cli, /new SyncService\([\s\S]+\$images,[\s\S]+\$budget,/);
});

test('never claims categories by a coincidentally equal name', () => {
    const category = service('CategoryMapper.php');

    assert.match(category, /\[PikaSupplySync:S/);
    assert.match(category, /mappingMatches/);
    assert.match(category, /\$actualPid !== \$expectedPid/);
    assert.doesNotMatch(category, /where\(['"]name['"]/);
    assert.doesNotMatch(category, /whereNull\(['"]pid['"]\)[\s\S]+first\(\)/);
});

test('counts only a created concurrent import as imported', () => {
    const importer = service('CommodityImporter.php');
    const sync = service('SyncService.php');

    assert.match(importer, /OUTCOME_CREATED\s*=\s*'created'/);
    assert.match(importer, /OUTCOME_ALREADY_MANAGED\s*=\s*'already_managed'/);
    assert.match(importer, /OUTCOME_HELD_EXISTING_UNMANAGED\s*=\s*'held_existing_unmanaged'/);
    assert.match(importer, /if \(\$existing\) \{[\s\S]+\$outcome = \$this->existingOutcome\(\$existing\);/);
    assert.match(importer, /OUTCOME_ALREADY_MANAGED[\s\S]+\$existing->category_id = \$categoryId/);
    assert.match(importer, /return \$outcome;/);
    assert.match(sync, /OUTCOME_CREATED\s*=>\s*'import'/);
    assert.match(sync, /OUTCOME_ALREADY_MANAGED\s*=>\s*'already_managed'/);
    assert.match(sync, /OUTCOME_HELD_EXISTING_UNMANAGED\s*=>\s*'held_existing_unmanaged'/);
});

test('checkpoints and commits each lane only through its last completed action', () => {
    const planner = service('CatalogPlanner.php');
    const sync = service('SyncService.php');

    assert.match(planner, /'lane'\s*=>\s*isset\(\$prioritySelection\[\$code\]\)\s*\?\s*'priority'\s*:\s*'normal'/);
    assert.match(sync, /\$completedCursor\s*=\s*\(string\)\$state\['cursor'\]/);
    assert.match(sync, /\$completedPriorityCursor\s*=\s*\(string\)\$state\['priority_cursor'\]/);
    assert.match(sync, /\$this->budget->checkpoint\(\);[\s\S]+\$completedPriorityCursor\s*=\s*\$code/);
    assert.match(sync, /\$persistedCursor\s*=\s*\$budgetExhausted\s*\?\s*\$completedCursor\s*:\s*\$plan\['next_cursor'\]/);
    assert.match(sync, /\$persistedPriorityCursor\s*=\s*\$budgetExhausted[\s\S]+\$completedPriorityCursor[\s\S]+\$plan\['next_priority_cursor'\]/);
});

test('fails malformed V4 stock closed and does not hide budget or cache integrity failures', () => {
    const gateway = service('SharedGateway.php');
    const image = service('ImageCache.php');
    const item = service('RemoteItem.php');
    const wiki = fs.readFileSync(path.join(extensionRoot, 'Wiki/README.md'), 'utf8');

    assert.match(gateway, /array_key_exists\('stock', \$entry\)/);
    assert.match(gateway, /\$stockFields === 0[\s\S]+\$stock = 10000000/);
    assert.match(gateway, /\$stockFields !== count\(\$sku\)/);
    assert.match(gateway, /if \(!is_int\(\$value\) \|\| \$value < 0 \|\| \$value > 2147483647\)/);
    assert.match(gateway, /isset\(\$seenNames\[\$key\]\) \|\| isset\(\$seenIds\[\$skuId\]\)/);
    assert.match(image, /catch \(BudgetExceeded \$exception\) \{\s*throw \$exception;/);
    assert.match(image, /throw new RemoteCoverUnavailable\('远端封面下载失败'/);
    assert.match(item, /catch \(BudgetExceeded \$exception\) \{\s*throw \$exception;/);
    assert.match(item, /catch \(RemoteCoverUnavailable \$exception\) \{\s*if \(\$refresh\) throw \$exception;\s*return '\/favicon\.ico';/);
    assert.doesNotMatch(item, /catch \(\\Throwable\)/);
    assert.match(wiki, /这不是进程硬截止/);
    assert.match(wiki, /systemd 模板另设 8 分钟外层上限/);
    assert.match(wiki, /真实 unit 的截止行为仍须在 S0 canary 验证/);
});
