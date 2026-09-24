import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {fileURLToPath, pathToFileURL} from 'node:url';

// Reuse installed Playwright/Chrome with real manager assets and synthetic data.
// Every request is intercepted; no application server or upstream is contacted.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const official = fs.realpathSync(process.env.ACG_FAKA_OFFICIAL_ROOT);
const output = fs.realpathSync(process.env.PIKA_QA_OUTPUT);
const phase = process.env.PIKA_QA_PHASE || 'after';
assert.ok(['before', 'after'].includes(phase));
const {chromium} = await import(pathToFileURL(path.join(process.env.PLAYWRIGHT_MODULE, 'index.mjs')));
const source = fs.readFileSync(path.join(root, 'manager/site/assets/admin/controller/local-extensions/index.js'), 'utf8');
const readySource = fs.readFileSync(path.join(official, 'assets/common/js/ready.js'), 'utf8');
const templateSource = fs.readFileSync(path.join(root, 'manager/site/app/View/Admin/LocalExtensions/Index.html'), 'utf8');
const readyCalls = [...templateSource.matchAll(/#\{ready\("([^"\n]+)"\)\}/g)];
assert.equal(readyCalls.length, 1, 'The actual page must select exactly one controller resource');
const currentResource = readyCalls[0][1];
const previousResource = '/assets/admin/controller/local-extensions/index.js';
const appVersion = '3.7.0';
const fixtureOrigin = 'https://pika-fixture.invalid';
// Render Helper.php:473-479's DEBUG=false ready URL contract without executing PHP.
const readyUrl = (resource, debug = false) => `${resource}${resource.includes('?') ? '&' : '?'}v=${appVersion}${debug ? '&debug=fixture0' : ''}`;
const previousUrl = readyUrl(previousResource);
const currentUrl = readyUrl(currentResource);
assert.equal(new URL(currentResource, fixtureOrigin).pathname, previousResource);
assert.equal(new URL(currentResource, fixtureOrigin).searchParams.get('rev'), '20260922-catalog1');
assert.notEqual(currentUrl, previousUrl, 'A fixed core version must still yield a new controller address');
assert.equal(new URL(currentUrl, fixtureOrigin).searchParams.get('v'), appVersion);
assert.equal(new URL(previousUrl, fixtureOrigin).searchParams.get('v'), appVersion);
const renderTemplate = debug => templateSource
    .replace(readyCalls[0][0], `<script>ready(${JSON.stringify(readyUrl(currentResource, debug))});</script>`)
    .replace(/#\{include[^\n]*\}/g, '')
    .replace(/#\{t\("([^"\n]*)"\)\}/g, '$1')
    .replace(/#\{\$local_extensions_csrf\|escape:'html'\}/g, 'synthetic-csrf');
assert.equal(renderTemplate(false).includes('#{'), false, 'All template directives must be resolved locally');
const headerSource = fs.readFileSync(path.join(official, 'app/View/Admin/Header.html'), 'utf8');
const helperSource = fs.readFileSync(path.join(official, 'kernel/Helper.php'), 'utf8');
const cssCall = headerSource.match(/#\{css\(\s*(\[[\s\S]*?\])\s*,\s*(\[[\s\S]*?\])\s*\)\}/);
assert.ok(cssCall, 'Extract both actual Header css() arrays instead of maintaining a partial hand-written list');
const cssHelper = helperSource.slice(helperSource.indexOf('function css('), helperSource.indexOf('function js('));
assert.match(cssHelper, /if \(DEBUG && \$backup !== null\)\s*\{\s*\$resource = \$backup;/);
assert.match(cssHelper, /foreach \(\$resource as \$item\)/);
assert.match(cssHelper, /\$item \. '\?v=' \. APP_VERSION \. \$debugRandom/);
const sha256 = text => createHash('sha256').update(text).digest('hex');
const styleSets = Object.fromEntries(['bundle', 'raw'].map((mode, index) => {
    const files = JSON.parse(cssCall[index + 1]);
    assert.ok(files.every(file => /^\/assets\/[\w./-]+\.css$/.test(file) && !file.includes('..')));
    assert.equal(new Set(files).size, files.length);
    return [mode, files.map(file => {
        const css = fs.readFileSync(path.join(official, file.slice(1)), 'utf8');
        return {file, url: `${file}?v=${appVersion}${mode === 'raw' ? '&debug=fixture0' : ''}`,
            bytes: Buffer.byteLength(css), sha256: sha256(css), css};
    })];
}));
assert.equal(styleSets.bundle.length, 7);
assert.equal(styleSets.raw.length, 15);
assert.equal(styleSets.raw[4].file, '/assets/common/css/component.css');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'extensions/PikaSupplySync/local-extension.json'), 'utf8'));
const fields = ['sync_name', 'sync_cover', 'sync_description', 'sync_price', 'sync_inventory', 'sync_options'];
const checkboxFields = [...fields, 'follow_upstream_config'];
const defaults = Object.fromEntries(manifest.settings.filter(setting => Object.hasOwn(setting, 'default'))
    .map(setting => [setting.key, setting.default]));
const applied = values => ({sync: 0, import: 0, zero: 0, held_race: 0, ...values});
const record = values => ({kind: 'actual', recorded_at: '2026-09-11 01:02:03', timezone: 'UTC',
    origin: 'log', mode: 'basic', status: 'ok', planned: 0, applied: applied({}), failed: 0,
    selection_held: 0, mass_zero_fuse: false, ...values});
const initialStatus = {
    availability: 'available', scheduler: 'unverified', running: 'unverified', incomplete: true,
    sources: [{source_id: 101,
        actual: record({}),
        saved_batch: record({kind: 'saved_batch', origin: 'state', timezone: 'unrecorded',
            recorded_at: '2026-09-11 11:02:03', planned: 2, applied: applied({sync: 2})}),
        preview: record({kind: 'preview', planned: 2}),
        unknown: record({kind: 'unknown', status: 'locked'}),
    }],
};
const selected = Object.fromEntries(fields.map(key => [key, ['sync_price', 'sync_inventory', 'sync_options'].includes(key)]));
const extension = (values = {}, syncStatus = initialStatus) => ({
    id: manifest.id, name: manifest.name, description: manifest.description, version: manifest.version,
    enabled: false, settings: manifest.settings, values: {...defaults, ...values}, sync_status: syncStatus,
});
const success = (values = {}, syncStatus = initialStatus) => ({status: 200, contentType: 'application/json',
    body: JSON.stringify({code: 200, data: {list: [extension(values, syncStatus)]}})});
const heldStatus = {...initialStatus, incomplete: false, sources: [{source_id: 202,
    actual: record({status: 'partial', planned: 3, applied: applied({sync: 1, held_race: 1}),
        failed: 2, selection_held: 2, mass_zero_fuse: true}),
}]};
const noSaveStatus = {...initialStatus, incomplete: false, sources: [{source_id: 303,
    actual: record({planned: 3}),
}]};
const browser = await chromium.launch({headless: true, executablePath: process.env.CHROME_BIN});
const summary = [];
const visualChecks = [];
const visualFailures = [];
let scenarioCount = 0;
let listingCount = 0;
let blockedAssetCount = 0;
let controllerLoadCount = 0;
let mockedSettingsSaveCount = 0;

async function openFixture(width, responses, theme = 'light', cssMode = 'bundle', expectedSaves = []) {
    const debug = cssMode === 'raw';
    const controllerUrl = readyUrl(currentResource, debug);
    const styleSet = styleSets[cssMode];
    const styles = styleSet.map(({url}) => `<link rel="stylesheet" href="${url}">`).join('');
    const context = await browser.newContext({viewport: {width, height: 1000}, serviceWorkers: 'block', colorScheme: theme});
    const requests = [];
    const saves = [];
    const loaderRequests = [];
    const controllerRequests = [];
    const cssRequests = [];
    const unexpected = [];
    const errors = [];
    await context.addInitScript(() => {
        window.__uiMessages = [];
        window.__readyEvents = [];
        window.message = {error: value => window.__uiMessages.push(String(value))};
        window.$ = () => ({one() {}, trigger: (event, detail) => window.__readyEvents.push({event, detail})});
        window.util = {debug() {}};
    });
    await context.route('**/*', async route => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.origin === fixtureOrigin && url.pathname === '/') {
            return route.fulfill({contentType: 'text/html', body: `<!doctype html><html lang="zh-CN" data-theme="${theme}" data-admin-layout="${width < 992 ? 'mobile' : 'desktop'}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">${styles}<script src="/assets/common/js/ready.js"></script></head><body>${renderTemplate(debug)}</body></html>`});
        }
        const stylesheet = url.origin === fixtureOrigin && styleSet.find(style => style.url === url.pathname + url.search);
        if (stylesheet) {
            cssRequests.push({method: request.method(), url: stylesheet.url});
            return route.fulfill({contentType: 'text/css', body: stylesheet.css});
        }
        if (url.origin === fixtureOrigin && url.pathname === '/assets/common/js/ready.js' && url.search === '') {
            loaderRequests.push(request.method());
            return route.fulfill({contentType: 'application/javascript', body: readySource});
        }
        if (url.origin === fixtureOrigin && url.pathname === previousResource) {
            const resource = url.pathname + url.search;
            controllerRequests.push({method: request.method(), resource});
            if (resource !== controllerUrl) {
                unexpected.push(`rejected-old-or-unexpected-controller: ${resource}`);
                return route.abort();
            }
            return route.fulfill({contentType: 'application/javascript', body: source});
        }
        if (url.origin === fixtureOrigin && url.pathname === '/admin/api/localExtensions/listing') {
            const body = new URLSearchParams(request.postData());
            requests.push({path: url.pathname, method: request.method(), keys: [...body.keys()]});
            const response = responses.shift();
            if (!response) {
                unexpected.push('extra-listing');
                return route.abort();
            }
            return route.fulfill(response);
        }
        if (url.origin === fixtureOrigin && url.pathname === '/admin/api/localExtensions/saveSettings'
            && saves.length < expectedSaves.length) {
            const body = new URLSearchParams(request.postData());
            saves.push({method: request.method(), keys: [...body.keys()], id: body.get('id'),
                csrf: body.get('csrf_token'), values: JSON.parse(body.get('settings_json'))});
            return route.fulfill({contentType: 'application/json', body: JSON.stringify({code: 200})});
        }
        // CSS fonts/images may reference URLs; they, and any unexpected API, never leave this fixture.
        if (['font', 'image'].includes(request.resourceType())) blockedAssetCount++;
        else unexpected.push(`${request.method()} ${url.pathname}`);
        return route.abort();
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${fixtureOrigin}/`);
    await page.locator('.local-extension-settings').waitFor();
    const loaded = await page.locator('script[data-ready-controller]').evaluateAll(scripts =>
        scripts.map(script => ({resource: script.dataset.readySrc, code: script.textContent})));
    assert.deepEqual(loaded, [{resource: controllerUrl, code: `${source}\n//# sourceURL=${fixtureOrigin}${controllerUrl}`}],
        'The official ready loader must inject the fetched current controller, not an inline test substitute');
    assert.deepEqual(await page.locator('link[rel="stylesheet"]').evaluateAll(links => links.map(link => link.getAttribute('href'))),
        styleSet.map(style => style.url), 'Stylesheet cascade order must match the chosen real Header array');
    const finish = async expectedRequests => {
        assert.equal(requests.length, expectedRequests);
        assert.equal(responses.length, 0, 'All synthetic responses must be exercised');
        for (const request of requests) {
            assert.equal(request.path, '/admin/api/localExtensions/listing');
            assert.equal(request.method, 'POST');
            assert.deepEqual(request.keys, ['csrf_token'], 'Status reads must not send config or sync arguments');
        }
        assert.equal(saves.length, expectedSaves.length, 'Only explicitly expected synthetic settings saves are allowed');
        saves.forEach((save, index) => {
            assert.equal(save.method, 'POST');
            assert.deepEqual(save.keys, ['id', 'settings_json', 'csrf_token']);
            assert.equal(save.id, 'PikaSupplySync');
            assert.equal(save.csrf, 'synthetic-csrf');
            assert.deepEqual(save.values, expectedSaves[index], 'The actual form must submit the complete selected settings');
        });
        assert.deepEqual(unexpected, [], 'No toggle, sync, or unapproved synthetic save request is allowed');
        assert.deepEqual(errors, []);
        assert.deepEqual(await page.evaluate(() => window.__uiMessages), []);
        assert.deepEqual(loaderRequests, ['GET']);
        assert.deepEqual(controllerRequests, [{method: 'GET', resource: controllerUrl}]);
        assert.deepEqual(cssRequests.map(request => request.url).sort(), styleSet.map(style => style.url).sort());
        assert.ok(cssRequests.every(request => request.method === 'GET'));
        assert.deepEqual(await page.evaluate(() => window.__readyEvents.map(({event, detail}) =>
            ({event, sources: detail[0].sources}))), [{event: 'admin:controllers:ready', sources: [controllerUrl]}]);
        scenarioCount++;
        listingCount += requests.length;
        controllerLoadCount += controllerRequests.length;
        mockedSettingsSaveCount += saves.length;
        await context.close();
    };
    return {page, finish, saves};
}

async function checks(page, keys = fields) {
    return page.locator('.local-extension-settings [data-setting-type="checkbox"]').evaluateAll((inputs, selectedKeys) =>
        Object.fromEntries(inputs.filter(input => selectedKeys.includes(input.dataset.settingKey))
            .map(input => [input.dataset.settingKey, input.checked])), keys);
}

async function layout(page, width, phase) {
    const metrics = await page.evaluate(() => ({
        width: innerWidth,
        scrollWidth: document.documentElement.scrollWidth,
        rootWidth: document.getElementById('local-extensions-root').getBoundingClientRect().width,
        statusWidth: document.querySelector('.local-sync-status').getBoundingClientRect().width,
        invalidInputs: [...document.querySelectorAll('.local-extension-setting input')]
            .filter(input => input.getBoundingClientRect().right > innerWidth).length,
    }));
    assert.equal(metrics.width, width);
    assert.ok(metrics.scrollWidth <= width, `Horizontal overflow at ${width}px: ${metrics.scrollWidth}`);
    assert.equal(metrics.invalidInputs, 0);
    const screenshot = path.join(output, `manager-${phase}-${width}.png`);
    await page.mouse.move(0, 0);
    await page.screenshot({path: screenshot, fullPage: true, animations: 'disabled'});
    summary.push({phase, ...metrics, screenshot});
}

async function inspectCheckboxes(page, context, state, screenshot = true) {
    await page.locator('[data-setting-type="checkbox"]').evaluateAll(async inputs => {
        await Promise.all(inputs.flatMap(input => input.getAnimations().map(animation => animation.finished.catch(() => {}))));
    });
    const measured = await page.evaluate(keys => {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 1;
        const ctx = canvas.getContext('2d', {willReadFrequently: true});
        const rgba = color => {
            ctx.clearRect(0, 0, 1, 1);
            ctx.fillStyle = color;
            ctx.fillRect(0, 0, 1, 1);
            const bytes = [...ctx.getImageData(0, 0, 1, 1).data];
            return [...bytes.slice(0, 3), bytes[3] / 255];
        };
        const over = (front, back) => front.slice(0, 3).map((value, index) => value * front[3] + back[index] * (1 - front[3])).concat(1);
        const luminance = color => color.slice(0, 3).map(value => value / 255)
            .map(value => value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4)
            .reduce((total, value, index) => total + value * [.2126, .7152, .0722][index], 0);
        const contrast = (a, b) => (Math.max(luminance(a), luminance(b)) + .05) / (Math.min(luminance(a), luminance(b)) + .05);
        return keys.map(key => {
            const input = document.getElementById(`local-extension-PikaSupplySync-${key}`);
            const style = getComputedStyle(input);
            const ancestors = [];
            for (let ancestor = input.parentElement; ancestor; ancestor = ancestor.parentElement) ancestors.unshift(ancestor);
            const surface = ancestors.reduce((color, ancestor) => over(rgba(getComputedStyle(ancestor).backgroundColor), color), [255, 255, 255, 1]);
            const background = rgba(style.backgroundColor);
            const effectiveBackground = over(background, surface);
            const border = rgba(style.borderTopColor);
            const outline = rgba(style.outlineColor);
            const svg = decodeURIComponent(style.backgroundImage).match(/<svg[\s\S]*<\/svg>/)?.[0];
            const svgDocument = svg ? new DOMParser().parseFromString(svg, 'image/svg+xml') : null;
            const markColors = svgDocument ? [...svgDocument.querySelectorAll('[fill], [stroke]')]
                .flatMap(element => ['fill', 'stroke'].map(attribute => element.getAttribute(attribute)))
                .filter(color => color && color !== 'none') : [];
            const box = input.getBoundingClientRect();
            return {key, checked: input.checked, width: box.width, height: box.height,
                backgroundColor: style.backgroundColor, backgroundAlpha: background[3], backgroundImage: style.backgroundImage,
                backgroundContrast: contrast(effectiveBackground, surface), borderColor: style.borderTopColor,
                borderStyle: style.borderTopStyle, borderWidth: parseFloat(style.borderTopWidth),
                borderContrast: contrast(over(border, effectiveBackground), surface), markColors,
                markContrast: markColors.length ? Math.min(...markColors.map(color => contrast(over(rgba(color), effectiveBackground), effectiveBackground))) : null,
                appearance: style.appearance, surface, opacity: style.opacity,
                focused: document.activeElement === input, focusVisible: input.matches(':focus-visible'),
                outlineStyle: style.outlineStyle, outlineWidth: parseFloat(style.outlineWidth), outlineOffset: parseFloat(style.outlineOffset),
                outlineColor: style.outlineColor, outlineContrast: contrast(over(outline, surface), surface), boxShadow: style.boxShadow};
        });
    }, checkboxFields);
    const reject = (box, message) => visualFailures.push({...context, state, field: box.key, message});
    for (const box of measured) {
        if (box.width < 16 || box.height < 16 || Number(box.opacity) < 1) reject(box, 'Checkbox is too small or translucent');
        if (box.backgroundAlpha < .99) reject(box, 'Checkbox background is transparent');
        if (box.checked) {
            if (box.backgroundImage === 'none' || box.markColors.length === 0) reject(box, 'Checked mark is missing');
            if (!(box.markContrast >= 3)) reject(box, `Checked mark contrast is below 3:1 (${box.markContrast})`);
            if (Math.max(box.backgroundContrast, box.borderContrast) < 3) reject(box, 'Checked boundary contrast is below 3:1');
        } else {
            if (box.backgroundImage !== 'none') reject(box, 'Unchecked box still contains a checked mark');
            if (!(box.borderWidth >= 1 && box.borderStyle !== 'none' && box.borderContrast >= 3)) reject(box, `Unchecked border contrast is below 3:1 (${box.borderContrast})`);
        }
        if (state === 'focus' && box.focused && !(box.focusVisible && box.outlineWidth >= 2
            && box.outlineStyle !== 'none' && box.outlineContrast >= 3)) reject(box, `Keyboard focus is not visibly outlined (${box.outlineContrast})`);
    }
    const evidence = {...context, state, boxes: measured};
    if (screenshot) {
        const clip = await page.evaluate(keys => {
            const rects = keys.map(key => document.getElementById(`local-extension-PikaSupplySync-${key}`).parentElement.getBoundingClientRect());
            const left = Math.min(...rects.map(rect => rect.left));
            const top = Math.min(...rects.map(rect => rect.top));
            return {x: Math.max(0, Math.floor(left + scrollX - 8)), y: Math.max(0, Math.floor(top + scrollY - 8)),
                width: Math.ceil(Math.max(...rects.map(rect => rect.right)) - left + 16),
                height: Math.ceil(Math.max(...rects.map(rect => rect.bottom)) - top + 16)};
        }, checkboxFields);
        evidence.screenshot = path.join(output, `${phase}-checkboxes-${context.cssMode}-${context.theme}-${context.width}-${state}.png`);
        await page.screenshot({path: evidence.screenshot, clip, animations: 'disabled'});
    }
    visualChecks.push(evidence);
}

async function checkboxJourney(width, theme, cssMode) {
    const allFalse = Object.fromEntries(checkboxFields.map(key => [key, false]));
    const fixture = await openFixture(width, [success(allFalse), success(allFalse, noSaveStatus)], theme, cssMode);
    const {page} = fixture;
    const context = {width, theme, cssMode};
    const expected = {...allFalse};
    await inspectCheckboxes(page, context, 'unchecked');
    for (const key of checkboxFields) {
        await page.locator(`#local-extension-PikaSupplySync-${key}`).click();
        expected[key] = true;
        assert.deepEqual(await checks(page, checkboxFields), expected, `Mouse input click must independently toggle ${key}`);
    }
    await page.mouse.move(0, 0);
    await inspectCheckboxes(page, context, 'checked');
    for (const key of checkboxFields) {
        await page.locator(`label[for="local-extension-PikaSupplySync-${key}"]`).click();
        expected[key] = false;
        assert.deepEqual(await checks(page, checkboxFields), expected, `Mouse label click must independently toggle ${key}`);
    }
    await page.locator('.local-extension-card h4').click();
    for (const key of checkboxFields) {
        for (let tabs = 0; tabs < 20; tabs++) {
            await page.keyboard.press('Tab');
            if (await page.locator(`#local-extension-PikaSupplySync-${key}`).evaluate(input => document.activeElement === input)) break;
        }
        assert.equal(await page.locator(`#local-extension-PikaSupplySync-${key}`).evaluate(input => document.activeElement === input), true);
        await inspectCheckboxes(page, context, 'focus', key === fields[0]);
        await page.keyboard.press('Space');
        expected[key] = true;
        assert.deepEqual(await checks(page, checkboxFields), expected, `Keyboard Space must independently toggle ${key}`);
    }
    const formHandle = await page.locator('.local-extension-settings').elementHandle();
    await page.getByRole('button', {name: '刷新记录', exact: true}).click();
    await page.getByText('货源 #303', {exact: true}).waitFor();
    assert.deepEqual(await checks(page, checkboxFields), expected, 'Refresh must preserve mouse/keyboard unsaved choices');
    assert.equal(await page.evaluate(form => document.querySelector('.local-extension-settings') === form, formHandle), true);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await fixture.finish(2);
}

async function configFollowJourney(width) {
    const enabled = {...defaults, ...Object.fromEntries(fields.map(key => [key, true])),
        source_ids: '', follow_upstream_config_source_ids: '202', follow_upstream_config: true};
    const syncExpanded = {...enabled, source_ids: '101,202,303'};
    const priceOff = {...syncExpanded, sync_price: false};
    const optionsOff = {...priceOff, sync_options: false};
    const disabled = {...optionsOff, follow_upstream_config_source_ids: '', follow_upstream_config: false};
    const expectedSaves = [enabled, syncExpanded, priceOff, optionsOff, disabled];
    const expectedWire = expectedSaves.map(values => Object.fromEntries(manifest.settings.map(setting =>
        [setting.key, setting.type === 'checkbox' ? values[setting.key] : String(values[setting.key])])));
    const fixture = await openFixture(width, [success({source_ids: '101'}), ...expectedSaves.map(values => success(values))],
        'light', 'bundle', expectedWire);
    const {page, saves} = fixture;
    const setting = key => page.locator(`[data-setting-key="${key}"]`);
    assert.deepEqual(await checks(page), Object.fromEntries(fields.map(key => [key, true])));
    assert.equal(await setting('follow_upstream_config').isChecked(), false,
        'Legacy displayed all-checked choices must never select config following');
    assert.equal(await setting('source_ids').inputValue(), '101');
    assert.equal(await setting('follow_upstream_config_source_ids').inputValue(), '',
        'An existing execution scope must not prefill the missing independent follow list');
    assert.equal(await page.getByLabel('参与同步的货源（空=全部）', {exact: true}).count(), 1);
    assert.equal(await page.getByLabel('完整配置跟随的货源（启用时不可空）', {exact: true}).count(), 1);
    assert.match(await page.locator('.local-extension-settings').innerText(),
        /两份名单均填写共享店铺 ID，以英文逗号分隔；参与同步来源不会扩大完整跟随范围。上游变更\/删除将覆盖跟随名单内商品 config 中的本地手工修改。只有价格与规格均生效时才执行；关闭任一项会暂停跟随，但不会取消此选择。关闭完整跟随不会恢复已覆盖或删除的本地内容。/);
    const descriptionId = await setting('follow_upstream_config').getAttribute('aria-describedby');
    assert.ok(descriptionId && await page.locator(`[id="${descriptionId}"]`).count() === 1);
    const helpFits = await page.locator(`[id="${descriptionId}"]`).evaluate(help => {
        const box = help.getBoundingClientRect();
        const next = help.parentElement.nextElementSibling.getBoundingClientRect();
        const text = document.createRange();
        text.selectNodeContents(help);
        return box.left >= 0 && box.right <= innerWidth && box.bottom <= next.top
            && [...text.getClientRects()].every(rect => rect.left >= box.left && rect.right <= box.right
                && rect.top >= box.top && rect.bottom <= box.bottom);
    });
    assert.equal(helpFits, true, 'The complete config-follow warning must wrap without overflow or overlapping the next control');
    await layout(page, width, 'config-follow-help');
    await setting('follow_upstream_config').check();
    for (const invalid of ['', '0', '-1', '101,,202', '101,a', '1,'.repeat(100) + '1']) {
        await setting('follow_upstream_config_source_ids').fill(invalid);
        await page.getByRole('button', {name: '保存配置', exact: true}).click();
        assert.equal(await setting('follow_upstream_config_source_ids').evaluate(input => input.validity.customError), true);
        assert.equal(saves.length, 0, 'Invalid config-follow scope must not issue a settings save');
    }
    const saveAndReload = async () => {
        const form = await page.locator('.local-extension-settings').elementHandle();
        await page.getByRole('button', {name: '保存配置', exact: true}).click();
        await page.waitForFunction(previous => !previous.isConnected, form);
    };
    await setting('source_ids').fill('');
    await setting('follow_upstream_config_source_ids').fill('202');
    await saveAndReload();
    assert.equal(await setting('follow_upstream_config').isChecked(), true);
    assert.equal(await setting('source_ids').inputValue(), '', 'All-source execution must remain supported with explicit follow ownership');
    assert.equal(await setting('follow_upstream_config_source_ids').inputValue(), '202');
    assert.equal(await page.locator('.local-extension-settings .alert-warning').count(), 0);
    await setting('source_ids').fill('101,202,303');
    await saveAndReload();
    assert.equal(await setting('source_ids').inputValue(), '101,202,303');
    assert.equal(await setting('follow_upstream_config_source_ids').inputValue(), '202',
        'Expanding execution sources must not change the independent follow list');
    await setting('sync_price').uncheck();
    await saveAndReload();
    assert.equal(await setting('follow_upstream_config').isChecked(), true,
        'Disabling price must not force config following off');
    await setting('sync_options').uncheck();
    await saveAndReload();
    assert.equal(await setting('follow_upstream_config').isChecked(), true,
        'Disabling options must not force config following off');
    await setting('follow_upstream_config_source_ids').fill('');
    assert.equal(await setting('follow_upstream_config_source_ids').evaluate(input => input.validity.customError), true);
    await setting('follow_upstream_config').uncheck();
    assert.equal(await setting('follow_upstream_config_source_ids').evaluate(input => input.validity.customError), false,
        'Turning config following off must clear a stale scope validation error');
    await saveAndReload();
    assert.equal(await setting('follow_upstream_config').isChecked(), false);
    assert.equal(await setting('follow_upstream_config_source_ids').inputValue(), '');
    assert.equal(await setting('source_ids').inputValue(), '101,202,303');
    await fixture.finish(6);
}

try {
    for (const theme of ['light', 'dark']) {
        for (const width of [1440, 390]) await checkboxJourney(width, theme, 'bundle');
    }
    await checkboxJourney(1440, 'light', 'raw');
    for (const width of [1440, 390]) await configFollowJourney(width);
    for (const width of [1440, 390]) {
        const {page, finish} = await openFixture(width, [
            success(), success(Object.fromEntries(fields.map(key => [key, false])), heldStatus),
            {status: 503, contentType: 'text/html', body: '<p>private-error-marker</p>'},
            success(selected, noSaveStatus),
        ]);
        const settings = page.locator('.local-extension-settings');
        const status = page.locator('.local-sync-status');
        assert.deepEqual(await checks(page), Object.fromEntries(fields.map(key => [key, true])));
        assert.match(await settings.innerText(), /当前仍按旧规则运行.*待保存选择/);
        assert.match(await settings.innerText(), /首次保存后才按六项与商品级开关共同生效/);
        assert.match(await settings.innerText(), /旧规则中配置同步可能同时更新规格价格/);
        assert.match(await settings.innerText(), /名称、图片、说明、规格仍受单品「远端配置参数同步」限制/);
        assert.match(await settings.innerText(), /价格受「远端价格同步」限制，库存受数量同步限制/);
        assert.match(await settings.innerText(), /保存不会入库或开启定时器/);
        assert.match(await status.innerText(), /调度状态：未核实；当前是否正在运行：未核实/);
        await status.locator('summary').click();
        const initialText = await status.innerText();
        for (const expected of [
            '日志中的最近实际模式记录：本批无动作，不代表全部商品已同步',
            '最近持久化批次（与日志分列，不推断先后）：批次完成',
            '历史站点时间，时区未记录', '来自每源历史状态，日志可能已截断',
            '最近只读预演记录（不写商品）', '历史记录（实际执行／预演类型未记录）',
            '未执行：货源锁被占用', '部分记录缺失、不可读或超出展示范围',
            '同步保存次数不是价格、库存等字段的真实变化数', '预演会读取上游，但不写商品',
        ]) assert.ok(initialText.includes(expected), expected);
        await layout(page, width, 'legacy-history');

        const formHandle = await settings.elementHandle();
        await settings.locator('[data-setting-key="sync_name"]').uncheck();
        const unsaved = await checks(page);
        const refresh = status.getByRole('button', {name: '刷新记录', exact: true});
        await refresh.click();
        await status.getByText('货源 #202', {exact: true}).waitFor();
        await status.locator('summary').click();
        assert.equal(await page.evaluate(form => document.querySelector('.local-extension-settings') === form, formHandle), true);
        assert.deepEqual(await checks(page), unsaved, 'Status refresh must preserve unsaved checkbox edits');
        const heldText = await status.innerText();
        for (const expected of ['部分完成／有失败或受限', '同步保存 1', '失败／待确认 2',
            '规格或价格变更待确认 2 项', '无法精确匹配的部分保留本地', '没有建立自动补查任务',
            '批量清零熔断已触发', '目录与详情库存不一致，暂缓 1 项']) {
            assert.ok(heldText.includes(expected), expected);
        }
        const history = await status.locator('.local-sync-status__content').innerText();
        await refresh.click();
        await status.getByRole('status').filter({hasText: '刷新失败'}).waitFor();
        assert.equal(await status.locator('.local-sync-status__content').innerText(), history);
        assert.deepEqual(await checks(page), unsaved);
        assert.equal(await page.evaluate(form => document.querySelector('.local-extension-settings') === form, formHandle), true);
        assert.equal(await page.locator('body').innerText().then(text => text.includes('private-error-marker')), false);
        await layout(page, width, 'refresh-failure');

        await refresh.click();
        await status.getByText('货源 #303', {exact: true}).waitFor();
        await status.locator('summary').click();
        assert.match(await status.innerText(), /本批未保存商品/);
        assert.match(await status.innerText(), /计划 3；同步保存 0；新建 0；库存清零 0/);
        assert.equal(await status.getByRole('status').innerText(), '');
        assert.deepEqual(await checks(page), unsaved);
        await finish(4);

        const explicit = await openFixture(width, [success(selected, noSaveStatus)]);
        assert.deepEqual(await checks(explicit.page), selected, 'An explicit six-field selection must not fall back to legacy');
        const explicitSettings = explicit.page.locator('.local-extension-settings');
        assert.equal(await explicitSettings.locator('.alert-warning').count(), 0);
        assert.match(await explicitSettings.innerText(), /六项只控制后续周期更新，不影响首次入库/);
        assert.match(await explicitSettings.innerText(), /未勾字段保留本地，全部不勾不更新商品/);
        const expectedSelection = {...selected};
        for (const key of fields) {
            await explicitSettings.locator(`[data-setting-key="${key}"]`).uncheck();
            expectedSelection[key] = false;
            assert.deepEqual(await checks(explicit.page), expectedSelection, `${key} must be independent`);
        }
        assert.deepEqual(await checks(explicit.page), Object.fromEntries(fields.map(key => [key, false])));
        await explicit.page.locator('.local-sync-status summary').click();
        await layout(explicit.page, width, 'six-unselected');
        await explicit.finish(1);
    }

    for (const [syncStatus, expected] of [
        [{availability: 'unavailable', sources: []}, '运行记录暂不可读'],
        [{availability: 'available', incomplete: false, sources: []}, '未找到可读记录，不代表从未执行'],
    ]) {
        const fixture = await openFixture(390, [success(selected, syncStatus)]);
        assert.ok((await fixture.page.locator('.local-sync-status').innerText()).includes(expected));
        assert.match(await fixture.page.locator('.local-sync-status').innerText(), /调度状态：未核实；当前是否正在运行：未核实/);
        await fixture.finish(1);
    }
    for (const width of [1440, 390]) {
        const diagnosis = {...initialStatus, sources: [{source_id: 404, actual: record({status: 'error',
            catalog_diagnostic: {category: 'response_size', http_status: 200, curl_code: 23,
                elapsed_ms: 2635, attempts: 1, url: 'private-error-marker'}})}]};
        const fixture = await openFixture(width, [success(selected, diagnosis)]);
        await fixture.page.locator('.local-sync-status summary').click();
        const text = await fixture.page.locator('.local-sync-status').innerText();
        for (const expected of ['目录超过 16 MiB 安全上限', '缩小批量不会减少整份目录大小',
            '本货源本轮未执行商品写入', 'HTTP 200', 'cURL 23', '2635 ms', '尝试 1 次', 'HTTP 状态不代表同步成功']) {
            assert.ok(text.includes(expected), expected);
        }
        assert.equal(text.includes('private-error-marker'), false);
        await layout(fixture.page, width, 'catalog-response-size');
        await fixture.finish(1);
    }
    {
        const malformed = {category: '<img src=x onerror=alert(1)>private-error-marker', http_status: '200',
            curl_code: -1, elapsed_ms: 480001, attempts: 4, app_key: 'private-error-marker'};
        const diagnosis = {...initialStatus, sources: [
            {source_id: 405, actual: record({status: 'error', catalog_diagnostic: malformed})},
            {source_id: 406, actual: record({status: 'error', catalog_diagnostic: [malformed]})},
            {source_id: 407, actual: record({status: 'success', catalog_diagnostic: malformed})},
        ]};
        const fixture = await openFixture(390, [success(selected, diagnosis)]);
        for (const summary of await fixture.page.locator('.local-sync-status summary').all()) await summary.click();
        const text = await fixture.page.locator('.local-sync-status').innerText();
        assert.equal((text.match(/目录请求诊断：/g) ?? []).length, 1);
        assert.ok(text.includes('目录错误类别未记录'));
        assert.ok(text.includes('HTTP 未记录；cURL 未记录；耗时 未记录 ms；尝试 未记录 次'));
        assert.equal(text.includes('private-error-marker'), false);
        assert.equal(await fixture.page.locator('.local-sync-status img').count(), 0);
        await fixture.finish(1);
    }
    const result = {status: visualFailures.length ? 'FAIL' : 'PASS', phase, scenarios: scenarioCount, listingRequests: listingCount,
        externalRequestsDelivered: 0, blockedAssetRequests: blockedAssetCount,
        mockedSettingsSaveRequests: mockedSettingsSaveCount, toggleSyncRequests: 0,
        identity: {officialRoot: official, headerSha256: sha256(headerSource), helperSha256: sha256(helperSource),
            templateSha256: sha256(templateSource), controllerSha256: sha256(source)},
        styles: Object.fromEntries(Object.entries(styleSets).map(([mode, styles]) => [mode, {
            debug: mode === 'raw', files: styles.map(({css, ...metadata}) => metadata)}])), visualChecks, visualFailures,
        readyLoader: {appVersion, debug: false, previousUrl, currentUrl, controllerLoads: controllerLoadCount,
            oldControllerLoads: 0, evidence: 'The actual ready loader requested the new address and injected current source; HTTP disk cache behavior is not tested.'}, summary};
    fs.writeFileSync(path.join(output, 'manager-status-results.json'), JSON.stringify(result, null, 2));
    console.log(JSON.stringify({...result, styles: undefined, visualChecks: undefined, summary: undefined,
        visualFailures: undefined, visualFailureCount: visualFailures.length, failureSample: visualFailures.slice(0, 3)}));
    assert.equal(visualFailures.length, 0, `Checkbox visual acceptance failed; evidence: ${output}/manager-status-results.json`);
} finally {
    await browser.close();
}
