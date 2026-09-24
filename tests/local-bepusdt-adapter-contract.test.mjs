import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const adapter = path.join(root, 'payment-adapters/PikaBEpusdtAdapter');
const read = relative => fs.readFileSync(path.join(adapter, relative), 'utf8');
const readRoot = relative => fs.readFileSync(path.join(root, relative), 'utf8');

function filesBelow(directory) {
    return fs.readdirSync(directory, {withFileTypes: true}).flatMap(entry => {
        const file = path.join(directory, entry.name);
        return entry.isDirectory() ? filesBelow(file) : [file];
    });
}

test('declares one independently installable payment adapter', () => {
    const manifest = JSON.parse(read('payment-adapter.json'));
    assert.equal(manifest.schema, 1);
    assert.equal(manifest.id, 'PikaBEpusdtAdapter');
    assert.equal(manifest.type, 'payment-adapter');
    assert.equal(manifest.version, '0.1.2');
    assert.equal(manifest.target, 'app/Pay/PikaBEpusdtAdapter');

    for (const file of [
        'Config/Config.php', 'Config/Info.php', 'Config/Submit.php',
        'Impl/Pay.php', 'Impl/Signature.php', 'Support/Amount.php',
        'Support/Gateway.php', 'Support/OrderId.php', 'Support/SecretStore.php',
        'Support/Settings.php', 'Support/UrlPolicy.php',
    ]) {
        assert.equal(fs.existsSync(path.join(adapter, file)), true, `missing ${file}`);
    }
});

test('keeps secrets and site identity out of Acg-Faka payment profiles', () => {
    const config = read('Config/Config.php');
    const submit = read('Config/Submit.php');
    const combined = config + submit;
    assert.match(combined, /gateway_origin/);
    assert.match(combined, /checkout_origin/);
    assert.match(combined, /merchant_origin/);
    assert.match(combined, /fiat/);
    assert.doesNotMatch(combined, /api_token|namespace|password|secret/i);

    const secret = read('Support/SecretStore.php');
    assert.match(secret, /hash\('sha256', \$canonical\)/);
    assert.match(secret, /\/var\/lib\/pika-local-extensions/);
    assert.match(secret, /bepusdt-token/);
    assert.match(secret, /bepusdt-namespace/);
    assert.match(secret, /0170000\) !== 0100000/);
    assert.match(secret, /0777\) !== 0640/);
    assert.match(secret, /\['nlink'\] !== 1/);
    assert.match(secret, /\['uid'\] !== 0/);
    assert.match(secret, /\{16,256\}/);
    assert.match(secret, /dirname\(\$directory\), 0, 0, 0755/);
});

test('uses only official Acg-Faka payment interfaces and an original MIT implementation', () => {
    const php = filesBelow(adapter)
        .filter(file => file.endsWith('.php'))
        .map(file => fs.readFileSync(file, 'utf8'))
        .join('\n');
    assert.doesNotMatch(php, /App\\Util\\SiteIdentity|PaymentCallbackAmount|PaymentTokenUnavailableException/);
    assert.doesNotMatch(php, /\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.shop\b/i);
    assert.doesNotMatch(php, /V03413\\BepusdtPhpSdk|Vendor\/autoload|composer require/);
    assert.match(php, /extends Base implements \\App\\Pay\\Pay/);
    assert.match(php, /implements \\App\\Pay\\Signature/);
    assert.match(php, /new PayEntity\(\)/);
    assert.match(read('README.md'), /clean-room implementation/);
});

test('pins loopback-only API access and a canonical HTTPS checkout origin', () => {
    const policy = read('Support/UrlPolicy.php');
    const gateway = read('Support/Gateway.php');
    const settings = read('Support/Settings.php');
    const pay = read('Impl/Pay.php');
    assert.match(policy, /http:\/\/127\\\.0\\\.0\\\.1/);
    assert.match(policy, /http:\/\/\\\[::1\\\]/);
    assert.match(policy, /str_starts_with\(\$origin, 'https:\/\/'\)/);
    assert.match(policy, /FILTER_VALIDATE_IP/);
    assert.match(policy, /assertCallbackUrl/);
    assert.match(policy, /assertReturnUrl/);
    assert.match(policy, /rechargeNotification/);
    assert.match(policy, /user\/recharge\/index/);
    assert.match(pay, /\$flow\s*=\s*UrlPolicy::assertCallbackUrl/);
    assert.match(pay, /\$flow,\s*\)/);
    assert.match(policy, /hash_equals\(\$trusted, \$actualOrigin\)/);
    assert.match(settings, /hash_equals\(\$merchantOrigin, \$checkoutOrigin\)/);
    assert.match(gateway, /'allow_redirects'\s*=>\s*false/);
    assert.match(gateway, /'http_errors'\s*=>\s*false/);
    assert.match(gateway, /'proxy'\s*=>\s*''/);
    assert.match(gateway, /'connect_timeout'\s*=>\s*2/);
    assert.match(gateway, /'timeout'\s*=>\s*10/);
    assert.match(gateway, /MAX_BODY\s*=\s*65536/);
});

test('accepts only paid callbacks, then unwraps the signed upstream order ID', () => {
    const info = read('Config/Info.php');
    const order = read('Support/OrderId.php');
    const signature = read('Impl/Signature.php');
    assert.match(order, /PREFIX\s*=\s*'pka1_'/);
    assert.match(order, /\^\[0-9\]\{18\}\$/);
    assert.match(order, /\^TEST_/);
    assert.match(order, /\^\[a-z0-9\]\{4,12\}\$/);
    const signAt = signature.indexOf('hash_equals($expected, $provided)');
    const statusAt = signature.indexOf("$status === 2");
    const unwrapAt = signature.indexOf('OrderId::unwrap');
    const contextAt = signature.indexOf('Context::set');
    assert.ok(signAt >= 0 && statusAt > signAt && unwrapAt > statusAt && contextAt > unwrapAt);
    assert.match(signature, /PayConsts::DAFA/);
    assert.match(info, /FIELD_STATUS_VALUE\s*=>\s*2/);
    assert.match(info, /FIELD_RESPONSE\s*=>\s*'ok'/);
});

test('dedicated BE callbacks fail closed and reuse native payment services', () => {
    const controller = readRoot('manager/site/app/Controller/User/Api/PikaBEpusdt.php');
    assert.match(controller, /final class PikaBEpusdt/);
    assert.doesNotMatch(controller, /extends .*Order|orderSuccess\(|Bill::|->save\(|->update\(|DB::transaction\(/);
    assert.match(controller, /CallbackIpWhitelist::enforce\(\)/);
    assert.match(controller, /callbackInitialize\(/);
    assert.match(controller, /PikaBEpusdtAdapter/);
    assert.match(controller, /transactionLevel\(\)/);
    assert.match(controller, /inTransaction\(\)/);
    assert.match(controller, /catch \(\\Throwable\)/);
    assert.match(controller, /http_response_code\(500\)/);
    assert.match(controller, /exit\(\$body\)/);
    assert.doesNotMatch(controller, /getMessage\(|str_contains\(|ob_start|register_shutdown_function/);
    assert.match(read('Impl/Pay.php'), /'notify_url'\s*=>\s*UrlPolicy::notifyUrl\(/);
    assert.match(read('Support/UrlPolicy.php'), /\/user\/api\/pikaBEpusdt\//);
});

test('validates every identity field and rebuilds the checkout URL from trusted configuration', () => {
    const pay = read('Impl/Pay.php');
    const policy = read('Support/UrlPolicy.php');
    for (const field of ['order_id', 'amount', 'fiat', 'status', 'trade_type', 'trade_id', 'payment_url']) {
        assert.match(pay, new RegExp(field));
    }
    assert.match(pay, /hash_equals\(\(string\)\(\$request\['order_id'\]/);
    assert.match(pay, /hash_equals\(\$expectedAmount, \$amount\)/);
    assert.match(pay, /UrlPolicy::paymentPath/);
    assert.match(pay, /\$settings->checkoutOrigin \. \$path/);
    assert.match(policy, /\/pay\/checkout\//);
    assert.match(policy, /\/pay\/checkout-counter\//);
    assert.match(pay, /json_decode\(\$raw, true, 32, JSON_THROW_ON_ERROR\)/);
});

test('publishes only the exact buyer checkout surface in the Nginx examples', () => {
    const http = readRoot('packaging/nginx/pika-bepusdt-checkout-http.conf.example');
    const server = readRoot('packaging/nginx/pika-bepusdt-checkout-server.conf.example');
    const reject = readRoot('packaging/nginx/pika-bepusdt-default-reject.conf.example');
    assert.match(http, /limit_req_zone \$binary_remote_addr/);
    assert.match(http, /limit_conn_zone \$binary_remote_addr/);
    assert.match(server, /^location ~ "\^\/pay\/.*\$" \{$/m);
    assert.doesNotMatch(server, /^location ~ \^\/pay\//m);
    assert.match(server, /\/pay\/(?:\(\?:)?checkout\//);
    assert.match(server, /checkout-counter/);
    assert.match(server, /location = \/api\/v1\/pay\/info/);
    assert.doesNotMatch(server, /methods|update-order/);
    assert.match(server, /\/checkout\/official\/assets\//);
    assert.doesNotMatch(server, /\/checkout\/langge\/assets\//);
    assert.match(server, /\/payment\/assets\//);
    assert.match(server, /proxy_set_header Cookie ""/);
    assert.match(server, /proxy_set_header Authorization ""/);
    assert.match(server, /proxy_hide_header Set-Cookie/);
    assert.equal((server.match(/Strict-Transport-Security/g) ?? []).length, 4);
    assert.equal((server.match(/X-Content-Type-Options/g) ?? []).length, 4);
    assert.doesNotMatch(server, /\/api\/v1\/order|\/api\/v1\/admin|\/secure\/|\/admin\//);
    assert.doesNotMatch(server, /proxy_pass\s+https?:\/\/(?!127\.0\.0\.1:__BEPUSDT_LOOPBACK_PORT__)/);
    assert.match(reject, /listen 80 default_server/);
    assert.match(reject, /listen 443 ssl default_server/);
    assert.match(reject, /ssl_reject_handshake on/);
});

test('renders a checkout configuration accepted by Nginx', t => {
    const probe = spawnSync('docker', ['version'], {encoding: 'utf8'});
    if (probe.error?.code === 'ENOENT') {
        t.skip('Docker is unavailable on this host');
        return;
    }
    assert.equal(probe.status, 0, probe.stderr);

    const workRoot = path.join(root, 'tests/.work');
    fs.mkdirSync(workRoot, {recursive: true});
    const directory = fs.mkdtempSync(path.join(workRoot, 'nginx-'));
    try {
        const http = readRoot('packaging/nginx/pika-bepusdt-checkout-http.conf.example');
        const server = readRoot('packaging/nginx/pika-bepusdt-checkout-server.conf.example')
            .replaceAll('__BEPUSDT_LOOPBACK_PORT__', '8080');
        const config = `events {}\nhttp {\n${http}\nserver {\nlisten 8081;\nserver_name catalog.example;\n${server}\nlocation / { return 204; }\n}\n}\n`;
        fs.writeFileSync(path.join(directory, 'nginx.conf'), config, {mode: 0o600});

        const result = spawnSync('docker', [
            'run', '--rm',
            '-v', `${directory}:/pika-nginx:ro`,
            'nginx:1.27-alpine',
            'nginx', '-t', '-c', '/pika-nginx/nginx.conf',
        ], {encoding: 'utf8', timeout: 120000});
        assert.equal(result.status, 0, result.stderr || result.stdout);
        assert.match(result.stderr, /syntax is ok/);
        assert.match(result.stderr, /test is successful/);
    } finally {
        fs.rmSync(directory, {recursive: true, force: true});
    }
});

test('passes the isolated PHP behavior suite', t => {
    const probe = spawnSync('docker', ['version'], {encoding: 'utf8'});
    if (probe.error?.code === 'ENOENT') {
        t.skip('Docker is unavailable on this host');
        return;
    }
    assert.equal(probe.status, 0, probe.stderr);
    const result = spawnSync('docker', [
        'run', '--rm',
        '-e', 'PIKA_BEPUSDT_TEST_CONTAINER=1',
        '-v', `${root}:/release:ro`,
        'php:8.3-cli',
        'php', '/release/tests/local-bepusdt-adapter-behavior.php',
    ], {encoding: 'utf8', timeout: 120000});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /PASS local BEpusdt adapter behavior/);
});
