import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const require = createRequire(import.meta.url);
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const extensionRoot = path.join(root, 'extensions/PikaOrderReturnWait');
const manifest = JSON.parse(fs.readFileSync(path.join(extensionRoot, 'local-extension.json'), 'utf8'));
const javascriptPath = path.join(extensionRoot, 'Assets/order-return-wait.js');
const javascript = fs.readFileSync(javascriptPath, 'utf8');
const api = require(javascriptPath);

function sourcesBelow(directory) {
    return fs.readdirSync(directory, {withFileTypes: true}).flatMap(entry => {
        const absolute = path.join(directory, entry.name);
        return entry.isDirectory() ? sourcesBelow(absolute) : [absolute];
    });
}

test('OrderReturnWait declares only the LocalExtensions bootstrap, hooks and public assets', () => {
    assert.equal(manifest.schema, 1);
    assert.equal(manifest.id, 'PikaOrderReturnWait');
    assert.equal(manifest.name, '订单支付结果等待');
    assert.equal(manifest.description, '支付返回后，在原订单页短暂等待并刷新付款结果；不处理支付或发货。');
    assert.equal(manifest.type, 'plugin');
    assert.equal(manifest.namespace, 'Pika\\LocalExtensions\\PikaOrderReturnWait\\');
    assert.equal(manifest.bootstrap, 'bootstrap.php');
    assert.deepEqual(manifest.hooks, [
        {
            point: 560,
            class: 'Pika\\LocalExtensions\\PikaOrderReturnWait\\Hook\\OrderReturnWait',
            method: 'guestOrderPage',
            priority: 100,
        },
        {
            point: 304,
            class: 'Pika\\LocalExtensions\\PikaOrderReturnWait\\Hook\\OrderReturnWait',
            method: 'memberOrderPage',
            priority: 100,
        },
    ]);
    assert.deepEqual(manifest.assets, [{
        source: 'Assets',
        target: 'assets/local-extensions/PikaOrderReturnWait',
    }]);
    assert.deepEqual(manifest.settings, [
        {
            key: 'wait_seconds', label: '最长等待秒数', type: 'number', required: true,
            default: 15, min: 5, max: 120,
        },
        {
            key: 'refresh_seconds', label: '页面刷新间隔', type: 'number', required: true,
            default: 2, min: 1, max: 10,
        },
    ]);

    for (const relative of [
        'bootstrap.php',
        'Hook/OrderReturnWait.php',
        'Service/WaitPage.php',
        'Assets/order-return-wait.js',
        'Assets/order-return-wait.css',
    ]) {
        assert.ok(fs.statSync(path.join(extensionRoot, relative)).isFile(), `missing ${relative}`);
    }
});

test('extension no longer depends on the official plugin lifecycle or STATUS', () => {
    const source = sourcesBelow(extensionRoot)
        .filter(file => /\.(?:php|json|js|md)$/.test(file))
        .map(file => fs.readFileSync(file, 'utf8'))
        .join('\n');

    assert.doesNotMatch(source, /App\\Plugin\\PikaOrderReturnWait/);
    assert.doesNotMatch(source, /Kernel\\Annotation\\Plugin|Kernel\\Annotation\\Hook/);
    assert.doesNotMatch(source, /getPluginConfig|PluginState::|['"]STATUS['"]/);
    assert.doesNotMatch(source, /\/app\/Plugin\/PikaOrderReturnWait/);
    assert.match(source, /\/assets\/local-extensions\/PikaOrderReturnWait\//);
    assert.match(source, /ConfigStore::get\('PikaOrderReturnWait'\)/);
});

test('waiting is non-blocking and stays on official query endpoints', () => {
    assert.doesNotMatch(javascript, /\bsleep\s*\(|\busleep\s*\(|Atomics\.wait/);
    assert.doesNotMatch(javascript, /\bfetch\s*\(|XMLHttpRequest|util\.post\s*\(/);
    assert.match(javascript, /window\.location\.reload\(\)/);
    assert.match(javascript, /\/user\/api\/index\/query/);
    assert.match(javascript, /\/user\/api\/purchaseRecord\/data/);
});

test('password-protected query results resolve without reading or rendering the secret', () => {
    const tradeNo = '202608301234567890';
    const protectedPaid = {
        trade_no: tradeNo,
        status: 1,
        pay_id: 7,
        password: true,
        secret: '<img src=x onerror=alert(1)>',
    };

    assert.equal(api.isResolved(protectedPaid, tradeNo), true);
    assert.equal(api.isRemotePending(protectedPaid, tradeNo), false);
    assert.equal(api.exactOrder([protectedPaid], tradeNo), protectedPaid);
    assert.equal(Object.hasOwn(api, 'showSecret'), false);
    assert.doesNotMatch(javascript, /\.secret\b|leave_message|\/user\/api\/index\/secret/);
});

test('refresh schedule and persisted state remain bounded and fail closed', () => {
    const schedule = api.normalizedSchedule(120, 1);
    assert.equal(schedule.maxReloads, 15);
    assert.equal(schedule.refreshMilliseconds, 8000);
    assert.ok(Math.ceil(schedule.waitMilliseconds / schedule.refreshMilliseconds) <= 15);
    assert.deepEqual(api.parseWaitState('', 1_000_000, 15_000), {deadline: 1_015_000, reloads: 0});
    assert.equal(api.parseWaitState('{bad-json', 1_000_000, 15_000), null);
    assert.equal(api.parseWaitState('{"deadline":1015000,"reloads":16}', 1_000_000, 15_000), null);
});
