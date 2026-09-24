import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = fs.readFileSync(path.join(root, 'themes/Pika/Assets/safe-dom.js'), 'utf8');
const themeSource = fs.readFileSync(path.join(root, 'themes/Pika/Assets/pika.js'), 'utf8');

function runtime(protocol = 'https:') {
    const document = {
        createElement() {
            let text = '';
            return {
                set textContent(value) { text = String(value); },
                get textContent() { return text; },
                get innerHTML() {
                    return text.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
                },
            };
        },
    };
    const window = {location: {origin: `${protocol}//shop.example`, protocol}};
    vm.runInNewContext(source, {window, document, URL, Object});
    return window.PikaSafeDom;
}

test('safe payment navigation accepts valid destinations and blocks executable or downgrade URLs', () => {
    const safeDom = runtime();
    assert.equal(safeDom.safeNavigationUrl('/pay/checkout?id=1'), '/pay/checkout?id=1');
    assert.equal(safeDom.safeNavigationUrl('https://pay.example/checkout'), 'https://pay.example/checkout');

    for (const unsafe of [
        'javascript:alert(1)',
        'data:text/html,<script>alert(1)</script>',
        '//pay.example/checkout',
        'https://user:secret@pay.example/checkout',
        'http://pay.example/checkout',
        '/pay/../admin',
        'https://pay.example/checkout\nX-Test: yes',
    ]) {
        assert.equal(safeDom.safeNavigationUrl(unsafe), null, unsafe);
    }
});

test('HTTP development pages may use HTTP payment URLs without permitting other schemes', () => {
    const safeDom = runtime('http:');
    assert.equal(safeDom.safeNavigationUrl('http://pay.example/checkout'), 'http://pay.example/checkout');
    assert.equal(safeDom.safeNavigationUrl('file:///tmp/pay'), null);
});

test('HTML-looking values stay inert when converted to text', () => {
    const safeDom = runtime();
    for (const payload of ['<img src=x onerror=alert(1)>', '<svg><script>alert(1)</script></svg>']) {
        assert.doesNotMatch(safeDom.textHtml(payload), /<(?:img|svg|script)\b/i);
    }
});

function fullNavigationRuntime() {
    const listeners = [];
    const assigned = [];
    const document = {
        readyState: 'loading',
        addEventListener(type, listener, options) {
            listeners.push({type, listener, options});
        },
    };
    const window = {
        location: {
            assign(url) {
                assigned.push(url);
            },
        },
    };
    vm.runInNewContext(themeSource, {window, document, navigator: {}, WeakMap, Set});
    const capture = listeners.find(entry => entry.type === 'click' && entry.options === true)?.listener;
    assert.equal(typeof capture, 'function', 'capture-phase navigation handler is missing');
    return {capture, assigned};
}

function navigationEvent(overrides = {}, linkOverrides = {}) {
    const state = {prevented: false, stopped: false};
    const link = {
        nodeName: 'A',
        getAttribute(name) {
            return name === 'href' ? '/' : null;
        },
        ...linkOverrides,
    };
    const event = {
        target: {
            closest(selector) {
                return selector === '[data-pika-full-navigation]'
                    ? link
                    : null;
            },
        },
        defaultPrevented: false,
        button: 0,
        metaKey: false,
        ctrlKey: false,
        shiftKey: false,
        altKey: false,
        preventDefault() { state.prevented = true; },
        stopPropagation() { state.stopped = true; },
        ...overrides,
    };
    return {event, state};
}

test('full-navigation links replace the current page before PJAX can capture them', () => {
    const runtime = fullNavigationRuntime();
    const plain = navigationEvent();
    runtime.capture(plain.event);
    assert.deepEqual(runtime.assigned, ['/']);
    assert.equal(plain.state.prevented, true);
    assert.equal(plain.state.stopped, true);
});

test('full-navigation links preserve modified-click browser behavior', () => {
    for (const modifier of ['metaKey', 'ctrlKey', 'shiftKey', 'altKey']) {
        const runtime = fullNavigationRuntime();
        const modified = navigationEvent({[modifier]: true});
        runtime.capture(modified.event);
        assert.deepEqual(runtime.assigned, []);
        assert.equal(modified.state.prevented, false);
        assert.equal(modified.state.stopped, false);
    }
});

test('full-navigation handler rejects non-root and non-anchor targets', () => {
    for (const link of [
        {nodeName: 'A', getAttribute: () => 'https://evil.example/'},
        {nodeName: 'A', getAttribute: () => '//evil.example/'},
        {nodeName: 'A', getAttribute: () => 'javascript:alert(1)'},
        {nodeName: 'BUTTON', getAttribute: () => '/'},
    ]) {
        const runtime = fullNavigationRuntime();
        const unsafe = navigationEvent({}, link);
        runtime.capture(unsafe.event);
        assert.deepEqual(runtime.assigned, []);
        assert.equal(unsafe.state.prevented, false);
        assert.equal(unsafe.state.stopped, false);
    }
});
