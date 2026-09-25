import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {execFileSync} from 'node:child_process';

// Reuse installed Playwright/Chrome, real styles and synthetic local data only.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const official = fs.realpathSync(process.env.ACG_FAKA_OFFICIAL_ROOT);
const output = fs.realpathSync(process.env.PIKA_QA_OUTPUT);
const phase = process.env.PIKA_QA_PHASE || 'after';
const scrollbars = process.env.PIKA_QA_SCROLLBARS || 'classic';
assert.ok(['classic', 'default'].includes(scrollbars));
// Playwright normally hides scrollbars, masking the 15px native layout gutter.
const browserOptions = {headless: true, executablePath: process.env.CHROME_BIN,
    ...(scrollbars === 'classic' ? {ignoreDefaultArgs: ['--hide-scrollbars']} : {})};
assert.ok(['before', 'after'].includes(phase) || (phase === 'parent' && process.env.PIKA_QA_CASE === 'purchase'));
if (process.env.PIKA_QA_CASE === 'purchase') {
    await checkPurchasePresentation();
} else {
const cssLink = header => {
    const link = header.match(/<link rel="stylesheet" href="\/app\/View\/User\/Theme\/Pika\/Assets\/pika\.css[^\"]*">/)?.[0];
    assert.ok(link, 'Theme header must reference the actual Pika stylesheet');
    return link;
};
const baseline = relative => execFileSync('git', ['show', `HEAD:${relative}`], {cwd: root, encoding: 'utf8'});
const headerPaths = ['Index', 'Common', 'Authentication'].map(part => `themes/Pika/${part}/Header.html`);
const previousLink = cssLink(baseline(headerPaths[0]));
const currentLink = cssLink(fs.readFileSync(path.join(root, headerPaths[0]), 'utf8'));
const resourceUrl = link => new URL(link.match(/href="([^\"]+)"/)[1].replaceAll('&amp;', '&'), 'https://pika-fixture.invalid').href;
const previousUrl = resourceUrl(previousLink), currentUrl = resourceUrl(currentLink);
assert.notEqual(currentUrl, previousUrl, 'Changed CSS must have a different URL on the same theme version');
for (const header of headerPaths) {
    assert.equal(cssLink(fs.readFileSync(path.join(root, header), 'utf8')), currentLink, `${header} must request the same new CSS`);
}
const requestedUrl = phase === 'before' ? previousUrl : currentUrl;
const cssSource = phase === 'before' ? baseline('themes/Pika/Assets/pika.css') : fs.readFileSync(path.join(root, 'themes/Pika/Assets/pika.css'), 'utf8');
const {chromium} = await import(pathToFileURL(path.join(process.env.PLAYWRIGHT_MODULE, 'index.mjs')));
const browser = await chromium.launch(browserOptions);
const svg = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="80" height="48" viewBox="0 0 80 48"><rect width="80" height="48" fill="#00708a"/><circle cx="40" cy="24" r="18" fill="#ffda59"/></svg>');
const chip = (id, label, parent = false) => `<${parent ? 'button' : 'a'} id="${id}" type="button" class="chip ${parent ? 'category-parent' : 'category-leaf switch-category'}" data-id="${parent ? 1 : id === 'child' ? 2 : 3}" data-count="1234" ${parent ? 'aria-expanded="false" aria-controls="category-children-root"' : 'href="/cat/2"'}><img class="chip-icon" src="${svg}" alt=""><span class="category-label" title="${label}">${label}</span><span class="category-count">1234</span><i class="category-caret"></i></${parent ? 'button' : 'a'}>`;
const styles = [
    path.join(official, 'assets/common/css/bootstrap.min.css'),
    path.join(official, 'assets/user/css/index.css'),
].map(file => `<style>${fs.readFileSync(file, 'utf8')}</style>`).join('') + (phase === 'before' ? previousLink : currentLink);
const summary = [];
const stylesheetRequests = [];
try {
    const context = await browser.newContext({serviceWorkers: 'block'});
    await context.route('**/*', route => {
        const request = route.request();
        if (request.url() === 'https://pika-fixture.invalid/') return route.fulfill({contentType: 'text/html', body: '<!doctype html><html></html>'});
        if (request.url() === requestedUrl && request.resourceType() === 'stylesheet') {
            stylesheetRequests.push(request.url());
            return route.fulfill({contentType: 'text/css', body: cssSource});
        }
        return route.abort();
    });
    for (const width of [1440, 390]) {
        const page = await context.newPage();
        await page.setViewportSize({width, height: 1000});
        await page.goto('https://pika-fixture.invalid/');
        await page.setContent(`<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">${styles}</head><body class="fbfaka-public"><main class="container fbfaka-storefront"><div class="fbfaka-catalog"><aside class="panel fbfaka-categories"><div class="panel-body"><div class="fbfaka-category-list"><div class="fbfaka-category-group">${chip('category-parent-root', '合成长分类名称用于检查图标与计数布局不重叠', true)}<div class="category-children" id="category-children-root" hidden>${chip('child', '子级合成长分类名称与完整图标测试')}</div></div>${chip('leaf', '另一个合成分类')}</div></div></aside><section class="fbfaka-products"><img class="brand-logo" src="${svg}" alt=""><div class="acg-thumb"><img src="${svg}" alt=""></div><p>合成商品区域</p><div class="item-list"></div></section></div></main></body></html>`);
        const measure = () => page.evaluate(() => {
            const result = {};
            for (const id of ['category-parent-root', 'child', 'leaf']) {
                const row = document.getElementById(id), icon = row.querySelector('.chip-icon');
                const style = getComputedStyle(icon), box = icon.getBoundingClientRect();
                result[id] = {width: style.width, height: style.height, fit: style.objectFit,
                    margin: style.marginRight, rowWidth: row.getBoundingClientRect().width,
                    iconRight: box.right, labelLeft: row.querySelector('.category-label').getBoundingClientRect().left,
                    labelRight: row.querySelector('.category-label').getBoundingClientRect().right,
                    countLeft: row.querySelector('.category-count').getBoundingClientRect().left};
            }
            result.overflow = document.documentElement.scrollWidth > document.documentElement.clientWidth;
            result.logo = getComputedStyle(document.querySelector('.brand-logo')).width;
            result.product = getComputedStyle(document.querySelector('.acg-thumb img')).width;
            return result;
        });
        await page.evaluate(() => {
            window.getVar = () => '3';
            window.trade = {getCommodityList(options) { options.done([]); }};
        });
        await page.addScriptTag({content: fs.readFileSync(path.join(root, 'themes/Pika/Assets/safe-dom.js'), 'utf8')});
        await page.addScriptTag({content: fs.readFileSync(path.join(root, 'themes/Pika/Assets/index.js'), 'utf8')});
        assert.equal(await page.locator('#category-parent-root').getAttribute('aria-expanded'), 'false');
        await page.locator('#category-parent-root').click();
        assert.equal(await page.locator('#category-parent-root').getAttribute('aria-expanded'), 'true');
        const measured = await measure();
        assert.equal(await page.locator('link[rel="stylesheet"]').evaluate(link => link.href), requestedUrl);
        assert.equal(stylesheetRequests.length, summary.length + 1, 'New page must fetch the external stylesheet URL');
        assert.equal(measured['category-parent-root'].width, phase === 'before' ? (width === 390 ? '16px' : '18px') : '32px');
        if (phase === 'after') {
            assert.equal(measured.child.width, '28px');
            for (const id of ['category-parent-root', 'child', 'leaf']) {
                assert.equal(measured[id].width, measured[id].height);
                assert.equal(measured[id].fit, 'contain');
                assert.equal(measured[id].margin, '0px');
                assert.ok(measured[id].iconRight <= measured[id].labelLeft);
                assert.ok(measured[id].labelRight <= measured[id].countLeft);
            }
            assert.equal(measured.overflow, false);
        }
        summary.push({width, measured});
        await page.screenshot({path: path.join(output, `${phase}-category-${width}.png`), fullPage: true});
        await page.locator('#child').click();
        assert.equal(await page.locator('#child').getAttribute('aria-current'), 'page');
        await page.locator('#category-parent-root').click();
        assert.equal(await page.locator('#category-children-root').isVisible(), false);
        await page.close();
    }
    fs.writeFileSync(path.join(output, `${phase}-computed.json`), JSON.stringify(summary, null, 2));
    const cacheEvidence = {previousUrl, currentUrl, requested: stylesheetRequests, headers: headerPaths,
        cacheStorageBehavior: 'NOT EVALUATED: routing disables the browser HTTP cache; this proves URL selection and loading only'};
    fs.writeFileSync(path.join(output, `${phase}-resource-urls.json`), JSON.stringify(cacheEvidence, null, 2));
    console.log(JSON.stringify({phase, status: 'PASS', summary, cacheEvidence}));
} finally {
    await browser.close();
}
}

async function checkPurchasePresentation() {
    const demo = process.env.PIKA_QA_DEMO === '1';
    assert.ok(!demo || phase === 'after', 'Public demo must use the repaired working tree');
    const baselineRef = phase === 'parent' ? 'HEAD^' : 'HEAD';
    const revision = execFileSync('git', ['rev-parse', baselineRef], {cwd: root, encoding: 'utf8'}).trim();
    const read = relative => phase === 'after'
        ? fs.readFileSync(path.join(root, relative), 'utf8')
        : execFileSync('git', ['show', `${revision}:${relative}`], {cwd: root, encoding: 'utf8'});
    const fixtures = JSON.parse(execFileSync('docker', [
        'run', '--rm', '-i', '--pull', 'never', '--network', 'none', '--read-only',
        '--tmpfs', '/tmp:rw,noexec,nosuid,size=32m',
        '-v', `${root}:/repo:ro`, '-v', `${official}:/official:ro`,
        '-e', 'ACG_FAKA_OFFICIAL_ROOT=/official',
        'acg-faka-php83-integration:20260828', 'php', '-d', 'display_errors=stderr',
        '/repo/tests/local-theme-config-behavior.php', '--purchase-fixtures',
    ], {input: JSON.stringify({template: read('themes/Pika/Index/Item.html'), indexTemplate: read('themes/Pika/Index/Index.html'),
        headerTemplate: read('themes/Pika/Index/Header.html'), footerTemplate: read('themes/Pika/Index/Footer.html'), demo}), encoding: 'utf8', timeout: 30000}));
    const readyUrl = source => source.match(/#\{ready\("([^"]+)"\)\}/)?.[1];
    const indexUrl = readyUrl(read('themes/Pika/Index/Index.html'));
    const itemUrl = fixtures.pages.normal.match(/data-fixture-ready="([^"]+)"/)?.[1].replaceAll('&amp;', '&');
    const cssUrl = read('themes/Pika/Index/Header.html').match(/href="([^\"]*pika\.css[^\"]*)"/)?.[1].replaceAll('&amp;', '&');
    const pikaUrl = read('themes/Pika/Index/Footer.html').match(/src="([^\"]*pika\.js[^\"]*)"/)?.[1];
    assert.ok(indexUrl && itemUrl && cssUrl, 'actual templates must provide resource URLs');
    const assetBodies = new Map([
        [indexUrl, read('themes/Pika/Assets/index.js')], [itemUrl, read('themes/Pika/Assets/item.js')],
        [cssUrl, read('themes/Pika/Assets/pika.css')],
        [pikaUrl, read('themes/Pika/Assets/pika.js')],
    ]);
    const baseStyles = ['assets/common/css/bootstrap.min.css', 'assets/user/css/index.css']
        .map(file => `<style>${fs.readFileSync(path.join(official, file), 'utf8')}</style>`).join('');
    const html = body => `<!doctype html><html><head><meta charset="utf-8">${baseStyles}<link rel="stylesheet" href="${cssUrl}"></head><body class="fbfaka-public">${body}</body></html>`;
    const {chromium} = await import(pathToFileURL(path.join(process.env.PLAYWRIGHT_MODULE, 'index.mjs')));
    const browser = await chromium.launch(browserOptions);
    const failures = [], errors = [], requests = [];
    // The official commodity validator permits stock up to signed INT_MAX.
    const stocks = [8, 999, 1000, 9999, 10000, 2147483647];
    const stockItems = demo ? [
        ['手绘图标素材包', '12.00', 48], ['柔和彩色壁纸合集', '8.00', 120],
        ['每日阅读计划模板', '6.00', 64], ['语言学习打卡手册', '9.00', 80],
        ['个人预算表格模板', '15.00', 32], ['轻量项目管理清单', '5.00', 96],
    ].map(([name, price, stock], index) => ({...fixtures.item, id: 100 + index, name, price, stock,
        cover: '/app/View/User/Theme/Pika/Assets/storefront-placeholder.svg', order_sold: 0}))
        : stocks.flatMap((stock, index) => ['合成商品', '合成长商品标题用于检查库存与价格保持独立'].map((name, variant) => ({
        ...fixtures.item, id: 100 + index * 2 + variant, stock, name,
        order_sold: variant ? 0 : 4321, price: '2.50',
    })));
    const stockWidths = [1440, 1358, 1200, 1199, 1101, 1100, 1024, 992, 991, 768, 767, 575, 390, 320];
    const purchaseWidths = [1440, 390, 320];
    const layouts = [], headers = [], purchases = [], brandCases = [], demoScreenshots = [];
    const expectedShopNames = new WeakMap();
    const check = (condition, description) => { if (!condition) failures.push(description); };
    try {
        const context = await browser.newContext({serviceWorkers: 'block', reducedMotion: 'reduce'});
        await context.route('**/*', route => {
            const url = new URL(route.request().url());
            requests.push(url.pathname + url.search);
            if (url.hostname !== 'pika-fixture.invalid') {
                errors.push('unexpected external request');
                return route.abort();
            }
            const resource = assetBodies.get(url.pathname + url.search);
            if (resource !== undefined) return route.fulfill({contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', body: resource});
            if (url.pathname === '/item/7') return route.fulfill({contentType: 'text/html', body: html(fixtures.pages[url.searchParams.get('case') || 'normal'])});
            if (url.pathname === '/') {
                const key = (url.searchParams.get('identity') || 'member') + (url.searchParams.has('brand') ? '/' + url.searchParams.get('brand') : '');
                assert.ok(fixtures.storefronts[key], 'known synthetic storefront');
                return route.fulfill({contentType: 'text/html', body: fixtures.storefronts[key]});
            }
            // Serve only public static assets, never a backend or a credential-bearing file.
            if (/^\/assets\/[A-Za-z0-9_./-]+\.(css|woff2?|ttf|png|jpg)$/.test(url.pathname)) {
                const file = fs.realpathSync(path.join(official, url.pathname));
                assert.ok(file.startsWith(official + '/assets/'));
                return route.fulfill({contentType: url.pathname.endsWith('.css') ? 'text/css' : 'application/octet-stream', body: fs.readFileSync(file)});
            }
            if (demo && ['brand-mark.svg', 'storefront-placeholder.svg', 'pika-favicon-1.0.3.png', 'topfans-logo.png', 'topfans-bg-poster.jpg']
                .some(name => url.pathname === '/app/View/User/Theme/Pika/Assets/' + name)) {
                const filename = /poster|placeholder/.test(url.pathname) ? 'storefront-placeholder.svg' : 'brand-mark.svg';
                return route.fulfill({contentType: 'image/svg+xml', body: fs.readFileSync(path.join(root, 'themes/Pika/Assets', filename))});
            }
            if (['pika-favicon-1.0.3.png', 'topfans-logo.png', 'topfans-bg-poster.jpg']
                .some(name => url.pathname === '/app/View/User/Theme/Pika/Assets/' + name)) {
                return route.fulfill({body: fs.readFileSync(path.join(root, 'themes/Pika/Assets', path.basename(url.pathname)))});
            }
            if (url.pathname.endsWith('/topfans-bg.mp4')) return route.fulfill({status: 204});
            if (['/user/personal/purchaseRecord', '/user/dashboard/index', '/user/authentication/login'].includes(url.pathname)) {
                return route.fulfill({contentType: 'text/html', body: '<!doctype html><p>合成导航目标</p>'});
            }
            if (url.pathname === '/fixture.svg' || url.pathname === '/favicon.ico') return route.fulfill({contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" fill="#fff"/></svg>'});
            errors.push('unexpected fixture request: ' + url.pathname);
            return route.abort();
        });
        const setup = async (page, scenario) => {
            await page.addScriptTag({path: path.join(official, 'assets/common/js/jquery.min.js')});
            await page.evaluate(({item, scenario, stockItems}) => {
                window.fixtureCalls = [];
                window.fixtureResults = [];
                window.i18n = value => value;
                window.getVar = key => key === '_var_item' ? item : key === 'CURRENCY' ? {symbol: '¥'} : '1';
                window.util = {
                    isEmptyOrNotJson: value => !value || Object.keys(value).length === 0,
                    arrayToObject: values => Object.fromEntries(values.map(({name, value}) => [name, value])),
                    post(options, data, done) {
                        if (typeof options === 'string') {
                            fixtureCalls.push({url: options, data: {...data}});
                            done({data: {tradeNo: 'synthetic-order', secret: 'synthetic-secret', leave_message: 'synthetic-result', url: null}});
                            return;
                        }
                        fixtureCalls.push({url: options.url, data: options.data});
                        if (options.url.endsWith('/valuation')) return options.done({data: {price: '2.50'}});
                        if (options.url.endsWith('/stock')) return options.done({data: {stock: 8, stock_state: 1}});
                        if (options.url.startsWith('/user/api/index/pay?')) {
                            if (scenario === 'pay-error') return;
                            const payments = [
                                {id: 2, handle: '#external', name: '第三方支付', icon: '/fixture.svg'},
                                {id: 9, handle: '#other', name: '余额', icon: '/fixture.svg'},
                            ];
                            if (scenario !== 'guest') payments.unshift({id: 1, handle: '#system', name: '站内钱包', icon: '/fixture.svg'});
                            window.fixturePayDone = () => options.done({data: payments});
                            if (scenario !== 'delayed') fixturePayDone();
                            return;
                        }
                        throw new Error('unexpected API in synthetic fixture');
                    },
                };
                window.treasure = {show: (...args) => fixtureResults.push(args)};
                window.message = {error: error => { throw new Error(String(error)); }};
                window.fixtureSearches = [];
                window.trade = {getCommodityList(options) { fixtureSearches.push({categoryId: options.categoryId, keywords: options.keywords}); options.done(stockItems); }};
            }, {item: fixtures.item, scenario, stockItems});
            await page.addScriptTag({path: path.join(official, 'assets/common/js/format.js')});
            if (scenario === 'format-error') await page.evaluate(() => { format.amount = () => { throw new Error('synthetic formatter failure'); }; });
            await page.addScriptTag({content: read('themes/Pika/Assets/safe-dom.js')});
            await page.addScriptTag({path: path.join(official, 'assets/common/js/bootstrap/bootstrap.bundle.min.js')});
            await page.evaluate(() => window.__pikaThemeRuntime?.init());
        };
        const openStorefront = async (page, width, identity, brandKey) => {
            expectedShopNames.set(page, brandKey ? fixtures.brandNames[brandKey]
                : identity === 'long' ? '合成长名称的商品与服务商店' : '合成商店');
            await page.setViewportSize({width, height: 1000});
            await page.goto(`https://pika-fixture.invalid/?identity=${identity}${brandKey ? '&brand=' + brandKey : ''}`);
            await setup(page, 'normal');
            if (!demo) await page.locator('.fbfaka-category-list').evaluate((node, count) => {
                const category = document.createElement('a');
                category.className = 'chip category-leaf switch-category';
                category.dataset.id = '1';
                category.dataset.count = String(count);
                category.href = '/cat/1';
                category.textContent = '合成分类';
                node.appendChild(category);
            }, stockItems.length);
            await page.addScriptTag({url: indexUrl});
            assert.equal(await page.locator('.item-list > a').count(), stockItems.length);
            assert.equal(await page.locator('.fb-view-toggle').count(), 1, 'real theme toolbar initialized');
        };
        const headerGeometry = async (page, label) => {
            const geometry = await page.evaluate(() => {
                const box = node => {
                    const {left, right, top, bottom, width, height} = node.getBoundingClientRect();
                    return {left, right, top, bottom, width, height};
                };
                const visible = node => node.getClientRects().length > 0;
                const brand = document.querySelector('.navbar-brand > span');
                const brandStyle = getComputedStyle(brand);
                const brandRange = document.createRange();
                brandRange.selectNodeContents(brand);
                const textBoxes = range => [...range.getClientRects()].filter(rect => rect.width && rect.height)
                    .map(({left, right, top, bottom}) => ({left, right, top, bottom}));
                const characters = [];
                let offset = 0;
                for (const character of brand.textContent) {
                    const start = offset;
                    offset += character.length;
                    if (/\s/.test(character)) continue;
                    const range = document.createRange();
                    range.setStart(brand.firstChild, start);
                    range.setEnd(brand.firstChild, offset);
                    characters.push({offset: start, rects: textBoxes(range)});
                }
                const clippingAncestors = [];
                let ancestorVisible = true;
                for (let node = brand; node; node = node.parentElement) {
                    const style = getComputedStyle(node);
                    ancestorVisible &&= style.display !== 'none' && style.visibility === 'visible' && Number(style.opacity) > 0;
                    const clipsX = /hidden|clip|scroll|auto/.test(style.overflowX);
                    const clipsY = /hidden|clip|scroll|auto/.test(style.overflowY);
                    if (clipsX || clipsY) clippingAncestors.push({selector: node.className, clipsX, clipsY, ...box(node)});
                }
                const controls = [...document.querySelectorAll('.navbar-brand, .brand-logo, .navbar-toggler, .user-info-box .dropdown-toggle, .user-login-box .btn')]
                    .filter(visible).map(node => ({selector: node.className, ...box(node)}));
                const links = [...document.querySelectorAll('#navbarNav .nav-link')].filter(visible).map(node => {
                    const range = document.createRange();
                    range.selectNodeContents(node);
                    return {text: node.textContent.trim(), ...box(node), rects: [...range.getClientRects()].map(rect => ({left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom}))};
                });
                const footer = document.querySelector('.fbfaka-footer');
                const footerRange = document.createRange();
                footerRange.selectNodeContents(footer);
                return {document: document.documentElement.scrollWidth, body: document.body.scrollWidth,
                    viewport: document.documentElement.clientWidth, innerWidth, scrollbarWidth: innerWidth - document.documentElement.clientWidth,
                    nav: box(document.querySelector('.navbar-acg')), controls, links, footer: box(footer),
                    brand: {text: brand.textContent, ...box(brand), fontSize: parseFloat(brandStyle.fontSize),
                        ancestorVisible, rects: textBoxes(brandRange), characters, clippingAncestors},
                    footerRects: [...footerRange.getClientRects()].filter(rect => rect.width && rect.height)
                        .map(rect => ({left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom}))};
            });
            headers.push({label, ...geometry});
            check(geometry.document <= geometry.viewport && geometry.body <= geometry.viewport, `${label}: document and body fit available clientWidth`);
            const inside = (inner, outer) => inner.left >= outer.left - 1 && inner.right <= outer.right + 1
                && inner.top >= outer.top - 1 && inner.bottom <= outer.bottom + 1;
            const separate = (a, b) => a.right <= b.left + 1 || b.right <= a.left + 1 || a.bottom <= b.top + 1 || b.bottom <= a.top + 1;
            const brand = geometry.brand;
            check(brand.text === expectedShopNames.get(page), `${label}: exact full shop name`);
            check(brand.ancestorVisible && brand.width > 0 && brand.height > 0 && brand.fontSize >= 12
                && brand.rects.length > 0 && brand.characters.every(character => character.rects.length > 0),
                `${label}: shop name and every non-space character visibly rendered`);
            const brandRects = [...brand.rects, ...brand.characters.flatMap(character => character.rects)];
            // Font glyphs can exceed an unclipped line box vertically; actual clipping is checked below.
            check(brandRects.every(rect => rect.left >= brand.left - 1 && rect.right <= brand.right + 1 && inside(rect, geometry.nav)
                && rect.left >= -1 && rect.right <= geometry.viewport + 1), `${label}: complete shop-name Range fits available brand width and navbar`);
            check(brandRects.every(rect => brand.clippingAncestors.every(ancestor =>
                (!ancestor.clipsX || rect.left >= ancestor.left - 1 && rect.right <= ancestor.right + 1)
                && (!ancestor.clipsY || rect.top >= ancestor.top - 1 && rect.bottom <= ancestor.bottom + 1))),
                `${label}: no shop-name character clipped by overflow ancestors`);
            for (const neighbor of [...geometry.controls.filter(control => !control.selector.includes('navbar-brand')), ...geometry.links]) {
                check(brandRects.every(rect => separate(rect, neighbor)), `${label}: shop name does not overlap logo, account, toggle or navigation`);
            }
            check(geometry.footer.width > 0 && geometry.footer.left >= -1 && geometry.footer.right <= geometry.viewport + 1
                && geometry.footerRects.length > 0 && geometry.footerRects.every(rect => rect.left >= geometry.footer.left - 1
                    && rect.right <= geometry.footer.right + 1), `${label}: full footer fits available clientWidth`);
            if (scrollbars === 'classic' && geometry.innerWidth === 320) {
                check(geometry.viewport === 305 && geometry.scrollbarWidth === 15, `${label}: real classic scrollbar leaves 305px at innerWidth 320`);
            }
            for (const control of geometry.controls) {
                check(control.width > 0 && control.left >= -1 && control.right <= geometry.viewport + 1, `${label}: visible control inside viewport: ${control.selector}`);
            }
            const mainControls = geometry.controls.filter(control => !control.selector.includes('brand-logo'));
            for (const [index, a] of mainControls.entries()) for (const b of mainControls.slice(index + 1)) {
                check(a.right <= b.left + 1 || b.right <= a.left + 1 || a.bottom <= b.top + 1 || b.bottom <= a.top + 1,
                    `${label}: header controls do not overlap`);
            }
            for (const link of geometry.links) {
                check(link.left >= -1 && link.right <= geometry.viewport + 1 && link.rects.every(rect => rect.left >= link.left - 1 && rect.right <= link.right + 1), `${label}: full navigation link: ${link.text}`);
                for (const control of mainControls) {
                    check(link.right <= control.left + 1 || control.right <= link.left + 1 || link.bottom <= control.top + 1 || control.bottom <= link.top + 1,
                        `${label}: navigation does not overlap header controls`);
                }
            }
            return geometry;
        };
        const checkHeader = async (page, width, identity, layout, brandKey) => {
            await page.evaluate(() => scrollTo(0, 0));
            const label = `${width}/${identity}/${layout}${brandKey ? '/' + brandKey : ''}`;
            const capture = !brandKey && layout === 'list' && identity !== 'long' && [1440, 390, 320].includes(width);
            const closed = await headerGeometry(page, label + '/closed');
            if (capture) {
                await page.screenshot({path: path.join(output, `${phase}-nav-${width}-${identity}-${layout}-closed.png`)});
            }
            const toggle = page.locator('.navbar-toggler');
            check(await toggle.isVisible() === (width < 1200), `${label}: native xl collapse breakpoint`);
            if (await toggle.isVisible()) {
                await toggle.focus();
                await page.keyboard.press('Enter');
                await page.waitForFunction(() => document.querySelector('#navbarNav').classList.contains('show'));
                check(await toggle.getAttribute('aria-expanded') === 'true', `${label}: Enter opens menu and updates aria`);
                const opened = await headerGeometry(page, label + '/open');
                check(opened.links.length === (identity === 'long' ? 6 : 5), `${label}: all navigation entries visible`);
                const account = geometry => geometry.controls.find(control => /dropdown-toggle|btn-outline-secondary/.test(control.selector));
                check(Math.abs(account(opened).top - account(closed).top) <= 1, `${label}: account stays in first row when menu opens`);
                check(await page.locator('#navbarNav .item-search-input').isVisible(), `${label}: collapsed-menu search visible`);
                await page.locator('#navbarNav .item-search-input').fill('菜单搜索');
                await page.locator('#navbarNav .item-search-input').press('Enter');
                check(await page.evaluate(() => fixtureSearches.at(-1).keywords === '菜单搜索'), `${label}: menu source search reaches commodity stub`);
                if (capture) await page.screenshot({path: path.join(output, `${phase}-nav-${width}-${identity}-${layout}-open.png`)});
                await page.keyboard.press('Escape');
                await page.waitForFunction(() => !document.querySelector('#navbarNav').matches('.show, .collapsing'));
                check(await toggle.getAttribute('aria-expanded') === 'false', `${label}: Escape closes menu and updates aria`);
                await toggle.focus();
                await page.keyboard.press('Space');
                await page.waitForFunction(() => document.querySelector('#navbarNav').classList.contains('show'));
                await page.keyboard.press('Escape');
                await page.waitForFunction(() => !document.querySelector('#navbarNav').matches('.show, .collapsing'));
                const reclosed = await headerGeometry(page, label + '/reclosed');
                check(await toggle.getAttribute('aria-expanded') === 'false' && reclosed.links.length === 0,
                    `${label}: repeated keyboard open/close ends fully collapsed`);
                check(Math.abs(account(reclosed).top - account(closed).top) <= 1,
                    `${label}: account stays in first row after repeated menu close`);
            }
            if (identity !== 'guest') {
                await page.locator('#userDropdown').focus();
                await page.keyboard.press('ArrowDown');
                check(await page.locator('#userDropdown').getAttribute('aria-expanded') === 'true', `${label}: keyboard opens account menu`);
                check(await page.locator('.dropdown-item:focus').count() === 1, `${label}: account keyboard focus enters menu`);
                const dropdown = await page.locator('.dropdown-menu').boundingBox();
                check(dropdown.x >= -1 && dropdown.x + dropdown.width <= closed.viewport + 1, `${label}: account menu inside available clientWidth`);
                await page.keyboard.press('Escape');
                check(await page.locator('#userDropdown').getAttribute('aria-expanded') === 'false', `${label}: account Escape closes menu`);
                check(await page.locator('#userDropdown:focus').count() === 1, `${label}: account Escape restores focus`);
            }
            await page.locator('.fb-product-search').fill('合成关键词');
            await page.locator('.fb-product-search').press('Enter');
            check(await page.locator('.item-search-input').inputValue() === '合成关键词', `${label}: mirror updates source search`);
            check(await page.evaluate(() => fixtureSearches.at(-1).keywords === '合成关键词'), `${label}: real search reaches commodity stub`);
            await page.locator('.fb-product-search').fill('按钮搜索');
            await page.locator('.fb-search-trigger').click();
            check(await page.locator('.item-search-input').inputValue() === '按钮搜索'
                && await page.evaluate(() => fixtureSearches.at(-1).keywords === '按钮搜索'), `${label}: search button forwards keywords`);
            check(await page.locator('.item-list').getAttribute('data-fb-view') === layout, `${label}: search preserves selected view`);
            check(await page.locator('.fb-view-toggle').getAttribute('aria-pressed') === String(layout === 'grid'), `${label}: view button aria matches layout`);
        };
        if (demo) {
            for (const width of [1440, 375]) {
                const page = await context.newPage();
                page.on('pageerror', error => errors.push(error.message));
                await openStorefront(page, width, 'guest', 'zh-six');
                await headerGeometry(page, `${width}/guest/demo/closed`);
                const screenshot = path.join(output, `demo-storefront-${width}.png`);
                await page.screenshot({path: screenshot, fullPage: true});
                demoScreenshots.push(screenshot);
                await page.close();
            }
            const result = {phase, demo, revision, workingTree: true, scrollbars, screenshots: demoScreenshots,
                headers, failures, errors, resourceUrls: [indexUrl, cssUrl, pikaUrl],
                boundary: 'Synthetic shop, categories, products, stock and prices rendered by real Smarty Header/Index/Footer and Bootstrap/Pika CSS/JS. Media responses use repository brand-mark.svg and storefront-placeholder.svg; video is empty. No production data or live API.'};
            fs.writeFileSync(path.join(output, 'demo-storefront-computed.json'), JSON.stringify(result, null, 2));
            console.log(JSON.stringify({...result, headers: headers.length}));
            assert.deepEqual(errors, [], 'demo has no unexpected requests or browser errors');
            assert.equal(failures.length, 0, 'demo header geometry acceptance');
            return;
        }
        for (const width of stockWidths) {
            const page = await context.newPage();
            page.on('pageerror', error => errors.push(error.message));
            await openStorefront(page, width, 'member');
            for (const layout of ['list', 'grid']) {
                if (layout === 'grid') await page.locator('.fb-view-toggle').click();
                assert.ok((await page.locator('.item-list').getAttribute('class')).includes(`fb-layout-${layout}`));
                await checkHeader(page, width, 'member', layout);
                if (phase !== 'parent') {
                    check(!/已售|4321/.test(await page.locator('.item-list').textContent()), `${width}/${layout}: sales must not be rendered`);
                    check(await page.locator('.stat-bottom span').count() === stockItems.length, `${width}/${layout}: only stock remains`);
                }
                const measured = await page.locator('.item-list .acg-card').evaluateAll(cards => cards.map(card => {
                    const stock = card.querySelector('.stat-bottom span'), price = card.querySelector('.price'), title = card.querySelector('.goods-title');
                    const box = node => {
                        const {left, top, right, bottom, width, height} = node.getBoundingClientRect();
                        return {left, top, right, bottom, width, height};
                    };
                    const textRects = node => {
                        const range = document.createRange();
                        range.selectNodeContents(node);
                        return [...range.getClientRects()].filter(rect => rect.width && rect.height).map(({left, top, right, bottom}) => ({left, top, right, bottom}));
                    };
                    return {text: stock.textContent, stock: box(stock), stockParent: box(stock.parentElement), stockRects: textRects(stock),
                        price: box(price), priceRects: textRects(price), title: box(title), card: box(card),
                        titleFont: parseFloat(getComputedStyle(title).fontSize), columns: getComputedStyle(card.querySelector('.p-3')).gridTemplateColumns};
                }));
                layouts.push({width, layout, measured});
                const inside = (inner, outer) => inner.left >= outer.left - 1 && inner.right <= outer.right + 1 && inner.top >= outer.top - 1 && inner.bottom <= outer.bottom + 1;
                const insideWidth = (inner, outer) => inner.left >= outer.left - 1 && inner.right <= outer.right + 1;
                const separate = (a, b) => a.right <= b.left + 1 || b.right <= a.left + 1 || a.bottom <= b.top + 1 || b.bottom <= a.top + 1;
                for (const [index, value] of measured.entries()) {
                    const label = `${width}/${layout}/${stockItems[index].stock}/${index % 2 ? 'long' : 'short'}`;
                    check(value.text === `库存：${stockItems[index].stock}`, `${label}: full stock text`);
                    check(value.stock.width > 0 && value.stock.height > 0, `${label}: visible stock`);
                    check(new Set(value.stockRects.map(rect => Math.round(rect.top))).size === 1, `${label}: stock label and full number on one line`);
                    check(inside(value.stock, value.card) && inside(value.stock, value.stockParent) && value.stockRects.every(rect => inside(rect, value.stock)), `${label}: stock not clipped or overflowing`);
                    // Glyphs may exceed a line-height:1 box vertically without clipping.
                    check(value.priceRects.length > 0 && value.priceRects.every(rect => insideWidth(rect, value.price) && inside(rect, value.card)) && inside(value.price, value.card), `${label}: price not clipped or overflowing`);
                    check(value.title.width >= value.titleFont * 4 && inside(value.title, value.card), `${label}: title retains readable space`);
                    check(separate(value.stock, value.price) && separate(value.stock, value.title) && separate(value.price, value.title), `${label}: stock/title/price do not overlap`);
                }
                check(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth
                    && document.body.scrollWidth <= document.documentElement.clientWidth), `${width}/${layout}: no horizontal overflow beyond available clientWidth`);
                if ([1440, 1101, 992, 390, 320].includes(width)) {
                    await page.screenshot({path: path.join(output, `${phase}-stock-${width}-${layout}.png`), fullPage: true});
                }
            }
            await page.evaluate(() => scrollTo(0, 0));
            if (await page.locator('.navbar-toggler').isVisible()) {
                await page.locator('.navbar-toggler').focus();
                await page.keyboard.press('Enter');
                await page.waitForFunction(() => document.querySelector('#navbarNav').classList.contains('show'));
            }
            await page.locator('#navbarNav a[href="/user/personal/purchaseRecord"]').focus();
            await Promise.all([page.waitForURL('https://pika-fixture.invalid/user/personal/purchaseRecord'), page.keyboard.press('Enter')]);
            await page.close();
        }
        for (const width of [991, 992, 1199, 1200, 1358, 390, 320]) for (const identity of ['guest', 'long']) {
            const page = await context.newPage();
            page.on('pageerror', error => errors.push(error.message));
            await openStorefront(page, width, identity);
            for (const layout of ['list', 'grid']) {
                if (layout === 'grid') await page.locator('.fb-view-toggle').click();
                await checkHeader(page, width, identity, layout);
            }
            // Follow an actual link with the keyboard, but only into a fixed synthetic route.
            const destination = identity === 'guest' ? '/user/authentication/login' : '/user/dashboard/index';
            if (identity !== 'guest') {
                await page.locator('#userDropdown').focus();
                await page.keyboard.press('ArrowDown');
            } else await page.locator(`a[href="${destination}"]`).focus();
            await Promise.all([page.waitForURL(`https://pika-fixture.invalid${destination}`), page.keyboard.press('Enter')]);
            await page.close();
        }
        for (const width of [320, 375, 390, 768, 1199, 1200, 1440]) for (const identity of ['guest', 'member']) {
            const page = await context.newPage();
            page.on('pageerror', error => errors.push(error.message));
            for (const brandKey of Object.keys(fixtures.brandNames)) {
                await openStorefront(page, width, identity, brandKey);
                await checkHeader(page, width, identity, 'list', brandKey);
                brandCases.push({width, identity, brandKey, shopName: fixtures.brandNames[brandKey]});
            }
            await page.close();
        }
        // Parent comparison isolates stock; current before/after retain all purchase gates.
        for (const width of phase === 'parent' ? [] : purchaseWidths) {
            const page = await context.newPage();
            page.on('pageerror', error => errors.push(error.message));
            await page.setViewportSize({width, height: width === 320 ? 568 : 1000});
            for (const scenario of [...Object.keys(fixtures.pages), 'pay-error', 'delayed', 'format-error']) {
                await page.goto(`https://pika-fixture.invalid/item/7?case=${fixtures.pages[scenario] ? scenario : 'normal'}`);
                await setup(page, scenario);
                await page.addScriptTag({url: itemUrl});
                const geometry = await page.evaluate(() => ({innerWidth, innerHeight, viewport: document.documentElement.clientWidth,
                    document: document.documentElement.scrollWidth, body: document.body.scrollWidth,
                    elements: [...document.querySelectorAll('.fbfaka-item-hero, .fbfaka-item-description, .fbfaka-purchase-card, .fbfaka-item-hero a, .fbfaka-purchase-card input, .fbfaka-purchase-card button, .pay')]
                        .filter(node => node.getClientRects().length > 0).map(node => {
                            const {left, right, width} = node.getBoundingClientRect();
                            return {selector: node.className, left, right, width};
                        })}));
                purchases.push({width, scenario, ...geometry});
                check(geometry.document <= geometry.viewport && geometry.body <= geometry.viewport,
                    `${width}/${scenario}: purchase body fits available clientWidth`);
                for (const element of geometry.elements) {
                    check(element.width > 0 && element.left >= -1 && element.right <= geometry.viewport + 1,
                        `${width}/${scenario}: purchase card or control fits available clientWidth: ${element.selector}`);
                }
                if (scrollbars === 'classic' && width === 320 && !['pay-error', 'delayed'].includes(scenario)) {
                    check(geometry.viewport === 305, `${width}/${scenario}: purchase uses real classic scrollbar in 320x568 window`);
                }
                check(!/已售|4321/.test(await page.locator('.fbfaka-item-hero').textContent()), `${width}/${scenario}: detail sales must not be rendered`);
                check(await page.locator('.item-stock').isVisible(), `${width}/${scenario}: detail stock remains visible`);
                if (scenario === 'pay-error') {
                    assert.equal(await page.locator('.pay').count(), 0, 'failed payment list must not invent options or amounts');
                    continue;
                }
                if (scenario === 'delayed') {
                    await page.evaluate(() => { dispatchEvent(new PageTransitionEvent('pagehide')); fixturePayDone(); });
                    assert.equal(await page.locator('.pay[data-id="1"] span').textContent(), '站内钱包', 'late response cannot restore an old snapshot');
                    continue;
                }
                if (scenario === 'guest') {
                    assert.equal(await page.locator('.pay[data-id="1"]').count(), 0);
                    assert.equal(await page.locator('.pay-list').getAttribute('data-user-balance'), null);
                } else {
                    const suffix = {normal: '（¥12.34）', zero: '（¥0.00）', changed: '（¥56.78）'}[scenario] || '';
                    check(await page.locator('.pay[data-id="1"] span').textContent() === '站内钱包' + suffix, `${width}/${scenario}: correct balance or safe omission`);
                    assert.equal(await page.locator('.pay-list [onerror]').count(), 0, 'escaped synthetic input must not become HTML');
                }
                assert.equal(await page.locator('.pay[data-id="2"] span').textContent(), '第三方支付');
                assert.equal(await page.locator('.pay[data-id="9"] span').textContent(), '余额', 'do not identify balance by its Chinese name');
                if (['normal', 'zero', 'guest', 'missing'].includes(scenario)) {
                    const ids = scenario === 'guest' ? [2, 9] : [1, 2, 9];
                    for (const id of ids) await page.locator(`.pay[data-id="${id}"]`).click();
                    const calls = await page.evaluate(() => fixtureCalls.filter(call => call.url === '/user/api/order/trade'));
                    assert.deepEqual(calls.map(call => call.data), ids.map(id => ({num: '1', ...(scenario === 'guest' ? {contact: ''} : {}), item_id: 7, pay_id: id})));
                    assert.equal(await page.evaluate(() => fixtureResults.length), ids.length, 'original payment result callback is retained');
                }
                if (scenario === 'normal') {
                    if (width === 320) {
                        // Keep the native gutter in the evidence; a fullPage capture may relayout a taller viewport.
                        await page.evaluate(async () => {
                            scrollTo(0, 0);
                            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                        });
                        await page.screenshot({path: path.join(output, `${phase}-purchase-${width}-top.png`)});
                        await page.evaluate(async () => {
                            scrollTo(0, document.documentElement.scrollHeight);
                            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                        });
                        await page.screenshot({path: path.join(output, `${phase}-purchase-${width}-bottom.png`)});
                    } else await page.screenshot({path: path.join(output, `${phase}-purchase-${width}.png`), fullPage: true});
                    await page.evaluate(() => dispatchEvent(new PageTransitionEvent('pageshow', {persisted: true})));
                    check(await page.locator('.pay[data-id="1"] span').textContent() === '站内钱包', `${width}: restored page must discard balance snapshot`);
                    assert.equal(await page.locator('.pay-list').getAttribute('data-user-balance'), null);
                }
                assert.deepEqual(await page.evaluate(() => [localStorage.length, sessionStorage.length]), [0, 0]);
                assert.equal(await page.evaluate(() => fixtureCalls.filter(call => !/\/index\/(valuation|stock|pay)|\/order\/trade/.test(call.url)).length), 0, 'no new balance endpoint');
            }
            await page.close();
        }
        if (phase === 'after') {
            for (const resource of [indexUrl, itemUrl, cssUrl]) {
                assert.match(resource, resource === cssUrl ? /theme=1\.1\.7&rev=20260925-brand1$/ : /rev=20260914$/);
                assert.ok(requests.includes(resource), `actual resource requested: ${resource}`);
            }
            for (const part of ['Index', 'Common', 'Authentication']) {
                const relative = `themes/Pika/${part}/Header.html`;
                const resource = read(relative).match(/href="([^\"]*pika\.css[^\"]*)"/)?.[1].replaceAll('&amp;', '&');
                assert.equal(resource, cssUrl, 'all CSS headers must use the same revision');
            }
        }
        const result = {phase, revision, workingTree: phase === 'after', scrollbars, browserVersion: browser.version(),
            cases: purchases.length,
            stockCases: layouts.length * stockItems.length, headerCases: new Set(headers.map(header => header.label.replace(/\/(open|closed|reclosed)$/, ''))).size,
            brandCases, layouts, headers, purchases, failures, errors, resourceUrls: [indexUrl, itemUrl, cssUrl, pikaUrl],
            boundary: 'Full synthetic Smarty Header/Index/Footer with real Bootstrap/Pika scripts and isolated purchase-body scenarios. Classic mode removes only Playwright default --hide-scrollbars and verifies native 320/305 geometry. No real session, API, payment, video playback, HTTP cache, or physical-device proof.'};
        fs.writeFileSync(path.join(output, `${phase}-purchase-computed.json`), JSON.stringify(result, null, 2));
        console.log(JSON.stringify({...result, layouts: layouts.length, headers: headers.length, failureCount: failures.length, failures: failures.slice(0, 12)}));
        assert.deepEqual(errors, [], 'browser errors or unexpected requests');
        assert.equal(failures.length, 0, 'purchase presentation acceptance; full failures saved in computed JSON');
    } finally {
        await browser.close();
    }
}
