import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {pathToFileURL, fileURLToPath} from 'node:url';

// Browser integration of the actual official template, controller, ready
// loader, Component and Form. Only table/API data and unused editor services
// are synthetic. All browser requests are intercepted; no server is contacted.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const official = fs.realpathSync(process.env.ACG_FAKA_OFFICIAL_ROOT || '');
const playwrightPath = process.env.PLAYWRIGHT_MODULE;
const chrome = process.env.CHROME_BIN;
assert.ok(playwrightPath && chrome, 'PLAYWRIGHT_MODULE and CHROME_BIN are required; do not install a browser');
const {chromium} = await import(pathToFileURL(path.join(playwrightPath, 'index.mjs')).href);
const php = spawnSync('docker', ['run', '--rm', '--network', 'none',
    '--volume', `${root}:/release:ro`, '--volume', `${official}:/official:ro`,
    '--tmpfs', '/tmp:rw,nosuid,nodev,size=64m',
    '--tmpfs', '/var/lib/pika-local-extensions:rw,nosuid,nodev,mode=0755',
    '--env', 'ACG_FAKA_OFFICIAL_ROOT=/official', process.env.PHP_FIXTURE_IMAGE || 'acg-faka-php83-integration:20260828',
    'php', '-d', 'error_reporting=24575', '/release/tests/local-native-category-rename-behavior.php', '33', '33', '--hooks'],
{encoding: 'utf8', timeout: 60000});
assert.equal(php.status, 0, php.stderr + php.stdout);
const hooks = JSON.parse(php.stdout);
assert.equal(hooks.form.submit, '/admin/api/category/save');
const scripts = [
    'jquery.min.js', 'ready.js', 'util.js', 'cache.js', 'layer/layer.js', 'component.js', 'layui/layui.js',
    'component/tree.select.js', 'component/form.js',
];
let template = fs.readFileSync(path.join(official, 'app/View/Admin/Trade/Category.html'), 'utf8');
assert.ok(template.indexOf('ADMIN_VIEW_CATEGORY_TOOLBAR') < template.indexOf('ready('));
template = template.replace(/#\{include[^}]+\}/g, '').replace(/#\{t\("([^"]*)"\)\}/g, '$1')
    .replace(/#\{hook\([^}]+\)\}/, hooks.toolbar)
    .replace(/#\{ready\("([^"]+)"\)\}/, '<script>ready("$1");</script>');
const bootstrap = `
window.ace = {config:{set(){}}};
window._Dict = {advanced(_key, done){done([{id:1,name:'synthetic-group',pid:0,children:[{id:11,name:'native-old',pid:1}]}])}};
window.fixtureMessages = []; window.fixtureTables = []; window.fixtureForms = [];
window.message = {success(text){fixtureMessages.push(['success',text])}, error(text){fixtureMessages.push(['error',text])}, alert(){}, ask(){}};
window.Table = class {
 constructor(route, target){this.route=route;this.target=target;fixtureTables.push(this);$(target).data('adminTable',this)}
 setUpdate(callback){this.update=callback} setTree(){} setColumns(columns){this.columns=columns}
 setSearch(){} setState(){} render(){} refresh(){this.refreshes=(this.refreshes||0)+1} destroy(){this.isDestroyed=true}
 getRows(){return []} getSelectionIds(){return []} getSelections(){return []}
 static destroyAll(){}
};
// Keep the original popup/Form implementation; capture only its public presenter seam.
window.AdminMobile = {isEnabled(){return true}, presentPopup(ctx){
 fixtureForms.push(ctx);
 const holder=document.createElement('section');holder.className=ctx.form.getUnique();holder.dataset.fixturePopup=fixtureForms.length;
 holder.innerHTML=ctx.tabs.map(tab=>tab.content).join('');document.body.append(holder);
 ctx.register(fixtureForms.length);
 return {handled:true};
}};
util.get=(_url,done)=>done({list:[]});
util.post=(route,data,done)=>{if(typeof route==='object'){data=route.data;done=route.done;route=route.url}return fetch(route,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data)}).then(r=>r.json()).then(done)};
setVar('HACK_SUBMIT_FORM',[${JSON.stringify(hooks.form)}]);
setVar('LANG','zh-cn');
`;
const html = '<!doctype html><html><head><meta charset="utf-8"></head><body>'
    + scripts.map(src => `<script src="/assets/common/js/${src}"></script>`).join('')
    + `<script>${bootstrap}</script>` + template + '</body></html>';
const browser = await chromium.launch({headless: true, executablePath: chrome});
try {
    const context = await browser.newContext();
    const page = await context.newPage();
    const calls = [], errors = [];
    let malformed = false, holdRename = false, releaseRename;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        const pathname = url.pathname;
        assert.equal(url.hostname, 'pika-fixture.invalid', 'external browser request escaped fixture');
        if (pathname === '/admin/category/index') return route.fulfill({contentType: 'text/html', body: html});
        if (pathname.startsWith('/admin/api/localExtensions/')) {
            const body = Object.fromEntries(new URLSearchParams(route.request().postData()));
            calls.push({pathname, body});
            const rename = pathname.endsWith('catalogHubCategoryRename');
            if (rename && holdRename) await new Promise(resolve => { releaseRename = resolve; });
            const category = Number(body.category_id) === 12 ? null : {category_id: 11, source_id: 1, source_node_count: 2,
                category_name: rename ? body.alias.trim() : 'native-old', alias: rename ? body.alias.trim() : 'native-old'};
            return route.fulfill({json: {code: 200, data: rename && malformed ? {} : {category}}});
        }
        if (pathname === '/admin/api/category/save') {
            calls.push({pathname, body: Object.fromEntries(new URLSearchParams(route.request().postData()))});
            return route.fulfill({json: {code: 200, data: {}}});
        }
        if (pathname === '/assets/local-extensions/PikaCatalogHub/category-rename.js') {
            // Invert network completion order: ready must still execute the
            // extension before the official category controller.
            await new Promise(resolve => setTimeout(resolve, 60));
            return route.fulfill({contentType: 'text/javascript', body: fs.readFileSync(path.join(root, 'extensions/PikaCatalogHub/Assets/category-rename.js'))});
        }
        if (pathname.startsWith('/assets/')) {
            const candidate = path.resolve(official, '.' + pathname);
            assert.ok(candidate.startsWith(official + path.sep));
            if (fs.existsSync(candidate) && fs.statSync(candidate).isFile()) return route.fulfill({path: candidate});
            return route.fulfill({status: 404, body: ''});
        }
        throw new Error('unexpected fixture route ' + pathname);
    });
    await page.goto('http://pika-fixture.invalid/admin/category/index', {waitUntil: 'networkidle'});
    await page.waitForFunction(() => fixtureTables.length > 0 && window.PikaCategoryRename);
    assert.deepEqual(errors, []);
    await page.evaluate(() => {
        const table = fixtureTables[0];
        if (table.columns.find(column => column.field === 'name').type !== undefined) throw new Error('name unexpectedly became inline editable');
        table.columns.find(column => column.field === 'operation').buttons[0].click(null, null,
            {id: 11, name: 'native-old', icon: '/favicon.ico', sort: 3, hide: 0, status: 1, pid: 1, owner: 0});
    });
    await page.getByRole('button', {name: '修改货源显示名（同步所有分类）', exact: true}).waitFor();
    assert.equal(await page.locator('textarea[name="name"]').getAttribute('readonly'), '');
    assert.equal(calls.filter(call => call.pathname.endsWith('Rename')).length, 0);
    const beforeSubmit = await page.evaluate(() => fixtureForms[0].form.getData());
    assert.ok(!Object.hasOwn(beforeSubmit, 'name'), 'actual native Form serialized the managed name');
    assert.equal(beforeSubmit.sort, '3');
    await page.getByRole('button', {name: '修改货源显示名（同步所有分类）', exact: true}).click();
    await page.locator('input[name="alias"]').fill('  saved-normalized  ');
    assert.equal(calls.filter(call => call.pathname.endsWith('Rename')).length, 0, 'typing triggered mutation');
    holdRename = true;
    await page.evaluate(() => { fixtureForms[1].submit(2); fixtureForms[1].submit(2); });
    await page.waitForFunction(() => fixtureForms.length === 2);
    for (let index = 0; index < 100 && !releaseRename; index++) await new Promise(resolve => setTimeout(resolve, 10));
    assert.ok(releaseRename, 'explicit rename request was not sent');
    assert.equal(calls.filter(call => call.pathname.endsWith('Rename')).length, 1, 'mobile presenter double-submit sent two writes');
    releaseRename(); holdRename = false;
    await page.waitForFunction(() => fixtureMessages.some(entry => entry[0] === 'success'));
    assert.equal(await page.locator('textarea[name="name"]').inputValue(), 'saved-normalized', 'did not show exact normalized API alias');
    await page.evaluate(() => fixtureForms[0].submit(1));
    await page.waitForFunction(() => fixtureTables[0].refreshes >= 1);
    assert.ok(!Object.hasOwn(calls.find(call => call.pathname === '/admin/api/category/save').body, 'name'));
    malformed = true;
    await page.evaluate(() => fixtureForms[1].options.submit({alias:'bad-response'}, 2));
    await page.waitForFunction(() => fixtureMessages.some(entry => entry[0] === 'error' && entry[1].includes('保存响应不完整')));
    assert.equal(await page.locator('textarea[name="name"]').inputValue(), 'saved-normalized');
    await page.evaluate(() => {
        fixtureTables[0].columns.find(column => column.field === 'operation').buttons[0].click(null, null,
            {id:12,name:'ordinary-child',icon:'/favicon.ico',sort:0,hide:0,status:1,pid:11,owner:0});
    });
    await page.waitForFunction(() => fixtureForms.length === 3);
    await page.waitForTimeout(80);
    assert.equal(await page.evaluate(() => fixtureForms[2].form.getData().name), 'ordinary-child');
    assert.equal(await page.locator('textarea[name="name"]').last().getAttribute('readonly'), null);
    // Also exercise the official desktop Layer presenter, without replacing
    // the popup container or its original save/cancel button callbacks.
    malformed = false;
    await page.evaluate(() => {
        fixtureForms.forEach(ctx => ctx.form.destroy());
        document.querySelectorAll('[data-fixture-popup]').forEach(element => element.remove());
        window.AdminMobile = {isEnabled(){return false}, presentPopup(){return false}};
        fixtureTables[0].columns.find(column => column.field === 'operation').buttons[0].click(null, null,
            {id:11,name:'native-old',icon:'/favicon.ico',sort:3,hide:0,status:1,pid:1,owner:0});
    });
    await page.waitForTimeout(250);
    assert.deepEqual(errors, []);
    await page.locator('.layui-layer').getByRole('button', {name:'修改货源显示名（同步所有分类）',exact:true}).waitFor({timeout:5000});
    assert.equal(await page.locator('.layui-layer textarea[name="name"]').getAttribute('readonly'), '');
    await page.locator('.layui-layer').getByRole('button', {name:'修改货源显示名（同步所有分类）',exact:true}).click();
    const linkedPopup = page.locator('.layui-layer').filter({has:page.locator('input[name="alias"]')});
    await linkedPopup.locator('input[name="alias"]').fill(' desktop-real-layer ');
    await linkedPopup.locator('.layui-layer-btn0').click();
    await linkedPopup.waitFor({state:'detached'});
    assert.equal(await page.locator('.layui-layer textarea[name="name"]').inputValue(), 'desktop-real-layer');
    assert.deepEqual(errors, []);
    await context.close();
    process.stdout.write('local native category rename actual template/ready/controller/Component/Form browser: PASS\n');
} finally { await browser.close(); }
