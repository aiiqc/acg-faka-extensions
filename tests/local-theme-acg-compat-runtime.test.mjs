import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = fs.readFileSync(path.join(root, 'themes/Pika/Assets/pika.js'), 'utf8');

class FakeDocument {
    constructor() {
        this.readyState = 'loading';
        this.listeners = new Map();
        this.targets = new Map();
    }

    addEventListener(type, listener, options) {
        const listeners = this.listeners.get(type) ?? [];
        listeners.push({listener, options});
        this.listeners.set(type, listeners);
    }

    emit(type, event) {
        for (const {listener} of this.listeners.get(type) ?? []) {
            listener(event);
        }
    }

    listenerCount(type) {
        return (this.listeners.get(type) ?? []).length;
    }

    querySelector(selector) {
        return this.targets.get(selector) ?? null;
    }
}

class FakeElement {
    constructor(attributes = {}) {
        this.attributes = new Map(Object.entries(attributes));
        this.clickCount = 0;
        this.src = '';
    }

    closest(selector) {
        if (selector === '[data-acg-proxy],[data-acg-refresh]'
            && (this.hasAttribute('data-acg-proxy') || this.hasAttribute('data-acg-refresh'))) {
            return this;
        }
        return null;
    }

    getAttribute(name) {
        return this.attributes.has(name) ? this.attributes.get(name) : null;
    }

    hasAttribute(name) {
        return this.attributes.has(name);
    }

    click() {
        this.clickCount += 1;
    }
}

function clickEvent(target) {
    return {
        target,
        defaultPrevented: false,
        button: 0,
        metaKey: false,
        ctrlKey: false,
        shiftKey: false,
        altKey: false,
        preventDefault() {
            this.defaultPrevented = true;
        },
        stopPropagation() {},
    };
}

function submitEvent(target) {
    return {
        target,
        defaultPrevented: false,
        preventDefault() {
            this.defaultPrevented = true;
        },
    };
}

function createRuntime({official = false} = {}) {
    const document = new FakeDocument();
    const assigned = [];
    const window = {
        location: {
            assign(value) {
                assigned.push(value);
            },
        },
    };
    if (official) {
        window.__acgBind = {sweep() {}};
    }

    vm.runInNewContext(source, {
        window,
        document,
        navigator: {},
        WeakMap,
        Set,
        Date: {now: () => 1700000000000},
    }, {filename: 'themes/Pika/Assets/pika.js'});

    return {document, window, assigned};
}

test('theme fallback implements refresh, proxy and submit prevention without the official binder', () => {
    const runtime = createRuntime();

    const captcha = new FakeElement({
        'data-acg-refresh': '/user/captcha/image?action=login',
    });
    runtime.document.emit('click', clickEvent(captcha));
    assert.equal(captcha.src, '/user/captcha/image?action=login&t=1700000000000');

    const input = new FakeElement();
    runtime.document.targets.set('.avatar-input', input);
    const proxy = new FakeElement({'data-acg-proxy': '.avatar-input'});
    runtime.document.emit('click', clickEvent(proxy));
    assert.equal(input.clickCount, 1);

    const form = new FakeElement({'data-acg-prevent': ''});
    const submit = submitEvent(form);
    runtime.document.emit('submit', submit);
    assert.equal(submit.defaultPrevented, true);
});

test('theme fallback remains delegated for captcha and upload controls added after startup', () => {
    const runtime = createRuntime();
    const dynamicInput = new FakeElement();
    runtime.document.targets.set('.wechat-input', dynamicInput);

    const dynamicCaptcha = new FakeElement({
        'data-acg-refresh': '/user/captcha/image?action=emailForgetCaptcha',
    });
    const dynamicProxy = new FakeElement({'data-acg-proxy': '.wechat-input'});
    runtime.document.emit('click', clickEvent(dynamicCaptcha));
    runtime.document.emit('click', clickEvent(dynamicProxy));

    assert.equal(
        dynamicCaptcha.src,
        '/user/captcha/image?action=emailForgetCaptcha&t=1700000000000',
    );
    assert.equal(dynamicInput.clickCount, 1);
});

test('an official binder present at startup suppresses the theme fallback listeners', () => {
    const runtime = createRuntime({official: true});
    assert.equal(runtime.document.listenerCount('click'), 2, 'only the two unrelated theme click handlers remain');
    assert.equal(runtime.document.listenerCount('submit'), 0);

    const captcha = new FakeElement({
        'data-acg-refresh': '/user/captcha/image?action=register',
    });
    runtime.document.emit('click', clickEvent(captcha));
    assert.equal(captcha.src, '');
});

test('a late official binder takes over without a duplicate theme action', () => {
    const runtime = createRuntime();
    let officialRefreshes = 0;
    runtime.document.addEventListener('click', event => {
        if (event.target.hasAttribute('data-acg-refresh')) {
            officialRefreshes += 1;
            event.target.src = 'official-refresh';
        }
    });
    runtime.window.__acgBind = {sweep() {}};

    const captcha = new FakeElement({
        'data-acg-refresh': '/user/captcha/image?action=register',
    });
    runtime.document.emit('click', clickEvent(captcha));

    assert.equal(officialRefreshes, 1);
    assert.equal(captcha.src, 'official-refresh');
});

test('theme fallback rejects selectors and refresh destinations outside its narrow contract', () => {
    const runtime = createRuntime();
    const input = new FakeElement();
    runtime.document.targets.set('#avatar-input', input);

    const unsafeProxy = new FakeElement({'data-acg-proxy': '#avatar-input'});
    const unsafeRefresh = new FakeElement({'data-acg-refresh': '//evil.example/captcha'});
    runtime.document.emit('click', clickEvent(unsafeProxy));
    runtime.document.emit('click', clickEvent(unsafeRefresh));

    assert.equal(input.clickCount, 0);
    assert.equal(unsafeRefresh.src, '');
});
