import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import fs from 'node:fs';
import {readFile, readdir} from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const manager = path.join(root, 'manager', 'site');

const source = async relative => readFile(path.join(manager, relative), 'utf8');

test('manager payload only adds canonical site-relative files', async () => {
    const top = (await readdir(path.join(root, 'manager'))).sort();
    assert.deepEqual(top, ['site']);
    const bootstrap = await source('local-extensions/bootstrap.php');
    assert.match(bootstrap, /Runtime::boot\(\)/);
    assert.doesNotMatch(bootstrap, /kernel\/Plugin\.php|_plugin_/);
});

test('registry binds canonical manifests to sha256 and never scans arbitrary directories', async () => {
    const registry = await source('local-extensions/src/Registry.php');
    assert.match(registry, /manifest_sha256/);
    assert.match(registry, /hash_file\('sha256'/);
    assert.match(registry, /extensions\/.*local-extension\.json/);
    assert.match(registry, /app\/View\/User\/Theme\/.*theme\.json/);
    assert.doesNotMatch(registry, /scandir|glob\s*\(/);
});

test('dispatcher keeps official result first and implements required hook result semantics', async () => {
    const dispatcher = await source('local-extensions/src/Dispatcher.php');
    assert.match(dispatcher, /mixed &\.\.\.\$args/);
    assert.match(dispatcher, /\$officialResult instanceof Stock \|\| is_bool\(\$officialResult\)/);
    assert.match(dispatcher, /call_user_func_array\(\[\$instance, \$callback\['method'\]\], \$args\)/);
    assert.match(dispatcher, /is_string\(\$next\)/);
    assert.match(dispatcher, /is_array\(\$current\)/);
    assert.match(dispatcher, /\$officialResult !== '' && \$officialResult !== \[\]/);
    assert.match(dispatcher, /AdminMenu::class/);
});

test('admin endpoints are session protected, POST and CSRF guarded, and owner only', async () => {
    const view = await source('app/Controller/Admin/LocalExtensions.php');
    const api = await source('app/Controller/Admin/Api/LocalExtensions.php');
    const guard = await source('local-extensions/src/RequestGuard.php');
    const indexTemplate = await source('app/View/Admin/LocalExtensions/Index.html');
    const catalogTemplate = await source('app/View/Admin/LocalExtensions/CatalogHub.html');
    assert.match(view, /use App\\Controller\\Base\\View\\Manage;/);
    assert.match(view, /final class LocalExtensions extends Manage/);
    assert.doesNotMatch(view, /function\s+render\s*\(/);
    assert.match(indexTemplate, /data-csrf="#\{\$local_extensions_csrf\|escape:'html'\}"/);
    assert.match(catalogTemplate, /data-csrf="#\{\$catalog_hub_csrf\|escape:'html'\}"/);
    assert.match(view, /#\[Interceptor\(ManageSession::class\)\]/);
    assert.match(api, /#\[Interceptor\(\[ManageSession::class\], Interceptor::TYPE_API\)\]/);
    assert.equal((api.match(/RequestGuard::mutation/g) || []).length, 14);
    const renewal = api.split('public function catalogHubRefreshCsrf(')[1]?.split('public function ')[0] || '';
    assert.match(renewal, /RequestGuard::csrfRenewal/);
    assert.match(renewal, /Csrf::renew/);
    assert.match(renewal, /Cache-Control: no-store/);
    assert.doesNotMatch(renewal, /catalogHubService|ManageLog|StateStore|ConfigStore/);
    for (const endpoint of ['catalogHubCategoryInfo', 'catalogHubCategoryRename']) {
        const body = api.split(`public function ${endpoint}(`)[1]?.split('public function ')[0] || '';
        assert.match(body, /RequestGuard::mutation/);
        assert.match(body, /positiveInteger\(\$request->unsafePost\('category_id'\)/);
    }
    assert.match(guard, /method\(\) !== 'POST'/);
    assert.match(guard, /Csrf::verify/);
    assert.match(guard, /manage->type/);
});

test('admin API failures stay JSON-safe and the browser rejects non-JSON error pages', async () => {
    const api = await source('app/Controller/Admin/Api/LocalExtensions.php');
    const js = await source('assets/admin/controller/local-extensions/index.js');
    assert.equal((api.match(/catch \(JSONException \$exception\)/g) || []).length, 15);
    assert.equal((api.match(/catch \(\\Throwable\)/g) || []).length, 15);
    assert.match(api, /本地扩展列表读取失败，请联系管理员检查安装状态/);
    assert.match(api, /本地扩展状态更新失败，请检查扩展安装与运行状态/);
    assert.match(api, /本地扩展配置保存失败，请检查输入或安装状态/);
    assert.match(js, /headers\.get\('content-type'\)/);
    assert.match(js, /contentType\.includes\('application\/json'\)/);
    assert.match(js, /try\s*\{\s*payload = await response\.json\(\)/s);
    assert.doesNotMatch(js, /await response\.text\(\)/);
});

test('admin API converts internal failures without leaking them and accepts an empty settings object', t => {
    const probe = spawnSync('docker', ['version'], {encoding: 'utf8'});
    if (probe.error?.code === 'ENOENT') {
        t.skip('Docker is unavailable on this host');
        return;
    }
    const result = spawnSync('docker', [
        'run', '--rm', '-v', `${root}:/release:ro`, '-w', '/release',
        'php:8.3-cli', 'php', 'tests/local-manager-api-behavior.php',
    ], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /local-manager-api-behavior: PASS/);
});

test('CSRF renewal preserves mutation lifetime, login binding and strict same-origin checks', () => {
    const result = spawnSync('docker', [
        'run', '--rm', '--pull=never', '--network', 'none', '--read-only',
        '--tmpfs', '/tmp:rw,noexec,nosuid,size=16m', '--user', '65534:65534',
        '-v', `${root}:/release:ro`, '-w', '/release',
        'php:8.3-cli', 'php', 'tests/local-manager-csrf-behavior.php',
    ], {encoding: 'utf8', timeout: 30000});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /local-manager-csrf-behavior: PASS/);
});

test('web manager has no install, archive, remote fetch, command or service execution surface', async () => {
    const files = [
        'app/Controller/Admin/Api/LocalExtensions.php',
        'local-extensions/src/ManagerService.php',
        'local-extensions/src/Registry.php',
        'local-extensions/src/StateStore.php',
        'local-extensions/src/ConfigStore.php',
        'local-extensions/src/SupplySyncStatus.php',
    ];
    const combined = (await Promise.all(files.map(source))).join('\n');
    const executableSurface = /ZipArchive|\bcurl_[A-Za-z0-9_]+\s*\(|https?:\/\/|systemctl|proc_open|shell_exec|passthru|\bexec\s*\(|\bsystem\s*\(/i;
    assert.match('curl_exec($handle)', executableSurface);
    assert.match('curl_init()', executableSurface);
    assert.doesNotMatch("'curl_code' => 23", executableSurface, 'bounded diagnostic data is not a network call');
    assert.doesNotMatch(combined, executableSurface);
});

test('settings are schema constrained, atomically stored, and passwords are never returned', async () => {
    const validator = await source('local-extensions/src/ManifestValidator.php');
    const config = await source('local-extensions/src/ConfigStore.php');
    const atomic = await source('local-extensions/src/AtomicJson.php');
    assert.match(validator, /\['text', 'password', 'number', 'select', 'checkbox'\]/);
    assert.match(validator, /\^\[a-z\]\[a-z0-9_\]\{0,63\}\$/);
    assert.match(config, /\$values\[\$setting\['key'\]\] = ''/);
    assert.match(config, /password_configured/);
    assert.match(atomic, /flock\(\$lock, LOCK_EX\)/);
    assert.match(atomic, /fsync/);
    assert.match(atomic, /rename\(\$temp, \$path\)/);
    assert.match(atomic, /chmod\(\$temp, 0600\)/);
    assert.match(atomic, /@fopen\(\$path, 'r\+b'\)/);
    assert.match(atomic, /@fopen\(\$path, 'x\+b'\)/);
    assert.match(atomic, /umask\(0o177\)/);
    assert.match(atomic, /finally[\s\S]+umask\(\$previousUmask\)/);
    assert.match(atomic, /assertSafeExistingLockPath\(\$path, \$pathMetadata\)/);
    assert.ok((atomic.match(/assertSensitiveHandle\(\$lock, \$lockPath, 'lock'\)/g) ?? []).length >= 2);
    assert.match(atomic, /fstat\(\$handle\)/);
    assert.match(atomic, /\$metadata\['uid'\] !== PathGuard::runtimeOwner\(\)/);
    assert.match(atomic, /\(\$metadata\['mode'\] & 0o777\) !== 0o600/);
    assert.match(atomic, /\$metadata\['nlink'\] !== 1/);
    assert.match(atomic, /\$metadata\['dev'\] !== \$pathMetadata\['dev'\]/);
    assert.doesNotMatch(atomic, /fopen\(\$lockPath, ['"]c\+b['"]\)/);
    assert.doesNotMatch(atomic, /chmod\(\$lockPath/);
    assert.doesNotMatch(atomic, /fchmod\(/);
    assert.doesNotMatch(atomic, /@chmod/);
});

test('all private state uses the canonical external state root', async () => {
    const guard = await source('local-extensions/src/PathGuard.php');
    const csrf = await source('local-extensions/src/Csrf.php');
    const config = await source('local-extensions/src/ConfigStore.php');
    const state = await source('local-extensions/src/StateStore.php');
    const atomic = await source('local-extensions/src/AtomicJson.php');
    assert.match(guard, /STATE_BASE = '\/var\/lib\/pika-local-extensions\/sites'/);
    assert.match(guard, /hash\('sha256', self::siteRoot\(\)\)/);
    assert.match(guard, /\$realBase !== \$base/);
    assert.match(guard, /\$siteDirectory.*'runtime'/s);
    assert.doesNotMatch(guard, /PIKA_LOCAL_EXTENSIONS_TEST|runtimeSapi/);
    assert.match(guard, /fileowner\(\$path\) !== 0/);
    assert.match(guard, /filegroup\(\$path\) !== 0/);
    assert.match(guard, /\(\$permissions & 0o777\) !== \$mode/);
    assert.match(guard, /\$owner === 0/);
    assert.match(guard, /!is_writable\(\$realRuntime\)/);
    assert.match(guard, /in_array\('\.', \$parts, true\)/);
    assert.match(csrf, /PathGuard::stateRoot\(\).*csrf\.key/);
    assert.match(config, /PathGuard::stateDirectory\('config'\)/);
    assert.match(state, /PathGuard::stateRoot\(\).*state\.json/);
    assert.match(atomic, /PathGuard::stateDirectory/);
    assert.doesNotMatch([csrf, config, state, atomic].join('\n'), /siteRoot\(\).*runtime\/local-extensions/);
});

test('runtime initializer uses only the fixed production state root in isolated Docker', t => {
    const script = path.join(root, 'scripts', 'init-runtime.php');
    const scriptSource = fs.readFileSync(script, 'utf8');
    assert.match(scriptSource, /PIKA_STATE_BASE = '\/var\/lib\/pika-local-extensions\/sites'/);
    assert.match(scriptSource, /is_link\(\$path\)/);
    assert.match(scriptSource, /realpath\(\$path\) !== \$path/);
    assert.match(scriptSource, /!noExtendedAcl\(\$path\)/);
    assert.doesNotMatch(scriptSource, /runtime-dir|PIKA_LOCAL_EXTENSIONS_TEST|TEST_STATE_BASE/);
    const probe = spawnSync('docker', ['version'], {encoding: 'utf8'});
    if (probe.error?.code === 'ENOENT') {
        t.skip('Docker is unavailable on this host');
        return;
    }
    const result = spawnSync('docker', [
        'run', '--rm', '-v', `${root}:/release:ro`, 'php:8.2-cli', 'sh', '-lc',
        `set -eu
         mkdir -p /site
         umask 0077
         php /release/scripts/init-runtime.php --site-root /site --web-uid 1001 --web-gid 1001
         digest="$(printf %s /site | sha256sum | awk '{print $1}')"
         state="/var/lib/pika-local-extensions/sites/$digest"
         test "$(stat -c '%u:%g:%a' /var/lib/pika-local-extensions)" = '0:0:755'
         test "$(stat -c '%u:%g:%a' /var/lib/pika-local-extensions/sites)" = '0:0:755'
         test "$(stat -c '%u:%g:%a' "$state")" = '0:0:755'
         test "$(stat -c '%u:%g:%a' "$state/runtime")" = '1001:1001:750'
         test "$(stat -c '%u:%g:%a' "$state/runtime/config")" = '1001:1001:750'
         test "$(stat -c '%u:%g:%a' "$state/runtime/csrf.key")" = '1001:1001:600'
         if PIKA_LOCAL_EXTENSIONS_TESTING=1 PIKA_LOCAL_EXTENSIONS_TEST_STATE_BASE=/tmp/ignored php /release/scripts/init-runtime.php --site-root /site --web-uid 1001 --web-gid 1001 >/tmp/repeat.out 2>/tmp/repeat.err; then exit 21; fi
         grep -q 'state directory already exists or cannot be created' /tmp/repeat.err
         chmod 0775 "$state"
         if php /release/scripts/init-runtime.php --site-root /site --web-uid 1001 --web-gid 1001 >/tmp/unsafe-existing.out 2>/tmp/unsafe-existing.err; then exit 22; fi
         grep -q 'state directory already exists or cannot be created' /tmp/unsafe-existing.err
         test "$(stat -c '%a' "$state")" = '775'
         chmod 0755 "$state"
         mkdir -p /site-gid-zero
         if php /release/scripts/init-runtime.php --site-root /site-gid-zero --web-uid 1001 --web-gid 0 >/tmp/gid-zero.out 2>/tmp/gid-zero.err; then exit 23; fi
         grep -q 'web GID is invalid' /tmp/gid-zero.err
         echo INIT_RUNTIME_DOCKER_PASS`,
    ], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /INIT_RUNTIME_DOCKER_PASS/);
});

test('runtime initializer precisely rolls back newly-created control directories', t => {
    const probe = spawnSync('docker', ['version'], {encoding: 'utf8'});
    if (probe.error?.code === 'ENOENT') {
        t.skip('Docker is unavailable on this host');
        return;
    }
    for (const precreateControlRoot of [false, true]) {
        const setup = precreateControlRoot
            ? 'mkdir -m 0755 /var/lib/pika-local-extensions'
            : ':';
        const retained = precreateControlRoot
            ? `test "$(stat -c '%u:%g:%a' /var/lib/pika-local-extensions)" = '0:0:755'`
            : 'test ! -e /var/lib/pika-local-extensions';
        const result = spawnSync('docker', [
            'run', '--rm', '-v', `${root}:/release:ro`,
            '--tmpfs', '/var/lib:rw,size=1m,nr_inodes=3',
            'php:8.2-cli', 'sh', '-lc',
            `set -eu
             mkdir -p /site
             umask 0077
             ${setup}
             if php /release/scripts/init-runtime.php --site-root /site --web-uid 1001 --web-gid 1001 >/tmp/init.out 2>/tmp/init.err; then exit 31; fi
             grep -q 'state directory already exists or cannot be created' /tmp/init.err
             test ! -e /var/lib/pika-local-extensions/sites
             ${retained}
             echo INIT_RUNTIME_CONTROL_ROLLBACK_PASS`,
        ], {encoding: 'utf8'});
        assert.equal(result.status, 0, result.stderr || result.stdout);
        assert.match(result.stdout, /INIT_RUNTIME_CONTROL_ROLLBACK_PASS/);
    }
});

test('runtime initializer routes thrown exceptions through exact cleanup', t => {
    const probe = spawnSync('docker', ['version'], {encoding: 'utf8'});
    if (probe.error?.code === 'ENOENT') {
        t.skip('Docker is unavailable on this host');
        return;
    }
    for (const precreateControlRoot of [false, true]) {
        const setup = precreateControlRoot
            ? 'mkdir -m 0755 /var/lib/pika-local-extensions'
            : ':';
        const retained = precreateControlRoot
            ? `test "$(stat -c '%u:%g:%a' /var/lib/pika-local-extensions)" = '0:0:755'`
            : 'test ! -e /var/lib/pika-local-extensions';
        const result = spawnSync('docker', [
            'run', '--rm', '-v', `${root}:/release:ro`, 'php:8.2-cli', 'sh', '-lc',
            `set -eu
             mkdir -p /site
             umask 0077
             ${setup}
             if php -d disable_functions=random_bytes /release/scripts/init-runtime.php --site-root /site --web-uid 1001 --web-gid 1001 >/tmp/init.out 2>/tmp/init.err; then exit 41; fi
             grep -q 'runtime initialization raised an exception' /tmp/init.err
             if grep -q 'RUNTIME_INIT_ROLLBACK_INCOMPLETE' /tmp/init.err; then exit 42; fi
             test ! -e /var/lib/pika-local-extensions/sites
             ${retained}
             echo INIT_RUNTIME_EXCEPTION_ROLLBACK_PASS`,
        ], {encoding: 'utf8'});
        assert.equal(result.status, 0, result.stderr || result.stdout);
        assert.match(result.stdout, /INIT_RUNTIME_EXCEPTION_ROLLBACK_PASS/);
    }
});

test('admin UI renders manifest data as text and offers no installer input', async () => {
    const js = await source('assets/admin/controller/local-extensions/index.js');
    const html = await source('app/View/Admin/LocalExtensions/Index.html');
    assert.match(js, /\.textContent = String\(text\)/);
    assert.doesNotMatch(js, /innerHTML|eval\s*\(|new Function/);
    assert.doesNotMatch(js, /type\s*=\s*['"]file|https?:\/\/|\.zip|systemd|systemctl/i);
    assert.match(html, /不提供在线安装、URL、ZIP 或系统服务操作/);
    assert.match(html, /ready\("\/assets\/admin\/controller\/local-extensions\/index\.js\?rev=20260925-supply118"\)/);
});

test('catalog hub is an owner-only queued workflow without install or system-service controls', async () => {
    const view = await source('app/Controller/Admin/LocalExtensions.php');
    const api = await source('app/Controller/Admin/Api/LocalExtensions.php');
    const html = await source('app/View/Admin/LocalExtensions/CatalogHub.html');
    const js = await source('assets/admin/controller/local-extensions/catalog-hub.js');
    assert.match(view, /function catalogHub\(\)/);
    assert.match(view, /manage->type !== 0/);
    assert.match(api, /function catalogHubBootstrap/);
    assert.match(api, /function catalogHubSave/);
    assert.match(api, /function catalogHubPreview/);
    assert.match(api, /function catalogHubAnalyze/);
    assert.match(api, /function catalogHubConnect/);
    assert.match(api, /function catalogHubSourceUpdate/);
    assert.doesNotMatch(api, /function catalogHubSourceDelete/);
    assert.match(api, /function catalogHubTasks/);
    assert.match(api, /function catalogHubConfirm/);
    assert.match(api, /function catalogHubTaskControl/);
    assert.match(api, /\^\[1-9\]\\d\{0,9\}\$\/D/);
    assert.match(html, /唯一推荐流程：选择首次入库分类方式与本次新商品加价/);
    assert.match(html, /确认冻结分类与加价 → 后台分批入库/);
    assert.match(html, /不要再到异次元原生店铺共享点击“接入货源”/);
    assert.match(html, /catalog-hub\.js\?rev=20260917-mirror1/);
    assert.doesNotMatch(js, /catalogHubSave|catalogHubPreview|保存字面规则|只读预览/);
    assert.match(js, /\.textContent = String\(text\)/);
    assert.match(js, /\/admin\/api\/localExtensions\/catalogHubConnect/);
    assert.match(js, /catalogHubSourceUpdate/);
    assert.match(js, /href = '\/admin\/store\/index'/);
    assert.match(js, /data.*nativeSourceManagement|dataset\.nativeSourceManagement/);
    assert.match(js, /填写货源方提供的连接信息，先测试，再确认分类与加价。/);
    assert.match(js, /按上游说明选择版本；不确定先询问货源方。/);
    assert.doesNotMatch(js, /资料先由 Pika|新货源只在这里添加|不要再到异次元原生店铺共享点击/);
    assert.match(js, /不要在那里再次执行商品入库。/);
    assert.match(js, /仅管理，不入库/);
    assert.doesNotMatch(js, /catalogHubSourceDelete/);
    assert.match(js, /appKey\.required = false/);
    assert.doesNotMatch(js, /confirmation: 'REMOVE'/);
    assert.doesNotMatch(js, /\/admin\/api\/store\/save|officialPost/);
    assert.match(js, /catalogHubAnalyze/);
    assert.match(js, /catalogHubConfirm/);
    assert.match(js, /catalogHubTaskControl/);
    assert.doesNotMatch(js, /innerHTML|eval\s*\(|new Function|setInterval/);
    assert.doesNotMatch([api, html, js].join('\n'), /systemctl|proc_open|shell_exec|passthru|\bexec\s*\(|\bsystem\s*\(/i);
    assert.doesNotMatch(html + js, /删除货源|回滚已入库商品|在线安装/);
});
