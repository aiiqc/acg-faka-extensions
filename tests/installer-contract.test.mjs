import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import {
  existsSync,
  lstatSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  readdirSync,
  rmSync,
  statSync,
  symlinkSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import test from 'node:test';

const root = resolve(import.meta.dirname, '..');
const read = path => readFileSync(join(root, path), 'utf8');

test('compatibility gate pins four official commits, six core inputs and 18 payment boundaries', () => {
  const manifest = JSON.parse(read('compatibility.json'));
  assert.equal(manifest.schema, 1);
  assert.deepEqual(manifest.acg_faka.map(target => target.version), ['3.6.4', '3.7.0', '3.7.5', '3.7.9']);
  const expectedFiles = [
    'app/Controller/Admin/Api/Config.php',
    'app/View/Admin/Footer.html',
    'assets/common/js/editor/markdown/editorv2.js',
    'config/app.php',
    'kernel/Helper.php',
    'kernel/Kernel.php',
  ];
  const sharedPaymentFiles = {
    'app/Consts/Pay.php': 'c9037212b14b612edd458ebcf1de76dcab5f3d54156b8818f790fe5392f35cf2',
    'app/Controller/User/Api/Order.php': '1a636b3bd1c9d6b984444a24cac5fae773b3dbb6aabeedb0ad62cd16c47c381f',
    'app/Controller/User/Api/Recharge.php': 'e716fb9ac03d8f9f96f9beb04d8f7704cefdc9b50f2740d42905d8cbe6eae67e',
    'app/Controller/User/Api/RechargeNotification.php': 'c08bd7d2b535527dc0c9fa6e003703cc0e44e7ca1efae991208a06f94b05c057',
    'app/Controller/User/Index.php': '18be030be49bebe46cfb7185d3dee4720f324a2f86425a2253b1044a9acfe5c3',
    'app/Controller/User/Personal.php': 'c0410c7aa26b2866520e696df1d11f38860fb0c840596a0d2125c2c041b7321b',
    'app/Controller/User/Recharge.php': '1386f2cf45817b3f9a364165795f518ade60bff816a0f64cd5b2853cafbc2beb',
    'app/Entity/PayEntity.php': '8034ac26903f213607835d4f8fb0cd4fb3c0d3a54c5477d11a804ba893cba49a',
    'app/Pay/Base.php': '290507b84dc529950ad99e74ded13cc3c86baee8e7dda9c7e23474f7a3f464f5',
    'app/Pay/Pay.php': '424e1c304b7a84ba27e0f5579426cb085517686268f529d30f159e8c90ed47ca',
    'app/Pay/Signature.php': 'aa4792cd07184a82366040900b19b871f88828197bc9aaa23f1303b47432f47a',
    'app/Service/Bind/Recharge.php': '8d804282e80a93345c1af3724caf959553f3776de3d5e46913ba347d8c6505be',
    'app/Service/Order.php': '90d8ebb41bbe592c2ddd0cdf241febef68c0df16d5b6f240ed048fd114282fa3',
    'app/Service/Recharge.php': '3a6f42fd64b22a4be545c713469190f4781dcf51631b0fdfe1e39f42ea4b0324',
    'app/Util/PayConfig.php': '2dc85e0fe1fb3bbd187f6e0a9dc9da92959981805fda868545f2e4c513a9fe67',
    'app/Util/PayFactory.php': '3fca5e1b21a5ea5e0a916d69c7f0e567552f1549dcaf1a50669e53ff51bd1710',
    'app/Util/PayProfile.php': '582b79d2872c0895f6880d6ebebdc513216231ba7bb8cfaba4d1ebcf8909674f',
  };
  const expectedPaymentFiles = [
    ...Object.keys(sharedPaymentFiles),
    'app/Service/Bind/Order.php',
  ].sort();
  const expected = {
    '3.6.4': {
      commit: '4ff6ba9b28af8e529fc54f643eea5f8bf0bb02d6',
      files: {
        'config/app.php': '16ff24b14d58b460038969dd8f2736debf8d8a3607c473d0a625002551d11d81',
        'kernel/Kernel.php': '442901f0d6c714fbfb739bf516da7708c788e985b84040fb28de0cd1e34eddf4',
        'kernel/Helper.php': '261d59c2a962f3ac12cf1d5f7c6ce53ce279751acd4ad7ca7196ec7ec3f8a732',
        'app/Controller/Admin/Api/Config.php': 'ec2f7530dd13cb15d72a8e9100b1371272be86967b420e6a80c66a0129b043bd',
        'app/View/Admin/Footer.html': '0449214c5b81a165a221c5a627f6d06198be7170221a9d88ad2d62150f63ee82',
        'assets/common/js/editor/markdown/editorv2.js': '097a1e66f36740ba2f58f504134275e188e03cfed76e833ca62515e8563625a3',
      },
      paymentFiles: {
        ...sharedPaymentFiles,
        'app/Service/Bind/Order.php': '528de4613a39f23042c3e08fd4545be68a0cdc087b98f715fbdd7038a82cef09',
      },
    },
    '3.7.0': {
      commit: '6e48aab25f08f4d1fc0dda6af84fa6852fbb37e4',
      files: {
        'config/app.php': 'e876e7e44e3e169d57da70eebc94852b3793639856d76de111d077e54c32e1b2',
        'kernel/Kernel.php': 'edd31ae68488dff37c757bc94fa21b7775e23cf5af4e23f06fd60e6957e4a830',
        'kernel/Helper.php': '261d59c2a962f3ac12cf1d5f7c6ce53ce279751acd4ad7ca7196ec7ec3f8a732',
        'app/Controller/Admin/Api/Config.php': '127af02d39bbcd85c0e8fc26bccfd8edb9211b4b748af62d733af89ce23a7baf',
        'app/View/Admin/Footer.html': '0449214c5b81a165a221c5a627f6d06198be7170221a9d88ad2d62150f63ee82',
        'assets/common/js/editor/markdown/editorv2.js': '164ef1e5cd7e6c42fc530fafce774c4211bd74fa92fe2ad1b032dcc7f72507de',
      },
      paymentFiles: {
        ...sharedPaymentFiles,
        'app/Service/Bind/Order.php': '92c0b6638578686807fb32b99efb69384077eb20ec8bc32578730e0ec809cbc4',
      },
    },
    '3.7.5': {
      commit: '3430d0a881c4dccbdee1513473a402bcb1b1773d',
      files: {
        'config/app.php': '8a78034f6d4bbf11abbd9ec890fb4fac8d43b928ef1d3da07bf397a4cad83ff7',
        'kernel/Kernel.php': 'edd31ae68488dff37c757bc94fa21b7775e23cf5af4e23f06fd60e6957e4a830',
        'kernel/Helper.php': 'ab992f096ec9f541bc596279bf7537704663303e1a9b0a24dc8f40e22f943a34',
        'app/Controller/Admin/Api/Config.php': '93d3d6d595f7950aae8deefdf871645da1e0603f9a37346da969ce7b692b759d',
        'app/View/Admin/Footer.html': '0449214c5b81a165a221c5a627f6d06198be7170221a9d88ad2d62150f63ee82',
        'assets/common/js/editor/markdown/editorv2.js': '164ef1e5cd7e6c42fc530fafce774c4211bd74fa92fe2ad1b032dcc7f72507de',
      },
      paymentFiles: {
        ...sharedPaymentFiles,
        'app/Service/Bind/Order.php': '3d59f122c816a53ed18492e4fa6f19434df290a8d2be1c17df8e8b6a0bd05b99',
        'app/Service/Bind/Recharge.php': 'e2ebbac628483272e61a4ca2c8279edeadd91de40ad4a4591440e7bba0ac1007',
      },
    },
    '3.7.9': {
      commit: '5120942d2c13ac900d614b09cfd6fbf672b62840',
      files: {
        'config/app.php': '6095cf94a9473e817bb7a92112617f5b476c23f5ab3b68c3114eea3cf6c3a0e7',
        'kernel/Kernel.php': '42c1cdd227b76d04a809bfc0ee5dbc47cdb9ebf0c83b9c2f7be7e004456ab309',
        'kernel/Helper.php': 'ab992f096ec9f541bc596279bf7537704663303e1a9b0a24dc8f40e22f943a34',
        'app/Controller/Admin/Api/Config.php': '2138e7a42998cd2a48a7f988a48445e314dc8d7c8281c31e2d7454b494e72a16',
        'app/View/Admin/Footer.html': '0449214c5b81a165a221c5a627f6d06198be7170221a9d88ad2d62150f63ee82',
        'assets/common/js/editor/markdown/editorv2.js': '385bf017dcc59775d4f05851b73f2777221b548e905c596ca4629c0ffd628fad',
      },
      paymentFiles: {
        ...sharedPaymentFiles,
        'app/Service/Bind/Order.php': '63f7a45cc6410f965eee2eff4d798c4db99b6548b73cc3106cd700eed1fc1473',
        'app/Service/Bind/Recharge.php': 'e2ebbac628483272e61a4ca2c8279edeadd91de40ad4a4591440e7bba0ac1007',
      },
    },
  };
  for (const target of manifest.acg_faka) {
    assert.equal(target.commit, expected[target.version].commit);
    assert.deepEqual(Object.keys(target.files).sort(), expectedFiles);
    assert.deepEqual(target.files, expected[target.version].files);
    assert.deepEqual(Object.keys(target.payment_files).sort(), expectedPaymentFiles);
    assert.deepEqual(target.payment_files, expected[target.version].paymentFiles);
    for (const digest of [...Object.values(target.files), ...Object.values(target.payment_files)]) {
      assert.match(digest, /^[a-f0-9]{64}$/);
    }
  }
});

test('payment compatibility files join the preinstall gate without expanding bridge writes', () => {
  const library = read('scripts/lib.sh');
  const bridgeBody = library.match(/pika_bridge_files\(\) \{([\s\S]*?)\n\}/)?.[1] ?? '';
  const paymentBody = library.match(/pika_payment_compat_files\(\) \{([\s\S]*?)\n\}/)?.[1] ?? '';
  const compatBody = library.match(/pika_compat_files\(\) \{([\s\S]*?)\n\}/)?.[1] ?? '';
  const paymentPaths = Object.keys(JSON.parse(read('compatibility.json')).acg_faka[0].payment_files);
  const quotedPaths = body => [...body.matchAll(/'([^']+)'/g)]
    .map(match => match[1])
    .filter(value => value !== '%s\\n');

  assert.notEqual(paymentBody, '');
  assert.deepEqual(quotedPaths(bridgeBody), [
    'kernel/Kernel.php',
    'kernel/Helper.php',
    'app/Controller/Admin/Api/Config.php',
    'app/View/Admin/Footer.html',
    'assets/common/js/editor/markdown/editorv2.js',
  ]);
  assert.deepEqual(quotedPaths(paymentBody), paymentPaths);
  assert.match(compatBody, /pika_bridge_files/);
  assert.match(compatBody, /pika_payment_compat_files/);
  assert.match(library, /\$row\["payment_files"\]\[\$argv\[3\]\]/);
});

test('container integration fails closed on dirty payment core at the pinned HEAD', () => {
  const integration = read('tests/install-integration-container.sh');
  assert.match(integration, /injected payment compatibility drift/);
  assert.match(integration, /doctor\.sh" --site-root "\$success_site"/);
  assert.match(integration, /compatibility hash mismatch: app\/Pay\/Base\.php/);
  assert.match(integration, /dirty payment core negative gate created external state/);
});

test('doctor rejects a mismatched HEAD or core/payment hash before installation', () => {
  const doctor = read('scripts/doctor.sh');
  const gate = doctor.match(/^(version="\$\(pika_site_version[\s\S]*?)^else$/m)?.[1] ?? '';
  assert.notEqual(gate, '');
  assert.match(gate, /git -C "\$site_root" rev-parse HEAD/);
  assert.match(gate, /pika_compat_files/);
  const commit = '5120942d2c13ac900d614b09cfd6fbf672b62840';
  const script = `set -euo pipefail
site_root=/fixture
mode=preinstall
pika_die() { printf '%s\\n' "$1" >&2; exit 1; }
pika_site_version() { printf '%s' 3.7.9; }
pika_compat_value() {
  if [[ "$2" == commit ]]; then printf '%s' "$EXPECTED_COMMIT";
  else printf '%s' expected-digest; fi
}
git() { printf '%s' "$ACTUAL_COMMIT"; }
pika_compat_files() { printf '%s\\n' kernel/Kernel.php app/Pay/Base.php; }
pika_sha256() {
  if [[ "$1" == "/fixture/$DRIFT_PATH" ]]; then printf '%s' changed-digest;
  else printf '%s' expected-digest; fi
}
${gate}fi
`;
  for (const [actualCommit, driftPath, error] of [
    [commit, '', null],
    ['3430d0a881c4dccbdee1513473a402bcb1b1773d', '', 'unsupported commit:'],
    [commit, 'kernel/Kernel.php', 'compatibility hash mismatch: kernel/Kernel.php'],
    [commit, 'app/Pay/Base.php', 'compatibility hash mismatch: app/Pay/Base.php'],
  ]) {
    const result = spawnSync('bash', ['-c', script], {
      encoding: 'utf8',
      env: { ...process.env, EXPECTED_COMMIT: commit, ACTUAL_COMMIT: actualCommit, DRIFT_PATH: driftPath },
    });
    assert.equal(result.status, error === null ? 0 : 1, result.stderr);
    if (error !== null) assert.ok(result.stderr.includes(error), result.stderr);
  }
});

test('doctor requires bcmath only for 3.7.9 and retains every existing PHP extension gate', () => {
  const doctor = read('scripts/doctor.sh');
  const gate = doctor.match(/^required_extensions=\([\s\S]*?^done$/m)?.[0] ?? '';
  assert.notEqual(gate, '');
  const baseExtensions = ['curl', 'json', 'mbstring', 'openssl', 'pdo_mysql', 'posix'];
  const script = `set -euo pipefail
version="$TARGET_VERSION"
php_bin=fixture_php
pika_die() { printf '%s\\n' "$1" >&2; exit 1; }
fixture_php() {
  printf '%s\\n' "$3"
  [[ "$3" != "$MISSING_EXTENSION" ]]
}
${gate}
`;
  for (const version of ['3.6.4', '3.7.0', '3.7.5', '3.7.9']) {
    const required = version === '3.7.9' ? [...baseExtensions, 'bcmath'] : baseExtensions;
    for (const missing of ['', ...baseExtensions, 'bcmath']) {
      const result = spawnSync('bash', ['-c', script], {
        encoding: 'utf8',
        env: { ...process.env, TARGET_VERSION: version, MISSING_EXTENSION: missing },
      });
      const shouldFail = required.includes(missing);
      assert.equal(result.status, shouldFail ? 1 : 0, `${version}/${missing}: ${result.stderr}`);
      if (shouldFail) {
        assert.equal(result.stderr.trim(), `required PHP extension is missing: ${missing}`);
      } else {
        assert.deepEqual(result.stdout.trim().split('\n'), required);
      }
    }
  }
});

test('container integration preserves official Smarty defaults through install and rollback', () => {
  const integration = read('tests/install-integration-container.sh');
  const prepare = integration.match(/prepare_official_smarty_tree\(\) \{([\s\S]*?)\n\}/)?.[1] ?? '';
  assert.notEqual(prepare, '');
  assert.match(prepare, /runuser -u "\$WEB_USER" -- \/usr\/local\/bin\/php -d display_errors=stderr -r/);
  assert.doesNotMatch(prepare, /error_reporting|display_errors=(?:0|off)|2>\/dev\/null/);
  assert.match(prepare, /require \$site \. "\/vendor\/autoload\.php"/);
  assert.match(prepare, /\$smarty = new Smarty\(\)/);
  assert.match(prepare, /setCompileDir\(\$site \. "\/runtime\/view\/compile"\)/);
  assert.match(prepare, /\$smarty->fetch\("string:official-smarty-permission-fixture"\)/);
  assert.doesNotMatch(prepare, /_dir_perms|_file_perms|chmod[^\n]*\/view|mkdir[^\n]*\/view/);
  assert.match(prepare, /runtime\/view" "\$WEB_UID" "\$WEB_GID" 771/);
  assert.match(prepare, /runtime\/view\/compile" "\$WEB_UID" "\$WEB_GID" 771/);
  assert.match(integration, /prepare_official_smarty_tree "\$success_site"/);
  assert.match(integration, /runtime\/view:751/);
  assert.match(integration, /runtime\/view\/compile:751/);
  assert.match(integration, /install changed an official Smarty node identity/);
  assert.match(integration, /install changed official Smarty compiled content/);
  assert.match(integration, /prepare_official_smarty_tree "\$authorization_failure_site"/);
  assert.match(integration, /mid-authorization rollback did not restore official Smarty inode and mode/);
  assert.match(integration, /\\App\\Util\\File::delDirectory\(\$argv\[1\] \. "\/runtime\/view"\)/);
  assert.doesNotMatch(integration, /clearHackFiles\(/);
  assert.match(integration, /official cache clearing target escaped the exact original Smarty fixture/);
  assert.match(integration, /prepare_official_smarty_tree "\$success_site" 0/);
  assert.match(integration, /restore changed the regenerated official Smarty identity or content/);
  assert.match(integration, /reinstall changed the regenerated official Smarty identity or content/);
  assert.match(integration, /for smarty_unsafe_mode in 0777 0770 01771/);
  assert.match(integration, /expect_smarty_reject 'official runtime tree must not have an extended POSIX ACL'/);
  assert.match(integration, /expect_smarty_reject 'official cache tree must not be group\/world writable'/);
  assert.match(integration, /smarty_symlink_probe/);
  for (const scenario of [
    'mutable-web-0771-file', 'mutable-web-0771-outside', 'mutable-web-0771-prefix',
    'mutable-root-0771-view', 'mutable-other-0771-view', 'mutable-gid-0771-view',
  ]) {
    assert.match(integration, new RegExp(`assert_untrusted_mutable_group_world_write_rejected \\\\\n    ${scenario} `));
  }
});

test('versioned bridge patches stay within their exact upstream boundaries', () => {
  const legacyPatch = read('bridge/3.6.4/local-extensions.patch');
  const legacyPaths = [...legacyPatch.matchAll(/^diff --git a\/(\S+) b\/\S+$/gm)]
    .map(match => match[1]).sort();
  assert.deepEqual(legacyPaths, [
    'app/Controller/Admin/Api/Config.php',
    'app/View/Admin/Footer.html',
    'assets/common/js/editor/markdown/editorv2.js',
    'kernel/Helper.php',
    'kernel/Kernel.php',
  ]);
  assert.doesNotMatch(legacyPatch, /kernel\/Plugin\.php|_APP_STORE_LOAD_STATE|_plugin_get_hwid/);
  assert.match(legacyPatch, /Runtime::isTrustedTheme/);
  assert.match(legacyPatch, /\$editor\.attr\('data-mode'\) === 'html'/);
  assert.match(legacyPatch, /\$textarea\.val\(aceEditor\.getValue\(\)\)/);
  const footerStart = legacyPatch.indexOf('diff --git a/app/View/Admin/Footer.html');
  const footerEnd = legacyPatch.indexOf('\ndiff --git ', footerStart + 1);
  const footerPatch = legacyPatch.slice(footerStart, footerEnd);
  assert.ok(footerStart >= 0 && footerEnd > footerStart);
  assert.match(footerPatch, /^-[^\n]*\/_editorv2\.js[^\n]*$/m);
  assert.match(footerPatch, /^\+[^\n]*\/editorv2\.js\?pika_bridge=editorv2-3\.6\.5[^\n]*$/m);
  assert.doesNotMatch(footerPatch, /^\+[^\n]*\/_editorv2\.js[^\n]*$/m);

  const currentPatches = ['3.7.0', '3.7.5', '3.7.9'].map(version => read(`bridge/${version}/local-extensions.patch`));
  for (const currentPatch of currentPatches) {
    const currentPaths = [...currentPatch.matchAll(/^diff --git a\/(\S+) b\/\S+$/gm)]
      .map(match => match[1]).sort();
    assert.deepEqual(currentPaths, [
      'app/Controller/Admin/Api/Config.php',
      'kernel/Helper.php',
      'kernel/Kernel.php',
    ]);
    assert.match(currentPatch, /Runtime::isTrustedTheme/);
    assert.match(currentPatch, /Runtime::hook/);
    assert.match(currentPatch, /local-extensions\/bootstrap\.php/);
    assert.doesNotMatch(currentPatch, /Footer\.html|editorv2\.js|pika_bridge|kernel\/Plugin\.php/);
  }
  for (const patch of [legacyPatch, ...currentPatches]) {
    assert.doesNotMatch(patch, /_APP_STORE_LOAD_STATE|_plugin_get_hwid/);
    assert.doesNotMatch(patch, /UPDATE\s+[`'"]?config|Config::put|runtime\/config/i);
  }
  const changedLines = patch => patch.split('\n')
    .filter(line => /^[+-](?![+-])/.test(line));
  assert.deepEqual(
    changedLines(read('bridge/3.7.9/local-extensions.patch')),
    changedLines(read('bridge/3.7.5/local-extensions.patch')),
    '3.7.9 must only port the existing local hooks without reverting official core fixes',
  );
});

test('release contains the manager, four local extensions, one theme and one exact payment adapter', () => {
  const release = JSON.parse(read('release.json'));
  assert.deepEqual(release.extensions, ['PikaSupplySync', 'PikaCatalogHub', 'PikaSharedAccess', 'PikaOrderReturnWait']);
  assert.deepEqual(release.themes, ['Pika']);
  assert.deepEqual(release.payment_adapters, ['PikaBEpusdtAdapter']);
  for (const id of release.extensions) {
    const manifest = JSON.parse(read(`extensions/${id}/local-extension.json`));
    assert.equal(manifest.id, id);
    assert.equal(manifest.type, 'plugin');
  }
  const theme = JSON.parse(read('themes/Pika/theme.json'));
  assert.equal(theme.id, 'Pika');
  assert.equal(theme.type, 'theme');
  assert.deepEqual(theme.preserve, ['Setting.php']);

  const adapter = JSON.parse(read('payment-adapters/PikaBEpusdtAdapter/payment-adapter.json'));
  assert.equal(adapter.schema, 1);
  assert.equal(adapter.id, 'PikaBEpusdtAdapter');
  assert.equal(adapter.type, 'payment-adapter');
});

test('full integration stages only Git-tracked working-tree bytes', () => {
  const integration = read('tests/install-integration.sh');
  assert.match(integration, /git -C "\$REPO_ROOT" ls-files -z/);
  assert.match(integration, /cp -pP -- "\$source_path" "\$target_path"/);
  assert.match(integration, /--volume "\$release_root:\/release:ro"/);
  assert.doesNotMatch(integration, /--volume "\$REPO_ROOT:\/release:ro"/);
  for (const excluded of ['README 2.md', 'scripts/init-runtime 2.php', 'tests/.work']) {
    assert.match(integration, new RegExp(excluded.replaceAll('/', '\\/').replaceAll('.', '\\.')));
  }
  assert.match(integration, /git -C "\$OFFICIAL_ROOT" rev-parse HEAD\^\{commit\}/);
  assert.match(integration, /git -C "\$OFFICIAL_ROOT" show "\$fixture_commit:\$relative"/);
  assert.match(integration, /git -c tar\.umask=0022 -C "\$OFFICIAL_ROOT" archive "\$fixture_commit"/);
  assert.match(integration, /matches\[0\]\.version \+ "\\n"/);
  assert.match(integration, /path \+ "\\t" \+ digest \+ "\\n"/);
  assert.doesNotMatch(integration, /matches\[0\]\.version \+ "\\\\n"/);
  assert.doesNotMatch(integration, /cp\s[^\n]*\$OFFICIAL_ROOT/);
  assert.match(integration, /--network none/);
  assert.match(integration, /PIKA_TEST_ACG_FAKA_VERSION=\$fixture_version/);
  assert.match(integration, /PIKA_TEST_ACG_FAKA_COMMIT=\$fixture_commit/);
  for (const commit of [
    '4ff6ba9b28af8e529fc54f643eea5f8bf0bb02d6',
    '6e48aab25f08f4d1fc0dda6af84fa6852fbb37e4',
    '3430d0a881c4dccbdee1513473a402bcb1b1773d',
    '5120942d2c13ac900d614b09cfd6fbf672b62840',
  ]) {
    assert.match(integration, new RegExp(commit));
  }
  assert.match(integration, /official fixture version\/commit pair is not allowlisted/);

  const fakeGit = read('tests/bin/git');
  assert.match(fakeGit, /PIKA_TEST_ACG_FAKA_VERSION/);
  assert.match(fakeGit, /PIKA_TEST_ACG_FAKA_COMMIT/);
  assert.match(fakeGit, /\^\[a-f0-9\]\{40\}\$/);
  assert.match(fakeGit, /patch --dry-run --silent --fuzz=0 --no-backup-if-mismatch/);
  assert.match(fakeGit, /patch --silent --fuzz=0 --no-backup-if-mismatch/);
  assert.doesNotMatch(fakeGit, /printf '%s\\n' '4ff6ba9b28af8e529fc54f643eea5f8bf0bb02d6'/);
});

test('integration fake git accepts only the four pinned version and commit pairs', () => {
  const fixture = mkdtempSync(join(tmpdir(), 'pika-fake-git-'));
  const fakeGit = join(root, 'tests/bin/git');
  mkdirSync(join(fixture, '.git'));
  try {
    for (const [version, commit] of [
      ['3.6.4', '4ff6ba9b28af8e529fc54f643eea5f8bf0bb02d6'],
      ['3.7.0', '6e48aab25f08f4d1fc0dda6af84fa6852fbb37e4'],
      ['3.7.5', '3430d0a881c4dccbdee1513473a402bcb1b1773d'],
      ['3.7.9', '5120942d2c13ac900d614b09cfd6fbf672b62840'],
    ]) {
      const output = execFileSync(fakeGit, ['-C', fixture, 'rev-parse', 'HEAD'], {
        encoding: 'utf8',
        env: {
          ...process.env,
          PIKA_TEST_ACG_FAKA_VERSION: version,
          PIKA_TEST_ACG_FAKA_COMMIT: commit,
        },
      });
      assert.equal(output, `${commit}\n`);
    }

    for (const [version, commit] of [
      ['3.7.0', '4ff6ba9b28af8e529fc54f643eea5f8bf0bb02d6'],
      ['3.7.0', '6E48AAB25F08F4D1FC0DDA6AF84FA6852FBB37E4'],
      ['3.7.0', '6e48aab'],
      ['3.7.5', '6e48aab25f08f4d1fc0dda6af84fa6852fbb37e4'],
      ['3.7.0', '3430d0a881c4dccbdee1513473a402bcb1b1773d'],
      ['3.7.6', '3430d0a881c4dccbdee1513473a402bcb1b1773d'],
      ['3.7.5', '3430d0a'],
      ['3.7.9', '3430d0a881c4dccbdee1513473a402bcb1b1773d'],
      ['3.7.5', '5120942d2c13ac900d614b09cfd6fbf672b62840'],
      ['3.7.9', '5120942D2C13AC900D614B09CFD6FBF672B62840'],
      ['3.7.9', '5120942'],
      ['3.7.8', '5120942d2c13ac900d614b09cfd6fbf672b62840'],
    ]) {
      const result = spawnSync(fakeGit, ['-C', fixture, 'rev-parse', 'HEAD'], {
        encoding: 'utf8',
        env: {
          ...process.env,
          PIKA_TEST_ACG_FAKA_VERSION: version,
          PIKA_TEST_ACG_FAKA_COMMIT: commit,
        },
      });
      assert.equal(result.status, 2);
    }
  } finally {
    rmSync(fixture, { recursive: true, force: true });
  }
});

test('theme package includes the active visual media and excludes only the superseded background', () => {
  const theme = JSON.parse(read('themes/Pika/theme.json'));
  assert.deepEqual(theme.exclude, ['Assets/storefront-bg.jpg']);
  for (const path of [
    'Assets/pika-favicon-1.0.3.png',
    'Assets/topfans-bg-poster.jpg',
    'Assets/topfans-bg.mp4',
    'Assets/topfans-logo.png',
  ]) {
    assert.equal(theme.exclude.includes(path), false, `active asset is excluded: ${path}`);
    assert.ok(statSync(join(root, 'themes/Pika', path)).isFile(), `active asset is missing: ${path}`);
  }
  const sourceFiles = [];
  const walk = dir => {
    for (const entry of readdirSync(dir)) {
      const path = join(dir, entry);
      if (statSync(path).isDirectory()) walk(path);
      else if (/\.(?:html|css|js|php)$/i.test(entry)) sourceFiles.push(path);
    }
  };
  walk(join(root, 'themes/Pika'));
  const source = sourceFiles.map(path => readFileSync(path, 'utf8')).join('\n');
  assert.match(source, /topfans-bg/);
  assert.match(source, /topfans-logo/);
  assert.match(source, /pika-favicon-1\.0\.3/);
  assert.doesNotMatch(source, /storefront-bg\.jpg/);
});

test('installer is local-only, fail-closed and stages only the exact payment adapter namespace', () => {
  const installer = read('scripts/install.sh');
  const doctor = read('scripts/doctor.sh');
  const stage = read('scripts/stage-payload.php');
  for (const source of [installer, doctor, stage]) {
    assert.doesNotMatch(source, /(?:^|[;&|]\s*)(?:curl|wget)\s|systemctl|\bservice\s|\b(?:mysql|mariadb|psql|sqlite3)\b|\b(?:PDO|mysqli)\b/i);
  }
  for (const source of [installer, doctor]) {
    assert.doesNotMatch(source, /app\/Pay\/(?!PikaBEpusdtAdapter(?:\/|['"]))/);
  }
  assert.match(installer, /git -C "\$site_root" apply --check/);
  assert.match(installer, /backup_root/);
  assert.match(installer, /mktemp -d \/run\/pika-local-extensions\.XXXXXX/);
  assert.match(installer, /installer must run as root \(use sudo\)/);
  assert.match(installer, /PIKA_INSTALL_TRUSTED_PATH='\/usr\/sbin:\/usr\/bin:\/sbin:\/bin'/);
  assert.match(installer, /builtin unalias -a/);
  assert.match(installer, /builtin compgen -A function/);
  assert.match(installer, /builtin hash -r/);
  assert.match(installer, /builtin readonly PATH/);
  assert.match(installer, /--web-user USER/);
  assert.match(installer, /--confirm-maintenance/);
  assert.match(installer, /external 503 maintenance and stopping FPM\/web and CLI writers/);
  assert.match(installer, /--web-user must not be root/);
  assert.match(installer, /pika_assert_protected_target/);
  assert.match(installer, /protected ancestor directory/);
  assert.match(installer, /LC_ALL=C stat -c '%h:%u:%g:%a:%F'.*protected target/s);
  assert.match(installer, /pika_assert_no_extended_acl "\$cursor" 'protected target file'/);
  assert.match(installer, /through owner permissions/);
  assert.match(installer, /through group permissions/);
  assert.match(installer, /through world permissions/);
  assert.match(installer, /effective permissions or ACL/);
  assert.match(installer, /runuser -u "\$web_user_arg" -- test -w/);
  assert.match(installer, /chown 0:0/);
  assert.match(installer, /chmod 0755/);
  assert.match(installer, /chmod 0644/);
  assert.match(installer, /chown "\$web_uid:\$web_gid" -- "\$setting_path"/);
  assert.match(installer, /chmod 0640 -- "\$setting_path"/);
  assert.match(installer, /stat -c '%h:%F'.*\$setting_path/s);
  assert.match(installer, /pika_assert_no_extended_acl "\$setting_path" 'Pika mutable setting'/);
  assert.match(installer, /app\/View\/User\/Theme\/Pika\/Setting\.php/);
  assert.match(installer, /patch_applied=1\s+git -C "\$site_root" apply/);
  assert.match(installer, /doctor\.sh" "\$\{installed_doctor_args\[@\]\}"/);
  assert.ok(installer.indexOf('doctor.sh" "${installed_doctor_args[@]}"') < installer.lastIndexOf('trap - EXIT INT TERM'));
  assert.doesNotMatch(installer, /not root; runtime ownership was not changed/);
  assert.doesNotMatch(installer, /chown\s+(?:-[^\s]+\s+)*-R\b|find[^\n]+-exec\s+chown/);
  assert.doesNotMatch(installer, /cp -a -- "\$payload_root\/\."/);
  assert.match(installer, /cp -p -- "\$payload_root\/\$relative" "\$target"/);
  assert.match(stage, /preserve/);
  assert.match(stage, /\['install', 'update'\]/);
  assert.match(stage, /\$mode === 'update'/);
  assert.match(stage, /\$skip = \$excludePolicy/);
  assert.match(stage, /assets\/local-extensions/);
  assert.match(stage, /payment_adapters/);
  assert.match(stage, /payment-adapter\.json/);
  assert.match(stage, /app\/Pay\/\{\$id\}/);
  assert.match(stage, /'payment-adapter\.json',[\s\S]*'README\.md',[\s\S]*'runtime\.log'/);
  assert.match(installer, /app\/Pay\/PikaBEpusdtAdapter\/runtime\.log/);
  assert.match(installer, /install -o "\$web_uid" -g "\$web_gid" -m 0640 \/dev\/null "\$payment_runtime_log"/);
  assert.match(installer, /pika_assert_no_extended_acl "\$payment_runtime_log" 'payment adapter runtime log'/);
  assert.match(installer, /\$state_dir\/secrets/);
  assert.match(installer, /pika_record_external_state_node "\$state_dir\/secrets" directory 0 "\$web_gid" 750/);
  assert.doesNotMatch(installer, /chown "\$web_uid:\$web_gid" -- "\$state_dir\/runtime/);
  assert.match(read('scripts/init-runtime.php'), /After runtime is handed to the Web identity/);
  assert.match(installer, /pika_assert_isolated_web_identity/);
  assert.match(installer, /pika_assert_external_state_no_acl/);
  for (const [relative, mode] of [
    ['assets/cache', '755'],
    ['assets/cache/general', '755'],
    ['assets/cache/general/image', '755'],
    ['assets/cache/pika-supply-sync', '755'],
    ['app/Pay', '755'],
    ['app/Plugin', '755'],
    ['app/View/User/Theme', '755'],
    ['config', '750'],
    ['kernel/Install', '750'],
    ['kernel/Install/OS', '750'],
    ['runtime', '750'],
  ]) {
    assert.match(installer, new RegExp(`${relative.replaceAll('/', '\\/')}:${mode}`));
  }
  assert.match(installer, /official runtime directory must not have special permission bits/);
  assert.match(installer, /official runtime directory must not be group\/world writable/);
  assert.match(installer, /\$label owner must have rwx permissions/);
  assert.doesNotMatch(installer, /Web-owned official runtime directory has an unexpected mode/);
  assert.match(installer, /official runtime directory is not a real directory/);
  assert.match(installer, /official runtime directory escaped the canonical site root/);
  assert.match(installer, /pika_assert_no_extended_acl "\$path" 'official runtime directory'/);
  assert.match(installer, /pika_assert_safe_web_owned_directory_metadata/);
  assert.match(installer, /while IFS=: read -r official_relative _official_mode/);
  assert.match(installer, /pika_assert_official_runtime_path_preinstall "\$official_relative"/);
  const protectedAncestor = installer.match(/pika_assert_protected_ancestor\(\) \{([\s\S]*?)\n\}/)?.[1] ?? '';
  assert.match(protectedAncestor, /pika_assert_safe_web_owned_directory_metadata/);
  assert.doesNotMatch(protectedAncestor, /expected_mode=['"]?755/);
  assert.match(installer, /official_contract_created/);
  assert.match(installer, /official_contract_existing/);
  assert.match(installer, /pika_assert_uid_quiescent/);
  assert.match(installer, /pika_take_official_barrier/);
  assert.match(installer, /official runtime root barrier/);
  assert.match(installer, /chown -h 0:0 -- "\$path"/);
  assert.match(installer, /chmod 0700 -- "\$path"/);
  assert.match(installer, /pika_authorize_official_contract/);
  assert.ok(installer.indexOf('pika_authorize_official_contract') < installer.indexOf('doctor.sh" "${installed_doctor_args[@]}"'));
  const mutableAuthorization = installer.match(/pika_normalize_mutable_contract\(\) \{([\s\S]*?)\n\}/)?.[1] ?? '';
  assert.notEqual(mutableAuthorization, '');
  assert.ok(
    mutableAuthorization.indexOf("[[ \"$type\" == 'regular file' ]]")
      < mutableAuthorization.indexOf('for ((depth = max_depth; depth >= 0; depth--))'),
    'mutable files must be authorized before mutable directories',
  );
  assert.match(mutableAuthorization, /depth = max_depth; depth >= 0; depth--/);
  const authorizeRecord = installer.match(/pika_authorize_mutable_record\(\) \{([\s\S]*?)\n\}/)?.[1] ?? '';
  const recordExisting = installer.match(/pika_record_existing_node\(\) \{([\s\S]*?)\n\}/)?.[1] ?? '';
  assert.notEqual(recordExisting, '');
  assert.match(installer, /group\/world write requires the dedicated Web identity/);
  assert.match(installer, /group\/world write compatibility requires exact mode 0777/);
  assert.match(recordExisting, /"\$allow_web_group_world_write" == 1/);
  assert.match(recordExisting, /"\$uid" == "\$web_uid" && "\$gid" == "\$web_gid"/);
  assert.match(recordExisting, /if \(\(mode_value != 0777\)\)/);
  assert.match(recordExisting, /\(\(mode_value == 0771\)\)/);
  assert.match(recordExisting, /"\$type" == directory/);
  assert.match(recordExisting, /"\$path" == "\$site_root\/runtime\/view"/);
  assert.match(recordExisting, /"\$path" == "\$site_root\/runtime\/view\/"\*/);
  assert.ok(recordExisting.indexOf('"$uid" == "$web_uid"') < recordExisting.indexOf('mode_value == 0771'));
  assert.ok(recordExisting.indexOf('pika_assert_no_extended_acl') < recordExisting.indexOf('mutable_existing+=('));
  assert.match(authorizeRecord, /mode_value & 0755/);
  assert.match(authorizeRecord, /\| 0700/);
  assert.match(authorizeRecord, /\| 0600/);
  assert.ok(authorizeRecord.indexOf('chmod "$normalized_mode"') < authorizeRecord.indexOf('chown -h "$web_uid:$web_gid"'));
  const authorizeContract = installer.match(/pika_authorize_official_contract\(\) \{([\s\S]*?)\n\}/)?.[1] ?? '';
  assert.ok(authorizeContract.indexOf('authorization_started=1') < authorizeContract.indexOf('pika_normalize_mutable_contract'));
  assert.ok(authorizeContract.indexOf('chmod "$mode"') < authorizeContract.indexOf('chown -h "$web_uid:$web_gid"'));
  assert.match(authorizeContract, /chown -h "0:\$web_gid"/);
  assert.match(authorizeContract, /barriers_authorized=1\s+authorization_started=0/);
  const rollbackBarrier = installer.match(/pika_reacquire_barriers_for_rollback\(\) \{([\s\S]*?)\n\}/)?.[1] ?? '';
  assert.match(rollbackBarrier, /barriers_authorized \|\| authorization_started/);
  assert.match(rollbackBarrier, /0:0:700/);
  assert.match(rollbackBarrier, /0:0:\$expected_mode/);
  assert.ok(
    rollbackBarrier.indexOf('metadata="$(stat -c') < rollbackBarrier.indexOf('chown -h 0:0'),
    'all partial authorization states must be preflighted before any shallow root is reclaimed',
  );
  assert.match(installer, /rmdir -- "\$path"/);
  assert.match(installer, /ROLLBACK_INCOMPLETE official runtime residue remains/);
  assert.match(installer, /official runtime metadata did not return to its snapshot/);
  assert.match(installer, /newly-created official runtime directory retained unexpected residue or identity drifted/);
  assert.doesNotMatch(installer, /chmod\s+(?:-[^\s]+\s+)*-R\b|find[^\n]+-exec\s+chmod/);
  const verifier = read('scripts/verify-install.php');
  assert.match(verifier, /PHP CLI proc_open is required to inspect/);
  assert.match(verifier, /trusted \/usr\/bin\/ls is required to inspect/);
  assert.match(verifier, /assertDedicatedWebTree/);
  assert.match(verifier, /assertOfficialThrottleTree/);
  assert.match(verifier, /\[0o755, 0o777\]/);
  assert.match(verifier, /\[a-f0-9\]\{32\}/);
  assert.match(verifier, /'official runtime tree',\s*\['throttle'\]/);
  assert.equal([...verifier.matchAll(/\?string \$smartyViewRoot = null/g)].length, 2);
  assert.match(verifier, /'official runtime tree',\s*\['throttle'\],\s*\$officialRuntimePaths\['runtime'\] \. DIRECTORY_SEPARATOR \. 'view'/);
  assert.match(verifier, /assertDedicatedWebNode\(\$path, \$webUid, \$webGid, \$label, \$smartyViewRoot\)/);
  assert.match(verifier, /\$smartyViewRoot !== null/);
  assert.match(verifier, /\$mode === 0o771/);
  assert.match(verifier, /realpath\(\$path\) === \$path/);
  assert.match(verifier, /\$path === \$smartyViewRoot/);
  assert.match(verifier, /str_starts_with\(\$path, \$smartyViewRoot \. DIRECTORY_SEPARATOR\)/);
  assert.match(verifier, /official mutable config file/);
  assert.match(verifier, /\[0o600, 0o640\]/);
  assert.match(verifier, /assertCanonicalSingleLinkAclFreeFile\(\$settingPath, 'Pika mutable setting'\)/);
  assert.match(verifier, /assertCanonicalSingleLinkAclFreeFile\(\$paymentRuntimePath, 'payment adapter runtime log'\)/);
  assert.match(verifier, /official payment core file/);
  assert.match(verifier, /official database configuration file/);
  assert.match(verifier, /200000-node verification limit/);
  assert.match(doctor, /PHP_VERSION_ID/);
  assert.match(doctor, /80100/);
  assert.match(doctor, /PHP 8\.1 or newer is required/);
  for (const extension of ['curl', 'json', 'mbstring', 'openssl', 'pdo_mysql', 'posix']) {
    assert.match(doctor, new RegExp(extension.replace('_', '\\_')));
  }
});

test('installed doctor compares active BEpusdt origins through official read APIs without reading secrets', () => {
  const doctor = read('scripts/doctor.sh');
  assert.match(doctor, /\$mode" == "installed"|else\n[\s\S]*?pika_install_receipt_path/);
  assert.match(doctor, /\\App\\Model\\Config::get\("callback_domain"\)/);
  assert.doesNotMatch(doctor, /Config::query\(\)[\s\S]*?callback_domain/);
  assert.match(doctor, /\\App\\Model\\Pay::query\(\)[\s\S]*?"PikaBEpusdtAdapter"/);
  assert.match(doctor, /\\App\\Util\\PayProfile::raw\("PikaBEpusdtAdapter"/);
  assert.match(doctor, /\\App\\Pay\\PikaBEpusdtAdapter\\Support\\Settings::from\(\$profile\)/);
  assert.match(doctor, /hash_equals\(\$callbackDomain, \$settings->merchantOrigin\)/);
  assert.match(doctor, /hash_equals\(\$callbackDomain, \$settings->checkoutOrigin\)/);
  assert.match(doctor, /BEPUSDT_ORIGIN_PASS status=/);
  assert.match(doctor, /pika_require_command setpriv/);
  assert.match(doctor, /--reuid "\$runtime_uid"[\s\S]+--regid "\$runtime_gid"[\s\S]+--clear-groups[\s\S]+--no-new-privs/);
  assert.ok(doctor.indexOf('--reuid "$runtime_uid"') < doctor.indexOf('require $databaseConfigPath'));
  assert.doesNotMatch(doctor, /SecretStore::load|bepusdt-token|bepusdt-namespace/);
});

test('all root shell entrypoints pin PATH and the shared PHP resolver rejects replaceable executables', () => {
  const library = read('scripts/lib.sh');
  const doctor = read('scripts/doctor.sh');
  const restore = read('scripts/restore.sh');
  const configure = read('scripts/configure-bepusdt.sh');
  assert.match(library, /unset TMPDIR TMP TEMP PHPRC PHP_INI_SCAN_DIR/);
  assert.match(library, /site root ancestor must be root-owned/);
  assert.match(configure, /export LC_ALL=C/);
  assert.doesNotMatch(configure, /readonly LC_ALL/);
  for (const [source, prefix] of [[doctor, 'DOCTOR'], [restore, 'RESTORE']]) {
    assert.match(source, new RegExp(`PIKA_${prefix}_TRUSTED_PATH='\\/usr\\/sbin:\\/usr\\/bin:\\/sbin:\\/bin'`));
    assert.match(source, /builtin unalias -a/);
    assert.match(source, /builtin compgen -A function/);
    assert.match(source, /builtin readonly PATH/);
    assert.ok(source.indexOf('builtin readonly PATH') < source.indexOf('source "${SCRIPT_DIR}/lib.sh"'));
  }
  for (const [path, source] of [
    ['install.sh', read('scripts/install.sh')],
    ['doctor.sh', doctor],
    ['restore.sh', restore],
    ['configure-bepusdt.sh', configure],
  ]) {
    assert.match(source, /bootstrap_assert_root_path/);
    assert.match(source, /release ancestor/);
    assert.match(source, /must not have an extended POSIX ACL/);
    assert.ok(source.indexOf('bootstrap_assert_root_path') < source.indexOf('source "${SCRIPT_DIR}/lib.sh"'), `${path} bootstrap runs too late`);
    assert.ok(source.indexOf('pika_assert_trusted_release_tree') > source.indexOf('source "${SCRIPT_DIR}/lib.sh"'), `${path} full release gate runs too early`);
  }
  const resolver = library.match(/pika_php\(\) \{([\s\S]*?)\n\}/)?.[1] ?? '';
  assert.notEqual(resolver, '');
  assert.match(resolver, /realpath -e -- "\$candidate"/);
  assert.match(resolver, /PHP binary must be root:root with a valid mode/);
  assert.match(resolver, /PHP binary must not be group\/world writable/);
  assert.match(resolver, /PHP binary ancestors must be root:root/);
  assert.match(resolver, /PHP binary ancestor must not be group\/world writable/);
  assert.match(resolver, /PHP CLI basename is not allowed/);
  assert.match(resolver, /echo PHP_VERSION_ID/);
  assert.match(resolver, /pika_assert_no_extended_acl "\$resolved" 'PHP binary'/);
  assert.match(resolver, /pika_assert_no_extended_acl "\$cursor" 'PHP binary ancestor'/);
});

test('root shell entrypoints resolve their own symlink before sourcing the adjacent library', () => {
  const fixture = mkdtempSync(join(tmpdir(), 'pika-entry-symlink-'));
  const marker = join(fixture, 'untrusted-lib-ran');
  try {
    writeFileSync(join(fixture, 'lib.sh'), `#!/bin/bash\nprintf untrusted > ${JSON.stringify(marker)}\n`);
    for (const name of ['install.sh', 'doctor.sh', 'restore.sh', 'scheduler.sh', 'configure-bepusdt.sh']) {
      const link = join(fixture, name);
      symlinkSync(join(root, 'scripts', name), link);
      const result = spawnSync(link, [], { encoding: 'utf8' });
      assert.notEqual(result.status, null, `${name} did not exit`);
      assert.equal(existsSync(marker), false, `${name} sourced lib.sh beside the invoking symlink`);
      rmSync(link);
    }
  } finally {
    rmSync(fixture, { recursive: true, force: true });
  }
});

test('site version detection hashes config/app.php without loading target PHP', () => {
  const library = read('scripts/lib.sh');
  const functionBody = library.match(/pika_site_version\(\) \{([\s\S]*?)\n\}/)?.[1] ?? '';
  assert.notEqual(functionBody, '');
  assert.doesNotMatch(functionBody, /\brequire\b|\binclude(?:_once)?\b/);
  assert.match(functionBody, /pika_sha256 "\$app_config"/);
  assert.match(functionBody, /"\$PIKA_COMPAT_FILE" "\$app_hash"/);

  const fixture = mkdtempSync(join(tmpdir(), 'pika-version-noexec-'));
  try {
    mkdirSync(join(fixture, 'config'), { recursive: true });
    const marker = join(fixture, 'executed');
    writeFileSync(
      join(fixture, 'config/app.php'),
      `<?php file_put_contents(${JSON.stringify(marker)}, 'executed'); return ['version' => '3.6.4'];\n`,
    );
    assert.equal(existsSync(marker), false);
    assert.equal(lstatSync(join(fixture, 'config/app.php')).isFile(), true);
  } finally {
    rmSync(fixture, { recursive: true, force: true });
  }
});

test('safe site root rejects symlinks for every compatibility input and .git', () => {
  const fixture = mkdtempSync(join(tmpdir(), 'pika-site-symlink-'));
  const target = JSON.parse(read('compatibility.json')).acg_faka[0];
  const compatibilityPaths = [...Object.keys(target.files), ...Object.keys(target.payment_files)];
  try {
    for (const relative of compatibilityPaths) {
      mkdirSync(join(fixture, relative, '..'), { recursive: true });
      writeFileSync(join(fixture, relative), 'fixture');
    }
    mkdirSync(join(fixture, '.git'));
    execFileSync('bash', [
      '-c', 'source "$1"; pika_assert_safe_site_root "$2"',
      'pika-site-root-test', join(root, 'scripts/lib.sh'), fixture,
    ]);

    for (const relative of [...compatibilityPaths, '.git']) {
      const absolute = join(fixture, relative);
      const replacement = `${absolute}.real`;
      rmSync(replacement, { recursive: true, force: true });
      if (relative === '.git') mkdirSync(replacement);
      else writeFileSync(replacement, 'fixture');
      rmSync(absolute, { recursive: true, force: true });
      symlinkSync(replacement, absolute);
      assert.throws(() => execFileSync('bash', [
        '-c', 'source "$1"; pika_assert_safe_site_root "$2"',
        'pika-site-root-test', join(root, 'scripts/lib.sh'), fixture,
      ], { stdio: 'pipe' }), /Command failed/);
      rmSync(absolute);
      if (relative === '.git') mkdirSync(absolute);
      else writeFileSync(absolute, 'fixture');
      rmSync(replacement, { recursive: true, force: true });
    }

    const kernel = join(fixture, 'kernel');
    const kernelReplacement = `${kernel}.real`;
    rmSync(kernelReplacement, { recursive: true, force: true });
    mkdirSync(kernelReplacement);
    writeFileSync(join(kernelReplacement, 'Kernel.php'), 'fixture');
    writeFileSync(join(kernelReplacement, 'Helper.php'), 'fixture');
    rmSync(kernel, { recursive: true });
    symlinkSync(kernelReplacement, kernel);
    assert.throws(() => execFileSync('bash', [
      '-c', 'source "$1"; pika_assert_safe_site_root "$2"',
      'pika-site-root-test', join(root, 'scripts/lib.sh'), fixture,
    ], { stdio: 'pipe' }), /Command failed/);
  } finally {
    rmSync(fixture, { recursive: true, force: true });
  }
});

test('BE callback controller is an exact managed file with protected ancestry and preserved official parents', () => {
  const relative = 'app/Controller/User/Api/PikaBEpusdt.php';
  const installer = read('scripts/install.sh');
  const protectedTargets = installer.match(/for protected_relative in ([\s\S]*?); do/)?.[1] ?? '';
  const absentTargets = installer.match(/for must_be_absent in ([\s\S]*?); do/)?.[1] ?? '';
  assert.ok(protectedTargets.includes(`'${relative}'`), 'BE callback ancestry must be preflighted before writes');
  assert.ok(absentTargets.includes(`"$site_root/${relative}"`), 'existing BE callback must be rejected before staging');

  for (const [file, name] of [
    ['scripts/verify-install.php', 'installedPathAllowed'],
    ['scripts/restore-install.php', 'isAllowedInstalledPath'],
  ]) {
    const body = read(file).match(new RegExp(`function ${name}\\([^]*?\\n\\}`))?.[0] ?? '';
    const controllerEntries = [...body.matchAll(/'(app\/Controller\/[^']+)'/g)].map(match => match[1]);
    assert.deepEqual(controllerEntries, [
      'app/Controller/Admin/LocalExtensions.php',
      'app/Controller/Admin/Api/LocalExtensions.php',
      relative,
    ], `${file} must allow only the three exact managed controllers`);
  }
  const cleanup = read('scripts/restore-install.php').match(/function addCleanupDirectories\([^]*?\n\}/)?.[0] ?? '';
  assert.doesNotMatch(cleanup, /app\/Controller/);

  const integration = read('tests/install-integration-container.sh');
  assert.match(integration, /BE callback controller receipt must bind exactly one installed file/);
  assert.match(integration, /BE callback restore changed an official controller parent/);
  assert.match(integration, /preexisting BE callback controller did not fail at the exact target gate/);
  assert.match(integration, /BE callback ancestor rejection mutated site or external state/);
});

test('installed verification binds the external protected receipt and exact payload sets', () => {
  const verifier = read('scripts/verify-install.php');
  const receiptWriter = read('scripts/write-receipt.php');
  const doctor = read('scripts/doctor.sh');
  assert.match(verifier, /\/var\/lib\/pika-local-extensions/);
  assert.match(verifier, /hash\('sha256', \$siteRoot\)/);
  assert.match(verifier, /assertRootFile\(\$siteReceiptPath, \[0o400, 0o440\]/);
  assert.match(verifier, /assertRootDirectory\(\$backupRoot, \[0o700\]/);
  assert.match(verifier, /\$actualBridgeFiles !== \$expectedBridgeFiles/);
  assert.match(verifier, /installedPathAllowed\(\$relative\)/);
  assert.match(verifier, /contains a duplicate path/);
  assert.match(verifier, /replaceable by a non-root identity/);
  assert.match(verifier, /numericMode\(\$path\) !== 0o644/);
  assert.match(verifier, /verifiedRegularFile\(\$siteRoot, \$relative, 'bridge file', true\)/);
  assert.match(verifier, /verifiedRegularFile\(\$backupRoot \. '\/site', \$relative, 'bridge backup file', true\)/);
  assert.match(verifier, /assertCanonicalSingleLinkAclFreeFile\(\$path, \$label\)/);
  assert.match(receiptWriter, /assertImmutableBridgeFile/);
  assert.match(receiptWriter, /current bridge receipt input/);
  assert.match(receiptWriter, /backup bridge receipt input/);
  assert.match(receiptWriter, /must not have an extended POSIX ACL/);
  assert.match(receiptWriter, /\(int\)\(\$stat\['nlink'\] \?\? 0\) !== 1/);
  assert.match(receiptWriter, /must be root:root mode 0644 without special bits/);
  assert.match(verifier, /verifiedRegularFile\([\s\S]*?\$siteRoot,[\s\S]*?\$relative,[\s\S]*?'installed file',[\s\S]*?true,[\s\S]*?\$approvedMutableAncestors/);
  assert.match(verifier, /external runtime does not match the receipt web identity/);
  assert.match(verifier, /Pika mutable setting does not match the receipt web identity/);
  assert.match(verifier, /app\/Pay\/PikaBEpusdtAdapter\/runtime\.log/);
  assert.match(verifier, /payment adapter runtime log does not match the receipt web identity/);
  assert.match(verifier, /payment secret directory does not match the receipt web identity/);
  assert.match(verifier, /assertOfficialRuntimeDirectory/);
  assert.match(verifier, /assets\/cache\/general\/image/);
  assert.match(verifier, /official runtime directory \{\$relative\} must be a canonical/);
  assert.match(verifier, /must not have an extended POSIX ACL/);
  assert.match(verifier, /approvedMutableAncestors/);
  assert.match(verifier, /\['bepusdt-token', 'bepusdt-namespace'\]/);
  assert.match(doctor, /pika_install_receipt_path/);
  assert.doesNotMatch(doctor, /runtime\/local-extensions\/install-receipt\.json/);
});

test('restore is root-only, receipt-bound and removes only the exact installed payload', () => {
  const restore = read('scripts/restore.sh');
  const helper = read('scripts/restore-install.php');
  for (const source of [restore, helper]) {
    assert.doesNotMatch(source, /(?:^|[;&|]\s*)(?:curl|wget)\s|systemctl|\bservice\s|\b(?:mysql|mariadb|psql|sqlite3)\b|\b(?:PDO|mysqli)\b/i);
    assert.doesNotMatch(source, /app\/Pay\/(?!PikaBEpusdtAdapter(?:\/|['"]))/);
    assert.doesNotMatch(source, /rm\s+-rf|find\s+[^\n]*-delete|glob\s*\(/i);
  }
  assert.match(restore, /restore must run as root \(use sudo\)/);
  assert.match(restore, /--site-root \/absolute\/acg-faka\/path --receipt \/absolute\/backup\/install-receipt\.json --confirm-maintenance/);
  assert.match(restore, /--confirm-maintenance is required/);
  assert.match(restore, /pika_assert_scheduler_removed/);
  assert.match(restore, /pika_assert_no_sync_process/);
  assert.match(restore, /pika_hold_sync_locks/);
  assert.match(restore, /pika_hold_catalog_worker_lock/);
  assert.match(restore, /pika-catalog-worker-\*\.service/);
  assert.match(restore, /pika-catalog-worker-\*\.timer/);
  assert.match(restore, /\\\( -type f -o -type l \\\)/);
  assert.match(restore, /scheduler unit path must not be a symbolic link before restore/);
  assert.match(restore, /# Pika-SiteRoot: \$site_root/);
  assert.match(restore, /PikaSupplySync\/bin\/sync\.php/);
  assert.match(restore, /PikaCatalogHub\/bin\/worker\.php/);
  assert.match(restore, /runtime\/extensions\/PikaCatalogHub/);
  assert.match(restore, /worker\.run\.lock/);
  assert.match(restore, /stat -L -c '%F:%u:%a:%h:%d:%i'/);
  assert.match(restore, /handle_dev.*path_dev/);
  assert.match(restore, /handle_ino.*path_ino/);
  assert.ok((restore.match(/pika_assert_no_sync_process/g) ?? []).length >= 3);
  assert.match(restore, /verify-install\.php" --site-root "\$site_root"/);
  assert.match(restore, /restore-install\.php/);
  const userInstall = read('docs/USER_INSTALL.md');
  const troubleshooting = read('docs/TROUBLESHOOTING.md');
  for (const guide of [userInstall, troubleshooting]) {
    assert.match(guide, /PikaSupplySync\/bin\/sync\.php\|PikaCatalogHub\/bin\/worker\.php/);
  }
  assert.match(helper, /explicit receipt does not match the verified site receipt/);
  assert.match(helper, /bridge receipt does not contain the exact approved file set/);
  assert.match(helper, /isCanonicalSingleLinkAclFreeFile/);
  assert.match(helper, /current bridge file', true/);
  assert.match(helper, /backup bridge file', true/);
  assert.match(helper, /installed file verification failed/);
  assert.match(helper, /Mutation starts only after every receipt, path and content precondition above passes/);
  assert.match(helper, /app\/View\/User\/Theme\/Pika\/Setting\.php/);
  assert.match(helper, /local-extensions\/registry\.json/);
  assert.match(helper, /app\/Pay\/PikaBEpusdtAdapter\/runtime\.log/);
  assert.match(helper, /payment adapter runtime log does not match the receipt web identity/);
  assert.match(helper, /assertDedicatedWebMutableFile/);
  assert.match(helper, /official exact mutable file does not match the installed contract/);
  assert.match(helper, /\(int\)\(\$stat\['nlink'\] \?\? 0\) !== 1/);
  assert.match(helper, /!noExtendedAcl\(\$path\)/);
  assert.match(helper, /payment secret directory does not match the receipt web identity/);
  assert.match(helper, /\/var\/lib\/pika-local-extensions/);
  assert.match(helper, /external site runtime is missing or unsafe/);
  assert.match(helper, /external runtime does not match the receipt web identity/);
  assert.match(helper, /\.pika-restore-payload\./);
  assert.match(helper, /\.pika-restore-old\./);
  assert.match(helper, /\.pika-restore-new\./);
  assert.match(helper, /rename\(\$stagedBridgeNew\[\$relative\], \$current\)/);
  assert.match(helper, /rollbackRestore/);
  assert.match(helper, /RESTORE_ROLLBACK_PASS/);
  assert.match(helper, /RESTORE_ROLLBACK_FAIL/);
  assert.match(helper, /RESTORE_CLEANUP_FAIL runtime_state=archived/);
  assert.match(helper, /after-first-bridge-swap/);
  assert.match(helper, /after-state-archive/);
  assert.match(helper, /\$stateBase \. '\/archives'/);
  assert.match(helper, /rename\(\$stateSiteRoot, \$stateArchivePath\)/);
  assert.match(helper, /rename\(\$stateArchivePath, \$stateSiteRoot\)/);
  assert.match(helper, /hash_equals\(\$expectedHash, hash_file\('sha256', \$current\)\)/);
  assert.match(helper, /count\(\$entries\) === 2 && !rmdir\(\$absolute\)/);
  assert.match(helper, /preserveMutableSetting/);
  assert.match(helper, /mutable-site-files/);
  assert.match(helper, /mutable_setting=\{\$mutableSettingBackup\} archive=\{\$stateArchivePath\}/);
  assert.match(helper, /officialRuntimeBarriersMatch/);
  assert.match(helper, /Preflight every bound root before authorizing any of them/);
  assert.ok(helper.indexOf('chmod($path, $mode)') < helper.indexOf('chown($path, $expectedUid)'));
  assert.match(helper, /officialRuntimeFinalOwner/);
  assert.match(
    helper,
    /function freshLstat\(string \$path\): array\|false\s*\{\s*clearstatcache\(true, \$path\);\s*return lstat\(\$path\);\s*\}/,
  );
  assert.equal(
    [...helper.matchAll(/clearstatcache\(true, \$path\)/g)].length,
    1,
    'restore must centralize fresh path metadata in one helper',
  );
  const boundDirectory = helper.match(/function boundDirectory\([\s\S]*?\n\}/)?.[0] ?? '';
  const barriersMatch = helper.match(/function officialRuntimeBarriersMatch\([\s\S]*?\n\}/)?.[0] ?? '';
  const releaseBarriers = helper.match(/function releaseOfficialRuntimeBarriers\([\s\S]*?\n\}/)?.[0] ?? '';
  for (const [label, source, expectedFreshReads] of [
    ['bound directory', boundDirectory, 1],
    ['barrier match', barriersMatch, 1],
    ['barrier release', releaseBarriers, 2],
  ]) {
    assert.notEqual(source, '', `${label} function must be present`);
    assert.equal(
      [...source.matchAll(/freshLstat\(\$path\)/g)].length,
      expectedFreshReads,
      `${label} must use fresh path metadata for every authoritative read`,
    );
    assert.doesNotMatch(source, /\$stat\s*=\s*lstat\(\$path\)/);
  }
  assert.doesNotMatch(helper, /restore-barrier|barrier manifest/i);
});

test('BEpusdt configurator is a root-only first-install secret publisher with no token argument', () => {
  const configure = read('scripts/configure-bepusdt.sh');
  assert.match(configure, /set \+x/);
  assert.match(configure, /umask 0077/);
  assert.match(configure, /configurator must run as root/);
  assert.match(configure, /--token-file/);
  assert.doesNotMatch(configure, /--token(?:\s|=|\))/);
  assert.match(configure, /\^\[a-z0-9\]\{4,12\}\$/);
  assert.doesNotMatch(configure, /a-z0-9-/);
  assert.match(configure, /token_size >= 16 && token_size <= 256/);
  assert.match(configure, /\$\{#token\} -ge 16 && \$\{#token\} -le 256/);
  assert.match(configure, /\^\[\[:graph:\]\]\+\$/);
  assert.match(configure, /BEpusdt secrets already exist; this first-install command will not overwrite them/);
  assert.match(configure, /bepusdt-token/);
  assert.match(configure, /bepusdt-namespace/);
  assert.match(configure, /pika_assert_isolated_web_identity/);
  assert.match(configure, /pika_assert_external_state_no_acl/);
  assert.match(configure, /stat -c '%u:%g:%a:%h'/);
  assert.match(configure, /chown "0:\$secret_gid"/);
  assert.match(configure, /chmod 0640/);
  assert.match(configure, /BEPUSDT_CONFIG_PASS site=\$site_root namespace=\$namespace/);
  assert.doesNotMatch(configure, /systemctl|\bservice\s|\b(?:UPDATE|DELETE|ALTER|INSERT)\b|install-receipt\.json/);
  assert.doesNotMatch(configure, /pika_(?:info|die).*\$token/);
});

test('restore preserves the finite payment log family behind exact per-site rule isolation', () => {
  const helper = read('scripts/restore-install.php');
  const names = helper.match(/function paymentLogNames\(\): array\s*\{([\s\S]*?)\n\}/)?.[1] ?? '';
  assert.deepEqual([...names.matchAll(/'(runtime\.log[^']*)'/g)].map(match => match[1]), [
    'runtime.log', 'runtime.log.1', 'runtime.log.2.gz', 'runtime.log.3.gz',
    'runtime.log.4.gz', 'runtime.log.5.gz', 'runtime.log.6.gz', 'runtime.log.7.gz',
  ]);
  assert.match(helper, /PIKA_PAYMENT_LOGROTATE_RULE_BACKUP/);
  assert.match(helper, /PIKA_PAYMENT_LOGROTATE_RULE/);
  assert.doesNotMatch(helper, /PIKA_PAYMENT_LOGROTATE_STATE|--skip-state-lock/);
  assert.match(helper, /trim\(\$comm\) === 'logrotate'/);
  assert.match(helper, /paymentRotationGuardMatches\(\$paymentRotationGuard\)/);
  assert.match(helper, /payment adapter contains unknown or unsupported entries/);
  assert.match(helper, /samePaymentLog\(paymentLogMetadata\(\$current, \$webUid, \$webGid\)/);
  assert.match(helper, /payment_logs=\{\$paymentLogBackup\}/);
  assert.ok(helper.indexOf('$paymentRotationGuard = requirePaymentRotationIsolation($siteRoot)')
    < helper.indexOf('$officialBarrierState = takeOfficialRuntimeBarriers('));
  assert.ok(helper.indexOf('$paymentDirectories = paymentDirectorySnapshot(')
    < helper.indexOf('$mutableSettingBackup = preserveMutableSetting('));
  assert.ok(helper.indexOf('$paymentLogBackup = preservePaymentLogs(')
    < helper.indexOf('// Mutation starts only after every receipt, path and content precondition above passes.'));
  const preserve = helper.match(/function preservePaymentLogs\([\s\S]*?\n\}/)?.[0] ?? '';
  assert.match(preserve, /payment-runtime-logs/);
  assert.match(preserve, /assertPaymentLogBackup\(\$stage, \$logs\)/);
  assert.match(preserve, /rename\(\$stage, \$target\)/);
  assert.match(preserve, /chmod\(\$copy, 0o600\)/);
  assert.doesNotMatch(preserve, /unlink\(|glob\(|RecursiveDirectoryIterator/);
});

test('shell and PHP installer sources pass syntax checks', () => {
  for (const file of ['lib.sh', 'doctor.sh', 'install.sh', 'restore.sh', 'configure-bepusdt.sh']) {
    execFileSync('bash', ['-n', join(root, 'scripts', file)]);
  }
});

test('release ships exact Nginx and logrotate deployment examples', () => {
  const checkout = read('packaging/nginx/pika-bepusdt-checkout-server.conf.example');
  const reject = read('packaging/nginx/pika-bepusdt-default-reject.conf.example');
  const logrotate = read('packaging/logrotate/pika-bepusdt-adapter.conf.example');
  assert.match(checkout, /server_name is[\s\S]*exact merchant_origin/);
  assert.match(checkout, /127\.0\.0\.1:__BEPUSDT_LOOPBACK_PORT__/);
  assert.doesNotMatch(checkout, /methods|update-order|langge/);
  assert.match(reject, /default_server/);
  assert.match(reject, /ssl_reject_handshake on/);
  assert.match(logrotate, /PikaBEpusdtAdapter\/runtime\.log/);
  assert.doesNotMatch(logrotate, /\/var\/www\/\*/);
  assert.doesNotMatch(logrotate, /^\s*su\s/m);
  assert.match(logrotate, /^\s*daily\s*$/m);
  assert.match(logrotate, /^\s*maxsize 10M\s*$/m);
  assert.doesNotMatch(logrotate, /^\s*size\s/m);
  for (const directive of [
    'rotate 7', 'start 1', 'noolddir', 'nodateext', 'missingok', 'notifempty',
    'noallowhardlink', 'compress', 'compresscmd /usr/bin/gzip',
    'uncompresscmd /usr/bin/gunzip', 'compressext .gz', 'compressoptions -6',
    'delaycompress', 'copytruncate',
  ]) {
    assert.ok(logrotate.split('\n').some(line => line.trim() === directive),
      `documented stable payment log family requires ${directive}`);
  }
  assert.match(logrotate, /create 0640 __WEB_USER__ __WEB_GROUP__/);
});
