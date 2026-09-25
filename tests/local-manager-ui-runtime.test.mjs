import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = await readFile(
  path.join(root, 'manager/site/assets/admin/controller/local-extensions/index.js'),
  'utf8',
);

async function reportedError(response) {
  let resolveMessage;
  const message = new Promise(resolve => { resolveMessage = resolve; });
  const context = {
    URLSearchParams,
    fetch: async () => response,
    document: {
      getElementById: () => ({ dataset: { csrf: 'fixture' }, replaceChildren() {} }),
      createElement: () => { throw new Error('unexpected DOM render on error path'); },
    },
    window: { message: { error: value => resolveMessage(String(value)) } },
    $: () => ({ one() {} }),
  };
  vm.runInNewContext(source, context, { filename: 'local-extensions/index.js' });
  return Promise.race([
    message,
    new Promise((_, reject) => setTimeout(() => reject(new Error('UI error was not reported')), 1000)),
  ]);
}

test('manager UI rejects an HTML 500 without reading or exposing its body', async () => {
  let parsed = false;
  const message = await reportedError({
    ok: false,
    headers: { get: () => 'text/html; charset=utf-8' },
    json: async () => { parsed = true; return {}; },
  });
  assert.equal(parsed, false);
  assert.equal(message, '服务暂时不可用，请刷新页面后重试');
});

test('manager UI reports malformed JSON with a stable local message', async () => {
  const message = await reportedError({
    ok: false,
    headers: { get: () => 'application/json' },
    json: async () => { throw new SyntaxError('private response fragment'); },
  });
  assert.equal(message, '服务返回格式异常，请刷新页面后重试');
});

test('manager UI preserves a valid JSON application error', async () => {
  const message = await reportedError({
    ok: true,
    headers: { get: () => 'application/json; charset=utf-8' },
    json: async () => ({ code: 0, msg: '本地扩展状态更新失败，请检查扩展安装与运行状态' }),
  });
  assert.equal(message, '本地扩展状态更新失败，请检查扩展安装与运行状态');
});

async function renderedStatus(entry, kind = 'actual') {
  let resolveRender;
  let rejectRender;
  const rendered = new Promise((resolve, reject) => { resolveRender = resolve; rejectRender = reject; });
  const element = () => ({
    dataset: {}, children: [], textContent: '',
    append(...children) { this.children.push(...children); },
    replaceChildren(...children) { this.children = children; },
    setAttribute() {}, addEventListener() {},
  });
  const text = node => [node.textContent, ...node.children.map(text)].join('\n');
  const rootElement = element();
  rootElement.dataset.csrf = 'fixture';
  rootElement.replaceChildren = (...children) => {
    rootElement.children = children;
    resolveRender(text(rootElement));
  };
  vm.runInNewContext(source, {
    URLSearchParams,
    fetch: async () => ({
      ok: true, headers: { get: () => 'application/json' },
      json: async () => ({ code: 200, data: { list: [{
        id: 'PikaSupplySync', name: 'Supply Sync', version: 'fixture', enabled: false,
        sync_status: { availability: 'available', incomplete: false,
          sources: [{ source_id: 1, [kind]: entry }] },
      }] } }),
    }),
    document: { getElementById: () => rootElement, createElement: element },
    window: { message: { error: value => rejectRender(new Error(String(value))) } },
    $: () => ({ one() {} }),
  }, { filename: 'local-extensions/index.js' });
  return rendered;
}

const statusEntry = overrides => ({
  kind: 'actual', status: 'ok', mode: 'basic', recorded_at: '2026-09-25T00:00:00+00:00',
  timezone: 'UTC', origin: 'log', planned: 1, planned_held_unknown: 0, catalog_unknown: 0,
  applied: { sync: 1, import: 0, zero: 0, held_race: 0, held_unknown: 0 }, failed: 0,
  ...overrides,
});

test('manager UI separates catalog unknown stock from selected and applied protection', { timeout: 1000 }, async () => {
  const text = await renderedStatus(statusEntry({
    status: 'partial', planned: 3, planned_held_unknown: 1, catalog_unknown: 4,
    applied: { sync: 1, import: 0, zero: 0, held_race: 0, held_unknown: 2 },
  }));
  assert.match(text, /部分完成/);
  assert.match(text, /同步保存 1；新建 0；库存清零 0；未知库存保护 2；失败／待确认 0/);
  assert.match(text, /目录库存未知 4 项/);
  assert.match(text, /本批选中未知库存 1 项/);
  assert.match(text, /保护计数包含详情转为未知/);
  assert.doesNotMatch(text, /目录与详情库存不一致，暂缓/);
});

test('manager UI does not present selected unknown stock as success or a generic skip', { timeout: 1000 }, async () => {
  for (const kind of ['actual', 'preview']) {
    const text = await renderedStatus(statusEntry({
      kind, planned_held_unknown: 1, catalog_unknown: 3,
      applied: { sync: 0, import: 0, zero: 0, held_race: 0, held_unknown: kind === 'actual' ? 1 : 0 },
    }), kind);
    assert.match(text, /部分完成/);
    assert.match(text, new RegExp(`未知库存保护 ${kind === 'actual' ? 1 : 0}`));
    assert.doesNotMatch(text, /批次完成|本批未保存商品|本批无动作/);
  }
});

test('manager UI retains catalog-only, historical success and error outcomes', { timeout: 1000 }, async () => {
  const catalogOnly = await renderedStatus(statusEntry({ catalog_unknown: 2 }));
  assert.match(catalogOnly, /批次完成/);
  assert.match(catalogOnly, /本批选中未知库存 0 项/);
  const legacy = statusEntry({ planned: 0, applied: { sync: 0, import: 0, zero: 0, held_race: 0 } });
  delete legacy.catalog_unknown;
  delete legacy.planned_held_unknown;
  assert.match(await renderedStatus(legacy), /本批无动作，不代表全部商品已同步/);
  const failed = await renderedStatus(statusEntry({
    status: 'error', failed: 1, planned_held_unknown: 1, catalog_unknown: 2,
    catalog_diagnostic: { category: 'schema', http_status: 200, curl_code: 0, elapsed_ms: 20, attempts: 1 },
  }));
  assert.match(failed, /本轮失败/);
  assert.match(failed, /目录响应结构不正确/);
  assert.doesNotMatch(failed, /部分完成|批次完成/);
});
