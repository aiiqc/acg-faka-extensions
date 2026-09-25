import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const themeRoot = path.join(root, 'themes/Pika');
const assetRoot = path.join(themeRoot, 'Assets');

function filesBelow(directory) {
    return fs.readdirSync(directory, {withFileTypes: true}).flatMap(entry => {
        const absolute = path.join(directory, entry.name);
        return entry.isDirectory() ? filesBelow(absolute) : [absolute];
    });
}

const requiredFiles = [
    'Config.php',
    'Metadata.php',
    'Setting.php',
    'theme.json',
    'Index/Header.html',
    'Index/Footer.html',
    'Index/Index.html',
    'Index/Item.html',
    'Index/Query.html',
    'Authentication/Header.html',
    'Authentication/Footer.html',
    'Common/Header.html',
    'Common/Footer.html',
    'Assets/brand-mark.svg',
    'Assets/storefront-placeholder.svg',
    'Assets/pika-favicon-1.0.3.png',
    'Assets/topfans-bg-poster.jpg',
    'Assets/topfans-bg.mp4',
    'Assets/topfans-logo.png',
    'Assets/safe-dom.js',
    'Assets/index.js',
    'Assets/item.js',
    'Assets/query.js',
    'Assets/pika.js',
    'Assets/pika.css',
];

test('Pika theme uses Metadata.php as its single runtime metadata and version source', () => {
    const config = fs.readFileSync(path.join(themeRoot, 'Config.php'), 'utf8');
    const metadata = fs.readFileSync(path.join(themeRoot, 'Metadata.php'), 'utf8');
    const manifest = JSON.parse(fs.readFileSync(path.join(themeRoot, 'theme.json'), 'utf8'));

    assert.match(config, /const INFO = Metadata::INFO;/);
    assert.match(config, /const SUBMIT = Metadata::SUBMIT;/);
    assert.match(config, /const THEME = Metadata::THEME;/);
    assert.match(metadata, /public const VERSION = '1\.1\.7';/);
    const version = metadata.match(/public const VERSION = '([^']+)';/)?.[1];
    assert.equal(version, '1.1.7');
    assert.equal(manifest.version, version, 'distribution manifest must mirror the runtime metadata version');
    assert.equal(manifest.theme_key, 'Pika');
    assert.equal(manifest.namespace, 'App\\View\\User\\Theme\\Pika\\');
    assert.deepEqual(manifest.preserve, ['Setting.php']);

    for (const relative of ['Authentication/Header.html', 'Common/Header.html', 'Index/Header.html']) {
        const header = fs.readFileSync(path.join(themeRoot, relative), 'utf8');
        assert.match(
            header,
            new RegExp(`href="/app/View/User/Theme/Pika/Assets/pika\\.css\\?theme=${version.replaceAll('.', '\\.') }&amp;rev=20260925-brand1"`),
            `${relative} must retain the theme version and invalidate the changed CSS resource`,
        );
        assert.doesNotMatch(header, /["']\/app\/View\/User\/Theme\/Pika\/Assets\/pika\.css["']/);
    }
    for (const relative of ['Authentication/Footer.html', 'Common/Footer.html', 'Index/Footer.html']) {
        const footer = fs.readFileSync(path.join(themeRoot, relative), 'utf8');
        assert.match(
            footer,
            new RegExp(`src="/app/View/User/Theme/Pika/Assets/pika\\.js\\?theme=${version.replaceAll('.', '\\.') }"`),
            `${relative} must cache-bust Pika JS with the theme version`,
        );
        assert.doesNotMatch(footer, /#\{js\("\/app\/View\/User\/Theme\/Pika\/Assets\/pika\.js"\)\}/);
    }

    const otherRuntimeFiles = filesBelow(themeRoot).filter(file =>
        file !== path.join(themeRoot, 'Metadata.php')
        && file !== path.join(themeRoot, 'theme.json')
        && /\.(?:php|html|js|css)$/.test(file)
    );
    for (const file of otherRuntimeFiles) {
        assert.doesNotMatch(fs.readFileSync(file, 'utf8'), /\b1\.1\.(?:0|1)\b/, file);
    }
});

test('theme package is complete and all case-sensitive internal references resolve on Linux', () => {
    for (const relative of requiredFiles) {
        assert.ok(fs.statSync(path.join(themeRoot, relative)).isFile(), `missing ${relative}`);
    }

    const allRelative = filesBelow(themeRoot).map(file => path.relative(themeRoot, file));
    const caseFolded = new Map();
    for (const relative of allRelative) {
        const folded = relative.toLowerCase();
        assert.equal(caseFolded.has(folded), false, `case-insensitive duplicate: ${relative}`);
        caseFolded.set(folded, relative);
    }

    const textFiles = filesBelow(themeRoot).filter(file => /\.(?:php|html|js|css|json)$/.test(file));
    for (const file of textFiles) {
        assert.equal(fs.lstatSync(file).isSymbolicLink(), false, `symlink is not packageable: ${file}`);
        const source = fs.readFileSync(file, 'utf8');
        for (const match of source.matchAll(/\/app\/View\/User\/Theme\/Pika\/([^\s"'?#)]+)/g)) {
            const referenced = match[1];
            assert.ok(allRelative.includes(referenced), `case-sensitive missing path in ${file}: ${referenced}`);
        }
        if (file.endsWith('.html')) {
            for (const match of source.matchAll(/#\{include\s+file="([^"]+)"/g)) {
                const absolute = path.resolve(path.dirname(file), match[1]);
                assert.ok(absolute.startsWith(themeRoot + path.sep), `include escaped theme root in ${file}: ${match[1]}`);
                const relative = path.relative(themeRoot, absolute);
                assert.ok(allRelative.includes(relative), `case-sensitive missing include in ${file}: ${match[1]}`);
            }
        }
    }

    const metadata = fs.readFileSync(path.join(themeRoot, 'Metadata.php'), 'utf8');
    for (const match of metadata.matchAll(/=>\s*'([^']+\.html)'/g)) {
        assert.ok(allRelative.includes(match[1]), `case-sensitive missing template: ${match[1]}`);
    }
});

test('password and purchase forms use POST and CSP-sensitive behavior uses official data bindings', () => {
    for (const relative of [
        'Authentication/Login.html',
        'Authentication/Register.html',
        'Authentication/ForgetEmail.html',
        'Authentication/ForgetPhone.html',
        'Index/Item.html',
        'User/Password.html',
    ]) {
        const template = fs.readFileSync(path.join(themeRoot, relative), 'utf8');
        assert.match(template, /<form\b[^>]*\bmethod="post"/i, `${relative} must not expose sensitive fields through GET fallback`);
    }

    const login = fs.readFileSync(path.join(themeRoot, 'Authentication/Login.html'), 'utf8');
    const register = fs.readFileSync(path.join(themeRoot, 'Authentication/Register.html'), 'utf8');
    const personal = fs.readFileSync(path.join(themeRoot, 'User/Personal.html'), 'utf8');
    const ticket = fs.readFileSync(path.join(themeRoot, 'User/TicketCreate.html'), 'utf8');
    assert.match(login, /data-acg-refresh="\/user\/captcha\/image\?action=login"/);
    assert.match(register, /data-acg-refresh="\/user\/captcha\/image\?action=register"/);
    assert.equal((personal.match(/data-acg-proxy="\.avatar-input"/g) || []).length, 1);
    assert.equal((personal.match(/data-acg-proxy="\.wechat-input"/g) || []).length, 2);
    assert.match(ticket, /<form\b[^>]*\bdata-acg-prevent\b/i);

    const templates = filesBelow(themeRoot)
        .filter(file => file.endsWith('.html'))
        .map(file => fs.readFileSync(file, 'utf8'))
        .join('\n');
    assert.doesNotMatch(templates, /\son[a-z]+\s*=/i, 'CSP enforce blocks inline event handlers');
});

test('operator docs preserve the exact Pika asset exception while keeping app source private', () => {
    const guide = fs.readFileSync(path.join(root, 'docs/USER_INSTALL.md'), 'utf8');
    const troubleshooting = fs.readFileSync(path.join(root, 'docs/TROUBLESHOOTING.md'), 'utf8');
    const exactAssetRule = 'location ~* ^/app/View/User/Theme/Pika/Assets/.+\\.(?:css|js|svg|png|jpe?g|mp4)$';

    assert.ok(guide.includes(exactAssetRule));
    assert.ok(troubleshooting.includes(exactAssetRule));
    assert.match(guide, /不要使用会抢先匹配全部请求的 `location \^~ \/app`/);
    assert.match(guide, /不会放行 PHP、README 或整个 `app` 目录/);
    assert.match(troubleshooting, /保留直接 PHP 与通用 `\/app` 拒绝规则/);
});

test('active Pika visual media is packaged while the superseded background stays excluded', () => {
    const manifest = JSON.parse(fs.readFileSync(path.join(themeRoot, 'theme.json'), 'utf8'));
    const gitignore = fs.readFileSync(path.join(root, '.gitignore'), 'utf8');
    const excluded = new Set(manifest.exclude);
    const active = [
        'Assets/pika-favicon-1.0.3.png',
        'Assets/topfans-bg-poster.jpg',
        'Assets/topfans-bg.mp4',
        'Assets/topfans-logo.png',
    ];
    const expectedSha256 = new Map([
        ['Assets/pika-favicon-1.0.3.png', '6e676d6865e1ef951350bb478ad3a562b4bbf6fbf62cfce7810fe7b48c2b002a'],
        ['Assets/topfans-bg-poster.jpg', '142f10f7aad4df7abc610951a7671ef7fcbc7140912f3f7c1304d86f31d06b78'],
        ['Assets/topfans-bg.mp4', '6101b9a46c1ba230a674ca163c48bcb65541d17e5627f35045ab08f97b4e7693'],
        ['Assets/topfans-logo.png', '6e676d6865e1ef951350bb478ad3a562b4bbf6fbf62cfce7810fe7b48c2b002a'],
    ]);
    assert.deepEqual([...excluded], ['Assets/storefront-bg.jpg']);
    assert.match(
        gitignore,
        /^themes\/Pika\/Assets\/storefront-bg\.jpg$/m,
        'superseded source is not ignored',
    );
    for (const relative of active) {
        assert.equal(excluded.has(relative), false, `active asset is excluded: ${relative}`);
        assert.doesNotMatch(
            gitignore,
            new RegExp(`^themes/Pika/${relative.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`, 'm'),
            `active asset is still ignored: ${relative}`,
        );
        const localPath = path.join(themeRoot, relative);
        assert.ok(fs.statSync(localPath).isFile(), `active asset is missing: ${relative}`);
        assert.ok(fs.statSync(localPath).size > 0, `active asset is empty: ${relative}`);
        assert.equal(fs.lstatSync(localPath).isSymbolicLink(), false, `active asset is a symlink: ${relative}`);
        assert.equal(
            createHash('sha256').update(fs.readFileSync(localPath)).digest('hex'),
            expectedSha256.get(relative),
            `active asset differs from the S1-S5 visual baseline: ${relative}`,
        );
    }

    const runtimeSource = filesBelow(themeRoot)
        .filter(file => /\.(?:php|html|js|css)$/.test(file))
        .map(file => fs.readFileSync(file, 'utf8'))
        .join('\n');
    assert.match(runtimeSource, /topfans-logo\.png/);
    assert.match(runtimeSource, /pika-favicon-1\.0\.3\.png/);
    assert.match(runtimeSource, /topfans-bg-poster\.jpg/);
    assert.match(runtimeSource, /topfans-bg\.mp4/);
    assert.doesNotMatch(runtimeSource, /storefront-bg\.jpg/);
    assert.doesNotMatch(runtimeSource, /brand-mark\.svg/);
    assert.doesNotMatch(runtimeSource, /storefront-placeholder\.svg/);
});

test('all three Pika page shells render the same safe animated background', () => {
    for (const relative of ['Index/Header.html', 'Authentication/Header.html', 'Common/Header.html']) {
        const header = fs.readFileSync(path.join(themeRoot, relative), 'utf8');
        assert.match(header, /<link rel="icon" type="image\/png" href="\/app\/View\/User\/Theme\/Pika\/Assets\/pika-favicon-1\.0\.3\.png">/);
        assert.match(header, /<body[^>]*data-pika-theme="Pika"[^>]*>\s*<video class="fbfaka-background-video" autoplay muted loop playsinline preload="metadata"/s);
        assert.match(header, /poster="\/app\/View\/User\/Theme\/Pika\/Assets\/topfans-bg-poster\.jpg" aria-hidden="true">/);
        assert.match(header, /<source src="\/app\/View\/User\/Theme\/Pika\/Assets\/topfans-bg\.mp4" type="video\/mp4">/);
    }

    const css = fs.readFileSync(path.join(assetRoot, 'pika.css'), 'utf8');
    assert.match(css, /background: #ece7db url\("\/app\/View\/User\/Theme\/Pika\/Assets\/topfans-bg-poster\.jpg"\) center \/ cover fixed;/);
    assert.match(css, /\.fbfaka-background-video\s*\{[\s\S]*?position: fixed;[\s\S]*?object-fit: cover;/);
    assert.match(css, /@media \(prefers-reduced-motion: reduce\)[\s\S]*?\.fbfaka-background-video\s*\{[\s\S]*?display: none;/);
});

test('payment navigation and upstream query fields use the theme safety runtime', () => {
    const item = fs.readFileSync(path.join(assetRoot, 'item.js'), 'utf8');
    const query = fs.readFileSync(path.join(assetRoot, 'query.js'), 'utf8');
    assert.match(item, /safeDom\.safeNavigationUrl\(res\.data\.url\)/);
    assert.match(item, /window\.location\.assign\(paymentUrl\)/);
    assert.doesNotMatch(item, /window\.location\.(?:href\s*=|assign\(res\.data\.url)/);
    assert.doesNotMatch(query, /\.html\s*\(|innerHTML\s*=/);
    assert.match(query, /safeDom\.setText\(element, text\)/);
});

test('shopping links stay in the current tab and bypass the user-center PJAX shell', () => {
    const commonHeader = fs.readFileSync(path.join(themeRoot, 'Common/Header.html'), 'utf8');
    const dashboard = fs.readFileSync(path.join(themeRoot, 'Dashboard/Index.html'), 'utf8');
    const runtime = fs.readFileSync(path.join(assetRoot, 'pika.js'), 'utf8');

    assert.match(commonHeader, /<a class="uc-nav__link" href="\/" data-pika-full-navigation>/);
    assert.match(commonHeader, /<a class="uc-drawer__link" href="\/" data-pika-full-navigation>/);
    assert.match(commonHeader, /<a class="uc-brand" href="\/" data-pika-full-navigation>/);
    assert.match(commonHeader, /<a class="uc-brand" href="\/" data-pika-full-navigation style=/);
    assert.match(dashboard, /<a class="uc-quick" href="\/" data-pika-full-navigation>/);
    assert.doesNotMatch(commonHeader + dashboard, /data-pjax="false"/);
    assert.doesNotMatch(commonHeader + dashboard, /data-pika-full-navigation[^>]*target=/);
    assert.match(runtime, /event\.target\.closest\("\[data-pika-full-navigation\]"\)/);
    assert.match(runtime, /link\.nodeName !== "A"/);
    assert.match(runtime, /rawHref !== "\/"/);
    assert.match(runtime, /event\.button !== 0/);
    assert.match(runtime, /event\.preventDefault\(\);\s*event\.stopPropagation\(\);\s*window\.location\.assign\("\/"\);/);
    assert.match(runtime, /\}, true\);/);
    assert.doesNotMatch(runtime, /pjax:click\.pikaFullNavigation/);
});

test('user-rules navigation survives the asynchronous storefront layout', () => {
    const header = fs.readFileSync(path.join(themeRoot, 'Index/Header.html'), 'utf8');
    const index = fs.readFileSync(path.join(themeRoot, 'Index/Index.html'), 'utf8');
    const storefront = fs.readFileSync(path.join(assetRoot, 'index.js'), 'utf8');

    assert.match(header, /href="\/#fbfaka-rules" data-pika-rules-navigation/);
    assert.match(index, /#\{ready\("\/app\/View\/User\/Theme\/Pika\/Assets\/index\.js\?pika=1\.1\.7&rev=20260914"\)\}/);
    assert.match(storefront, /document\.querySelectorAll\("\[data-pika-rules-navigation\]"\)/);
    assert.match(storefront, /var rulesScrollPending = Boolean\(/);
    assert.match(storefront, /activeRequest !== 0/);
    assert.match(storefront, /rulesScrollFrame = window\.requestAnimationFrame\(scroll\)/);
    assert.match(storefront, /window\.cancelAnimationFrame\(rulesScrollFrame\)/);
    assert.match(storefront, /window\.__pikaCategoryRuntime !== runtimeHandle/);
    assert.match(storefront, /rules\.isConnected === false/);
    assert.match(storefront, /window\.history\.pushState\(window\.history\.state, "", "#fbfaka-rules"\)/);
    assert.match(storefront, /catch \(error\) \{\s*return;\s*\}/);
    assert.match(storefront, /rules\.scrollIntoView\(\{ block: "start", behavior: "auto" \}\)/);
    assert.match(storefront, /settleRequestedRulesAnchor\(\);/);
});

test('desktop catalog and short query pages keep usable proportions', () => {
    const categoryNode = fs.readFileSync(path.join(themeRoot, 'Index/CategoryNode.html'), 'utf8');
    const styles = fs.readFileSync(path.join(assetRoot, 'pika.css'), 'utf8');

    assert.match(categoryNode, /class="category-label" title="/);
    assert.match(styles, /grid-template-columns: clamp\(360px, 32vw, 420px\) minmax\(0, 1fr\);/);
    assert.match(styles, /body\.fbfaka-public\s*\{[\s\S]*?display: flex;[\s\S]*?flex-direction: column;/);
    assert.match(styles, /body\.fbfaka-public > #pjax-container\s*\{[\s\S]*?flex: 1 0 auto;/);
    assert.match(styles, /\.fbfaka-query-hero \.btn-search-query\s*\{[\s\S]*?min-width: 112px;[\s\S]*?white-space: nowrap;/);
    assert.match(styles, /\.fbfaka-footer\s*\{[\s\S]*?margin-top: auto;/);
});

test('storefront suppresses empty leaves and hides an entirely empty category tree', () => {
    const categoryNode = fs.readFileSync(path.join(themeRoot, 'Index/CategoryNode.html'), 'utf8');
    const storefront = fs.readFileSync(path.join(assetRoot, 'index.js'), 'utf8');
    const styles = fs.readFileSync(path.join(assetRoot, 'pika.css'), 'utf8');

    assert.match(categoryNode, /#\{if \$cate\.commodity_count > 0\}[\s\S]*class="switch-category category-leaf chip/);
    assert.match(storefront, /group\.hidden = sum === 0;/);
    assert.match(storefront, /parent\.setAttribute\("aria-hidden", sum === 0 \? "true" : "false"\);/);
    assert.match(styles, /\.fbfaka-category-group\[hidden\]\s*\{\s*display: none;/);
});

test('hook navigation accepts only local or HTTP(S) URLs and fixed browsing targets', () => {
    const header = fs.readFileSync(path.join(themeRoot, 'Index/Header.html'), 'utf8');
    assert.match(header, /preg_match\('#\^\(\?:https\?:\/\//);
    assert.match(header, /\(\?!\[A-Za-z\]\[A-Za-z0-9\+\.\-\]\*:\)/);
    assert.match(header, /\(\?!\/\/\)/);
    assert.match(header, /value="_self"/);
    assert.match(header, /\$nav\.target == '_blank'/);
    assert.match(header, /rel="noopener noreferrer"/);
    assert.doesNotMatch(header, /target="#\{\$nav\.target/);
});
