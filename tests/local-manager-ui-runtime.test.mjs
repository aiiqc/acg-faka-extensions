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
