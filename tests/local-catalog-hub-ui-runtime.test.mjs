import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const rootPath = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = await readFile(
    path.join(rootPath, 'manager/site/assets/admin/controller/local-extensions/catalog-hub.js'),
    'utf8',
);

const dataName = selector => selector
    .slice('[data-'.length, -1)
    .replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());

class FakeElement {
    constructor(tag = 'div', ownerDocument = null) {
        this.tag = tag;
        this.ownerDocument = ownerDocument;
        this.dataset = {};
        this.children = [];
        this.parent = null;
        this.textContent = '';
        this.className = '';
        this.id = '';
        this.value = '';
        this.disabled = false;
        this.open = false;
        this.listeners = {};
    }

    append(...children) {
        children.forEach(child => {
            if (child instanceof FakeElement) child.parent = this;
            this.children.push(child);
        });
    }

    replaceChildren(...children) {
        if (this.children.some(child => child instanceof FakeElement && child.contains(this.ownerDocument?.activeElement))) {
            this.ownerDocument.activeElement = this.ownerDocument.body;
        }
        this.children.forEach(child => {
            if (child instanceof FakeElement) child.parent = null;
        });
        this.children = [];
        this.append(...children);
    }

    addEventListener(type, listener) { this.listeners[type] = listener; }

    contains(element) {
        for (let current = element; current; current = current.parent) {
            if (current === this) return true;
        }
        return false;
    }

    focus(options) {
        if (this.disabled || !this.ownerDocument) return;
        this.ownerDocument.activeElement = this;
        this.ownerDocument.focusCalls.push({element: this, preventScroll: options?.preventScroll});
    }

    remove() {
        if (!this.parent) return;
        this.parent.children = this.parent.children.filter(child => child !== this);
        this.parent = null;
    }

    querySelectorAll(selector) {
        const matches = [];
        const predicate = selector.startsWith('[data-') && selector.endsWith(']')
            ? element => Object.prototype.hasOwnProperty.call(element.dataset, dataName(selector))
            : selector.startsWith('#')
                ? element => element.id === selector.slice(1)
                : () => false;
        const visit = element => {
            for (const child of element.children || []) {
                if (!(child instanceof FakeElement)) continue;
                if (predicate(child)) matches.push(child);
                visit(child);
            }
        };
        visit(this);
        return matches;
    }

    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}

function findElement(root, predicate) {
    if (predicate(root)) return root;
    for (const child of root.children || []) {
        if (!(child instanceof FakeElement)) continue;
        const match = findElement(child, predicate);
        if (match) return match;
    }
    return null;
}

function findByDataset(root, name) {
    return findElement(root, element => Object.prototype.hasOwnProperty.call(element.dataset, name));
}

function textValues(root) {
    const values = root.textContent ? [root.textContent] : [];
    for (const child of root.children || []) {
        if (child instanceof FakeElement) values.push(...textValues(child));
    }
    return values;
}

const jsonResponse = (payload, ok = true) => ({
    ok,
    headers: {get: () => 'application/json; charset=utf-8'},
    json: async () => payload,
});

const defaultBootstrap = {
    code: 200,
    data: {sources: [], settings: {schema: 1, aliases: [], rules: []}},
};

function createRuntime(fetchImpl, options = {}) {
    const root = new FakeElement();
    root.dataset.csrf = 'fixture-csrf';
    const timers = [];
    const clearedTimers = [];
    let nextTimerId = 1;
    let pjaxHandler = null;
    const document = {
        activeElement: null,
        focusCalls: [],
        getElementById: id => id === 'catalog-hub-root'
            ? root
            : findElement(root, element => element.id === id),
        createElement: tag => new FakeElement(tag, document),
    };
    document.body = new FakeElement('body', document);
    document.activeElement = document.body;
    root.ownerDocument = document;
    document.body.append(root);
    const context = {
        AbortController,
        URL,
        URLSearchParams,
        fetch: fetchImpl,
        document,
        window: {message: {error() {}}},
        setTimeout: (callback, delay) => {
            const timer = {id: nextTimerId++, callback, delay};
            timers.push(timer);
            return timer.id;
        },
        clearTimeout: id => clearedTimers.push(id),
        $: () => ({
            one(_event, callback) { pjaxHandler = callback; },
        }),
        ...options.context,
    };
    vm.runInNewContext(source, context, {filename: 'catalog-hub.js'});
    return {
        root,
        document,
        timers,
        clearedTimers,
        triggerPjax: () => pjaxHandler?.(),
    };
}

const flush = async (count = 4) => {
    for (let index = 0; index < count; index += 1) {
        await new Promise(resolve => setImmediate(resolve));
    }
};

const draftScope = 'd'.repeat(64);
const draftPrefix = `pika-catalog-draft:v1:${draftScope}:`;
const fixtureSource = id => ({id, name: `Source ${id}`, alias: `测试货源${id}`, type: 0, domain: `https://source-${id}.example`, app_id: `fixture-${id}`, currency: 'CNY', currency_rate: '0'});
const waitingTask = (id = 1, extra = {}) => ({
    task_id: `waiting-${id}`, source_id: id, source_alias: `测试货源${id}`, state: 'awaiting_confirmation',
    phase: 'analysis', revision: 3, premium_percent: null,
    snapshot: {sha256: 'a'.repeat(64), plan_hash: 'b'.repeat(64), item_count: 2},
    categories: [{name: `上游分类${id}`, count: 2, confidence: 'high', target: {group: '默认一级', family: '默认二级'}}],
    ...extra,
});
const taskDraftKey = task => `${draftPrefix}task:${task.task_id}:${task.source_id}:${task.snapshot?.plan_hash || ''}:${task.snapshot?.sha256 || ''}`;
function memoryStorage(initial = []) {
    const records = new Map(initial);
    return {
        records,
        get length() { return records.size; },
        key(index) { return [...records.keys()][index] ?? null; },
        getItem(key) { return records.get(key) ?? null; },
        setItem(key, value) { records.set(key, String(value)); },
        removeItem(key) { records.delete(key); },
    };
}
function draftRuntime({storage = memoryStorage(), scope = draftScope, sources = [fixtureSource(1)], tasks = [], handle} = {}) {
    const calls = [];
    const runtime = createRuntime(async (url, request) => {
        calls.push({url, request});
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse({code: 200, data: {draft_scope: scope, sources, settings: {schema: 1, aliases: [], rules: []}}});
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: typeof tasks === 'function' ? tasks() : tasks}});
        if (handle) return handle(url, request);
        throw new Error(`unexpected URL: ${url}`);
    }, {context: {window: {sessionStorage: storage}}});
    return {...runtime, calls, storage};
}
function enter(element, value) {
    element.value = value;
    element.listeners.input?.();
}
const confirmationButton = runtime => findElement(runtime.root, element => element.textContent === '确认并后台入库');

const csrfRejected = () => jsonResponse({code: 419, msg: '本地扩展 CSRF 校验失败，请刷新页面', data: {error_code: 'LOCAL_EXTENSIONS_CSRF_INVALID'}});
const refreshedCsrf = '1002.' + 'a'.repeat(64);
const importingTask = () => waitingTask(2, {state: 'queued_import', phase: 'import', progress: {total: 100, processed: 20, succeeded: 18, skipped: 2, failed: 0}});
const disclosureTask = (id = 1, extra = {}) => waitingTask(id, {
    state: 'importing', phase: 'import',
    progress: {total: 10, processed: 1, succeeded: 0, skipped: 0, failed: 1},
    item_failures: [{index: 0, code: 'ITEM_DETAIL_HTTP_RETRYABLE', attempts: 3}],
    last_detail_diagnostic: {index: 0, diagnostics: {category: 'json', json_valid: false}},
    ...extra,
});
const taskCard = (runtime, id = 'waiting-1') => runtime.document.getElementById(`catalog-hub-task-${id}`);
const taskDisclosure = (runtime, label, id = 'waiting-1') => findElement(taskCard(runtime, id),
    element => element.tag === 'details' && element.children[0]?.textContent.startsWith(label));
const taskAction = (runtime, action, id = 'waiting-1') => taskCard(runtime, id).querySelectorAll('[data-task-action]')
    .find(button => button.dataset.taskAction === action);
const fireTaskPoll = runtime => {
    const timer = runtime.timers.findLast(timer => timer.delay === 3000 && !timer.fired && !runtime.clearedTimers.includes(timer.id));
    assert.ok(timer, 'an active task must have a pending poll');
    timer.fired = true;
    timer.callback();
};

test('task disclosures and summary focus survive two changing polls, reordering and active-to-failed state', async () => {
    let tasks = [disclosureTask(), disclosureTask(2)];
    const runtime = draftRuntime({tasks: () => tasks});
    await flush();
    const labels = ['未导入清单', '任务详情', '最近详情诊断'];
    labels.forEach(label => { taskDisclosure(runtime, label).open = true; });
    taskDisclosure(runtime, '最近详情诊断').children[0].focus();
    const oldSummary = runtime.document.activeElement;
    tasks = [{...tasks[0], revision: 4, progress: {...tasks[0].progress, processed: 2, succeeded: 1}}, tasks[1]];
    fireTaskPoll(runtime);
    await flush();
    labels.forEach(label => assert.equal(taskDisclosure(runtime, label).open, true, `${label} collapsed after the first poll`));
    assert.notEqual(runtime.document.activeElement, oldSummary, 'the test must exercise replacement nodes');
    assert.ok(runtime.document.activeElement === taskDisclosure(runtime, '最近详情诊断').children[0], 'diagnostic summary focus was lost');
    assert.equal(runtime.document.focusCalls.at(-1).preventScroll, true);
    assert.match(textValues(taskCard(runtime)).join('\n'), /已处理 2\/10/);

    taskDisclosure(runtime, '未导入清单').open = false;
    tasks = [tasks[1], {...tasks[0], revision: 5, state: 'failed', error_code: 'ITEM_DETAIL_FETCH_FAILED', can_resume: true,
        progress: {...tasks[0].progress, processed: 3, succeeded: 2}}];
    fireTaskPoll(runtime);
    await flush();
    assert.equal(taskDisclosure(runtime, '未导入清单').open, false, 'an explicit close must survive the next poll');
    assert.equal(taskDisclosure(runtime, '任务详情').open, true);
    assert.equal(taskDisclosure(runtime, '最近详情诊断').open, true);
    assert.equal(taskDisclosure(runtime, '任务详情', 'waiting-2').open, false, 'reordering must not transfer another task\'s state');
    assert.ok(runtime.document.activeElement === taskDisclosure(runtime, '最近详情诊断').children[0], 'summary focus moved after task reordering');
    assert.equal(runtime.document.focusCalls.at(-1).preventScroll, true);
    assert.match(textValues(taskCard(runtime)).join('\n'), /已处理 3\/10.*任务已中断/);
    assert.equal(runtime.calls.filter(call => call.url.endsWith('catalogHubTasks')).length, 3);
    assert.equal(runtime.calls.some(call => /catalogHubTaskControl|catalogHubAnalyze|catalogHubConfirm/.test(call.url)), false);
    assert.equal(runtime.storage.length, 0, 'visual state must not enter draft storage');
});

test('polling keeps an enabled action focus by task and action but never redirects a removed action', async () => {
    let tasks = [disclosureTask(), disclosureTask(2)];
    const runtime = draftRuntime({tasks: () => tasks});
    await flush();
    taskAction(runtime, 'pause').focus();
    tasks = [tasks[1], {...tasks[0], revision: 4}];
    fireTaskPoll(runtime);
    await flush();
    assert.ok(runtime.document.activeElement === taskAction(runtime, 'pause'), 'the same enabled action must retain focus');
    assert.equal(runtime.document.focusCalls.at(-1).preventScroll, true);
    const focusCount = runtime.document.focusCalls.length;
    tasks = [tasks[0], {...tasks[1], state: 'failed', revision: 5, error_code: 'ITEM_DETAIL_FETCH_FAILED', can_resume: true}];
    fireTaskPoll(runtime);
    await flush();
    assert.equal(taskAction(runtime, 'pause'), undefined);
    assert.ok(taskAction(runtime, 'resume'), 'a replacement action exists but must not inherit focus');
    assert.ok(runtime.document.activeElement === runtime.document.body, 'a removed action must not redirect focus');
    assert.equal(runtime.document.focusCalls.length, focusCount);
});

test('a disappearing disclosure or task does not focus another section or retain stale open state', async () => {
    for (const removeTask of [false, true]) {
        const original = disclosureTask();
        const other = disclosureTask(2);
        let tasks = [original, other];
        const runtime = draftRuntime({tasks: () => tasks});
        await flush();
        taskDisclosure(runtime, '最近详情诊断').open = true;
        taskDisclosure(runtime, '最近详情诊断').children[0].focus();
        const focusCount = runtime.document.focusCalls.length;
        tasks = removeTask ? [other] : [{...original, last_detail_diagnostic: null, revision: 4}, other];
        fireTaskPoll(runtime);
        await flush();
        assert.ok(runtime.document.activeElement === runtime.document.body, 'a removed target must not redirect focus');
        assert.equal(runtime.document.focusCalls.length, focusCount);
        assert.equal(taskDisclosure(runtime, '最近详情诊断', 'waiting-2').open, false);
        tasks = [original, other];
        fireTaskPoll(runtime);
        await flush();
        assert.equal(taskDisclosure(runtime, '最近详情诊断').open, false, 'a reappearing section starts closed');
        assert.equal(runtime.document.focusCalls.length, focusCount, 'focus must not be replayed on a later response');
    }
});

test('a failed poll and its recovery preserve open details, legal focus and the last known progress', async () => {
    let reads = 0;
    const task = disclosureTask();
    const runtime = createRuntime(async url => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        assert.ok(url.endsWith('catalogHubTasks'));
        reads++;
        if (reads === 2) throw new Error('temporary poll failure');
        return jsonResponse({code: 200, data: {tasks: [{...task, revision: reads,
            progress: {...task.progress, processed: reads}}]}});
    });
    await flush();
    ['未导入清单', '任务详情', '最近详情诊断'].forEach(label => { taskDisclosure(runtime, label).open = true; });
    taskDisclosure(runtime, '未导入清单').children[0].focus();
    fireTaskPoll(runtime);
    await flush();
    assert.match(textValues(runtime.root).join('\n'), /temporary poll failure.*最后一次已获取的进度/);
    assert.match(textValues(taskCard(runtime)).join('\n'), /已处理 1\/10/);
    ['未导入清单', '任务详情', '最近详情诊断'].forEach(label => assert.equal(taskDisclosure(runtime, label).open, true));
    assert.ok(runtime.document.activeElement === taskDisclosure(runtime, '未导入清单').children[0], 'failed refresh lost summary focus');
    fireTaskPoll(runtime);
    await flush();
    assert.doesNotMatch(textValues(runtime.root).join('\n'), /temporary poll failure|最后一次已获取的进度/);
    assert.match(textValues(taskCard(runtime)).join('\n'), /已处理 3\/10/);
    ['未导入清单', '任务详情', '最近详情诊断'].forEach(label => assert.equal(taskDisclosure(runtime, label).open, true));
    assert.ok(runtime.document.activeElement === taskDisclosure(runtime, '未导入清单').children[0], 'recovery lost summary focus');
    assert.equal(runtime.document.focusCalls.at(-1).preventScroll, true);
    assert.equal(reads, 3);
});

test('a pending poll respects focus moved outside tasks and page cleanup prevents later restoration', async () => {
    let reads = 0;
    let finishPoll;
    let signal;
    const task = disclosureTask();
    const runtime = createRuntime(async (url, request) => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        assert.ok(url.endsWith('catalogHubTasks'));
        reads++;
        if (reads === 1) return jsonResponse({code: 200, data: {tasks: [task]}});
        signal = request.signal;
        return new Promise(resolve => { finishPoll = resolve; });
    });
    await flush();
    taskDisclosure(runtime, '任务详情').open = true;
    taskDisclosure(runtime, '任务详情').children[0].focus();
    fireTaskPoll(runtime);
    await flush(1);
    const outside = findByDataset(runtime.root, 'sourceFormAlias');
    outside.focus();
    let focusCount = runtime.document.focusCalls.length;
    finishPoll(jsonResponse({code: 200, data: {tasks: [{...task, revision: 4}]}}));
    await flush();
    assert.ok(runtime.document.activeElement === outside, 'polling must not steal focus from outside tasks');
    assert.equal(runtime.document.focusCalls.length, focusCount);
    assert.equal(taskDisclosure(runtime, '任务详情').open, true);

    taskDisclosure(runtime, '任务详情').children[0].focus();
    fireTaskPoll(runtime);
    await flush(1);
    const oldCard = taskCard(runtime);
    runtime.triggerPjax();
    assert.equal(signal.aborted, true);
    outside.focus();
    focusCount = runtime.document.focusCalls.length;
    finishPoll(jsonResponse({code: 200, data: {tasks: [{...task, revision: 5}]}}));
    await flush();
    assert.ok(taskCard(runtime) === oldCard, 'a late receipt after cleanup must not repaint the old page');
    assert.ok(runtime.document.activeElement === outside, 'cleanup must not restore stale focus');
    assert.equal(runtime.document.focusCalls.length, focusCount);
    assert.equal(runtime.timers.filter(timer => timer.delay === 3000).length, 2, 'cleanup must not schedule another poll');
});

test('expired progress CSRF renews once in a single flight and preserves confirmation drafts', async () => {
    const calls = [];
    const storage = memoryStorage();
    let taskCalls = 0;
    let finishRenewal;
    const tasks = [waitingTask(), importingTask()];
    const runtime = createRuntime(async (url, request) => {
        calls.push({url, request});
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse({code: 200, data: {draft_scope: draftScope, sources: [fixtureSource(1), fixtureSource(2)], settings: {schema: 1, aliases: [], rules: []}}});
        if (url.endsWith('catalogHubTasks')) {
            taskCalls++;
            if (taskCalls === 2) return csrfRejected();
            if (taskCalls === 3) assert.equal(new URLSearchParams(request.body).get('csrf_token'), refreshedCsrf);
            return jsonResponse({code: 200, data: {tasks}});
        }
        if (url.endsWith('catalogHubRefreshCsrf')) return new Promise(resolve => { finishRenewal = resolve; });
        throw new Error('unexpected write request');
    }, {context: {window: {sessionStorage: storage}}});
    await flush();
    const group = findByDataset(runtime.root, 'mappingGroup');
    enter(group, '保留分类草稿');
    enter(findByDataset(runtime.root, 'premiumPercent'), '17');
    const draftContents = () => [...storage.records].map(([key, value]) => {
        const {expiresAt, ...content} = JSON.parse(value);
        assert.ok(expiresAt > Date.now(), 'draft expired during renewal');
        return [key, content];
    });
    const stored = draftContents();
    const poll = runtime.timers.find(timer => timer.delay === 3000);
    poll.callback();
    await flush();
    poll.callback();
    await flush();
    assert.equal(calls.filter(call => call.url.endsWith('catalogHubRefreshCsrf')).length, 1);
    assert.equal(taskCalls, 2, 'concurrent poll sent a duplicate request');
    const refresh = calls.find(call => call.url.endsWith('catalogHubRefreshCsrf'));
    assert.equal(refresh.request.credentials, 'same-origin');
    assert.equal(new URLSearchParams(refresh.request.body).get('csrf_token'), 'fixture-csrf');
    finishRenewal(jsonResponse({code: 200, data: {csrf_token: refreshedCsrf}}));
    await flush();
    assert.equal(taskCalls, 3);
    assert.equal(findByDataset(runtime.root, 'mappingGroup'), group);
    assert.equal(group.value, '保留分类草稿');
    assert.equal(findByDataset(runtime.root, 'premiumPercent').value, '17');
    assert.deepEqual(draftContents(), stored);
    assert.doesNotMatch(JSON.stringify([...storage.records]), /fixture-csrf|1002\./);
});

test('failed CSRF renewal or changed session stops polling without clearing the last progress or drafts', async () => {
    for (const renewal of [
        async () => jsonResponse({code: 0, msg: '页面会话已变化，请重新登录'}),
        async () => { throw new Error('network failure'); },
        async () => jsonResponse({code: 200, data: {csrf_token: 'invalid'}}),
        async () => ({ok: false, headers: {get: () => 'text/html'}}),
    ]) {
        let taskCalls = 0;
        let renewals = 0;
        const storage = memoryStorage();
        const runtime = createRuntime(async url => {
            if (url.endsWith('catalogHubBootstrap')) return jsonResponse({code: 200, data: {draft_scope: draftScope, sources: [fixtureSource(1)], settings: {schema: 1, aliases: [], rules: []}}});
            if (url.endsWith('catalogHubTasks')) return ++taskCalls === 1 ? jsonResponse({code: 200, data: {tasks: [waitingTask(), importingTask()]}}) : csrfRejected();
            if (url.endsWith('catalogHubRefreshCsrf')) { renewals++; return renewal(); }
            throw new Error('unexpected request');
        }, {context: {window: {sessionStorage: storage}}});
        await flush();
        enter(findByDataset(runtime.root, 'mappingGroup'), '续期失败保留');
        const stored = JSON.stringify([...storage.records]);
        const poll = runtime.timers.find(timer => timer.delay === 3000);
        poll.callback();
        await flush();
        assert.equal(renewals, 1);
        assert.equal(taskCalls, 2);
        assert.match(textValues(runtime.document.getElementById('catalog-hub-tasks')).join('\n'), /已处理 20\/100/);
        assert.match(textValues(runtime.root).join('\n'), /刷新页面或重新登录/);
        assert.equal(findByDataset(runtime.root, 'mappingGroup').value, '续期失败保留');
        assert.equal(JSON.stringify([...storage.records]), stored);
        assert.equal(runtime.timers.filter(timer => timer.delay === 3000).length, 1, 'failed renewal scheduled another poll');
        poll.callback();
        await flush();
        assert.equal(taskCalls, 2, 'stale timer resumed suspended polling');
    }
});

test('a CSRF-rejected progress retry cannot trigger a second renewal', async () => {
    let taskCalls = 0;
    let renewals = 0;
    const runtime = createRuntime(async url => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        if (url.endsWith('catalogHubTasks')) return ++taskCalls === 1 ? jsonResponse({code: 200, data: {tasks: [importingTask()]}}) : csrfRejected();
        if (url.endsWith('catalogHubRefreshCsrf')) { renewals++; return jsonResponse({code: 200, data: {csrf_token: refreshedCsrf}}); }
        throw new Error('unexpected request');
    });
    await flush();
    runtime.timers.find(timer => timer.delay === 3000).callback();
    await flush();
    assert.equal(taskCalls, 3);
    assert.equal(renewals, 1);
    assert.equal(runtime.timers.filter(timer => timer.delay === 3000).length, 1);
    assert.match(textValues(runtime.root).join('\n'), /已处理 20\/100/);
});

test('CSRF rejection of a confirmation never renews or replays the write and keeps its draft', async () => {
    const runtime = draftRuntime({tasks: [waitingTask()], handle: async () => csrfRejected()});
    await flush();
    enter(findByDataset(runtime.root, 'mappingGroup'), '写入失败保留');
    await confirmationButton(runtime).listeners.click();
    await flush();
    assert.equal(runtime.calls.filter(call => call.url.endsWith('catalogHubConfirm')).length, 1);
    assert.equal(runtime.calls.some(call => call.url.endsWith('catalogHubRefreshCsrf')), false);
    assert.equal(findByDataset(runtime.root, 'mappingGroup').value, '写入失败保留');
    assert.equal(runtime.storage.records.has(taskDraftKey(waitingTask())), true);
});

test('one ordinary poll network failure preserves progress then recovers without a CSRF renewal', async () => {
    let taskCalls = 0;
    const runtime = createRuntime(async url => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        assert.ok(url.endsWith('catalogHubTasks'), 'network error attempted a CSRF renewal');
        taskCalls++;
        if (taskCalls === 2) throw new Error('temporary network failure');
        const task = importingTask();
        if (taskCalls === 3) task.progress = {...task.progress, processed: 40, succeeded: 38};
        return jsonResponse({code: 200, data: {tasks: [task]}});
    });
    await flush();
    runtime.timers.find(timer => timer.delay === 3000).callback();
    await flush();
    assert.match(textValues(runtime.root).join('\n'), /已处理 20\/100/);
    assert.match(textValues(runtime.root).join('\n'), /最后一次已获取/);
    assert.match(textValues(runtime.root).join('\n'), /将稍后重试/);
    runtime.timers.filter(timer => timer.delay === 3000).at(-1).callback();
    await flush();
    assert.equal(taskCalls, 3);
    assert.match(textValues(runtime.root).join('\n'), /已处理 40\/100/);
    assert.doesNotMatch(textValues(runtime.root).join('\n'), /temporary network failure|最后一次已获取/);
});

test('three consecutive ordinary poll failures suspend bounded retries without renewing CSRF', async () => {
    let taskCalls = 0;
    const runtime = createRuntime(async url => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        assert.ok(url.endsWith('catalogHubTasks'));
        if (++taskCalls === 1) return jsonResponse({code: 200, data: {tasks: [importingTask()]}});
        throw new Error('network unavailable');
    });
    await flush();
    for (let attempt = 0; attempt < 3; attempt++) {
        runtime.timers.filter(timer => timer.delay === 3000).at(-1).callback();
        await flush();
    }
    assert.equal(taskCalls, 4);
    assert.equal(runtime.timers.filter(timer => timer.delay === 3000).length, 3);
    assert.match(textValues(runtime.root).join('\n'), /自动更新已停止/);
    assert.match(textValues(runtime.root).join('\n'), /已处理 20\/100/);
});

test('CSRF renewal timeout and PJAX abort never retry progress or continue polling', async () => {
    for (const stop of ['timeout', 'pjax']) {
        let taskCalls = 0;
        let renewalSignal;
        const runtime = createRuntime(async (url, request) => {
            if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
            if (url.endsWith('catalogHubTasks')) return ++taskCalls === 1 ? jsonResponse({code: 200, data: {tasks: [importingTask()]}}) : csrfRejected();
            assert.ok(url.endsWith('catalogHubRefreshCsrf'));
            renewalSignal = request.signal;
            return new Promise((_resolve, reject) => request.signal.addEventListener('abort', () => reject(new Error('request aborted'))));
        });
        await flush();
        runtime.timers.find(timer => timer.delay === 3000).callback();
        await flush();
        if (stop === 'timeout') runtime.timers.find(timer => timer.delay === 15000).callback();
        else runtime.triggerPjax();
        await flush();
        assert.equal(renewalSignal.aborted, true);
        assert.equal(taskCalls, 2);
        assert.equal(runtime.timers.filter(timer => timer.delay === 3000).length, 1);
    }
});

test('detail diagnostics distinguish transient, rejected, invalid, budget and unknown failures without guessing legacy causes', async () => {
    const hints = {
        ITEM_DETAIL_UNKNOWN_FAILED: /未分类错误/,
        ITEM_DETAIL_TRANSPORT_FAILED: /临时连接故障/,
        ITEM_DETAIL_HTTP_RETRYABLE: /暂时不可用或限流/,
        ITEM_DETAIL_HTTP_REJECTED: /HTTP 请求被拒绝/,
        ITEM_DETAIL_CREDENTIALS_INVALID: /凭据格式无效/,
        ITEM_DETAIL_BUSINESS_REJECTED: /业务接口拒绝/,
        ITEM_DETAIL_RESPONSE_INVALID: /响应格式不正确/,
        ITEM_DETAIL_BUDGET_EXCEEDED: /安全预算/,
        ITEM_DETAIL_FETCH_FAILED: /原因尚未确定/,
    };
    for (const [code, hint] of Object.entries(hints)) {
        const resumeOptions = code === 'ITEM_DETAIL_RESPONSE_INVALID' ? [false, true]
            : [['ITEM_DETAIL_TRANSPORT_FAILED', 'ITEM_DETAIL_HTTP_RETRYABLE', 'ITEM_DETAIL_FETCH_FAILED'].includes(code)];
        for (const canResume of resumeOptions) {
            const task = waitingTask(1, {state: 'failed', phase: 'import', error_code: code, can_resume: canResume});
            const runtime = draftRuntime({tasks: [task]});
            await flush();
            const text = textValues(taskCard(runtime)).join('\n');
            assert.match(text, hint);
            assert.equal(runtime.root.querySelectorAll('[data-task-action]').some(button => button.dataset.taskAction === 'resume'), canResume);
            if (canResume) {
                assert.match(text, /货源恢复后可继续入库/);
                assert.doesNotMatch(text, /不能直接继续|请重新分析/);
            }
            assert.equal(runtime.calls.some(call => call.url.endsWith('catalogHubTaskControl')), false);
        }
    }
});

test('an isolated item failure remains visible while the remaining import continues', async () => {
    const task = waitingTask(1, {
        state: 'importing', phase: 'import',
        progress: {total: 100, processed: 20, succeeded: 18, failed: 1, skipped: 1},
        item_failures: [{index: 3, code: 'ITEM_DETAIL_UNAVAILABLE', attempts: 1}],
    });
    const runtime = draftRuntime({tasks: [task]});
    await flush();
    const card = runtime.document.getElementById('catalog-hub-task-waiting-1');
    const text = textValues(card).join('\n');
    assert.match(text, /正在后台入库/);
    assert.match(text, /已处理 20\/100；成功 18；跳过 1；剩余 80；未导入 1/);
    assert.match(text, /第 4 件：未取得有效商品详情，不能据此认定下架；请求次数 1/);
    assert.doesNotMatch(text, /任务已中断|处理结束，有异常|全部成功/);
    assert.deepEqual(card.querySelectorAll('[data-task-action]').map(button => button.dataset.taskAction), ['pause', 'cancel']);
    assert.equal(runtime.timers.some(timer => timer.delay === 3000), true);
});

test('a finished import with issues reports unimported items without success or resume claims', async () => {
    const task = waitingTask(1, {
        state: 'failed', phase: 'import', error_code: 'IMPORT_FINISHED_WITH_ISSUES', can_resume: true, can_cancel: true,
        progress: {total: 3, processed: 3, succeeded: 1, failed: 1, skipped: 1},
        item_failures: [{index: 1, code: 'ITEM_REMOTE_DATA_INVALID', attempts: 2}],
    });
    const runtime = draftRuntime({tasks: [task]});
    await flush();
    const card = runtime.document.getElementById('catalog-hub-task-waiting-1');
    const text = textValues(card).join('\n');
    assert.match(text, /处理结束，有异常/);
    assert.match(text, /已处理 3\/3；成功 1；跳过 1；剩余 0；未导入 1；处理结束/);
    assert.match(text, /正常商品已处理，1 件异常商品未导入/);
    assert.match(text, /第 2 件：远端商品数据未通过校验；请求次数 2/);
    assert.doesNotMatch(text, /任务已中断|全部成功|货源恢复后可继续|此任务不能直接继续/);
    assert.equal(card.querySelectorAll('[data-task-action]').length, 0);
    assert.doesNotMatch(textValues(runtime.root).join('\n'), /查看并继续任务/);
    assert.equal(runtime.timers.some(timer => timer.delay === 3000), false);
    assert.equal(runtime.calls.some(call => call.url.endsWith('catalogHubTaskControl')), false);
});

test('an explicit retry-failed action uses the current task revision once without replaying the catalog', async () => {
    const task = waitingTask(1, {
        state: 'failed', phase: 'import', error_code: 'IMPORT_FINISHED_WITH_ISSUES',
        can_resume: false, can_retry_failed: true, can_cancel: true, premium_percent: '10',
        progress: {total: 5137, processed: 5137, succeeded: 3223, failed: 1, skipped: 1913},
        item_failures: [{index: 3244, code: 'ITEM_DETAIL_HTTP_RETRYABLE', attempts: 3}],
    });
    let finishControl;
    const runtime = draftRuntime({tasks: [task], handle: async (url, request) => {
        assert.ok(url.endsWith('catalogHubTaskControl'));
        const body = new URLSearchParams(request.body);
        assert.deepEqual([...body.keys()].sort(), ['action', 'csrf_token', 'revision', 'task_id']);
        assert.equal(body.get('action'), 'retry_failed');
        assert.equal(body.get('task_id'), task.task_id);
        assert.equal(body.get('revision'), String(task.revision));
        return new Promise(resolve => { finishControl = resolve; });
    }});
    await flush();
    const card = runtime.document.getElementById('catalog-hub-task-waiting-1');
    const retry = card.querySelectorAll('[data-task-action]').find(button => button.dataset.taskAction === 'retry_failed');
    assert.ok(retry);
    assert.equal(retry.textContent, '只重试未入库项');
    assert.deepEqual(card.querySelectorAll('[data-task-action]').map(button => button.dataset.taskAction), ['retry_failed', 'cancel']);
    assert.equal(card.querySelectorAll('[data-task-action]').some(button => button.dataset.taskAction === 'resume'), false);
    assert.match(textValues(card).join('\n'), /已处理 5137\/5137；成功 3223；跳过 1913；剩余 0；未导入 1/);
    assert.equal(findByDataset(runtime.root, 'editSource').disabled, true);
    const pending = retry.listeners.click();
    await flush(1);
    await retry.listeners.click();
    assert.equal(runtime.calls.filter(call => call.url.endsWith('catalogHubTaskControl')).length, 1);
    assert.equal(runtime.root.querySelectorAll('[data-task-action]').every(button => button.disabled), true);
    finishControl(jsonResponse({code: 200, data: {task: {...task, state: 'queued_import', error_code: null, revision: 4, can_retry_failed: false}}}));
    await pending;
    assert.equal(runtime.calls.filter(call => call.url.endsWith('catalogHubTaskControl')).length, 1);
    assert.equal(runtime.calls.some(call => /catalogHubAnalyze|catalogHubConfirm/.test(call.url)), false);
});

test('retry progress remains separate from a 5137-item ledger across pause, refresh and explicit original continuation', async () => {
    const task = waitingTask(1, {
        state: 'paused', phase: 'import', can_resume: true, can_retry_failed: false, premium_percent: '10',
        progress: {total: 5137, processed: 3244, succeeded: 1331, failed: 1, skipped: 1912},
        item_failures: [{index: 10, code: 'ITEM_REMOTE_DATA_INVALID', attempts: 1}],
        retry: {origin_error_code: 'IMPORT_ITEM_FAILURE_LIMIT', indices: [3, 10], cursor: 1, succeeded: 1, skipped: 0, failed: 0, consecutive_failed: 0, halted: false, active: true},
    });
    for (let refresh = 0; refresh < 2; refresh++) {
        const runtime = draftRuntime({tasks: [task]});
        await flush();
        const card = runtime.document.getElementById('catalog-hub-task-waiting-1');
        const text = textValues(card).join('\n');
        assert.match(text, /已处理 3244\/5137；成功 1331；跳过 1912；剩余 1893；未导入 1/);
        assert.match(text, /本次补处理 1\/2；已处理 1；未补查 1；补入成功 1；已存在 0；本轮仍失败 0/);
        assert.match(text, /本次已确认新商品加价：10%/);
        assert.match(text, /故障计数保留/);
        assert.equal(card.querySelectorAll('[data-task-action]').find(button => button.dataset.taskAction === 'resume').textContent, '继续本次补处理');
        assert.equal(runtime.calls.some(call => call.url.endsWith('catalogHubTaskControl')), false);
    }
    const completedRetry = {...task, retry: {...task.retry, cursor: 2, failed: 1, consecutive_failed: 1, active: false}};
    const finished = draftRuntime({tasks: [completedRetry]});
    await flush();
    assert.match(textValues(finished.root).join('\n'), /本次补处理 2\/2.*本轮仍失败 1；本轮已结束/);
    assert.match(textValues(finished.root).join('\n'), /原任务仍有尚未处理的商品/);
    assert.equal(finished.root.querySelectorAll('[data-task-action]').find(button => button.dataset.taskAction === 'resume').textContent, '继续原任务');
    assert.equal(finished.calls.some(call => call.url.endsWith('catalogHubTaskControl')), false);
});

test('halted retry and explicit resume denial never expose a reset-through-resume action', async () => {
    const task = waitingTask(1, {
        state: 'failed', phase: 'import', error_code: 'IMPORT_ITEM_FAILURE_LIMIT', can_resume: false, can_retry_failed: true, can_cancel: true,
        progress: {total: 5137, processed: 3244, succeeded: 1326, failed: 5, skipped: 1913},
        item_failures: Array.from({length: 5}, (_, index) => ({index, code: 'ITEM_DETAIL_HTTP_RETRYABLE', attempts: 3})),
        retry: {origin_error_code: 'IMPORT_ITEM_FAILURE_LIMIT', indices: [0, 1, 2, 3, 4], cursor: 5, succeeded: 0, skipped: 0, failed: 5, consecutive_failed: 5, halted: true, active: false},
    });
    const runtime = draftRuntime({tasks: [task]});
    await flush();
    assert.deepEqual(runtime.root.querySelectorAll('[data-task-action]').map(button => button.dataset.taskAction), ['retry_failed', 'cancel']);
    assert.match(textValues(runtime.root).join('\n'), /已处理 5；未补查 0.*旧规则停止，本轮已处理完/);
    assert.equal(runtime.root.querySelectorAll('[data-task-action]').find(button => button.dataset.taskAction === 'retry_failed').textContent, '开始新一轮未入库项补查');
    assert.doesNotMatch(textValues(runtime.root).join('\n'), /全部成功|继续本次补处理/);
    const paused = draftRuntime({tasks: [{...task, state: 'paused', can_retry_failed: false}]});
    await flush();
    assert.equal(paused.root.querySelectorAll('[data-task-action]').some(button => button.dataset.taskAction === 'resume'), false);
    assert.equal(paused.root.querySelectorAll('[data-task-action]').some(button => button.dataset.taskAction === 'retry_failed'), false);
    const pausedAtFuse = draftRuntime({tasks: [{...task, state: 'paused'}]});
    await flush();
    assert.deepEqual(pausedAtFuse.root.querySelectorAll('[data-task-action]').map(button => button.dataset.taskAction), ['retry_failed', 'cancel']);
    assert.equal(pausedAtFuse.calls.some(call => call.url.endsWith('catalogHubTaskControl')), false);
});

test('legacy halted 5 of 14 continues the same round once and reports the nine unvisited items', async () => {
    for (const state of ['failed', 'paused']) {
        const task = waitingTask(1, {
            state, phase: 'import', error_code: 'IMPORT_ITEM_FAILURE_LIMIT', can_resume: false, can_retry_failed: true,
            progress: {total: 5187, processed: 5187, succeeded: 1959, failed: 14, skipped: 3214},
            item_failures: Array.from({length: 14}, (_, index) => ({index, code: 'ITEM_DETAIL_UNAVAILABLE', attempts: 1})),
            retry: {origin_error_code: 'IMPORT_FINISHED_WITH_ISSUES', indices: Array.from({length: 14}, (_, index) => index), cursor: 5, succeeded: 0, skipped: 0, failed: 5, consecutive_failed: 5, halted: true, active: false},
        });
        const runtime = draftRuntime({tasks: [task], handle: async (url, request) => {
            assert.ok(url.endsWith('catalogHubTaskControl'));
            const body = new URLSearchParams(request.body);
            assert.equal(body.get('action'), 'retry_failed');
            assert.equal(body.get('revision'), String(task.revision));
            throw new Error('unknown response');
        }});
        await flush();
        const text = textValues(runtime.root).join('\n');
        assert.match(text, /本次补处理 5\/14；已处理 5；未补查 9.*本轮仍失败 5/);
        assert.match(text, /未导入 14/);
        assert.doesNotMatch(text, /开始新一轮未入库项补查/);
        const button = runtime.root.querySelectorAll('[data-task-action]').find(button => button.dataset.taskAction === 'retry_failed');
        assert.equal(button.textContent, '继续本次补处理');
        assert.equal(runtime.root.querySelectorAll('[data-task-action]').some(button => button.dataset.taskAction === 'resume'), false);
        await button.listeners.click();
        assert.equal(runtime.calls.filter(call => call.url.endsWith('catalogHubTaskControl')).length, 1);
        assert.match(textValues(runtime.root).join('\n'), /已处理 5187\/5187；成功 1959；跳过 3214/);
    }
});

test('retry-failed stale, CSRF and unknown responses preserve the ledger and never replay a write', async () => {
    const task = waitingTask(1, {
        state: 'failed', phase: 'import', error_code: 'IMPORT_FINISHED_WITH_ISSUES', can_retry_failed: true,
        progress: {total: 2, processed: 2, succeeded: 1, failed: 1, skipped: 0},
        item_failures: [{index: 1, code: 'ITEM_DETAIL_HTTP_RETRYABLE', attempts: 3}],
    });
    for (const respond of [
        async () => jsonResponse({code: 409, msg: '后台任务已被其他操作更新，请刷新后重试。'}),
        async () => csrfRejected(),
        async () => { throw new Error('UPSTREAM_SECRET network result unknown'); },
        async () => jsonResponse({code: 200, data: {}}),
    ]) {
        const runtime = draftRuntime({tasks: [task], handle: respond});
        await flush();
        await runtime.root.querySelectorAll('[data-task-action]').find(button => button.dataset.taskAction === 'retry_failed').listeners.click();
        const text = textValues(runtime.root).join('\n');
        assert.match(text, /已处理 2\/2；成功 1；跳过 0；剩余 0；未导入 1/);
        assert.doesNotMatch(text, /UPSTREAM_SECRET|写入 0|没有写入/);
        assert.equal(runtime.calls.filter(call => call.url.endsWith('catalogHubTaskControl')).length, 1);
        assert.equal(runtime.calls.some(call => /catalogHubRefreshCsrf|catalogHubAnalyze|catalogHubConfirm/.test(call.url)), false);
    }
});

test('detail compatibility is informational only after a successful checkpoint and renders fixed safe diagnostics', async () => {
    const base = waitingTask(1, {
        state: 'importing', phase: 'import',
        progress: {total: 3, processed: 1, succeeded: 1, failed: 0, skipped: 0}, item_failures: [],
        last_detail_diagnostic: {index: 0, diagnostics: {category: 'none', http_status: 200, curl_code: 0, elapsed_ms: 201, attempts: 1, mime_category: 'text_plain', mime_count: 1, json_valid: true, mime_compatibility: true, raw: 'UPSTREAM_SECRET'}},
    });
    const accepted = draftRuntime({tasks: [{...base, detail_compatibility_count: 1}]});
    await flush();
    const card = accepted.document.getElementById('catalog-hub-task-waiting-1');
    const text = textValues(card).join('\n');
    const hint = findElement(card, element => element.textContent.startsWith('兼容提示：'));
    assert.match(hint.className, /alert-info/);
    assert.match(text, /1 次非标准详情标签已通过商品校验并完成处理/);
    assert.match(text, /MIME 分类：纯文本标签/);
    assert.match(text, /严格 JSON：有效/);
    assert.match(text, /是否入库仍由完整商品校验决定/);
    assert.doesNotMatch(text, /UPSTREAM_SECRET|任务已中断|未导入清单/);
    const rejected = draftRuntime({tasks: [{...base, state: 'failed', error_code: 'ITEM_CONFIG_EXTRACTION_FAILED', detail_compatibility_count: 0}]});
    await flush();
    assert.doesNotMatch(textValues(rejected.root).join('\n'), /兼容提示：|已通过商品校验并完成处理/);
    assert.match(textValues(rejected.root).join('\n'), /下单配置无法处理/);
});

test('JSON diagnostics show only fixed recorded reasons without guessing a legacy syntax error', async () => {
    for (const [diagnostic, reason, code] of [
        [{json_error_code: 1, json_error: 'depth'}, '本地解码深度超限', '1'],
        [{json_error_code: 5, json_error: 'utf8'}, 'UTF-8 编码无效', '5'],
        ...[2, 3, 4, 9, 10].map(code => [{json_error_code: code, json_error: 'syntax'}, '语法无效', String(code)]),
        ...[12, 255].map(code => [{json_error_code: code, json_error: 'unknown'}, '未分类', String(code)]),
        ...[0, 6, 7, 8, 11, -1, 256, 1.5, '4'].map(code => [{json_error_code: code, json_error: 'unknown'}, '未记录', '未记录']),
        [{json_error_code: 1, json_error: 'syntax'}, '未记录', '未记录'],
        [{}, '未记录', '未记录'],
        [{json_error_code: 'UPSTREAM_SECRET', json_error: 'UPSTREAM_SECRET'}, '未记录', '未记录'],
        [{json_error_code: -1, json_error: '__proto__'}, '未记录', '未记录'],
    ]) {
        const runtime = draftRuntime({tasks: [disclosureTask(1, {
            item_failures: [{index: 0, code: 'ITEM_DETAIL_JSON_INVALID', attempts: 1}],
            last_detail_diagnostic: {index: 0, diagnostics: {category: 'json', json_valid: false, ...diagnostic}},
        })]});
        await flush();
        const failures = textValues(taskDisclosure(runtime, '未导入清单')).join('\n');
        assert.match(failures, /第 1 件：详情 JSON 解析失败，该商品未入库；请求次数 1/);
        assert.doesNotMatch(failures, /异常原因未核实/);
        const text = textValues(taskDisclosure(runtime, '最近详情诊断')).join('\n');
        assert.match(text, /严格 JSON：无效/);
        assert.ok(text.includes(`JSON 原因：${reason}`));
        assert.ok(text.includes(`JSON 错误码：${code}`));
        assert.doesNotMatch(text, /UPSTREAM_SECRET|__proto__/);
        if (!Object.hasOwn(diagnostic, 'json_error')) assert.doesNotMatch(text, /语法无效/);
    }
});

test('response structure displays only observed bounded fields and never implies delisting', async () => {
    const secret = 'UPSTREAM_SECRET';
    for (const [structure, expected] of [
        [{business_code: 200, data_type: 'empty_array_or_object', data_count: 0}, /data 元素数：0/],
        [{data_type: 'list', data_count: 1, first_children_type: 'empty_array_or_object', first_children_count: 0, first_item_type: 'missing'}, /首商品位置类型：缺少字段\/位置/],
        [{data_type: 'list', first_children_type: 'list', first_item_type: 'object'}, /首商品位置类型：非空对象/],
        [{business_code: secret, data_type: '__proto__', data_count: -1, first_children_count: 10001, first_item_type: secret, body: secret, url: secret}, /结构仅反映本次已取得响应/],
    ]) {
        const task = waitingTask(1, {state: 'failed', phase: 'import', last_detail_diagnostic: {
            index: 0, diagnostics: {category: 'item_unavailable', response_structure: structure},
        }});
        const runtime = draftRuntime({tasks: [task]});
        await flush();
        const text = textValues(runtime.root).join('\n');
        assert.match(text, expected);
        assert.match(text, /不代表商品已下架/);
        assert.doesNotMatch(text, /UPSTREAM_SECRET|__proto__|10001|元素数：-1/);
        if (!Object.hasOwn(structure, 'first_children_type')) assert.doesNotMatch(text, /首分类 children 类型/);
        if (!Object.hasOwn(structure, 'business_code')) assert.doesNotMatch(text, /业务码：/);
    }
});

test('malformed retry and diagnostic fields never disclose arbitrary codes, headers or body values', async () => {
    const secret = 'UPSTREAM_SECRET <script> https://private.example';
    const task = waitingTask(1, {
        state: 'failed', phase: 'import', error_code: secret,
        progress: {total: 2, processed: 1, succeeded: 1, failed: 0, skipped: 0},
        retry: {indices: [secret], cursor: secret, succeeded: 0, failed: 0, skipped: 0, halted: false, active: true},
        detail_compatibility_count: secret,
        last_detail_diagnostic: {index: secret, diagnostics: {category: secret, http_status: 9999, curl_code: secret, elapsed_ms: -1, attempts: 99, mime_category: '__proto__', mime_count: secret, json_valid: secret, mime_compatibility: 'true', headers: secret, body: secret}},
    });
    const runtime = draftRuntime({tasks: [task]});
    await flush();
    const text = textValues(runtime.root).join('\n');
    assert.match(text, /本次补处理进度未核实/);
    assert.match(text, /类别：未分类/);
    assert.match(text, /HTTP 状态：未记录/);
    assert.match(text, /MIME 分类：未核实/);
    assert.match(text, /严格 JSON：未核实/);
    assert.doesNotMatch(text, /UPSTREAM_SECRET|__proto__|private\.example|<script>|请求次数：99|兼容提示：/);
});

test('item-failure limits and genuine fatal failures still show an interrupted task', async () => {
    for (const code of ['IMPORT_ITEM_FAILURE_LIMIT', 'ITEM_PERSISTENCE_FAILED']) {
        const task = waitingTask(1, {
            state: 'failed', phase: 'import', error_code: code,
            can_resume: code === 'IMPORT_ITEM_FAILURE_LIMIT',
            can_cancel: code === 'IMPORT_ITEM_FAILURE_LIMIT',
            progress: {total: 10, processed: 2, succeeded: 1, failed: 1, skipped: 0},
            item_failures: [{index: 1, code: 'ITEM_DETAIL_HTTP_RETRYABLE', attempts: 3}],
        });
        const runtime = draftRuntime({tasks: [task]});
        await flush();
        const card = runtime.document.getElementById('catalog-hub-task-waiting-1');
        const text = textValues(card).join('\n');
        assert.match(text, /已处理 2\/10；成功 1；跳过 0；剩余 8；未导入 1；任务已中断/);
        assert.match(text, /第 2 件：货源暂时不可用或限流，有限尝试已结束；请求次数 3/);
        assert.doesNotMatch(text, /处理结束，有异常|正常商品已处理/);
        assert.equal(card.querySelectorAll('[data-task-action]').length, 0);
        if (code === 'IMPORT_ITEM_FAILURE_LIMIT') {
            assert.match(text, /单件异常已达到安全上限/);
            assert.match(text, /刷新不会自动重试/);
        }
    }
});

test('an ordinary completed import retains successful and skipped counts without an issue list', async () => {
    const task = waitingTask(1, {
        state: 'completed', phase: 'import', error_code: null,
        progress: {total: 3, processed: 3, succeeded: 2, failed: 0, skipped: 1},
        item_failures: [],
    });
    const runtime = draftRuntime({tasks: [task]});
    await flush();
    const card = runtime.document.getElementById('catalog-hub-task-waiting-1');
    const text = textValues(card).join('\n');
    assert.match(text, /已完成/);
    assert.match(text, /已处理 3\/3；成功 2；跳过 1；剩余 0/);
    assert.doesNotMatch(text, /未导入清单|处理结束，有异常|任务已中断/);
    assert.equal(card.querySelectorAll('[data-task-action]').length, 0);
});

test('untrusted item-failure fields never render upstream text or fabricate bounded measurements', async () => {
    const untrusted = '<img src=x onerror=alert(1)>UPSTREAM_SECRET';
    const task = waitingTask(1, {
        state: 'importing', phase: 'import',
        progress: {total: 10, processed: 4, succeeded: 0, failed: 4, skipped: 0},
        item_failures: [
            {index: 0, code: untrusted, attempts: 99, message: untrusted},
            {index: 1, code: '__proto__', attempts: -1},
            {index: untrusted, code: 'ITEM_REMOTE_DATA_INVALID', attempts: 1},
            {index: 3, code: 'ITEM_DETAIL_TRANSPORT_FAILED', attempts: '3', raw: untrusted},
        ],
    });
    const runtime = draftRuntime({tasks: [task]});
    await flush();
    const card = runtime.document.getElementById('catalog-hub-task-waiting-1');
    const text = textValues(card).join('\n');
    assert.match(text, /第 1 件：异常原因未核实；请求次数未核实/);
    assert.match(text, /第 2 件：异常原因未核实；请求次数未核实/);
    assert.match(text, /商品序号未核实，异常记录格式不正确/);
    assert.match(text, /第 4 件：商品详情连接失败，有限尝试已结束；请求次数未核实/);
    assert.doesNotMatch(text, /UPSTREAM_SECRET|<img|onerror|__proto__|请求次数 99|请求次数 -1|请求次数 3/);
    assert.equal(findElement(card, element => ['img', 'script', 'iframe'].includes(element.tag)), null);
    assert.doesNotMatch(source, /innerHTML|outerHTML|insertAdjacentHTML|eval\s*\(|new Function/);
});

test('legacy, malformed and oversized item-failure lists have explicit bounded fallback displays', async () => {
    const base = waitingTask(1, {
        state: 'failed', phase: 'import', error_code: 'IMPORT_FINISHED_WITH_ISSUES',
        progress: {total: 2, processed: 2, succeeded: 1, failed: 1, skipped: 0},
    });
    for (const itemFailures of [null, '<script>UPSTREAM_SECRET</script>', {message: 'UPSTREAM_SECRET'}]) {
        const runtime = draftRuntime({tasks: [{...base, item_failures: itemFailures}]});
        await flush();
        const text = textValues(runtime.document.getElementById('catalog-hub-task-waiting-1')).join('\n');
        assert.match(text, itemFailures === null ? /旧任务未保存逐件异常明细/ : /未导入清单格式不正确/);
        assert.doesNotMatch(text, /UPSTREAM_SECRET|<script>|没有异常/);
    }
    const task = {...base,
        progress: {total: 200, processed: 101, succeeded: 0, failed: 101, skipped: 0},
        item_failures: Array.from({length: 101}, (_, index) => ({index, code: 'ITEM_REMOTE_DATA_INVALID', attempts: 0})),
    };
    const runtime = draftRuntime({tasks: [task]});
    await flush();
    const card = runtime.document.getElementById('catalog-hub-task-waiting-1');
    const text = textValues(card).join('\n');
    assert.equal(card.querySelectorAll('[data-item-failure-index]').length, 100);
    assert.match(text, /清单超过显示上限，仅展示前 100 条/);
    assert.match(text, /第 1 件：远端商品数据未通过校验；请求次数未记录/);
    assert.doesNotMatch(text, /请求次数 0|第 101 件|处理结束，有异常|正常商品已处理/);
});

test('an explicitly cancellable legacy failure has a cancel-only exit without recreating missing detail history', async () => {
    const task = waitingTask(1, {
        state: 'failed', phase: 'import', error_code: 'ITEM_DETAIL_FETCH_FAILED',
        can_resume: false, can_cancel: true, premium_percent: '10',
        progress: {total: 10, processed: 2, succeeded: 1, failed: 1, skipped: 0},
        item_failures: null,
    });
    const runtime = draftRuntime({tasks: [task], handle: async (url, request) => {
        assert.ok(url.endsWith('catalogHubTaskControl'));
        const body = new URLSearchParams(request.body);
        assert.equal(body.get('action'), 'cancel');
        assert.equal(body.get('task_id'), task.task_id);
        assert.equal(body.get('revision'), String(task.revision));
        return jsonResponse({code: 200, data: {task: {...task, state: 'cancelled', can_cancel: false, revision: 4}}});
    }});
    await flush();
    const card = runtime.document.getElementById('catalog-hub-task-waiting-1');
    const buttons = card.querySelectorAll('[data-task-action]');
    assert.deepEqual(buttons.map(button => button.dataset.taskAction), ['cancel']);
    assert.match(textValues(card).join('\n'), /旧任务未保存逐件异常明细/);
    assert.match(textValues(card).join('\n'), /不能直接继续，可取消后结束此任务/);
    assert.equal(findByDataset(runtime.root, 'editSource').disabled, true);
    assert.equal(findByDataset(runtime.root, 'analyzeSource').textContent, '查看任务');
    await buttons[0].listeners.click();
    await flush();
    const cancelled = runtime.document.getElementById('catalog-hub-task-waiting-1');
    assert.equal(cancelled.querySelectorAll('[data-task-action]').length, 0);
    assert.match(textValues(cancelled).join('\n'), /已处理 2\/10；成功 1；跳过 0；剩余 8；未导入 1/);
    assert.match(textValues(cancelled).join('\n'), /旧任务未保存逐件异常明细/);
    assert.equal(findByDataset(runtime.root, 'editSource').disabled, false);
    assert.equal(runtime.calls.filter(call => call.url.endsWith('catalogHubTaskControl')).length, 1);
    for (const code of ['ITEM_DETAIL_TRANSPORT_FAILED', 'ITEM_DETAIL_HTTP_RETRYABLE']) {
        const legacy = draftRuntime({tasks: [{...task, error_code: code}]});
        await flush();
        assert.deepEqual(legacy.root.querySelectorAll('[data-task-action]').map(button => button.dataset.taskAction), ['cancel']);
        assert.doesNotMatch(textValues(legacy.root).join('\n'), /恢复后可手动继续|恢复后可继续入库|查看并继续任务/);
    }
    for (const canCancel of [undefined, null, false, 'true', 1]) {
        const conservative = draftRuntime({tasks: [{...task, can_cancel: canCancel}]});
        await flush();
        assert.equal(conservative.root.querySelectorAll('[data-task-action]').length, 0);
    }
});

test('catalog hub refuses a non-JSON bootstrap response without reading its body', async () => {
    let parsed = false;
    const runtime = createRuntime(async () => ({
        ok: false,
        headers: {get: () => 'text/html; charset=utf-8'},
        json: async () => { parsed = true; return {}; },
    }));
    await flush();
    assert.equal(parsed, false);
    assert.equal(runtime.root.children[0]?.textContent, '服务暂时不可用，请刷新页面后重试');
});

test('catalog hub renders untrusted values only through textContent', () => {
    assert.match(source, /element\.textContent = String\(text\)/);
    assert.doesNotMatch(source, /innerHTML|outerHTML|insertAdjacentHTML|eval\s*\(|new Function/);
    assert.doesNotMatch(source, /catalogHubPreview|catalogHubSave|localStorage/);
    assert.doesNotMatch(source, /console\s*\./);
    assert.match(source, /credentials: 'same-origin'/);
    assert.match(source, /signal: controller\.signal/);
});

test('ordinary source workflow contains no legacy preview or literal-rule entry', async () => {
    const sourceRow = {
        id: 7,
        name: 'One',
        alias: '<货源A>',
        type: 0,
        domain: 'https://one.example',
        app_id: 'one',
        currency: 'CNY',
        currency_rate: '0',
    };
    const runtime = createRuntime(async url => {
        if (url.endsWith('catalogHubBootstrap')) {
            return jsonResponse({
                code: 200,
                data: {
                    sources: [sourceRow],
                    settings: {schema: 1, aliases: [{source_id: 7, alias: '<货源A>'}], rules: []},
                },
            });
        }
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: []}});
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();

    assert.equal(runtime.document.getElementById('catalog-hub-preview-button'), null);
    assert.equal(runtime.document.getElementById('catalog-hub-source-select'), null);
    assert.equal(findElement(runtime.root, element => /保存字面规则|只读预览/.test(element.textContent)), null);
    assert.ok(textValues(runtime.root).includes('<货源A>'));
    assert.ok(findByDataset(runtime.root, 'sourcePremium'));
});

test('source protocol select exactly follows the official order and labels', async () => {
    const runtime = createRuntime(async url => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: []}});
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();
    const select = runtime.document.getElementById('catalog-hub-protocol');
    assert.ok(select, 'protocol select must render');
    assert.deepEqual(
        select.children.map(option => [option.value, option.textContent]),
        [
            ['0', '异次元(V3.1.2 重构后全新版)'],
            ['2', '异次元(V3.1.1 之前旧版)'],
            ['1', '萌次元(V4.0)'],
        ],
    );
    assert.equal(select.value, '0');
});

test('test-and-connect clears KEY before analyzing with source id, alias and explicit default mode', async () => {
    const calls = [];
    let keyInput;
    const runtime = createRuntime(async (url, request) => {
        calls.push({url, request});
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: []}});
        if (url.endsWith('catalogHubConnect')) return jsonResponse({code: 200, data: {source_id: 7}});
        if (url.endsWith('catalogHubAnalyze')) {
            assert.equal(keyInput.value, '', 'KEY must be cleared before Pika analysis starts');
            return jsonResponse({
                code: 200,
                data: {task: {task_id: 'task-7', source_id: 7, source_alias: '货源A', state: 'queued_analysis', revision: 1}},
            });
        }
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();

    findByDataset(runtime.root, 'sourceFormAlias').value = '货源A';
    findByDataset(runtime.root, 'sourceFormDomain').value = 'https://upstream.example';
    findByDataset(runtime.root, 'sourceFormAppId').value = 'merchant-1';
    keyInput = findByDataset(runtime.root, 'sourceFormAppKey');
    keyInput.value = 'secret-value';
    findByDataset(runtime.root, 'sourceCurrencyRate').value = '';
    const connect = findByDataset(runtime.root, 'connectSource');
    await connect.listeners.click();

    const secureConnect = calls.find(call => call.url.endsWith('catalogHubConnect'));
    assert.ok(secureConnect);
    assert.equal(secureConnect.request.credentials, 'same-origin');
    assert.equal(Object.hasOwn(secureConnect.request, 'referrer'), false, 'browser default Referer policy must be retained');
    assert.equal(Object.hasOwn(secureConnect.request, 'referrerPolicy'), false);
    const connectBody = new URLSearchParams(secureConnect.request.body);
    assert.deepEqual(
        [...connectBody.keys()].sort(),
        ['app_id', 'app_key', 'csrf_token', 'currency', 'currency_rate', 'domain', 'type'],
    );
    assert.equal(connectBody.get('csrf_token'), 'fixture-csrf');
    assert.equal(connectBody.has('alias'), false);
    assert.equal(connectBody.get('domain'), 'https://upstream.example');

    const analysis = calls.find(call => call.url.endsWith('catalogHubAnalyze'));
    assert.ok(analysis);
    const analysisBody = new URLSearchParams(analysis.request.body);
    assert.deepEqual([...analysisBody.keys()].sort(), ['alias', 'category_mode', 'csrf_token', 'source_id']);
    assert.equal(analysisBody.get('category_mode'), 'smart');
    assert.equal(analysisBody.get('source_id'), '7');
    assert.equal(analysisBody.get('alias'), '货源A');
    assert.equal(analysisBody.has('app_id'), false);
    assert.equal(analysisBody.has('app_key'), false);
    assert.equal(keyInput.value, '');
});

test('a failed second step preserves the saved-source fact and requires task reconciliation before retry', async () => {
    const runtime = createRuntime(async url => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: []}});
        if (url.endsWith('catalogHubConnect')) return jsonResponse({code: 200, data: {source_id: 8}});
        if (url.endsWith('catalogHubAnalyze')) return jsonResponse({code: 500, msg: 'analysis unavailable'});
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();
    findByDataset(runtime.root, 'sourceFormAlias').value = '货源B';
    findByDataset(runtime.root, 'sourceFormDomain').value = 'https://second.example';
    findByDataset(runtime.root, 'sourceFormAppId').value = 'merchant-2';
    const key = findByDataset(runtime.root, 'sourceFormAppKey');
    key.value = 'secret-value';
    await findByDataset(runtime.root, 'connectSource').listeners.click();
    const status = runtime.document.getElementById('catalog-hub-message-source-form');
    assert.match(status.children[0]?.textContent || '', /货源已保存，智能分析结果尚未核实.*请先刷新任务列表/);
    assert.match(status.children[0]?.className || '', /alert-warning/);
    assert.equal(key.value, '');
});

test('test-and-connect is single-click locked while the secure request is pending', async () => {
    let storeCalls = 0;
    let releaseStore;
    const pendingStore = new Promise(resolve => { releaseStore = resolve; });
    const runtime = createRuntime(async url => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: []}});
        if (url.endsWith('catalogHubConnect')) {
            storeCalls += 1;
            return pendingStore;
        }
        if (url.endsWith('catalogHubAnalyze')) {
            return jsonResponse({code: 200, data: {task: {task_id: 'task-lock', state: 'queued_analysis', revision: 1}}});
        }
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();
    findByDataset(runtime.root, 'sourceFormAlias').value = '货源A';
    findByDataset(runtime.root, 'sourceFormDomain').value = 'https://locked.example';
    findByDataset(runtime.root, 'sourceFormAppId').value = 'merchant-lock';
    findByDataset(runtime.root, 'sourceFormAppKey').value = 'secret-value';
    const connect = findByDataset(runtime.root, 'connectSource');
    const first = connect.listeners.click();
    const second = connect.listeners.click();
    assert.equal(storeCalls, 1);
    assert.equal(connect.disabled, true);
    releaseStore(jsonResponse({code: 200, data: {source_id: 9}}));
    await Promise.all([first, second]);
    assert.equal(storeCalls, 1);
    assert.equal(connect.disabled, false);
});

test('failed secure connection clears the KEY input and disables password-manager autocomplete', async () => {
    const runtime = createRuntime(async url => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: []}});
        if (url.endsWith('catalogHubConnect')) return jsonResponse({code: 500, msg: 'connection failed'});
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();
    findByDataset(runtime.root, 'sourceFormAlias').value = '货源A';
    findByDataset(runtime.root, 'sourceFormDomain').value = 'https://failed.example';
    findByDataset(runtime.root, 'sourceFormAppId').value = 'merchant-failed';
    const key = findByDataset(runtime.root, 'sourceFormAppKey');
    key.value = 'secret-value';
    assert.equal(key.autocomplete, 'off');
    await findByDataset(runtime.root, 'connectSource').listeners.click();
    assert.equal(key.value, '');
});

test('PJAX abort during secure connection also clears the KEY input', async () => {
    let connectSignal;
    const runtime = createRuntime(async (url, request) => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: []}});
        if (url.endsWith('catalogHubConnect')) {
            connectSignal = request.signal;
            return new Promise((resolve, reject) => {
                request.signal.addEventListener('abort', () => reject(new Error('aborted')), {once: true});
            });
        }
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();
    findByDataset(runtime.root, 'sourceFormAlias').value = '货源A';
    findByDataset(runtime.root, 'sourceFormDomain').value = 'https://abort.example';
    findByDataset(runtime.root, 'sourceFormAppId').value = 'merchant-abort';
    const key = findByDataset(runtime.root, 'sourceFormAppKey');
    key.value = 'secret-value';
    const pending = findByDataset(runtime.root, 'connectSource').listeners.click();
    await flush(1);
    assert.ok(connectSignal && !connectSignal.aborted);
    runtime.triggerPjax();
    await pending;
    assert.equal(connectSignal.aborted, true);
    assert.equal(key.value, '');
});

test('source address UI rejects HTTP, non-root paths, parameters and non-standard ports before sending KEY', async () => {
    let connectCalls = 0;
    const runtime = createRuntime(async url => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: []}});
        if (url.endsWith('catalogHubConnect')) connectCalls += 1;
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();
    findByDataset(runtime.root, 'sourceFormAlias').value = '货源A';
    findByDataset(runtime.root, 'sourceFormAppId').value = 'merchant-a';
    findByDataset(runtime.root, 'sourceFormAppKey').value = 'secret-value';
    const domain = findByDataset(runtime.root, 'sourceFormDomain');
    const connect = findByDataset(runtime.root, 'connectSource');
    for (const invalid of [
        'http://upstream.example',
        'https://upstream.example/path',
        'https://upstream.example/?query=1',
        'https://upstream.example:8443',
    ]) {
        domain.value = invalid;
        await connect.listeners.click();
    }
    assert.equal(connectCalls, 0);
});

test('source onboarding rejects keys shorter than eight characters before any request', async () => {
    let connectCalls = 0;
    const runtime = createRuntime(async url => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: []}});
        if (url.endsWith('catalogHubConnect')) connectCalls += 1;
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();

    findByDataset(runtime.root, 'sourceFormAlias').value = '货源A';
    findByDataset(runtime.root, 'sourceFormDomain').value = 'https://upstream.example';
    findByDataset(runtime.root, 'sourceFormAppId').value = 'merchant-a';
    findByDataset(runtime.root, 'sourceFormAppKey').value = 'short';
    await findByDataset(runtime.root, 'connectSource').listeners.click();

    assert.equal(connectCalls, 0);
    assert.match(textValues(runtime.root).join('\n'), /8–64/);
});

test('source edit changes the Pika alias while locking binding identity and preserving a blank KEY', async () => {
    const calls = [];
    const sourceRow = {
        id: 7,
        name: 'Remote Store',
        alias: '货源A',
        type: 2,
        domain: 'https://upstream.example',
        app_id: 'merchant-7',
        currency: 'USD',
        currency_rate: '2.500000',
    };
    const runtime = createRuntime(async (url, request) => {
        calls.push({url, request});
        if (url.endsWith('catalogHubBootstrap')) {
            return jsonResponse({
                code: 200,
                data: {
                    sources: [sourceRow],
                    settings: {schema: 1, aliases: [{source_id: 7, alias: '货源A'}], rules: []},
                },
            });
        }
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: []}});
        if (url.endsWith('catalogHubSourceUpdate')) {
            return jsonResponse({
                code: 200,
                data: {source: sourceRow},
            });
        }
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();

    const nativeManagement = findByDataset(runtime.root, 'nativeSourceManagement');
    assert.equal(nativeManagement.href, '/admin/store/index');
    assert.equal(nativeManagement.textContent, '高级：打开异次元原生店铺共享（仅管理，不入库）');
    assert.ok(textValues(runtime.root).some(value => value.includes('填写货源方提供的连接信息，先测试，再确认分类与加价。')));
    assert.ok(textValues(runtime.root).some(value => value.includes('不要在那里再次执行商品入库。')));

    const edit = findByDataset(runtime.root, 'editSource');
    assert.equal(edit.dataset.editSource, '7');
    await edit.listeners.click();
    const protocol = runtime.document.getElementById('catalog-hub-protocol');
    const alias = findByDataset(runtime.root, 'sourceFormAlias');
    const domain = findByDataset(runtime.root, 'sourceFormDomain');
    const appId = findByDataset(runtime.root, 'sourceFormAppId');
    const key = findByDataset(runtime.root, 'sourceFormAppKey');
    assert.equal(protocol.value, '2');
    assert.equal(protocol.disabled, true);
    assert.equal(alias.value, '货源A');
    assert.equal(alias.disabled, false);
    assert.equal(domain.disabled, true);
    assert.equal(appId.disabled, true);
    assert.equal(key.value, '');
    assert.equal(key.required, false);
    assert.equal(key.placeholder, '留空则保留现有密钥');
    assert.equal(findByDataset(runtime.root, 'connectSource').textContent, '保存修改');
    assert.ok(textValues(runtime.root).some(value => value.includes('联动更新此货源的受管货源层分类名称')));

    alias.value = '货源B';
    await findByDataset(runtime.root, 'connectSource').listeners.click();
    const update = calls.find(call => call.url.endsWith('catalogHubSourceUpdate'));
    const body = new URLSearchParams(update.request.body);
    assert.deepEqual(
        [...body.keys()].sort(),
        ['alias', 'app_id', 'app_key', 'csrf_token', 'currency', 'currency_rate', 'domain', 'source_id', 'type'],
    );
    assert.equal(body.get('source_id'), '7');
    assert.equal(body.get('alias'), '货源B');
    assert.equal(body.get('type'), '2');
    assert.equal(body.get('app_key'), '');
    assert.equal(body.get('currency_rate'), '2.5');
    assert.equal(calls.some(call => call.url.endsWith('catalogHubAnalyze')), false);
    assert.ok(textValues(runtime.document.getElementById('catalog-hub-sources')).includes('货源B'));
    assert.ok(textValues(runtime.document.getElementById('catalog-hub-sources')).some(value => value.includes('Remote Store')));
    assert.ok(textValues(runtime.root).includes('货源修改成功。'));
    assert.equal(findByDataset(runtime.root, 'sourceFormAlias').disabled, false);
    assert.equal(runtime.document.getElementById('catalog-hub-protocol').disabled, false);
    assert.equal(findByDataset(runtime.root, 'sourceFormDomain').disabled, false);
    assert.equal(findByDataset(runtime.root, 'sourceFormAppId').disabled, false);
    assert.equal(findByDataset(runtime.root, 'sourceFormAppKey').required, true);
});

test('source edit normalizes a bootstrap zero rate to an empty same-currency request', async () => {
    const calls = [];
    const sourceRow = {
        id: 7,
        name: 'Remote Store',
        alias: '货源A',
        type: 0,
        domain: 'https://upstream.example',
        app_id: 'merchant-7',
        currency: 'CNY',
        currency_rate: '0.000000',
    };
    const runtime = createRuntime(async (url, request) => {
        calls.push({url, request});
        if (url.endsWith('catalogHubBootstrap')) {
            return jsonResponse({
                code: 200,
                data: {
                    sources: [sourceRow],
                    settings: {schema: 1, aliases: [{source_id: 7, alias: '货源A'}], rules: []},
                },
            });
        }
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: []}});
        if (url.endsWith('catalogHubSourceUpdate')) {
            return jsonResponse({code: 200, data: {source: sourceRow}});
        }
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();

    await findByDataset(runtime.root, 'editSource').listeners.click();
    const protocol = runtime.document.getElementById('catalog-hub-protocol');
    const domain = findByDataset(runtime.root, 'sourceFormDomain');
    const appId = findByDataset(runtime.root, 'sourceFormAppId');
    const key = findByDataset(runtime.root, 'sourceFormAppKey');
    assert.equal(protocol.disabled, true);
    assert.equal(domain.disabled, true);
    assert.equal(appId.disabled, true);
    assert.equal(key.value, '');
    assert.equal(findByDataset(runtime.root, 'sourceCurrencyRate').value, '');
    await findByDataset(runtime.root, 'connectSource').listeners.click();

    const update = calls.find(call => call.url.endsWith('catalogHubSourceUpdate'));
    const body = new URLSearchParams(update.request.body);
    assert.equal(body.get('source_id'), '7');
    assert.equal(body.get('type'), '0');
    assert.equal(body.get('domain'), 'https://upstream.example');
    assert.equal(body.get('app_id'), 'merchant-7');
    assert.equal(body.get('currency'), 'CNY');
    assert.equal(body.get('currency_rate'), '');
    assert.equal(body.get('app_key'), '');
    assert.equal(calls.some(call => call.url.endsWith('catalogHubAnalyze')), false);
});

test('an in-flight source edit keeps its immutable target and locks cancel plus row actions', async () => {
    const sourceRow = {id: 7, name: 'Old', alias: '货源A', type: 2, domain: 'https://old.example', app_id: 'old-id', currency: 'CNY', currency_rate: '0'};
    const calls = [];
    let finishUpdate;
    const pendingUpdate = new Promise(resolve => { finishUpdate = resolve; });
    const runtime = createRuntime(async (url, request) => {
        calls.push({url, request});
        if (url.endsWith('catalogHubBootstrap')) {
            return jsonResponse({code: 200, data: {sources: [sourceRow], settings: {schema: 1, aliases: [{source_id: 7, alias: '货源A'}], rules: []}}});
        }
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: []}});
        if (url.endsWith('catalogHubSourceUpdate')) return pendingUpdate;
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();
    findByDataset(runtime.root, 'editSource').listeners.click();
    const cancel = findByDataset(runtime.root, 'cancelSourceEdit');
    const savePromise = findByDataset(runtime.root, 'connectSource').listeners.click();
    await flush(1);
    assert.equal(cancel.disabled, true);
    assert.equal(findByDataset(runtime.root, 'editSource').disabled, true);
    cancel.listeners.click();
    assert.equal(findByDataset(runtime.root, 'sourceFormAlias').value, '货源A', 'cancel changed the edit target while save was pending');
    assert.equal(findByDataset(runtime.root, 'sourceAlias'), null);
    assert.equal(findByDataset(runtime.root, 'analyzeSource').disabled, true);
    assert.equal(calls.some(call => call.url.endsWith('catalogHubSave')), false);
    finishUpdate(jsonResponse({code: 200, data: {source: {...sourceRow, name: 'Updated'}}}));
    await savePromise;
    await flush();
    assert.equal(findByDataset(runtime.root, 'sourceFormAlias').disabled, false);
    assert.equal(findByDataset(runtime.root, 'sourceFormAppKey').required, true);
    assert.ok(textValues(runtime.document.getElementById('catalog-hub-sources')).includes('货源A'));
});

test('paused and awaiting-confirmation tasks disable source editing', async () => {
    const sourceRow = {id: 7, name: 'One', alias: '货源A', type: 0, domain: 'https://one.example', app_id: 'one', currency: 'CNY', currency_rate: '0'};
    for (const state of ['paused', 'awaiting_confirmation']) {
        const runtime = createRuntime(async url => {
            if (url.endsWith('catalogHubBootstrap')) {
                return jsonResponse({code: 200, data: {sources: [sourceRow], settings: {schema: 1, aliases: [], rules: []}}});
            }
            if (url.endsWith('catalogHubTasks')) {
                return jsonResponse({code: 200, data: {tasks: [{task_id: state, source_id: 7, state, revision: 2}]}});
            }
            throw new Error(`unexpected URL: ${url}`);
        });
        await flush();
        assert.equal(findByDataset(runtime.root, 'editSource').disabled, true, state);
    }
});

test('awaiting-confirmation suggestions are editable and confirm with public premium default 0', async () => {
    const calls = [];
    const task = {
        task_id: 'task-confirm',
        source_id: 1,
        source_alias: '<货源A>',
        state: 'awaiting_confirmation',
        revision: 4,
        plan_hash: 'a'.repeat(64),
        premium_percent: 0,
        counts: {categories: 2, high: 1, low: 1},
        categories: [
            {name: '<AI Chat-GPT>', count: 9, target: {group: 'AI工具', family: 'GPT'}, confidence: 'high'},
            {name: '<未知分类>', count: 2, target: {group: '其他', family: ''}, confidence: 'low'},
        ],
    };
    const runtime = createRuntime(async (url, request) => {
        calls.push({url, request});
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: [task]}});
        if (url.endsWith('catalogHubConfirm')) {
            return jsonResponse({code: 200, data: {task: {...task, state: 'queued_import', revision: 5}}});
        }
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();
    const suggestions = runtime.document.getElementById('catalog-hub-suggestions');
    const rendered = textValues(suggestions);
    assert.ok(rendered.includes('<AI Chat-GPT>'));
    assert.ok(rendered.includes('<未知分类>'));
    assert.ok(rendered.includes('待确认'));
    assert.equal(runtime.timers.some(timer => timer.delay === 3000), false, 'awaiting confirmation is not an active polling state');
    const premium = findByDataset(suggestions, 'premiumPercent');
    assert.equal(premium.value, '0');
    const confirm = findElement(suggestions, element => element.textContent === '确认并后台入库');
    await confirm.listeners.click();

    const request = calls.find(call => call.url.endsWith('catalogHubConfirm')).request;
    const body = new URLSearchParams(request.body);
    assert.deepEqual(
        [...body.keys()].sort(),
        ['csrf_token', 'mappings_json', 'plan_hash', 'premium_percent', 'revision', 'task_id'],
    );
    assert.equal(body.get('premium_percent'), '0');
    assert.equal(body.get('revision'), '4');
    assert.equal(body.get('plan_hash'), 'a'.repeat(64));
    assert.deepEqual(JSON.parse(body.get('mappings_json')), [
        {source_category: '<AI Chat-GPT>', target: {group: 'AI工具', family: 'GPT'}, confidence: 'high'},
        {source_category: '<未知分类>', target: {group: '其他', family: ''}, confidence: 'low'},
    ]);
});

test('mirror confirmation preserves duplicate-name ID paths and ignores editable smart drafts', async () => {
    const pathA = [{id: 1, pid: 0, name: 'Telegram', sort: 0}, {id: 2, pid: 1, name: '货源A', sort: 0}, {id: 3, pid: 2, name: '同名分类', sort: 0}];
    const pathB = [{id: 11, pid: 0, name: '第二根', sort: 0}, {id: 13, pid: 11, name: '同名分类', sort: 0}];
    const task = {task_id: 'mirror-confirm', source_id: 1, source_alias: '仅后台别名', category_mode: 'mirror',
        state: 'awaiting_confirmation', revision: 4, plan_hash: 'c'.repeat(64), premium_percent: 0,
        categories: [pathA, pathB].map(path => ({name: '同名分类', count: 1, confidence: 'high', target: {mode: 'mirror', path}}))};
    const calls = [];
    const runtime = createRuntime(async (url, request) => {
        calls.push({url, request});
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: [task]}});
        if (url.endsWith('catalogHubConfirm')) return jsonResponse({code: 200, data: {task: {...task, state: 'queued_import', revision: 5}}});
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();
    const suggestions = runtime.document.getElementById('catalog-hub-suggestions');
    assert.equal(suggestions.querySelectorAll('[data-mapping-row]').length, 2);
    assert.equal(suggestions.querySelectorAll('[data-mapping-group]').length, 0);
    assert.equal(suggestions.querySelectorAll('[data-mapping-family]').length, 0);
    assert.ok(textValues(suggestions).includes('Telegram → 货源A → 同名分类'));
    assert.ok(textValues(suggestions).includes('第二根 → 同名分类'));
    assert.equal(textValues(suggestions).some(value => value.includes('仅后台别名 →')), false);
    const premium = findByDataset(suggestions, 'premiumPercent');
    premium.value = '8'; premium.listeners.input();
    const confirm = findElement(suggestions, element => element.textContent === '确认并后台入库');
    assert.equal(confirm.disabled, false);
    await confirm.listeners.click();
    const body = new URLSearchParams(calls.find(call => call.url.endsWith('catalogHubConfirm')).request.body);
    assert.deepEqual(JSON.parse(body.get('mappings_json')), task.categories.map(category => ({
        source_category: category.name, target: category.target, confidence: category.confidence,
    })));
    assert.equal(body.get('premium_percent'), '8');
});

test('mirror invalid ancestry cannot expose an enabled confirmation or fall back to smart fields', async () => {
    const task = {task_id: 'bad-mirror', source_id: 1, category_mode: 'mirror', state: 'awaiting_confirmation', revision: 1,
        plan_hash: 'd'.repeat(64), categories: [{name: 'leaf', count: 1, confidence: 'high',
            target: {mode: 'mirror', path: [{id: 2, pid: 99, name: 'leaf', sort: 0}]}}]};
    let mutations = 0;
    const runtime = createRuntime(async url => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: [task]}});
        mutations++; throw new Error('unexpected mutation');
    });
    await flush();
    const confirm = findElement(runtime.root, element => element.textContent === '确认并后台入库');
    assert.equal(confirm.disabled, true);
    await confirm.listeners.click();
    assert.equal(mutations, 0);
    assert.equal(findByDataset(runtime.root, 'mappingGroup'), null);
});

test('explicit source mirror selection is sent only in analysis and defaults remain smart', async () => {
    const runtime = draftRuntime({handle: async url => {
        assert.ok(url.endsWith('catalogHubAnalyze'));
        return jsonResponse({code: 200, data: {task: {task_id: 'mirror-queued', source_id: 1, category_mode: 'mirror', state: 'queued_analysis', revision: 1}}});
    }});
    await flush();
    const mode = findByDataset(runtime.root, 'sourceCategoryMode');
    assert.equal(mode.value, 'smart');
    mode.value = 'mirror'; mode.listeners.change();
    findByDataset(runtime.root, 'analyzeSource').listeners.click();
    await flush();
    const body = new URLSearchParams(runtime.calls.find(call => call.url.endsWith('catalogHubAnalyze')).request.body);
    assert.equal(body.get('category_mode'), 'mirror');
    assert.deepEqual([...body.keys()].sort(), ['alias', 'category_mode', 'csrf_token', 'source_id']);
    assert.equal(findByDataset(runtime.root, 'sourceCategoryMode').disabled, true);
});

test('mirror unsupported errors explain the stop without offering silent smart fallback', async () => {
    const runtime = createRuntime(async url => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        return jsonResponse({code: 200, data: {tasks: [{task_id: 'unsupported-mirror', source_id: 1, category_mode: 'mirror',
            state: 'failed', phase: 'analysis', revision: 2, error_code: 'MIRROR_TREE_UNAVAILABLE'}]}});
    });
    await flush();
    assert.ok(textValues(runtime.root).some(value => value.includes('不会压平或回退智能分类')));
    assert.equal(findByDataset(runtime.root, 'mappingGroup'), null);
});

test('active tasks poll at 3 seconds and PJAX aborts the in-flight poll', async () => {
    let taskRequests = 0;
    let secondSignal;
    const activeTask = {
        task_id: 'active-1', source_id: 1, source_alias: '货源A', state: 'importing', revision: 3,
        progress: {processed: 100, total: 3947, success: 100, failed: 0},
    };
    const runtime = createRuntime(async (url, request) => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        if (url.endsWith('catalogHubTasks')) {
            taskRequests += 1;
            if (taskRequests === 1) return jsonResponse({code: 200, data: {tasks: [activeTask]}});
            secondSignal = request.signal;
            return new Promise((resolve, reject) => {
                request.signal.addEventListener('abort', () => reject(new Error('aborted')), {once: true});
            });
        }
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();
    const poll = runtime.timers.find(timer => timer.delay === 3000);
    assert.ok(poll, 'active task must schedule a 3-second poll');
    poll.callback();
    await flush(1);
    assert.ok(secondSignal && !secondSignal.aborted, 'poll request must be in flight');
    runtime.triggerPjax();
    assert.equal(secondSignal.aborted, true);
    assert.ok(runtime.clearedTimers.length >= 0);
});

test('task cancellation sends only bounded fields and immediately unlocks source editing', async () => {
    const calls = [];
    const sourceRow = {id: 1, name: 'One', alias: '货源A', type: 0, domain: 'https://one.example', app_id: 'one', currency: 'CNY', currency_rate: '0'};
    const paused = {task_id: 'paused-1', source_id: 1, source_alias: '货源A', state: 'paused', revision: 9};
    const runtime = createRuntime(async (url, request) => {
        calls.push({url, request});
        if (url.endsWith('catalogHubBootstrap')) {
            return jsonResponse({code: 200, data: {sources: [sourceRow], settings: {schema: 1, aliases: [{source_id: 1, alias: '货源A'}], rules: []}}});
        }
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: [paused]}});
        if (url.endsWith('catalogHubTaskControl')) {
            return jsonResponse({code: 200, data: {task: {...paused, state: 'cancelled', revision: 10}}});
        }
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();
    assert.equal(findByDataset(runtime.root, 'editSource').disabled, true);
    const cancel = findElement(runtime.root, element => element.textContent === '取消');
    assert.ok(cancel?.listeners.click);
    await cancel.listeners.click();
    const control = calls.find(call => call.url.endsWith('catalogHubTaskControl'));
    const body = new URLSearchParams(control.request.body);
    assert.deepEqual([...body.keys()].sort(), ['action', 'csrf_token', 'revision', 'task_id']);
    assert.equal(body.get('action'), 'cancel');
    assert.equal(body.get('task_id'), 'paused-1');
    assert.equal(body.get('revision'), '9');
    assert.equal(findByDataset(runtime.root, 'editSource').disabled, false);
    assert.match(source, /taskActionButton\(task, 'pause'/);
    assert.match(source, /taskActionButton\(task, 'cancel'/);
});

test('a pending task control locks every action and a failure restores them', async () => {
    let rejectControl;
    const pendingControl = new Promise((resolve, reject) => { rejectControl = reject; });
    const paused = {task_id: 'paused-failure', source_id: 1, source_alias: '货源A', state: 'paused', revision: 3};
    const runtime = createRuntime(async url => {
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        if (url.endsWith('catalogHubTasks')) return jsonResponse({code: 200, data: {tasks: [paused]}});
        if (url.endsWith('catalogHubTaskControl')) return pendingControl;
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();
    const before = runtime.root.querySelectorAll('[data-task-action]');
    assert.equal(before.length, 2);
    const continueButton = before.find(button => button.dataset.taskAction === 'resume');
    continueButton.focus();
    const focusCount = runtime.document.focusCalls.length;
    const control = continueButton.listeners.click();
    await flush(1);
    const pending = runtime.root.querySelectorAll('[data-task-action]');
    assert.equal(pending.every(button => button.disabled), true);
    assert.ok(runtime.document.activeElement === runtime.document.body, 'a disabled action must not receive restored focus');
    assert.equal(runtime.document.focusCalls.length, focusCount);
    rejectControl(new Error('simulated control failure'));
    await control;
    await flush();
    const restored = runtime.root.querySelectorAll('[data-task-action]');
    assert.equal(restored.length, 2);
    assert.equal(restored.every(button => button.disabled === false), true);
    assert.equal(runtime.document.focusCalls.length, focusCount, 'a later control receipt must not replay old focus');
});

test('only a failed import detail-fetch checkpoint exposes manual continue', async () => {
    const retryable = {
        task_id: 'retryable-detail', source_id: 2, source_alias: '货源B', state: 'failed',
        phase: 'import', revision: 20, error_code: 'ITEM_DETAIL_FETCH_FAILED',
        snapshot: {sha256: 'a'.repeat(64), plan_hash: 'b'.repeat(64), item_count: 5076},
        premium_percent: '10', can_resume: true,
        progress: {total: 5076, processed: 13, succeeded: 13, failed: 0, skipped: 0},
    };
    const deterministic = {
        task_id: 'deterministic-detail', source_id: 3, source_alias: '货源C', state: 'failed',
        phase: 'import', revision: 4, error_code: 'ITEM_DETAIL_NORMALIZATION_FAILED',
        progress: {total: 10, processed: 1, succeeded: 1, failed: 0, skipped: 0},
    };
    const analysis = {
        task_id: 'failed-analysis', source_id: 4, source_alias: '货源D', state: 'failed',
        phase: 'analysis', revision: 2, error_code: 'ANALYSIS_FETCH_FAILED',
        progress: {total: 0, processed: 0, succeeded: 0, failed: 0, skipped: 0},
    };
    const incompleteCheckpoint = {
        ...retryable, task_id: 'missing-snapshot', source_id: 5, source_alias: '货源E',
        snapshot: null, can_resume: false,
    };
    const missingPremium = {
        ...retryable, task_id: 'missing-premium', source_id: 6, source_alias: '货源F',
        premium_percent: null, can_resume: false,
    };
    const alreadyProcessed = {
        ...retryable, task_id: 'already-processed', source_id: 7, source_alias: '货源G',
        can_resume: false,
        progress: {total: 13, processed: 13, succeeded: 13, failed: 0, skipped: 0},
    };
    const calls = [];
    const runtime = createRuntime(async (url, request) => {
        calls.push({url, request});
        if (url.endsWith('catalogHubBootstrap')) return jsonResponse(defaultBootstrap);
        if (url.endsWith('catalogHubTasks')) {
            return jsonResponse({
                code: 200,
                data: {tasks: [retryable, deterministic, analysis, incompleteCheckpoint, missingPremium, alreadyProcessed]},
            });
        }
        if (url.endsWith('catalogHubTaskControl')) {
            return jsonResponse({
                code: 200,
                data: {task: {...retryable, state: 'queued_import', revision: 21, error_code: null}},
            });
        }
        throw new Error(`unexpected URL: ${url}`);
    });
    await flush();
    const resumeButtons = runtime.root.querySelectorAll('[data-task-action]')
        .filter(button => button.dataset.taskAction === 'resume');
    assert.equal(resumeButtons.length, 1);
    assert.equal(resumeButtons[0].textContent, '继续原任务');
    const interruptedCard = runtime.document.getElementById('catalog-hub-task-retryable-detail');
    const interruptedText = textValues(interruptedCard).join('\n');
    assert.match(interruptedText, /已处理 13\/5076；成功 13；跳过 0；剩余 5063；任务已中断/);
    assert.doesNotMatch(interruptedText, /失败 0|失败商品 0/);
    assert.match(interruptedText, /本次已确认新商品加价：10%/);
    assert.match(interruptedText, /取消不会撤销已入库商品/);
    const cancelButtons = runtime.root.querySelectorAll('[data-task-action]').filter(button => button.dataset.taskAction === 'cancel');
    assert.equal(cancelButtons.length, 1);
    assert.equal(cancelButtons[0].textContent, '取消后结束此任务');
    await resumeButtons[0].listeners.click();
    const control = calls.find(call => call.url.endsWith('catalogHubTaskControl'));
    const body = new URLSearchParams(control.request.body);
    assert.equal(body.get('task_id'), 'retryable-detail');
    assert.equal(body.get('revision'), '20');
    assert.equal(body.get('action'), 'resume');
});

test('pre-analysis premium survives queued-task refresh and reaches confirmation without changing analyze fields', async () => {
    const storage = memoryStorage();
    const queued = {task_id: 'waiting-1', source_id: 1, source_alias: '测试货源1', state: 'queued_analysis', phase: 'analysis', revision: 1, snapshot: null};
    const runtime = draftRuntime({storage, handle: async url => {
        assert.ok(url.endsWith('catalogHubAnalyze'));
        return jsonResponse({code: 200, data: {task: queued}});
    }});
    await flush();
    assert.equal(findByDataset(runtime.root, 'sourcePremium').value, '0');
    enter(findByDataset(runtime.root, 'sourcePremium'), '12.5');
    findByDataset(runtime.root, 'analyzeSource').listeners.click();
    await flush();
    const analyze = runtime.calls.find(call => call.url.endsWith('catalogHubAnalyze'));
    assert.deepEqual([...new URLSearchParams(analyze.request.body).keys()].sort(), ['alias', 'category_mode', 'csrf_token', 'source_id']);
    assert.equal(findByDataset(runtime.root, 'sourcePremium').value, '12.5');
    const refreshed = draftRuntime({storage, tasks: [queued]});
    await flush();
    assert.equal(findByDataset(refreshed.root, 'sourcePremium').value, '12.5');
    const confirmation = draftRuntime({storage, tasks: [waitingTask()]});
    await flush();
    assert.equal(findByDataset(confirmation.root, 'premiumPercent').value, '12.5');
    assert.ok(textValues(confirmation.root).some(value => value.includes('已恢复本会话草稿')));
    assert.equal(confirmation.calls.some(call => /catalogHubConfirm|catalogHubTaskControl/.test(call.url)), false);
});

test('new-source premium reaches confirmation and is not sent with source credentials', async () => {
    const runtime = draftRuntime({sources: [], handle: async url => {
        if (url.endsWith('catalogHubConnect')) return jsonResponse({code: 200, data: {source_id: 1}});
        if (url.endsWith('catalogHubAnalyze')) return jsonResponse({code: 200, data: {task: waitingTask()}});
        throw new Error(`unexpected URL: ${url}`);
    }});
    await flush();
    enter(findByDataset(runtime.root, 'sourceFormAlias'), '新测试货源');
    enter(findByDataset(runtime.root, 'sourceFormDomain'), 'https://fixture.example');
    enter(findByDataset(runtime.root, 'sourceFormAppId'), 'fixture-id');
    enter(findByDataset(runtime.root, 'sourceFormAppKey'), 'fixture-key-only');
    enter(findByDataset(runtime.root, 'sourceFormPremium'), '10');
    await findByDataset(runtime.root, 'connectSource').listeners.click();
    assert.equal(findByDataset(runtime.root, 'premiumPercent').value, '10');
    const body = new URLSearchParams(runtime.calls.find(call => call.url.endsWith('catalogHubConnect')).request.body);
    assert.deepEqual([...body.keys()].sort(), ['app_id', 'app_key', 'csrf_token', 'currency', 'currency_rate', 'domain', 'type']);
    assert.equal(findByDataset(runtime.root, 'sourceFormPremium').value, '0');
    const persisted = JSON.stringify([...runtime.storage.records]);
    assert.doesNotMatch(persisted, /fixture\.example|fixture-id|fixture-key-only|fixture-csrf|新测试货源|app_key|app_id|domain|csrf/);
});

test('multiple awaiting tasks keep separate mapping and premium drafts across switching and refresh', async () => {
    const storage = memoryStorage();
    const tasks = [waitingTask(1), waitingTask(2)];
    const runtime = draftRuntime({storage, sources: [fixtureSource(1), fixtureSource(2)], tasks});
    await flush();
    enter(findByDataset(runtime.root, 'mappingGroup'), '一级草稿一');
    enter(findByDataset(runtime.root, 'mappingFamily'), '二级草稿一');
    enter(findByDataset(runtime.root, 'premiumPercent'), '11');
    let chooser = findByDataset(runtime.root, 'confirmationTask');
    chooser.value = 'waiting-2';
    chooser.listeners.change();
    enter(findByDataset(runtime.root, 'mappingGroup'), '一级草稿二');
    enter(findByDataset(runtime.root, 'premiumPercent'), '22');
    chooser = findByDataset(runtime.root, 'confirmationTask');
    chooser.value = 'waiting-1';
    chooser.listeners.change();
    assert.equal(findByDataset(runtime.root, 'mappingGroup').value, '一级草稿一');
    assert.equal(findByDataset(runtime.root, 'mappingFamily').value, '二级草稿一');
    assert.equal(findByDataset(runtime.root, 'premiumPercent').value, '11');
    const refreshed = draftRuntime({storage, sources: [fixtureSource(1), fixtureSource(2)], tasks});
    await flush();
    assert.equal(findByDataset(refreshed.root, 'mappingGroup').value, '一级草稿一');
    chooser = findByDataset(refreshed.root, 'confirmationTask');
    chooser.value = 'waiting-2';
    chooser.listeners.change();
    assert.equal(findByDataset(refreshed.root, 'mappingGroup').value, '一级草稿二');
    assert.equal(findByDataset(refreshed.root, 'premiumPercent').value, '22');
    assert.equal(refreshed.calls.some(call => call.url.endsWith('catalogHubConfirm')), false);
});

test('unrelated polling keeps the current input node and pending confirmation cannot be submitted twice', async () => {
    const waiting = waitingTask();
    const active = {task_id: 'active-2', source_id: 2, state: 'importing', phase: 'import', revision: 5};
    let finishConfirm;
    const pendingConfirm = new Promise(resolve => { finishConfirm = resolve; });
    const runtime = draftRuntime({tasks: [waiting, active], handle: async url => {
        assert.ok(url.endsWith('catalogHubConfirm'));
        return pendingConfirm;
    }});
    await flush();
    const group = findByDataset(runtime.root, 'mappingGroup');
    enter(group, '轮询保留');
    enter(findByDataset(runtime.root, 'premiumPercent'), '15');
    runtime.timers.at(-1).callback();
    await flush();
    assert.equal(findByDataset(runtime.root, 'mappingGroup'), group, 'unrelated poll replaced the focused input');
    const staleButton = confirmationButton(runtime);
    const submission = staleButton.listeners.click();
    await flush(1);
    assert.equal(confirmationButton(runtime).disabled, true);
    assert.equal(findByDataset(runtime.root, 'premiumPercent').disabled, true);
    runtime.timers.at(-1).callback();
    await flush();
    await staleButton.listeners.click();
    await confirmationButton(runtime).listeners.click();
    assert.equal(runtime.calls.filter(call => call.url.endsWith('catalogHubConfirm')).length, 1);
    finishConfirm(jsonResponse({code: 200, data: {task: {...waiting, state: 'queued_import', phase: 'import', revision: 4, premium_percent: '15'}}}));
    await submission;
    assert.equal(runtime.storage.records.has(taskDraftKey(waiting)), false, 'confirmed draft must be removed');
});

test('editing a source blocks same-source analysis and notices remain contextual across another task action', async () => {
    const paused = {task_id: 'paused-2', source_id: 2, state: 'paused', revision: 5};
    const runtime = draftRuntime({sources: [fixtureSource(1), fixtureSource(2)], tasks: [paused], handle: async url => {
        assert.ok(url.endsWith('catalogHubTaskControl'));
        throw new Error('private diagnostic must not be printed');
    }});
    await flush();
    findByDataset(runtime.root, 'editSource').listeners.click();
    findByDataset(runtime.root, 'analyzeSource').listeners.click();
    await flush();
    const sourceNotice = runtime.document.getElementById('catalog-hub-message-source-1');
    const before = textValues(sourceNotice).join('\n');
    assert.match(before, /请先保存修改或取消编辑/);
    assert.equal(runtime.calls.some(call => call.url.endsWith('catalogHubAnalyze')), false);
    await findByDataset(runtime.root, 'taskAction').listeners.click();
    assert.equal(textValues(runtime.document.getElementById('catalog-hub-message-source-1')).join('\n'), before);
    assert.match(textValues(runtime.document.getElementById('catalog-hub-message-task-paused-2')).join('\n'), /结果尚未核实.*不要重复提交/);
    assert.doesNotMatch(textValues(runtime.root).join('\n'), /private diagnostic/);
});

test('session scope and snapshot binding prevent reuse of another login or stale classification plan', async () => {
    const storage = memoryStorage();
    const original = waitingTask();
    const runtime = draftRuntime({storage, tasks: [original]});
    await flush();
    enter(findByDataset(runtime.root, 'mappingGroup'), '旧方案草稿');
    enter(findByDataset(runtime.root, 'premiumPercent'), '17');
    const otherLogin = draftRuntime({storage, scope: 'e'.repeat(64), tasks: [original]});
    await flush();
    assert.equal(findByDataset(otherLogin.root, 'mappingGroup').value, '默认一级');
    assert.equal(findByDataset(otherLogin.root, 'premiumPercent').value, '0');
    const changed = waitingTask(1, {snapshot: {...original.snapshot, sha256: 'c'.repeat(64)}});
    const newSnapshot = draftRuntime({storage, tasks: [changed]});
    await flush();
    assert.equal(findByDataset(newSnapshot.root, 'mappingGroup').value, '默认一级');
    assert.equal(findByDataset(newSnapshot.root, 'premiumPercent').value, '0');
    assert.equal(newSnapshot.calls.some(call => call.url.endsWith('catalogHubConfirm')), false);
});

test('expired, corrupt, oversized and non-whitelisted stored drafts are discarded', async () => {
    const task = waitingTask();
    const valid = {premium: 99, mappings: [{name: '上游分类1', group: '不应恢复', family: ''}], expiresAt: Date.now() + 60000};
    const invalidRecords = [
        '{bad json', 'x'.repeat(65537), 'null', '[]',
        JSON.stringify({...valid, expiresAt: Date.now() - 1}),
        JSON.stringify({...valid, expiresAt: Date.now() + 13 * 60 * 60 * 1000}),
        JSON.stringify({...valid, premium: -1}),
        JSON.stringify({...valid, app_key: 'forbidden-field'}),
        JSON.stringify({...valid, mappings: [{name: '不同分类', group: '不应恢复', family: ''}]}),
    ];
    for (const raw of invalidRecords) {
        const storage = memoryStorage([[taskDraftKey(task), raw]]);
        const runtime = draftRuntime({storage, tasks: [task]});
        await flush();
        assert.equal(findByDataset(runtime.root, 'mappingGroup').value, '默认一级');
        assert.equal(findByDataset(runtime.root, 'premiumPercent').value, '0');
        assert.doesNotMatch(JSON.stringify([...storage.records]), /forbidden-field|不应恢复/);
    }
});

test('unavailable or quota-limited session storage preserves in-page drafts and shows a refresh warning', async () => {
    for (const storage of [
        {getItem() { throw new Error('SecurityError'); }},
        {...memoryStorage(), get length() { return 0; }, setItem() { throw new Error('QuotaExceededError'); }},
    ]) {
        const runtime = draftRuntime({storage, tasks: [waitingTask(1), waitingTask(2)]});
        await flush();
        enter(findByDataset(runtime.root, 'mappingGroup'), '内存草稿');
        enter(findByDataset(runtime.root, 'premiumPercent'), '16');
        let chooser = findByDataset(runtime.root, 'confirmationTask');
        chooser.value = 'waiting-2';
        chooser.listeners.change();
        chooser = findByDataset(runtime.root, 'confirmationTask');
        chooser.value = 'waiting-1';
        chooser.listeners.change();
        assert.equal(findByDataset(runtime.root, 'mappingGroup').value, '内存草稿');
        assert.equal(findByDataset(runtime.root, 'premiumPercent').value, '16');
        assert.match(textValues(runtime.document.getElementById('catalog-hub-draft-status')).join('\n'), /无法跨刷新保存草稿/);
    }
});

test('draft persistence remains bounded and stores only numeric premium, mapping strings and expiration metadata', async () => {
    const records = Array.from({length: 40}, (_, index) => [`${draftPrefix}source:${index + 10}`, JSON.stringify({premium: index, mappings: [], expiresAt: Date.now() + 60000})]);
    const storage = memoryStorage(records);
    const runtime = draftRuntime({storage, tasks: [waitingTask()]});
    await flush();
    enter(findByDataset(runtime.root, 'premiumPercent'), '10');
    assert.ok(storage.length <= 32);
    assert.ok([...storage.records.values()].reduce((sum, raw) => sum + raw.length * 2, 0) <= 524288);
    for (const [key, raw] of storage.records) {
        assert.ok(key.startsWith(draftPrefix));
        const value = JSON.parse(raw);
        assert.deepEqual(Object.keys(value).sort(), ['expiresAt', 'mappings', 'premium']);
        assert.equal(typeof value.premium, 'number');
        for (const mapping of value.mappings) assert.deepEqual(Object.keys(mapping).sort(), ['family', 'group', 'name']);
    }
    assert.doesNotMatch(JSON.stringify([...storage.records]), /https:|fixture-csrf|fixture-1|source_alias|app_key|app_id|domain|csrf/);
});

test('repeat analysis explains an inherited confirmed premium while a fresh source still starts at zero', async () => {
    const completed = {task_id: 'completed-1', source_id: 1, state: 'completed', phase: 'import', revision: 9, premium_percent: '10', updated_at: '2026-09-05T01:00:00Z'};
    const runtime = draftRuntime({sources: [fixtureSource(1), fixtureSource(2)], tasks: [completed]});
    await flush();
    const prices = runtime.root.querySelectorAll('[data-source-premium]');
    assert.equal(prices.find(input => input.dataset.sourcePremium === '1').value, '10');
    assert.equal(prices.find(input => input.dataset.sourcePremium === '2').value, '0');
    assert.match(textValues(runtime.root).join('\n'), /沿用上次确认的加价，仅本次确认后生效/);
});

test('a resumable failed task locks source edits and its explicit cancel preserves import facts while unlocking a new run', async () => {
    const failed = waitingTask(1, {state: 'failed', phase: 'import', can_resume: true, premium_percent: '10', error_code: 'ITEM_DETAIL_FETCH_FAILED', progress: {total: 2, processed: 1, succeeded: 1, skipped: 0, failed: 0}});
    const runtime = draftRuntime({tasks: [failed], handle: async (url, request) => {
        assert.ok(url.endsWith('catalogHubTaskControl'));
        const body = new URLSearchParams(request.body);
        assert.equal(body.get('action'), 'cancel');
        return jsonResponse({code: 200, data: {task: {...failed, state: 'cancelled', revision: 4, can_resume: false}}});
    }});
    await flush();
    assert.equal(findByDataset(runtime.root, 'editSource').disabled, true);
    assert.equal(findByDataset(runtime.root, 'sourcePremium').disabled, true);
    assert.equal(findByDataset(runtime.root, 'sourcePremium').value, '10');
    const cancel = runtime.root.querySelectorAll('[data-task-action]').find(button => button.dataset.taskAction === 'cancel');
    await cancel.listeners.click();
    assert.equal(findByDataset(runtime.root, 'editSource').disabled, false);
    assert.equal(findByDataset(runtime.root, 'sourcePremium').disabled, false);
    assert.equal(findByDataset(runtime.root, 'analyzeSource').textContent, '重新分析');
    const card = runtime.document.getElementById('catalog-hub-task-waiting-1');
    assert.match(textValues(card).join('\n'), /本次已确认新商品加价：10%/);
    assert.match(textValues(card).join('\n'), /已处理 1\/2；成功 1；跳过 0；剩余 1/);
});

test('an incomplete confirmation receipt never claims success or drops the unconfirmed draft', async () => {
    const runtime = draftRuntime({tasks: [waitingTask()], handle: async url => {
        assert.ok(url.endsWith('catalogHubConfirm'));
        return jsonResponse({code: 200, data: {}});
    }});
    await flush();
    enter(findByDataset(runtime.root, 'mappingGroup'), '保留待核实草稿');
    enter(findByDataset(runtime.root, 'premiumPercent'), '21');
    await confirmationButton(runtime).listeners.click();
    assert.equal(findByDataset(runtime.root, 'mappingGroup').value, '保留待核实草稿');
    assert.equal(findByDataset(runtime.root, 'premiumPercent').value, '21');
    assert.equal(runtime.storage.records.has(taskDraftKey(waitingTask())), true);
    assert.match(textValues(runtime.document.getElementById('catalog-hub-message-confirm-waiting-1')).join('\n'), /结果尚未核实.*不要重复提交/);
    assert.equal(runtime.calls.filter(call => call.url.endsWith('catalogHubConfirm')).length, 1);
});

test('safe task diagnostic codes and trace IDs are shown in collapsed contextual details', async () => {
    const paused = {task_id: 'paused-diagnostic', source_id: 1, state: 'paused', revision: 3};
    const runtime = draftRuntime({tasks: [paused], handle: async url => {
        assert.ok(url.endsWith('catalogHubTaskControl'));
        return jsonResponse({code: 409, msg: '请核对任务状态。', data: {error_code: 'TASK_STATE_CHANGED', trace_id: 'fixture-trace-123'}});
    }});
    await flush();
    await findByDataset(runtime.root, 'taskAction').listeners.click();
    const notice = runtime.document.getElementById('catalog-hub-message-task-paused-diagnostic');
    const details = findElement(notice, element => element.tag === 'details');
    assert.ok(details);
    assert.equal(Boolean(details.open), false);
    assert.match(textValues(details).join('\n'), /TASK_STATE_CHANGED/);
    assert.match(textValues(details).join('\n'), /fixture-trace-123/);
    assert.doesNotMatch(JSON.stringify([...runtime.storage.records]), /TASK_STATE_CHANGED|fixture-trace-123/);
});

test('msg-only confirmation range and stale-plan rejections retain the backend safe guidance', async () => {
    for (const message of ['后台任务加价百分比超出 0-1000 范围。', '分类方案已变化，请重新分析后确认。']) {
        const runtime = draftRuntime({tasks: [waitingTask()], handle: async url => {
            assert.ok(url.endsWith('catalogHubConfirm'));
            return jsonResponse({code: 500, msg: message});
        }});
        await flush();
        enter(findByDataset(runtime.root, 'mappingGroup'), '仍未确认的草稿');
        await confirmationButton(runtime).listeners.click();
        const notice = runtime.document.getElementById('catalog-hub-message-confirm-waiting-1');
        assert.equal(notice.children[0].textContent, message);
        assert.equal(findByDataset(runtime.root, 'mappingGroup').value, '仍未确认的草稿');
        assert.equal(runtime.calls.filter(call => call.url.endsWith('catalogHubConfirm')).length, 1);
    }
});

test('a msg-only resume lock rejection retains the precise safe backend next action', async () => {
    const message = '该货源正在同步或修改，请刷新任务状态后再继续。';
    const paused = {task_id: 'paused-msg-only', source_id: 1, state: 'paused', revision: 3};
    const runtime = draftRuntime({tasks: [paused], handle: async url => {
        assert.ok(url.endsWith('catalogHubTaskControl'));
        return jsonResponse({code: 500, msg: message});
    }});
    await flush();
    const resume = runtime.root.querySelectorAll('[data-task-action]').find(button => button.dataset.taskAction === 'resume');
    await resume.listeners.click();
    assert.equal(runtime.document.getElementById('catalog-hub-message-task-paused-msg-only').children[0].textContent, message);
    assert.equal(runtime.calls.filter(call => call.url.endsWith('catalogHubTaskControl')).length, 1);
});

test('network, HTML, JSON-parse and missing-receipt confirmation outcomes stay unknown without claiming zero writes', async () => {
    const responses = [
        async () => { throw new Error('network disconnected; private raw diagnostic'); },
        async () => ({ok: false, headers: {get: () => 'text/html'}, json: async () => ({code: 500, msg: 'not a JSON API rejection'})}),
        async () => ({ok: false, headers: {get: () => 'application/json'}, json: async () => { throw new Error('invalid JSON'); }}),
        async () => jsonResponse({code: 200, data: {}}),
    ];
    for (const respond of responses) {
        const runtime = draftRuntime({tasks: [waitingTask()], handle: respond});
        await flush();
        await confirmationButton(runtime).listeners.click();
        const text = textValues(runtime.document.getElementById('catalog-hub-message-confirm-waiting-1')).join('\n');
        assert.equal(text, '结果尚未核实，请先刷新任务列表；不要重复提交。');
        assert.doesNotMatch(text, /未写入|写入 0|没有写入|network|private raw|invalid JSON/);
        assert.equal(runtime.calls.filter(call => call.url.endsWith('catalogHubConfirm')).length, 1);
        assert.equal(runtime.storage.records.has(taskDraftKey(waitingTask())), true);
    }
});
