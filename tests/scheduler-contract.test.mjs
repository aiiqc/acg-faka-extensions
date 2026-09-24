import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { chmodSync, existsSync, linkSync, mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import test from 'node:test';

const root = resolve(import.meta.dirname, '..');
const scheduler = join(root, 'scripts', 'scheduler.sh');
const read = path => readFileSync(join(root, path), 'utf8');

const makeMetadataTools = work => {
  const bin = join(work, 'test-bin');
  mkdirSync(bin, { recursive: true });
  const stat = join(bin, 'stat');
  const realpath = join(bin, 'realpath');
  const ls = join(bin, 'ls');
  const id = join(bin, 'id');
  writeFileSync(stat, `#!/bin/bash
set -euo pipefail
path="\${!#}"
if [[ -n "\${PIKA_TEST_CONFIG:-}" && "$path" == "$PIKA_TEST_CONFIG" ]]; then
  printf '%s\n' "\${PIKA_TEST_CONFIG_META:-33:33:640:1:64770:10984360:regular file}"
elif [[ -n "\${PIKA_TEST_FILE:-}" && "$path" == "$PIKA_TEST_FILE" ]]; then
  printf '%s\n' "\${PIKA_TEST_FILE_META:-0:0:755}"
elif [[ -n "\${PIKA_TEST_STATE:-}" && "$path" == "$PIKA_TEST_STATE" ]]; then
  printf '%s\n' "\${PIKA_TEST_STATE_META:-0:0:755}"
elif [[ -n "\${PIKA_TEST_RUNTIME:-}" && "$path" == "$PIKA_TEST_RUNTIME" ]]; then
  printf '%s\n' "\${PIKA_TEST_RUNTIME_META:-33:33:750}"
elif [[ -n "\${PIKA_TEST_CACHE:-}" && "$path" == "$PIKA_TEST_CACHE" ]]; then
  printf '%s\n' "\${PIKA_TEST_CACHE_META:-33:33:755}"
elif [[ -n "\${PIKA_TEST_CACHE_PARENT:-}" && "$path" == "$PIKA_TEST_CACHE_PARENT" ]]; then
  printf '%s\n' "\${PIKA_TEST_CACHE_PARENT_META:-33:33:755}"
elif [[ -n "\${PIKA_TEST_UNSAFE_ANCESTOR:-}" && "$path" == "$PIKA_TEST_UNSAFE_ANCESTOR" ]]; then
  printf '%s\n' "\${PIKA_TEST_UNSAFE_META:-0:0:777}"
else
  printf '0:0:755\n'
fi
`);
  writeFileSync(realpath, `#!/bin/bash
set -euo pipefail
printf '%s\n' "\${!#}"
`);
  writeFileSync(ls, `#!/bin/bash
set -euo pipefail
path="\${!#}"
if [[ -n "\${PIKA_TEST_ACL_PATH:-}" && "$path" == "$PIKA_TEST_ACL_PATH" ]]; then
  printf '%s\n' 'drwxr-x---+ 1 fixture fixture 0 Jan 1 00:00 runtime'
  exit 0
fi
if [[ -n "\${PIKA_TEST_CONFIG:-}" && "$path" == "$PIKA_TEST_CONFIG" && "\${PIKA_TEST_CONFIG_ACL:-0}" == 1 ]]; then
  printf '%s\n' '-rw-r-----+ 1 fixture fixture 0 Jan 1 00:00 config'
  exit 0
fi
exec /bin/ls "$@"
`);
  writeFileSync(id, `#!/bin/bash
set -euo pipefail
if [[ -n "\${PIKA_TEST_ID_USER:-}" && "$#" == 2 && "$2" == "$PIKA_TEST_ID_USER" ]]; then
  case "$1" in
    -u) printf '%s\n' "\${PIKA_TEST_ID_UID}"; exit 0 ;;
    -g) printf '%s\n' "\${PIKA_TEST_ID_GID}"; exit 0 ;;
  esac
fi
exec /usr/bin/id "$@"
`);
  chmodSync(stat, 0o755);
  chmodSync(realpath, 0o755);
  chmodSync(ls, 0o755);
  chmodSync(id, 0o755);
  return bin;
};

const makeFakeSystemctl = work => {
  const fake = join(work, 'fake-systemctl');
  writeFileSync(fake, `#!/bin/bash
set -euo pipefail
state="\${PIKA_FAKE_SYSTEMD_STATE:?}"
mkdir -p "$state"
printf '%s\n' "$*" >> "$state/calls.log"
command="$1"
shift
case "$command" in
  daemon-reload)
    if [[ "\${PIKA_FAKE_FAIL_FIRST_RELOAD:-0}" == 1 && ! -e "$state/reload-failed" ]]; then
      : > "$state/reload-failed"
      exit 51
    fi
    ;;
  enable)
    unit="\${!#}"
    if [[ "\${PIKA_FAKE_FAIL_CATALOG_ENABLE:-0}" == 1 && "$unit" == pika-catalog-worker-* ]]; then
      exit 41
    fi
    : > "$state/enabled-$unit"
    [[ " $* " == *' --now '* ]] && : > "$state/active-$unit"
    ;;
  disable)
    unit="\${!#}"
    rm -f -- "$state/enabled-$unit"
    [[ " $* " == *' --now '* ]] && rm -f -- "$state/active-$unit"
    ;;
  start)
    unit="\${!#}"
    : > "$state/active-$unit"
    ;;
  stop)
    unit="\${!#}"
    rm -f -- "$state/active-$unit"
    ;;
  is-enabled)
    unit="\${!#}"
    [[ -e "$state/enabled-$unit" ]]
    ;;
  is-active)
    unit="\${!#}"
    [[ -e "$state/active-$unit" ]]
    ;;
  *) exit 90 ;;
esac
`);
  chmodSync(fake, 0o755);
  return fake;
};

const runSchedulerFunction = (command, args, env) => spawnSync('/bin/bash', [
  '-c', `source "$1"; ${command}`, 'bash', scheduler, ...args,
], {
  encoding: 'utf8',
  env: { ...process.env, ...env },
});

test('scheduler CLI is root-only, installed-doctor gated and instance scoped', () => {
  const source = read('scripts/scheduler.sh');
  const main = source.slice(source.indexOf('pika_scheduler_main()'));
  assert.match(source, /install\|remove/);
  assert.match(source, /scheduler must run as root \(use sudo\)/);
  assert.ok(
    main.indexOf('pika_assert_trusted_release_tree')
      < main.indexOf("local site_arg=''"),
    'release verification must precede argument-derived site operations',
  );
  assert.ok(
    main.indexOf('scheduler must run as root (use sudo)')
      < main.indexOf("local site_arg=''"),
    'install and remove must reject non-root callers before site arguments are parsed',
  );
  assert.match(source, /--site-root, --web-user and --instance are required/);
  assert.match(source, /\^\[a-z0-9\]\[a-z0-9-\]\{0,62\}\$/);
  assert.match(source, /minutes='10'/);
  assert.match(source, /10#\$minutes >= 5 && 10#\$minutes <= 1440/);
  assert.match(source, /doctor\.sh" --site-root "\$site_root" --installed --php "\$php_bin"/);
  assert.match(source, /pika-supply-sync-\$\{instance\}\.service/);
  assert.match(source, /pika-supply-sync-\$\{instance\}\.timer/);
  assert.match(source, /pika-catalog-worker-\$\{instance\}\.service/);
  assert.match(source, /pika-catalog-worker-\$\{instance\}\.timer/);
  assert.match(source, /install\|remove\|run-sync\|run-catalog/);
  assert.match(source, /flock -n 9/);
});

test('root entry pins and freezes the trusted system command PATH before sourcing helpers', () => {
  const source = read('scripts/scheduler.sh');
  const trustedPath = "readonly PIKA_SCHEDULER_TRUSTED_PATH='/usr/sbin:/usr/bin:/sbin:/bin'";
  assert.ok(source.indexOf(trustedPath) >= 0);
  assert.ok(source.indexOf('PATH="$PIKA_SCHEDULER_TRUSTED_PATH"') < source.indexOf('source "${PIKA_SCHEDULER_SCRIPT_DIR}/lib.sh"'));
  assert.ok(source.indexOf("pika_scheduler_bootstrap_assert_root_path \"$PIKA_SCHEDULER_SCRIPT_DIR/lib.sh\"") < source.indexOf('source "${PIKA_SCHEDULER_SCRIPT_DIR}/lib.sh"'));
  assert.match(source, /scheduler release ancestor/);
  assert.match(source, /builtin unalias -a/);
  assert.match(source, /builtin compgen -A function/);
  assert.match(source, /builtin readonly PATH/);
  assert.match(source, /pika_scheduler_resolve_path_command "\$php_arg"/);
  assert.match(source, /PIKA_SCHEDULER_SYSTEMCTL="\$\(pika_scheduler_resolve_path_command systemctl\)"/);
});

test('trusted PHP rejects a root-looking executable below an unsafe tmp ancestor', () => {
  const work = mkdtempSync('/tmp/pika-scheduler-php-');
  const php = join(work, 'php');
  try {
    writeFileSync(php, '#!/bin/bash\nexit 0\n');
    chmodSync(php, 0o755);
    const tools = makeMetadataTools(work);
    const result = runSchedulerFunction(
      'pika_scheduler_assert_trusted_php_binary "$2"',
      [php],
      {
        PATH: `${tools}:/usr/bin:/bin`,
        PIKA_TEST_FILE: php,
        PIKA_TEST_FILE_META: '0:0:755',
        PIKA_TEST_UNSAFE_ANCESTOR: '/tmp',
        PIKA_TEST_UNSAFE_META: '0:0:1777',
      },
    );
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /trusted PHP ancestor.*(?:symbolic-link|group\/world-writable)/);
  } finally {
    rmSync(work, { recursive: true, force: true });
  }
});

test('trusted PHP rejects non-root ownership and group/world-writable executables', () => {
  const work = mkdtempSync(join(root, 'tests', '.scheduler-php-meta-'));
  const php = join(work, 'php');
  try {
    writeFileSync(php, '#!/bin/bash\nexit 0\n');
    chmodSync(php, 0o755);
    const tools = makeMetadataTools(work);
    const baseEnv = { PATH: `${tools}:/usr/bin:/bin`, PIKA_TEST_FILE: php };
    const wrongOwner = runSchedulerFunction(
      'pika_scheduler_assert_trusted_php_binary "$2"',
      [php],
      { ...baseEnv, PIKA_TEST_FILE_META: '1000:1000:755' },
    );
    assert.notEqual(wrongOwner.status, 0);
    assert.match(wrongOwner.stderr, /owned by root:root/);
    const writable = runSchedulerFunction(
      'pika_scheduler_assert_trusted_php_binary "$2"',
      [php],
      { ...baseEnv, PIKA_TEST_FILE_META: '0:0:777' },
    );
    assert.notEqual(writable.status, 0);
    assert.match(writable.stderr, /must not be group\/world writable/);
    const setuid = runSchedulerFunction(
      'pika_scheduler_assert_trusted_php_binary "$2"',
      [php],
      { ...baseEnv, PIKA_TEST_FILE_META: '0:0:4755' },
    );
    assert.notEqual(setuid.status, 0);
    assert.match(setuid.stderr, /must not have setuid, setgid, or sticky/);
  } finally {
    rmSync(work, { recursive: true, force: true });
  }
});

test('runtime and image cache enforce exact web ownership and modes', () => {
  const work = mkdtempSync(join(root, 'tests', '.scheduler-directory-meta-'));
  const state = join(work, 'state');
  const runtime = join(state, 'runtime');
  const site = join(work, 'site');
  const cacheParent = join(site, 'assets', 'cache');
  const cache = join(cacheParent, 'pika-supply-sync');
  try {
    mkdirSync(runtime, { recursive: true });
    mkdirSync(cache, { recursive: true });
    const tools = makeMetadataTools(work);
    const baseEnv = {
      PATH: `${tools}:/usr/bin:/bin`,
      PIKA_TEST_STATE: state,
      PIKA_TEST_RUNTIME: runtime,
      PIKA_TEST_CACHE: cache,
      PIKA_TEST_CACHE_PARENT: cacheParent,
    };
    const runtimeWrongMode = runSchedulerFunction(
      'pika_scheduler_assert_runtime_dir "$2" 33 33 "$3"',
      [runtime, state],
      { ...baseEnv, PIKA_TEST_RUNTIME_META: '33:33:770' },
    );
    assert.notEqual(runtimeWrongMode.status, 0);
    assert.match(runtimeWrongMode.stderr, /mode 0750/);
    const runtimeExact = runSchedulerFunction(
      'pika_scheduler_assert_runtime_dir "$2" 33 33 "$3"',
      [runtime, state],
      { ...baseEnv, PIKA_TEST_RUNTIME_META: '33:33:750' },
    );
    assert.equal(runtimeExact.status, 0, runtimeExact.stderr);
    const stateAcl = runSchedulerFunction(
      'pika_scheduler_assert_runtime_dir "$2" 33 33 "$3"',
      [runtime, state],
      { ...baseEnv, PIKA_TEST_RUNTIME_META: '33:33:750', PIKA_TEST_ACL_PATH: state },
    );
    assert.notEqual(stateAcl.status, 0);
    assert.match(stateAcl.stderr, /external site state directory must not have an extended POSIX ACL/);
    const runtimeAcl = runSchedulerFunction(
      'pika_scheduler_assert_runtime_dir "$2" 33 33 "$3"',
      [runtime, state],
      { ...baseEnv, PIKA_TEST_RUNTIME_META: '33:33:750', PIKA_TEST_ACL_PATH: runtime },
    );
    assert.notEqual(runtimeAcl.status, 0);
    assert.match(runtimeAcl.stderr, /external runtime directory must not have an extended POSIX ACL/);

    const cacheWrongMode = runSchedulerFunction(
      'pika_scheduler_assert_image_cache "$2" 33 33 "$3"',
      [cache, site],
      { ...baseEnv, PIKA_TEST_CACHE_META: '33:33:750' },
    );
    assert.notEqual(cacheWrongMode.status, 0);
    assert.match(cacheWrongMode.stderr, /mode 0755/);
    const cacheExact = runSchedulerFunction(
      'pika_scheduler_assert_image_cache "$2" 33 33 "$3"',
      [cache, site],
      { ...baseEnv, PIKA_TEST_CACHE_META: '33:33:755' },
    );
    assert.equal(cacheExact.status, 0, cacheExact.stderr);

    const cacheAcl = runSchedulerFunction(
      'pika_scheduler_assert_image_cache "$2" 33 33 "$3"',
      [cache, site],
      {
        ...baseEnv,
        PIKA_TEST_CACHE_META: '33:33:755',
        PIKA_TEST_ACL_PATH: cache,
      },
    );
    assert.notEqual(cacheAcl.status, 0);
    assert.match(cacheAcl.stderr, /image cache must not have an extended POSIX ACL/);

    const unsafeParent = runSchedulerFunction(
      'pika_scheduler_assert_image_cache "$2" 33 33 "$3"',
      [cache, site],
      {
        ...baseEnv,
        PIKA_TEST_CACHE_META: '33:33:755',
        PIKA_TEST_CACHE_PARENT_META: '0:0:775',
      },
    );
    assert.notEqual(unsafeParent.status, 0);
    assert.match(unsafeParent.stderr, /assets cache parent.*group\/world-writable/);
  } finally {
    rmSync(work, { recursive: true, force: true });
  }
});

test('image cache rejects a symbolic-link assets cache parent', () => {
  const work = mkdtempSync(join(root, 'tests', '.scheduler-cache-link-'));
  const site = join(work, 'site');
  const realCacheParent = join(site, 'assets', 'real-cache');
  const linkedCacheParent = join(site, 'assets', 'cache');
  const cache = join(linkedCacheParent, 'pika-supply-sync');
  try {
    mkdirSync(join(realCacheParent, 'pika-supply-sync'), { recursive: true });
    symlinkSync(realCacheParent, linkedCacheParent);
    const tools = makeMetadataTools(work);
    const result = runSchedulerFunction(
      'pika_scheduler_assert_image_cache "$2" 33 33 "$3"',
      [cache, site],
      {
        PATH: `${tools}:/usr/bin:/bin`,
        PIKA_TEST_CACHE: cache,
        PIKA_TEST_CACHE_META: '33:33:755',
        PIKA_TEST_CACHE_PARENT: linkedCacheParent,
      },
    );
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /parent is missing or unsafe/);
  } finally {
    rmSync(work, { recursive: true, force: true });
  }
});

test('site config cache accepts only the exact canonical 0640 web-owned file', () => {
  const work = mkdtempSync(join(root, 'tests', '.scheduler-site-config-'));
  const site = join(work, 'site');
  const runtime = join(site, 'runtime');
  const config = join(runtime, 'config');
  const target = join(work, 'target');
  const hardlink = join(runtime, 'config-hardlink');
  try {
    mkdirSync(runtime, { recursive: true });
    writeFileSync(config, 'fixture');
    writeFileSync(target, 'target');
    const tools = makeMetadataTools(work);
    const baseEnv = {
      PATH: `${tools}:/usr/bin:/bin`,
      PIKA_TEST_CONFIG: config,
      PIKA_TEST_CONFIG_META: '33:33:640:1:64770:10984360:regular file',
    };
    const run = env => runSchedulerFunction(
      'pika_scheduler_assert_site_config_cache "$2" 33 33 "$3"',
      [config, site],
      { ...baseEnv, ...env },
    );

    const exact = run({});
    assert.equal(exact.status, 0, exact.stderr);

    for (const [metadata, message] of [
      ['34:33:640:1:64770:10984360:regular file', /owned by --web-user/],
      ['33:34:640:1:64770:10984360:regular file', /owned by --web-user/],
      ['33:33:600:1:64770:10984360:regular file', /mode 0640/],
      ['33:33:660:1:64770:10984360:regular file', /mode 0640/],
      ['33:33:640:2:64770:10984360:regular file', /exactly one hard link/],
      ['33:33:640:1:64770:10984360:directory', /not a regular file/],
    ]) {
      const rejected = run({ PIKA_TEST_CONFIG_META: metadata });
      assert.notEqual(rejected.status, 0);
      assert.match(rejected.stderr, message);
    }

    linkSync(config, hardlink);
    const linked = run({ PIKA_TEST_CONFIG_META: '33:33:640:2:64770:10984360:regular file' });
    assert.notEqual(linked.status, 0);
    assert.match(linked.stderr, /exactly one hard link/);
    rmSync(hardlink);

    const acl = run({ PIKA_TEST_CONFIG_ACL: '1' });
    assert.notEqual(acl.status, 0);
    assert.match(acl.stderr, /extended POSIX ACL/);

    const unsafeParent = run({
      PIKA_TEST_UNSAFE_ANCESTOR: runtime,
      PIKA_TEST_UNSAFE_META: '33:33:770',
    });
    assert.notEqual(unsafeParent.status, 0);
    assert.match(unsafeParent.stderr, /site config cache parent.*group\/world-writable/);

    const parentAcl = run({ PIKA_TEST_ACL_PATH: runtime });
    assert.notEqual(parentAcl.status, 0);
    assert.match(parentAcl.stderr, /site config cache parent.*extended POSIX ACL/);

    const identityChanged = runSchedulerFunction(
      'pika_scheduler_assert_site_config_cache "$2" 33 33 "$3" 64770 10984361',
      [config, site],
      baseEnv,
    );
    assert.notEqual(identityChanged.status, 0);
    assert.match(identityChanged.stderr, /device or inode changed/);

    const deviceChanged = runSchedulerFunction(
      'pika_scheduler_assert_site_config_cache "$2" 33 33 "$3" 64771 10984360',
      [config, site],
      baseEnv,
    );
    assert.notEqual(deviceChanged.status, 0);
    assert.match(deviceChanged.stderr, /device or inode changed/);

    const exactIdentity = runSchedulerFunction(
      'pika_scheduler_assert_site_config_cache "$2" 33 33 "$3" 64770 10984360',
      [config, site],
      baseEnv,
    );
    assert.equal(exactIdentity.status, 0, exactIdentity.stderr);

    rmSync(config);
    const missing = run({});
    assert.notEqual(missing.status, 0);
    assert.match(missing.stderr, /missing, not a regular file, or a symbolic link/);

    symlinkSync(target, config);
    const symbolic = run({});
    assert.notEqual(symbolic.status, 0);
    assert.match(symbolic.stderr, /missing, not a regular file, or a symbolic link/);
    rmSync(config);

    mkdirSync(config);
    const nonRegular = run({});
    assert.notEqual(nonRegular.status, 0);
    assert.match(nonRegular.stderr, /missing, not a regular file, or a symbolic link/);
    rmSync(config, { recursive: true });

    const realRuntime = join(site, 'real-runtime');
    mkdirSync(realRuntime);
    writeFileSync(join(realRuntime, 'config'), 'fixture');
    rmSync(runtime, { recursive: true });
    symlinkSync(realRuntime, runtime);
    const ancestorLink = run({});
    assert.notEqual(ancestorLink.status, 0);
    assert.match(ancestorLink.stderr, /site config cache.*(?:not canonical|symbolic[- ]link ancestor|symbolic link)/);
  } finally {
    rmSync(work, { recursive: true, force: true });
  }
});

test('internal runners fail closed and exec only the bounded PHP commands', t => {
  const uid = Number(execFileSync('/usr/bin/id', ['-u'], { encoding: 'utf8' }).trim());
  const gid = Number(execFileSync('/usr/bin/id', ['-g'], { encoding: 'utf8' }).trim());
  const webUser = execFileSync('/usr/bin/id', ['-un'], { encoding: 'utf8' }).trim();
  if (uid === 0) {
    t.skip('non-root runner behavior requires a non-root host identity');
    return;
  }
  const work = mkdtempSync(join(root, 'tests', '.scheduler-run-sync-'));
  const site = join(work, 'site');
  const runtime = join(site, 'runtime');
  const sync = join(site, 'local-extensions', 'extensions', 'PikaSupplySync', 'bin', 'sync.php');
  const catalog = join(site, 'local-extensions', 'extensions', 'PikaCatalogHub', 'bin', 'worker.php');
  const php = join(work, 'php-fixture');
  const phpLink = join(work, 'php-link');
  const marker = join(work, 'php-executed');
  try {
    mkdirSync(runtime, { recursive: true });
    mkdirSync(join(site, 'local-extensions', 'extensions', 'PikaSupplySync', 'bin'), { recursive: true });
    mkdirSync(join(site, 'local-extensions', 'extensions', 'PikaCatalogHub', 'bin'), { recursive: true });
    writeFileSync(join(runtime, 'config'), 'fixture');
    writeFileSync(sync, '<?php // fixture');
    writeFileSync(catalog, '<?php // fixture');
    writeFileSync(php, `#!/bin/bash
set -euo pipefail
printf 'PHP_EXEC|%s' "$#"
printf '|%s' "$@"
printf '|%s\n' "$(umask)"
: > "$PIKA_TEST_EXEC_MARKER"
`);
    chmodSync(php, 0o755);
    symlinkSync(php, phpLink);
    const tools = makeMetadataTools(work);
    const config = join(runtime, 'config');
    const baseEnv = {
      PATH: `${tools}:/usr/bin:/bin`,
      PIKA_TEST_CONFIG: config,
      PIKA_TEST_CONFIG_META: `${uid}:${gid}:640:1:64770:10984360:regular file`,
      PIKA_TEST_FILE: php,
      PIKA_TEST_FILE_META: '0:0:755',
      PIKA_TEST_ID_USER: webUser,
      PIKA_TEST_ID_UID: String(uid),
      PIKA_TEST_ID_GID: String(gid),
      PIKA_TEST_EXEC_MARKER: marker,
    };
    const exactArgs = [
      `--site-root=${site}`,
      `--web-user=${webUser}`,
      '--expected-dev=64770',
      '--expected-inode=10984360',
      `--php=${php}`,
    ];
    const run = (args, env = {}) => runSchedulerFunction(
      'pika_assert_safe_site_root() { :; }; pika_scheduler_run_sync_main "${@:2}"',
      args,
      { ...baseEnv, ...env },
    );

    const missing = run(exactArgs.slice(0, -1));
    assert.notEqual(missing.status, 0);
    assert.match(missing.stderr, /requires site root, web user, device, inode and PHP CLI/);

    const duplicate = run([...exactArgs, exactArgs[0]]);
    assert.notEqual(duplicate.status, 0);
    assert.match(duplicate.stderr, /--site-root must be provided exactly once/);

    const unknown = run([...exactArgs, '--unexpected=value']);
    assert.notEqual(unknown.status, 0);
    assert.match(unknown.stderr, /unknown run-sync argument/);

    const mismatch = run(exactArgs, { PIKA_TEST_ID_UID: String(uid + 1) });
    assert.notEqual(mismatch.status, 0);
    assert.match(mismatch.stderr, /identity does not match --web-user/);

    const drift = run(exactArgs.map(value => value === '--expected-inode=10984360'
      ? '--expected-inode=10984361' : value));
    assert.notEqual(drift.status, 0);
    assert.match(drift.stderr, /device or inode changed/);
    assert.equal(existsSync(marker), false, 'failed gates must not execute PHP');

    const linkedPhp = run(exactArgs.map(value => value === `--php=${php}` ? `--php=${phpLink}` : value));
    assert.notEqual(linkedPhp.status, 0);
    assert.match(linkedPhp.stderr, /PHP CLI.*(?:canonical|regular file)/);
    assert.equal(existsSync(marker), false, 'unsafe PHP path must not execute');

    const exact = run(exactArgs);
    assert.equal(exact.status, 0, exact.stderr);
    assert.match(exact.stdout, new RegExp(`PHP_EXEC\\|2\\|${sync.replaceAll('/', '\\/')}\\|--root=${site.replaceAll('/', '\\/')}\\|0027`));
    assert.equal(existsSync(marker), true, 'exact runner path must exec the PHP fixture');

    rmSync(marker);
    const catalogRun = runSchedulerFunction(
      'pika_assert_safe_site_root() { :; }; pika_scheduler_run_catalog_main "${@:2}"',
      exactArgs,
      baseEnv,
    );
    assert.equal(catalogRun.status, 0, catalogRun.stderr);
    assert.match(catalogRun.stdout, new RegExp(`PHP_EXEC\\|3\\|${catalog.replaceAll('/', '\\/')}\\|--root=${site.replaceAll('/', '\\/')}\\|--batch=20\\|0027`));
    assert.equal(existsSync(marker), true, 'CatalogHub runner must exec the fixed worker batch');
  } finally {
    rmSync(work, { recursive: true, force: true });
  }
});

test('direct scheduler execution ignores an inherited malicious PATH', () => {
  const work = mkdtempSync(join(root, 'tests', '.scheduler-path-poison-'));
  const marker = join(work, 'executed');
  try {
    for (const command of ['dirname', 'ls', 'realpath', 'stat']) {
      const file = join(work, command);
      writeFileSync(file, `#!/bin/bash\n: > "${marker}"\nexit 99\n`);
      chmodSync(file, 0o755);
    }
    const result = spawnSync(scheduler, ['run-sync'], {
      encoding: 'utf8',
      env: { ...process.env, PATH: work },
    });
    assert.notEqual(result.status, 0);
    assert.equal(existsSync(marker), false, 'scheduler executed a command from inherited PATH');
  } finally {
    rmSync(work, { recursive: true, force: true });
  }
});

test('run-sync rejects root before reading a site', t => {
  const docker = spawnSync('docker', ['image', 'inspect', 'php:8.3-cli'], { encoding: 'utf8' });
  if (docker.error?.code === 'ENOENT' || docker.status !== 0) {
    t.skip('the local PHP integration image is unavailable');
    return;
  }
  const script = `set -euo pipefail
release=/opt/pika-runner-test/release
mkdir -p "$release"
for entry in bridge manager extensions themes payment-adapters scripts packaging; do
  cp -R --no-preserve=ownership "/release/$entry" "$release/"
done
cp --no-preserve=ownership /release/compatibility.json /release/release.json "$release/"
chown -R 0:0 /opt/pika-runner-test
"$release/scripts/scheduler.sh" run-sync \
  --site-root=/does-not-exist \
  --web-user=www-data \
  --expected-dev=1 \
  --expected-inode=1 \
  --php=/usr/local/bin/php
`;
  const result = spawnSync('docker', [
    'run', '--rm', '--volume', `${root}:/release:ro`,
    '--tmpfs', '/opt/pika-runner-test:rw,nosuid,nodev,exec,mode=0755,size=64m',
    'php:8.3-cli', '/bin/bash', '-c', script,
  ], { encoding: 'utf8' });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /run-sync must execute as the non-root site web identity/);
  assert.doesNotMatch(result.stderr, /site root does not exist/);
});

test('service and timer templates enforce the least-privilege scheduler boundary', () => {
  const service = read('packaging/systemd/pika-supply-sync.service.in');
  const timer = read('packaging/systemd/pika-supply-sync.timer.in');
  const catalogService = read('packaging/systemd/pika-catalog-worker.service.in');
  const catalogTimer = read('packaging/systemd/pika-catalog-worker.timer.in');
  assert.match(service, /^User=@@PIKA_WEB_USER@@$/m);
  assert.match(service, /^Group=@@PIKA_WEB_GROUP@@$/m);
  assert.match(service, /^WorkingDirectory=@@PIKA_SITE_ROOT_SCALAR@@$/m);
  assert.doesNotMatch(service, /^ExecStartPre=/m);
  assert.match(service, /^ExecStart=@@PIKA_SCHEDULER_Q@@ run-sync @@PIKA_SITE_ROOT_ARG_Q@@ @@PIKA_WEB_USER_ARG_Q@@ @@PIKA_CONFIG_DEV_ARG_Q@@ @@PIKA_CONFIG_INODE_ARG_Q@@ @@PIKA_PHP_ARG_Q@@$/m);
  assert.match(service, /^TimeoutStartSec=8min$/m);
  assert.match(service, /^ProtectSystem=strict$/m);
  assert.match(service, /^ProtectHome=read-only$/m);
  assert.match(service, /^NoNewPrivileges=true$/m);
  assert.match(service, /^CapabilityBoundingSet=$/m);
  assert.match(service, /^ReadWritePaths=@@PIKA_RUNTIME_Q@@ @@PIKA_IMAGE_CACHE_Q@@ @@PIKA_SITE_CONFIG_Q@@$/m);
  assert.match(timer, /^OnActiveSec=@@PIKA_MINUTES@@min$/m);
  assert.match(timer, /^OnUnitInactiveSec=@@PIKA_MINUTES@@min$/m);
  assert.match(timer, /^Persistent=true$/m);
  assert.match(timer, /^WantedBy=timers\.target$/m);

  assert.match(catalogService, /^User=@@PIKA_WEB_USER@@$/m);
  assert.match(catalogService, /^Group=@@PIKA_WEB_GROUP@@$/m);
  assert.match(catalogService, /^WorkingDirectory=@@PIKA_SITE_ROOT_SCALAR@@$/m);
  assert.doesNotMatch(catalogService, /^ExecStartPre=/m);
  assert.match(catalogService, /^ExecStart=@@PIKA_SCHEDULER_Q@@ run-catalog @@PIKA_SITE_ROOT_ARG_Q@@ @@PIKA_WEB_USER_ARG_Q@@ @@PIKA_CONFIG_DEV_ARG_Q@@ @@PIKA_CONFIG_INODE_ARG_Q@@ @@PIKA_PHP_ARG_Q@@$/m);
  assert.match(catalogService, /^TimeoutStartSec=8min$/m);
  for (const marker of [
    'ProtectSystem=strict', 'ProtectHome=read-only', 'NoNewPrivileges=true',
    'PrivateDevices=true', 'PrivateTmp=true', 'RestrictNamespaces=true',
    'RestrictSUIDSGID=true', 'LockPersonality=true', 'CapabilityBoundingSet=',
  ]) {
    assert.match(catalogService, new RegExp(`^${marker.replaceAll('=', '\\=')}$`, 'm'));
  }
  assert.match(catalogService, /^ReadWritePaths=@@PIKA_RUNTIME_Q@@ @@PIKA_IMAGE_CACHE_Q@@ @@PIKA_SITE_CONFIG_Q@@$/m);
  assert.match(catalogTimer, /^OnActiveSec=1min$/m);
  assert.match(catalogTimer, /^OnUnitInactiveSec=1min$/m);
  assert.match(catalogTimer, /about one minute after the prior run becomes inactive/);
  assert.match(read('scripts/scheduler.sh'), /wakes about one minute after the prior run becomes inactive/);
  assert.doesNotMatch(catalogTimer, /every minute/);
  assert.match(catalogTimer, /^AccuracySec=15s$/m);
  assert.match(catalogTimer, /^Persistent=true$/m);
  assert.match(catalogTimer, /^WantedBy=timers\.target$/m);
});

test('user documentation locks the native protocol dropdown and S0 disk-space gate', () => {
  const protocolDocuments = [
    read('README.md'),
    read('docs/USER_INSTALL.md'),
    read('docs/TROUBLESHOOTING.md'),
    read('extensions/PikaCatalogHub/Wiki/README.md'),
  ];
  const protocols = [
    ['`0`', '异次元(V3.1.2 重构后全新版)'],
    ['`2`', '异次元(V3.1.1 之前旧版)'],
    ['`1`', '萌次元(V4.0)'],
  ];
  for (const document of protocolDocuments) {
    let prior = -1;
    for (const [value, label] of protocols) {
      const line = document.split('\n').find(candidate => candidate.includes(value) && candidate.includes(label));
      assert.ok(line, `missing native protocol pair ${value} ${label}`);
      const index = document.indexOf(label);
      assert.ok(index > prior, `native protocol label is out of order: ${label}`);
      prior = index;
    }
  }

  const install = read('docs/USER_INSTALL.md');
  assert.match(install, /16 MiB × 64 = 1,073,741,824 字节/);
  assert.match(install, /sudo df -B1 --output=source,size,used,avail,pcent,target/);
  assert.match(install, /du -sb/);
  assert.match(install, /完整灾备目标/);
  assert.match(install, /图片缓存增长余量/);
  assert.match(install, /保持 503、保持 timer 停用并停止/);
  assert.match(install, /容量门不会触发额外自动清理/);
  assert.match(install, /不得为了过门自动删除任务、快照、备份、图片缓存或其他站点数据/);
});

test('rendered units safely quote paths and call the installed SupplySync CLI', () => {
  const work = mkdtempSync(join(tmpdir(), 'pika-scheduler-render-'));
  const servicePath = join(work, 'pika-supply-sync-s0.service');
  const timerPath = join(work, 'pika-supply-sync-s0.timer');
  const catalogServicePath = join(work, 'pika-catalog-worker-s0.service');
  const catalogTimerPath = join(work, 'pika-catalog-worker-s0.timer');
  const php = join(work, 'php-fixture');
  const siteRoot = '/srv/acg faka/quoted"percent%';
  const runtimeRoot = '/var/lib/pika-local-extensions/sites/0123456789abcdef/runtime';
  try {
    writeFileSync(php, `#!/usr/bin/env node
const [flag, code, templatePath, ...pairs] = process.argv.slice(2);
if (flag !== '-r' || !code || pairs.length % 2 !== 0) process.exit(2);
let template = require('node:fs').readFileSync(templatePath, 'utf8');
for (let index = 0; index < pairs.length; index += 2) {
  if (!template.includes(pairs[index])) process.exit(3);
  template = template.split(pairs[index]).join(pairs[index + 1]);
}
if (/@@PIKA_[A-Z_]+@@/.test(template)) process.exit(4);
process.stdout.write(template);
`);
    chmodSync(php, 0o755);
    execFileSync('bash', [
      '-c',
      'source "$1"; pika_scheduler_render_service "$2" "$3" s0 "$4" www-data 33 "$6" 64770 10984360; pika_scheduler_render_timer "$2" "$5" s0 "$4" www-data 10 pika-supply-sync-s0.service; pika_scheduler_render_catalog_service "$2" "$7" s0 "$4" www-data 33 "$6" 64770 10984360; pika_scheduler_render_catalog_timer "$2" "$8" s0 "$4" www-data pika-catalog-worker-s0.service',
      'bash', scheduler, php, servicePath, siteRoot, timerPath, runtimeRoot, catalogServicePath, catalogTimerPath,
    ]);
    const service = readFileSync(servicePath, 'utf8');
    const timer = readFileSync(timerPath, 'utf8');
    const catalogService = readFileSync(catalogServicePath, 'utf8');
    const catalogTimer = readFileSync(catalogTimerPath, 'utf8');
    assert.match(service, /WorkingDirectory=\/srv\/acg faka\/quoted"percent%%/);
    assert.doesNotMatch(service, /WorkingDirectory="/);
    assert.match(service, /ExecStart=".*\/scripts\/scheduler\.sh" run-sync "--site-root=\/srv\/acg faka\/quoted\\"percent%%" "--web-user=www-data" "--expected-dev=64770" "--expected-inode=10984360" "--php=/);
    assert.match(service, /ReadWritePaths="\/var\/lib\/pika-local-extensions\/sites\/0123456789abcdef\/runtime" "\/srv\/acg faka\/quoted\\"percent%%\/assets\/cache\/pika-supply-sync" "\/srv\/acg faka\/quoted\\"percent%%\/runtime\/config"/);
    assert.doesNotMatch(service, /\/runtime\/local-extensions/);
    assert.match(timer, /^Unit=pika-supply-sync-s0\.service$/m);
    assert.match(timer, /^OnUnitInactiveSec=10min$/m);
    assert.match(catalogService, /ExecStart=".*\/scripts\/scheduler\.sh" run-catalog "--site-root=\/srv\/acg faka\/quoted\\"percent%%" "--web-user=www-data" "--expected-dev=64770" "--expected-inode=10984360" "--php=/);
    assert.match(catalogService, /ReadWritePaths="\/var\/lib\/pika-local-extensions\/sites\/0123456789abcdef\/runtime" "\/srv\/acg faka\/quoted\\"percent%%\/assets\/cache\/pika-supply-sync" "\/srv\/acg faka\/quoted\\"percent%%\/runtime\/config"/);
    assert.match(catalogTimer, /^Unit=pika-catalog-worker-s0\.service$/m);
    assert.match(catalogTimer, /^OnUnitInactiveSec=1min$/m);
    assert.doesNotMatch(service + timer + catalogService + catalogTimer, /@@PIKA_/);
  } finally {
    rmSync(work, { recursive: true, force: true });
  }
});

test('unsafe instance IDs fail closed before any unit path can be formed', () => {
  const result = spawnSync('bash', [
    '-c',
    'source "$1"; pika_scheduler_validate_instance "$2"',
    'bash', scheduler, 's0;touch-pwned',
  ], { encoding: 'utf8' });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /--instance must match/);
});

test('systemd values reject dollar expansion syntax', () => {
  const ordinaryPath = execFileSync('bash', [
    '-c',
    'source "$1"; pika_scheduler_systemd_scalar_path "$2"',
    'bash', scheduler, '/var/www/s0-next',
  ], { encoding: 'utf8' });
  assert.equal(ordinaryPath, '/var/www/s0-next');

  const result = spawnSync('bash', [
    '-c',
    'source "$1"; pika_scheduler_systemd_quote "$2"',
    'bash', scheduler, '/srv/$UNTRUSTED/site',
  ], { encoding: 'utf8' });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /unsupported dollar expansion/);

  const pathResult = spawnSync('bash', [
    '-c',
    'source "$1"; pika_scheduler_systemd_scalar_path "$2"',
    'bash', scheduler, '/srv/$UNTRUSTED/site',
  ], { encoding: 'utf8' });
  assert.notEqual(pathResult.status, 0);
  assert.match(pathResult.stderr, /unsupported dollar expansion/);

  for (const [value, message] of [
    ['/srv/trailing ', /must not end in whitespace/],
    ['/srv/trailing\\', /must not end in a backslash/],
    ['/srv/control\npath', /contains a control character/],
  ]) {
    const unsafeResult = spawnSync('bash', [
      '-c',
      'source "$1"; pika_scheduler_systemd_scalar_path "$2"',
      'bash', scheduler, value,
    ], { encoding: 'utf8' });
    assert.notEqual(unsafeResult.status, 0);
    assert.match(unsafeResult.stderr, message);
  }
});

test('one install upgrades an exact SupplySync-only scheduler with the CatalogHub pair', () => {
  const work = mkdtempSync(join(root, 'tests', '.scheduler-pair-upgrade-'));
  const state = join(work, 'systemd-state');
  const fakeSystemctl = makeFakeSystemctl(work);
  const upgradeScript = `source "$1"
work="$2"
PIKA_SCHEDULER_SYSTEMCTL="$3"
instance='s0'
web_user='www-data'
PIKA_SCHEDULER_SERVICE_UNIT='pika-supply-sync-s0.service'
PIKA_SCHEDULER_TIMER_UNIT='pika-supply-sync-s0.timer'
PIKA_SCHEDULER_CATALOG_SERVICE_UNIT='pika-catalog-worker-s0.service'
PIKA_SCHEDULER_CATALOG_TIMER_UNIT='pika-catalog-worker-s0.timer'
PIKA_SCHEDULER_SERVICE_TARGET="$work/$PIKA_SCHEDULER_SERVICE_UNIT"
PIKA_SCHEDULER_TIMER_TARGET="$work/$PIKA_SCHEDULER_TIMER_UNIT"
PIKA_SCHEDULER_CATALOG_SERVICE_TARGET="$work/$PIKA_SCHEDULER_CATALOG_SERVICE_UNIT"
PIKA_SCHEDULER_CATALOG_TIMER_TARGET="$work/$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"
PIKA_SCHEDULER_STAGE_DIR="$work/stage"
mkdir -p "$PIKA_SCHEDULER_STAGE_DIR"
for unit in "$PIKA_SCHEDULER_SERVICE_UNIT" "$PIKA_SCHEDULER_TIMER_UNIT" "$PIKA_SCHEDULER_CATALOG_SERVICE_UNIT" "$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"; do
  printf 'staged:%s\n' "$unit" > "$PIKA_SCHEDULER_STAGE_DIR/$unit"
done
cp -- "$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_SERVICE_UNIT" "$PIKA_SCHEDULER_SERVICE_TARGET"
cp -- "$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_TIMER_UNIT" "$PIKA_SCHEDULER_TIMER_TARGET"
pika_scheduler_check_managed_unit() { :; }
pika_scheduler_assert_runtime_dir() { :; }
pika_scheduler_assert_site_config_cache() { :; }
pika_scheduler_assert_secure_directory_chain() { :; }
pika_scheduler_assert_image_cache() { :; }
pika_scheduler_atomic_install() { cp -- "$1" "$2"; }
pika_info() { :; }
trap pika_scheduler_on_exit EXIT
pika_scheduler_install \
  "$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_SERVICE_UNIT" \
  "$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_TIMER_UNIT" \
  "$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_CATALOG_SERVICE_UNIT" \
  "$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_CATALOG_TIMER_UNIT" \
  '/srv/s0' 33 33 '/var/lib/pika/sites/s0' '/var/lib/pika/sites/s0/runtime' 64770 10984360
`;
  try {
    mkdirSync(state);
    const result = spawnSync('/bin/bash', ['-c', upgradeScript, 'bash', scheduler, work, fakeSystemctl], {
      encoding: 'utf8',
      env: { ...process.env, PIKA_FAKE_SYSTEMD_STATE: state },
    });
    assert.equal(result.status, 0, result.stderr);
    for (const unit of [
      'pika-supply-sync-s0.service', 'pika-supply-sync-s0.timer',
      'pika-catalog-worker-s0.service', 'pika-catalog-worker-s0.timer',
    ]) {
      assert.equal(readFileSync(join(work, unit), 'utf8'), `staged:${unit}\n`);
    }
    assert.equal(existsSync(join(state, 'enabled-pika-supply-sync-s0.timer')), true);
    assert.equal(existsSync(join(state, 'enabled-pika-catalog-worker-s0.timer')), true);
  } finally {
    rmSync(work, { recursive: true, force: true });
  }
});

test('four-unit install rolls back SupplySync and CatalogHub together when CatalogHub enable fails', () => {
  const work = mkdtempSync(join(root, 'tests', '.scheduler-install-rollback-'));
  const state = join(work, 'systemd-state');
  const fakeSystemctl = makeFakeSystemctl(work);
  const installScript = `source "$1"
work="$2"
PIKA_SCHEDULER_SYSTEMCTL="$3"
instance='s0'
web_user='www-data'
PIKA_SCHEDULER_SERVICE_UNIT='pika-supply-sync-s0.service'
PIKA_SCHEDULER_TIMER_UNIT='pika-supply-sync-s0.timer'
PIKA_SCHEDULER_CATALOG_SERVICE_UNIT='pika-catalog-worker-s0.service'
PIKA_SCHEDULER_CATALOG_TIMER_UNIT='pika-catalog-worker-s0.timer'
PIKA_SCHEDULER_SERVICE_TARGET="$work/$PIKA_SCHEDULER_SERVICE_UNIT"
PIKA_SCHEDULER_TIMER_TARGET="$work/$PIKA_SCHEDULER_TIMER_UNIT"
PIKA_SCHEDULER_CATALOG_SERVICE_TARGET="$work/$PIKA_SCHEDULER_CATALOG_SERVICE_UNIT"
PIKA_SCHEDULER_CATALOG_TIMER_TARGET="$work/$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"
PIKA_SCHEDULER_STAGE_DIR="$work/stage"
mkdir -p "$PIKA_SCHEDULER_STAGE_DIR"
for unit in "$PIKA_SCHEDULER_SERVICE_UNIT" "$PIKA_SCHEDULER_TIMER_UNIT" "$PIKA_SCHEDULER_CATALOG_SERVICE_UNIT" "$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"; do
  printf 'staged:%s\n' "$unit" > "$PIKA_SCHEDULER_STAGE_DIR/$unit"
done
pika_scheduler_assert_runtime_dir() { :; }
pika_scheduler_assert_site_config_cache() { :; }
pika_scheduler_assert_secure_directory_chain() { :; }
pika_scheduler_assert_image_cache() { :; }
pika_scheduler_atomic_install() { cp -- "$1" "$2"; }
pika_info() { :; }
trap pika_scheduler_on_exit EXIT
pika_scheduler_install \
  "$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_SERVICE_UNIT" \
  "$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_TIMER_UNIT" \
  "$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_CATALOG_SERVICE_UNIT" \
  "$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_CATALOG_TIMER_UNIT" \
  '/srv/s0' 33 33 '/var/lib/pika/sites/s0' '/var/lib/pika/sites/s0/runtime' 64770 10984360
`;
  try {
    mkdirSync(state);
    const result = spawnSync('/bin/bash', ['-c', installScript, 'bash', scheduler, work, fakeSystemctl], {
      encoding: 'utf8',
      env: {
        ...process.env,
        PIKA_FAKE_SYSTEMD_STATE: state,
        PIKA_FAKE_FAIL_CATALOG_ENABLE: '1',
      },
    });
    assert.notEqual(result.status, 0, 'CatalogHub enable failure must fail the install');
    for (const unit of [
      'pika-supply-sync-s0.service', 'pika-supply-sync-s0.timer',
      'pika-catalog-worker-s0.service', 'pika-catalog-worker-s0.timer',
    ]) {
      assert.equal(existsSync(join(work, unit)), false, `rollback left ${unit}`);
    }
    const calls = readFileSync(join(state, 'calls.log'), 'utf8');
    assert.match(calls, /enable --now pika-supply-sync-s0\.timer/);
    assert.match(calls, /enable --now pika-catalog-worker-s0\.timer/);
    assert.match(calls, /disable --now pika-supply-sync-s0\.timer/);
    assert.match(calls, /disable --now pika-catalog-worker-s0\.timer/);
  } finally {
    rmSync(work, { recursive: true, force: true });
  }
});

test('four-unit remove restores exact files and both timer states when daemon-reload fails', () => {
  const work = mkdtempSync(join(root, 'tests', '.scheduler-remove-rollback-'));
  const state = join(work, 'systemd-state');
  const fakeSystemctl = makeFakeSystemctl(work);
  const units = [
    'pika-supply-sync-s0.service', 'pika-supply-sync-s0.timer',
    'pika-catalog-worker-s0.service', 'pika-catalog-worker-s0.timer',
  ];
  const removeScript = `source "$1"
work="$2"
PIKA_SCHEDULER_SYSTEMCTL="$3"
instance='s0'
web_user='www-data'
PIKA_SCHEDULER_SERVICE_UNIT='pika-supply-sync-s0.service'
PIKA_SCHEDULER_TIMER_UNIT='pika-supply-sync-s0.timer'
PIKA_SCHEDULER_CATALOG_SERVICE_UNIT='pika-catalog-worker-s0.service'
PIKA_SCHEDULER_CATALOG_TIMER_UNIT='pika-catalog-worker-s0.timer'
PIKA_SCHEDULER_SERVICE_TARGET="$work/$PIKA_SCHEDULER_SERVICE_UNIT"
PIKA_SCHEDULER_TIMER_TARGET="$work/$PIKA_SCHEDULER_TIMER_UNIT"
PIKA_SCHEDULER_CATALOG_SERVICE_TARGET="$work/$PIKA_SCHEDULER_CATALOG_SERVICE_UNIT"
PIKA_SCHEDULER_CATALOG_TIMER_TARGET="$work/$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"
PIKA_SCHEDULER_STAGE_DIR="$work/stage"
mkdir -p "$PIKA_SCHEDULER_STAGE_DIR"
pika_scheduler_check_managed_unit() { :; }
pika_scheduler_atomic_install() { cp -- "$1" "$2"; }
pika_info() { :; }
trap pika_scheduler_on_exit EXIT
pika_scheduler_remove '/srv/s0'
`;
  try {
    mkdirSync(state);
    for (const unit of units) {
      writeFileSync(join(work, unit), `original:${unit}\n`);
    }
    for (const timer of ['pika-supply-sync-s0.timer', 'pika-catalog-worker-s0.timer']) {
      writeFileSync(join(state, `enabled-${timer}`), '');
      writeFileSync(join(state, `active-${timer}`), '');
    }
    const result = spawnSync('/bin/bash', ['-c', removeScript, 'bash', scheduler, work, fakeSystemctl], {
      encoding: 'utf8',
      env: {
        ...process.env,
        PIKA_FAKE_SYSTEMD_STATE: state,
        PIKA_FAKE_FAIL_FIRST_RELOAD: '1',
      },
    });
    assert.notEqual(result.status, 0, 'daemon-reload failure must fail the remove operation');
    for (const unit of units) {
      assert.equal(readFileSync(join(work, unit), 'utf8'), `original:${unit}\n`);
    }
    for (const timer of ['pika-supply-sync-s0.timer', 'pika-catalog-worker-s0.timer']) {
      assert.equal(existsSync(join(state, `enabled-${timer}`)), true, `${timer} enabled state was not restored`);
      assert.equal(existsSync(join(state, `active-${timer}`)), true, `${timer} active state was not restored`);
    }
    const calls = readFileSync(join(state, 'calls.log'), 'utf8');
    assert.match(calls, /disable --now pika-supply-sync-s0\.timer/);
    assert.match(calls, /disable --now pika-catalog-worker-s0\.timer/);
    assert.match(calls, /enable pika-supply-sync-s0\.timer/);
    assert.match(calls, /enable pika-catalog-worker-s0\.timer/);
    assert.match(calls, /start pika-supply-sync-s0\.timer/);
    assert.match(calls, /start pika-catalog-worker-s0\.timer/);
  } finally {
    rmSync(work, { recursive: true, force: true });
  }
});

test('install is atomic and fail-if-different; remove and rollback stay exact', () => {
  const source = read('scripts/scheduler.sh');
  assert.match(source, /existing service unit differs; refusing to overwrite/);
  assert.match(source, /existing timer unit differs; refusing to overwrite/);
  assert.match(source, /existing CatalogHub service unit differs; refusing to overwrite/);
  assert.match(source, /existing CatalogHub timer unit differs; refusing to overwrite/);
  assert.match(source, /mktemp "\$\{PIKA_SCHEDULER_UNIT_DIR\}\/\./);
  assert.match(source, /install -o 0 -g 0 -m 0644/);
  assert.match(source, /pika_require_command systemd-analyze/);
  assert.match(source, /"\$PIKA_SCHEDULER_SYSTEMD_ANALYZE" verify/);
  assert.doesNotMatch(source, /if type -P -- systemd-analyze/);
  assert.match(source, /daemon-reload/);
  assert.match(source, /enable --now "\$PIKA_SCHEDULER_TIMER_UNIT"/);
  assert.match(source, /enable --now "\$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"/);
  assert.match(source, /disable --now "\$PIKA_SCHEDULER_TIMER_UNIT"/);
  assert.match(source, /disable --now "\$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"/);
  assert.match(source, /scheduler rollback was incomplete/);
  assert.match(source, /PIKA_SCHEDULER_REMOVE_DELETED_SERVICE/);
  assert.match(source, /PIKA_SCHEDULER_REMOVE_DELETED_TIMER/);
  assert.match(source, /PIKA_SCHEDULER_REMOVE_DELETED_CATALOG_SERVICE/);
  assert.match(source, /PIKA_SCHEDULER_REMOVE_DELETED_CATALOG_TIMER/);
  assert.match(source, /assets\/cache\/pika-supply-sync/);
  assert.match(source, /pika_scheduler_assert_image_cache/);
  assert.match(source, /assets cache parent must match the installed dedicated-Web contract/);
  assert.doesNotMatch(source, /install -d[^\n]+assets\/cache|rmdir[^\n]+assets\/cache/);
  assert.match(source, /external runtime directory must be owned by --web-user with mode 0750/);
  assert.match(source, /image cache must be owned by --web-user with mode 0755/);
  assert.match(source, /pika_site_state_dir/);
  assert.match(source, /LC_ALL=C stat -c '%u:%g:%a:%h:%d:%i:%F'/);
  assert.match(source, /pika_scheduler_run_sync_main/);
  assert.match(source, /pika_scheduler_run_catalog_main/);
  assert.match(source, /\$runner_label must execute as the non-root site web identity/);
  assert.match(source, /local-extensions\/extensions\/PikaCatalogHub\/bin\/worker\.php/);
  assert.match(source, /exec -- "\$php_bin" "\$extension_bin" "--root=\$site_root" '--batch=20'/);
  assert.match(source, /exec -- "\$php_bin" "\$extension_bin" "--root=\$site_root"/);
  assert.match(source, /umask 0027/);
  assert.doesNotMatch(source, /site_root\/runtime\/local-extensions/);
  assert.doesNotMatch(source, /rm\s+-rf|find\s+[^\n]*-delete|chown\s+-R|chmod\s+-R/);
});

test('scheduler never manages payment, database, network downloads or web-triggered services', () => {
  const combined = [
    read('scripts/scheduler.sh'),
    read('packaging/systemd/pika-supply-sync.service.in'),
    read('packaging/systemd/pika-supply-sync.timer.in'),
    read('packaging/systemd/pika-catalog-worker.service.in'),
    read('packaging/systemd/pika-catalog-worker.timer.in'),
  ].join('\n');
  assert.doesNotMatch(combined, /(?:^|[;&|]\s*)(?:curl|wget)\s|app\/Pay|BEpusdt|\b(?:UPDATE|DELETE|ALTER|INSERT)\b|mysql/i);
  assert.doesNotMatch(combined, /Controller|assets\/admin|local-extensions\/src/);
  execFileSync('bash', ['-n', scheduler]);
});
