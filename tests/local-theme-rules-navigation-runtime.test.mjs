import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = await readFile(path.join(root, 'themes/Pika/Assets/index.js'), 'utf8');

class FakeTarget {
  constructor() {
    this.listeners = new Map();
  }

  addEventListener(type, listener) {
    const listeners = this.listeners.get(type) ?? [];
    listeners.push(listener);
    this.listeners.set(type, listeners);
  }

  removeEventListener(type, listener) {
    const listeners = this.listeners.get(type) ?? [];
    this.listeners.set(type, listeners.filter(candidate => candidate !== listener));
  }

  emit(type, event = {}) {
    for (const listener of [...(this.listeners.get(type) ?? [])]) {
      listener(event);
    }
  }
}

class FakeClassList {
  constructor(names = []) {
    this.names = new Set(names);
  }

  contains(name) {
    return this.names.has(name);
  }

  toggle(name, force) {
    const enabled = force === undefined ? !this.names.has(name) : Boolean(force);
    if (enabled) {
      this.names.add(name);
    } else {
      this.names.delete(name);
    }
    return enabled;
  }

  replace(names) {
    this.names = new Set(names);
  }
}

class FakeNode extends FakeTarget {
  constructor(tagName = 'div', classNames = []) {
    super();
    this.nodeName = tagName.toUpperCase();
    this.dataset = {};
    this.attributes = new Map();
    this.classList = new FakeClassList(classNames);
    this.children = [];
    this.parentElement = null;
    this.hidden = false;
    this.isConnected = true;
    this.textContent = '';
  }

  set className(value) {
    this.classList.replace(String(value).split(/\s+/).filter(Boolean));
  }

  get className() {
    return [...this.classList.names].join(' ');
  }

  appendChild(child) {
    this.children.push(child);
    if (child && typeof child === 'object') {
      child.parentElement = this;
    }
    return child;
  }

  replaceChildren(...children) {
    this.children = children;
  }

  setAttribute(name, value) {
    this.attributes.set(name, String(value));
  }

  getAttribute(name) {
    return this.attributes.has(name) ? this.attributes.get(name) : null;
  }

  removeAttribute(name) {
    this.attributes.delete(name);
  }

  querySelector() {
    return null;
  }

  querySelectorAll() {
    return [];
  }

  closest() {
    return null;
  }
}

function clickEvent() {
  return {
    defaultPrevented: false,
    propagationStopped: false,
    button: 0,
    metaKey: false,
    ctrlKey: false,
    shiftKey: false,
    altKey: false,
    preventDefault() {
      this.defaultPrevented = true;
    },
    stopPropagation() {
      this.propagationStopped = true;
    },
  };
}

function createRuntime({
  hash = '',
  pathname = '/',
  search = '',
  categoryCount = 1,
  categoryIds = null,
  pushStateThrows = false,
  hasAnimationFrame = true,
} = {}) {
  const requests = [];
  const scrollCalls = [];
  const pushCalls = [];
  const replaceCalls = [];
  const cancelledFrames = [];
  const timers = new Map();
  const frames = new Map();
  let nextTimerId = 1;
  let nextFrameId = 1;

  const locationState = { pathname, search, hash };
  const location = {};
  Object.defineProperties(location, {
    pathname: {
      get: () => locationState.pathname,
      set: value => { locationState.pathname = String(value); },
      enumerable: true,
    },
    search: {
      get: () => locationState.search,
      set: value => { locationState.search = String(value); },
      enumerable: true,
    },
    hash: {
      get: () => locationState.hash,
      set: value => {
        const next = String(value);
        locationState.hash = next === '' || next.startsWith('#') ? next : `#${next}`;
      },
      enumerable: true,
    },
  });

  function applyUrl(url) {
    const value = String(url);
    if (value.startsWith('#')) {
      location.hash = value;
      return;
    }
    const hashAt = value.indexOf('#');
    const beforeHash = hashAt === -1 ? value : value.slice(0, hashAt);
    const searchAt = beforeHash.indexOf('?');
    location.pathname = searchAt === -1 ? beforeHash : beforeHash.slice(0, searchAt);
    location.search = searchAt === -1 ? '' : beforeHash.slice(searchAt);
    location.hash = hashAt === -1 ? '' : value.slice(hashAt);
  }

  const initialHistoryState = { pjax: 'preserve-me' };
  const history = {
    state: initialHistoryState,
    pushState(state, unused, url) {
      pushCalls.push({ state, unused, url });
      if (pushStateThrows) {
        throw new Error('simulated pushState failure');
      }
      this.state = state;
      applyUrl(url);
    },
    replaceState(state, unused, url) {
      replaceCalls.push({ state, unused, url });
      this.state = state;
      applyUrl(url);
    },
  };

  const itemList = new FakeNode('div', ['item-list']);
  const rules = new FakeNode('section', ['fbfaka-rules']);
  rules.scrollIntoView = options => scrollCalls.push(options);
  const rulesLink = new FakeNode('a');
  rulesLink.setAttribute('href', '/#fbfaka-rules');

  const effectiveCategoryIds = categoryIds ?? Array.from(
    { length: categoryCount },
    (_, index) => String(index + 1),
  );
  const categories = effectiveCategoryIds.map(id => {
    const category = new FakeNode('a', ['switch-category']);
    category.dataset.id = String(id);
    category.dataset.count = '1';
    return category;
  });

  const document = new FakeTarget();
  document.querySelectorAll = selector => {
    if (selector === '.switch-category') return categories;
    if (selector === '.category-parent') return [];
    if (selector === '[data-pika-rules-navigation]') return [rulesLink];
    if (selector === '.item-search-input') return [];
    return [];
  };
  document.querySelector = selector => selector === '.item-list' ? itemList : null;
  document.getElementById = id => id === 'fbfaka-rules' ? rules : null;
  document.createElement = tagName => new FakeNode(tagName);
  document.createDocumentFragment = () => new FakeNode('#fragment');
  document.createTextNode = text => ({ nodeName: '#text', textContent: String(text) });

  const window = new FakeTarget();
  Object.assign(window, {
    location,
    history,
    getVar: key => key === 'CAT_ID' && categories[0] ? categories[0].dataset.id : '',
    i18n: value => value,
    PikaSafeDom: {
      safeResourceUrl: (value, fallback) => typeof value === 'string' && value ? value : fallback,
    },
    setTimeout(callback, delay) {
      const id = nextTimerId++;
      timers.set(id, { callback, delay });
      return id;
    },
    clearTimeout(id) {
      timers.delete(id);
    },
  });
  if (hasAnimationFrame) {
    window.requestAnimationFrame = callback => {
      const id = nextFrameId++;
      frames.set(id, callback);
      return id;
    };
    window.cancelAnimationFrame = id => {
      cancelledFrames.push(id);
      frames.delete(id);
    };
  }

  const trade = {
    getCommodityList(options) {
      requests.push(options);
    },
  };
  window.trade = trade;

  vm.runInNewContext(source, {
    window,
    document,
    trade,
    format: { currencySymbol: () => '¥' },
  }, { filename: 'themes/Pika/Assets/index.js' });

  function clickRules() {
    const event = clickEvent();
    rulesLink.emit('click', event);
    return event;
  }

  function flushFrames() {
    const pending = [...frames.entries()];
    frames.clear();
    for (const [, callback] of pending) callback();
  }

  function fireWatchdog() {
    const entry = [...timers.entries()][0];
    assert.ok(entry, 'expected a pending request watchdog');
    timers.delete(entry[0]);
    entry[1].callback();
  }

  return {
    window,
    document,
    itemList,
    rules,
    requests,
    scrollCalls,
    pushCalls,
    replaceCalls,
    cancelledFrames,
    frames,
    timers,
    initialHistoryState,
    clickRules,
    flushFrames,
    fireWatchdog,
    setHash(nextHash) { location.hash = nextHash; },
    emitHashChange() { window.emit('hashchange', {}); },
  };
}

function assertSingleRulesScroll(runtime) {
  assert.equal(runtime.scrollCalls.length, 1);
  assert.equal(runtime.scrollCalls[0].block, 'start');
  assert.equal(runtime.scrollCalls[0].behavior, 'auto');
}

test('initial rules hash waits for an asynchronous commodity success', () => {
  const runtime = createRuntime({ hash: '#fbfaka-rules' });
  assert.equal(runtime.requests.length, 1);
  assert.equal(runtime.frames.size, 0);
  assert.equal(runtime.scrollCalls.length, 0);

  runtime.requests[0].done([]);
  assert.equal(runtime.frames.size, 1);
  runtime.flushFrames();
  assertSingleRulesScroll(runtime);

  runtime.requests[0].done([]);
  runtime.requests[0].error();
  runtime.flushFrames();
  assertSingleRulesScroll(runtime);
});

test('rules click during loading is intercepted and settles after the request', () => {
  const runtime = createRuntime();
  const event = runtime.clickRules();

  assert.equal(event.defaultPrevented, true);
  assert.equal(event.propagationStopped, true);
  assert.equal(runtime.window.location.hash, '#fbfaka-rules');
  assert.equal(runtime.pushCalls.length, 1);
  assert.equal(runtime.pushCalls[0].state, runtime.initialHistoryState);
  assert.equal(runtime.scrollCalls.length, 0);
  assert.equal(runtime.frames.size, 0);

  runtime.requests[0].done([]);
  runtime.flushFrames();
  assertSingleRulesScroll(runtime);
});

test('rules click after loading schedules exactly one scroll', () => {
  const runtime = createRuntime();
  runtime.requests[0].done([]);

  const event = runtime.clickRules();
  runtime.emitHashChange();
  runtime.emitHashChange();
  assert.equal(event.defaultPrevented, true);
  assert.equal(runtime.frames.size, 1);

  runtime.flushFrames();
  assertSingleRulesScroll(runtime);
});

test('request errors and watchdog timeouts both release a pending rules scroll', async t => {
  await t.test('error callback', () => {
    const runtime = createRuntime({ hash: '#fbfaka-rules' });
    runtime.requests[0].error();
    runtime.flushFrames();
    assertSingleRulesScroll(runtime);
    assert.equal(runtime.timers.size, 0);
  });

  await t.test('timeout callback', () => {
    const runtime = createRuntime({ hash: '#fbfaka-rules' });
    runtime.fireWatchdog();
    runtime.flushFrames();
    assertSingleRulesScroll(runtime);

    runtime.requests[0].error();
    runtime.flushFrames();
    assertSingleRulesScroll(runtime);
  });
});

test('an empty category tree still settles an initial rules hash', () => {
  const runtime = createRuntime({ hash: '#fbfaka-rules', categoryCount: 0 });
  assert.equal(runtime.requests.length, 0);
  assert.equal(runtime.frames.size, 1);
  runtime.flushFrames();
  assertSingleRulesScroll(runtime);
});

test('changing away from the rules hash cancels a pending scroll intent', () => {
  const runtime = createRuntime({ hash: '#fbfaka-rules' });
  runtime.requests[0].done([]);
  assert.equal(runtime.frames.size, 1);

  runtime.setHash('#somewhere-else');
  runtime.emitHashChange();
  runtime.flushFrames();
  assert.equal(runtime.scrollCalls.length, 0);
});

test('destroying the storefront runtime before RAF prevents stale scrolling', () => {
  const runtime = createRuntime({ hash: '#fbfaka-rules' });
  runtime.requests[0].done([]);
  assert.equal(runtime.frames.size, 1);

  runtime.window.__pikaCategoryRuntime.destroy();
  assert.equal(runtime.window.__pikaCategoryRuntime, null);
  assert.equal(runtime.cancelledFrames.length, 1);
  runtime.flushFrames();
  assert.equal(runtime.scrollCalls.length, 0);
});

test('missing requestAnimationFrame uses the synchronous scroll fallback', () => {
  const runtime = createRuntime({ hash: '#fbfaka-rules', hasAnimationFrame: false });
  assert.equal(runtime.scrollCalls.length, 0);

  runtime.requests[0].done([]);
  assert.equal(runtime.frames.size, 0);
  assertSingleRulesScroll(runtime);
});

test('rules click on a category URL preserves its path, query and history state', () => {
  const runtime = createRuntime({
    pathname: '/cat/7',
    search: '?x=1',
    categoryIds: ['7'],
  });
  runtime.requests[0].done([]);

  const event = runtime.clickRules();
  assert.equal(event.defaultPrevented, true);
  assert.equal(event.propagationStopped, true);
  assert.equal(runtime.window.location.pathname, '/cat/7');
  assert.equal(runtime.window.location.search, '?x=1');
  assert.equal(runtime.window.location.hash, '#fbfaka-rules');
  assert.equal(runtime.window.history.state, runtime.initialHistoryState);
  assert.equal(runtime.pushCalls[0].state, runtime.initialHistoryState);
  assert.equal(runtime.pushCalls[0].url, '#fbfaka-rules');

  runtime.flushFrames();
  assertSingleRulesScroll(runtime);
});

test('pushState failure leaves the absolute rules link to native navigation', () => {
  const runtime = createRuntime({ pushStateThrows: true });
  runtime.requests[0].done([]);

  const event = runtime.clickRules();
  assert.equal(event.defaultPrevented, false);
  assert.equal(event.propagationStopped, false);
  assert.equal(runtime.window.location.hash, '');
  assert.equal(runtime.window.history.state, runtime.initialHistoryState);
  assert.equal(runtime.pushCalls.length, 1);
  assert.equal(runtime.pushCalls[0].state, runtime.initialHistoryState);
  assert.equal(runtime.frames.size, 0);
  assert.equal(runtime.scrollCalls.length, 0);
});
