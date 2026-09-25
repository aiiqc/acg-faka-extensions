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

test('manager UI separates failed products, diagnostic totals and upstream declarations', { timeout: 1000 }, async () => {
  const text = await renderedStatus(statusEntry({
    status: 'partial', failed: 2, error_total: 23, errors_truncated: true,
    errors: [{ code_hash: 'abcdef123456', message: '远端返回业务失败', diagnostics: {
      category: 'business', stage: 'detail', http_status: 200, curl_code: 0, attempts: 1,
      response_structure: { business_code: -1, data_type: 'null' }, remote_reason: 'not_shared',
      timings_ms: { connect: 9, tls: 17, first_byte: 43, total: 48 }, received_bytes: 62,
    } }],
  }));
  assert.match(text, /失败／待确认 2/);
  assert.match(text, /诊断事件总数 23；展示 1 条/);
  assert.match(text, /记录已截断或有不可读条目/);
  assert.match(text, /上游返回的声明，未核实根因：商品未共享/);
  assert.match(text, /HTTP 200；业务码 -1/);
  assert.match(text, /HTTP 200 不代表业务成功/);
  assert.match(text, /DNS 未取得/);
  assert.match(text, /各值从该次 curl 开始累计，不是独立阶段耗时/);
  assert.match(text, /不含连接策略在 curl 外部执行的 DNS 查询/);
});

test('manager UI bounds item and request observations without displaying raw secrets', { timeout: 1000 }, async () => {
  const request = { stage: 'detail', category: 'business', remote_reason: 'secret-sentinel',
    response_structure: { business_code: 'secret-sentinel', data_type: 'secret-sentinel' },
    attempt_history: Array.from({ length: 5 }, () => ({ http_status: 200, timings_ms: { total: 10 } })),
  };
  const text = await renderedStatus(statusEntry({ status: 'partial', failed: 4, error_total: 21, errors_truncated: true,
    errors: Array.from({ length: 21 }, (_, index) => ({ code_hash: index === 20 ? 'ffffffffffff' : 'abcdef123456',
      message: 'secret-sentinel', diagnostics: request })),
    request_diagnostics: { detail: { count: 9, last: request, last_failure: request },
      unexpected: { count: 999, last: request } },
  }));
  assert.doesNotMatch(text, /secret-sentinel|ffffffffffff/);
  assert.match(text, /诊断事件总数 21；展示 20 条/);
  assert.match(text, /详情请求次数 9/);
  assert.match(text, /最近一次请求/);
  assert.match(text, /最近一次失败请求/);
  assert.doesNotMatch(text, /第 4 次尝试|第 5 次尝试/);
  assert.match(text, /最近记录不是平均值、p95 或完整请求历史/);
});

test('manager UI keeps old failure counts when item details or timing evidence is absent', { timeout: 1000 }, async () => {
  const text = await renderedStatus(statusEntry({ status: 'partial', failed: 4 }));
  assert.match(text, /失败／待确认 4/);
  assert.match(text, /未取得逐项诊断，不能从失败数推断原因/);
  assert.doesNotMatch(text, /诊断事件总数 0|DNS 0|HTTP 0/);
});

test('manager UI distinguishes saved noncover products, media actions and image quota scopes', { timeout: 1000 }, async () => {
  const fieldSync = { trade_planned: 4, noncover_saved: 5, media_planned: 3, media_attempted: 2,
    media_refreshed: 1, media_failed: 1, media_deferred: 1, image_quota_scope: 'source' };
  for (const [scope, label] of [['none', '本批未触发限制'], ['source', '本货源媒体受限'], ['round', '本轮媒体受限']]) {
    const text = await renderedStatus(statusEntry({ status: 'partial', field_sync: { ...fieldSync, image_quota_scope: scope },
      error_total: 1, errors: [{ message: '图片配额已耗尽：保留原图，其他有效字段继续按时间和文本预算处理' }] }));
    assert.match(text, /部分完成/);
    assert.match(text, /常规计划动作 4；非图片保存 5；媒体已复查 2／3；已刷新 1；失败 1；未完成 1/);
    assert.match(text, new RegExp(`图片配额影响：${label}`));
    assert.match(text, /常规与媒体计划动作可重叠同一商品/);
    assert.match(text, /非图片保存按不同商品计数/);
    assert.match(text, /可能来自媒体首次详情，不代表价格、库存、规格全部成功/);
    assert.match(text, /媒体刷新也不代表六项字段全部成功/);
    assert.match(text, /未完成仅指本批已选媒体/);
    assert.match(text, /不是全目录积压，也未建立补查任务/);
    assert.match(text, /图片配额不等于时间预算耗尽/);
    assert.match(text, /记录不代表完整覆盖或时限保证/);
    assert.match(text, /图片配额已耗尽：保留原图，其他有效字段继续按时间和文本预算处理/);
  }
});

test('manager UI keeps unavailable field evidence unknown and bounds each observation', { timeout: 1000 }, async () => {
  for (const fieldSync of [undefined, null, 'secret-sentinel', [], false]) {
    const text = await renderedStatus(statusEntry({ field_sync: fieldSync }));
    assert.match(text, /字段分层：未记录；非图片保存与媒体复查结果未知/);
    assert.doesNotMatch(text, /非图片保存 0|媒体已复查 0|secret-sentinel/);
  }
  const text = await renderedStatus(statusEntry({ field_sync: { trade_planned: 501, noncover_saved: '5',
    media_planned: 500, media_attempted: -1, media_refreshed: true, media_failed: 0.5,
    media_deferred: null, image_quota_scope: 'secret-sentinel', secret: 'secret-sentinel' } }));
  assert.match(text, /常规计划动作 未知；非图片保存 未知；媒体已复查 未知／500；已刷新 未知；失败 未知；未完成 未知/);
  assert.match(text, /图片配额影响：未知/);
  assert.doesNotMatch(text, /secret-sentinel/);
});

test('manager UI preserves error, all-disabled and preview outcomes with field observations', { timeout: 1000 }, async () => {
  const fieldSync = { trade_planned: 0, noncover_saved: 0, media_planned: 0, media_attempted: 0,
    media_refreshed: 0, media_failed: 0, media_deferred: 0, image_quota_scope: 'none' };
  const failed = await renderedStatus(statusEntry({ status: 'error', field_sync: fieldSync }));
  assert.match(failed, /本轮失败/);
  assert.doesNotMatch(failed, /部分完成|批次完成/);
  const disabled = await renderedStatus(statusEntry({ planned: 0, field_sync: fieldSync,
    applied: { sync: 0, import: 0, zero: 0, held_race: 0, held_unknown: 0 } }));
  assert.match(disabled, /本批无动作，不代表全部商品已同步/);
  const preview = await renderedStatus(statusEntry({ kind: 'preview', field_sync: { ...fieldSync,
    media_planned: 2, media_deferred: 2 } }), 'preview');
  assert.match(preview, /最近只读预演记录（不写商品）：批次完成/);
  assert.match(preview, /媒体已复查 0／2；已刷新 0；失败 0；未完成 2/);
});
