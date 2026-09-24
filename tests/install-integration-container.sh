#!/bin/bash
set -euo pipefail

readonly RELEASE_SOURCE_ROOT=/release
readonly RELEASE_ROOT=/opt/pika-test-space/release
readonly FIXTURE_INPUT=/fixture
readonly FIXTURE_ROOT="$FIXTURE_INPUT"
readonly BACKUP_FIXTURE_ROOT="/var/backups/pika-install-integration-$$"
readonly PAYMENT_RULE_BACKUP_ROOT="/etc/pika-install-integration-logrotate-$$"
readonly WEB_USER=www-data
readonly WEB_UID="$(id -u "$WEB_USER")"
readonly WEB_GID="$(id -g "$WEB_USER")"
cp -a /fixture-seed/. "$FIXTURE_ROOT/"
chmod 0755 "$FIXTURE_ROOT"
for drift_scenario in \
    rollback-inode rollback-symlink rollback-acl authorization-failure legacy-upgrade unconfigured-logs payment-rule-drift \
    throttle-mode-0700 throttle-mode-0750 \
    mutable-root-write mutable-other-write mutable-web-0666 mutable-web-0770 \
    mutable-web-0771-file mutable-web-0771-outside mutable-web-0771-prefix \
    mutable-root-0771-view mutable-other-0771-view mutable-gid-0771-view; do
    mkdir -p "$FIXTURE_ROOT/$drift_scenario"
    cp -a "$FIXTURE_ROOT/failure/site" "$FIXTURE_ROOT/$drift_scenario/site"
done
mkdir -p "$RELEASE_ROOT"
for release_entry in bridge manager extensions themes payment-adapters scripts packaging; do
    cp -R --no-preserve=ownership "$RELEASE_SOURCE_ROOT/$release_entry" "$RELEASE_ROOT/"
done
cp --no-preserve=ownership \
    "$RELEASE_SOURCE_ROOT/compatibility.json" \
    "$RELEASE_SOURCE_ROOT/release.json" \
    "$RELEASE_ROOT/"
install -o root -g root -m 0755 "$RELEASE_SOURCE_ROOT/tests/bin/git" /usr/bin/git
mkdir -p /opt/pika-test-space/bin/php80 /opt/pika-test-space/bin/php-fail-write-receipt
mkdir -p /opt/pika-test-space/bin/php-fail-verify-install
mkdir -p /opt/pika-test-space/bin/php-fail-write-receipt-delayed
mkdir -p /opt/pika-test-space/bin/php-fail-verify-install-delayed
install -o root -g root -m 0755 "$RELEASE_SOURCE_ROOT/tests/bin/php-80-sim" /opt/pika-test-space/bin/php80/php
install -o root -g root -m 0755 "$RELEASE_SOURCE_ROOT/tests/bin/php-fail-write-receipt" /opt/pika-test-space/bin/php-fail-write-receipt/php
install -o root -g root -m 0755 "$RELEASE_SOURCE_ROOT/tests/bin/php-fail-verify-install" /opt/pika-test-space/bin/php-fail-verify-install/php
cat > /opt/pika-test-space/bin/php-fail-write-receipt-delayed/php <<'EOF'
#!/bin/bash
set -euo pipefail
for argument in "$@"; do
    if [[ "$argument" == */scripts/write-receipt.php ]]; then
        : > /tmp/pika-install-mutation-ready
        for _ in $(seq 1 1000); do
            [[ -f /tmp/pika-install-mutation-done ]] && break
            sleep 0.01
        done
        [[ -f /tmp/pika-install-mutation-done ]] || {
            printf 'INJECTED_MUTATION_HANDSHAKE_TIMEOUT\n' >&2
            exit 77
        }
        printf 'INJECTED_WRITE_RECEIPT_FAILURE\n' >&2
        exit 79
    fi
done
exec /usr/local/bin/php "$@"
EOF
cat > /opt/pika-test-space/bin/php-fail-verify-install-delayed/php <<'EOF'
#!/bin/bash
set -euo pipefail
if [[ "${1:-}" == */verify-install.php ]]; then
    : > /tmp/pika-install-mutation-ready
    for _ in $(seq 1 1000); do
        [[ -f /tmp/pika-install-mutation-done ]] && break
        sleep 0.01
    done
    [[ -f /tmp/pika-install-mutation-done ]] || {
        printf 'INJECTED_MUTATION_HANDSHAKE_TIMEOUT\n' >&2
        exit 77
    }
    printf 'INJECTED_VERIFY_INSTALL_FAILURE\n' >&2
    exit 98
fi
exec /usr/local/bin/php "$@"
EOF
chown 0:0 \
    /opt/pika-test-space/bin/php-fail-write-receipt-delayed/php \
    /opt/pika-test-space/bin/php-fail-verify-install-delayed/php
chmod 0755 \
    /opt/pika-test-space/bin/php-fail-write-receipt-delayed/php \
    /opt/pika-test-space/bin/php-fail-verify-install-delayed/php
readonly PHP80_FIXTURE=/opt/pika-test-space/bin/php80/php
readonly PHP_FAIL_WRITE_RECEIPT_FIXTURE=/opt/pika-test-space/bin/php-fail-write-receipt/php
readonly PHP_FAIL_VERIFY_INSTALL_FIXTURE=/opt/pika-test-space/bin/php-fail-verify-install/php
readonly PHP_FAIL_WRITE_RECEIPT_DELAYED_FIXTURE=/opt/pika-test-space/bin/php-fail-write-receipt-delayed/php
readonly PHP_FAIL_VERIFY_INSTALL_DELAYED_FIXTURE=/opt/pika-test-space/bin/php-fail-verify-install-delayed/php

# Docker Desktop bind mounts retain the host fixture owner. Normalize only the
# disposable scenario parents so the installer reaches each intended unsafe
# descendant instead of rejecting the shared fixture boundary first.
for scenario in \
    writable-owner writable-group writable-world writable-site-parent \
    writable-assets success tamper restore-rollback failure post-doctor-failure \
    rollback-inode rollback-symlink rollback-acl authorization-failure legacy-upgrade unconfigured-logs payment-rule-drift \
    throttle-mode-0700 throttle-mode-0750 \
    mutable-root-write mutable-other-write mutable-web-0666 mutable-web-0770 \
    mutable-web-0771-file mutable-web-0771-outside mutable-web-0771-prefix \
    mutable-root-0771-view mutable-other-0771-view mutable-gid-0771-view; do
    chown 0:0 "$FIXTURE_ROOT/$scenario"
    chmod 0755 "$FIXTURE_ROOT/$scenario"
    chown 0:0 "$FIXTURE_ROOT/$scenario/site"
    chmod 0755 "$FIXTURE_ROOT/$scenario/site"
    printf '%s\n' 'fixture-admin-terms-accepted' \
        > "$FIXTURE_ROOT/$scenario/site/config/terms"
    printf '%s\n' 'fixture-install-lock' \
        > "$FIXTURE_ROOT/$scenario/site/kernel/Install/Lock"
    cat > "$FIXTURE_ROOT/$scenario/site/config/database.php" <<'PHP'
<?php
declare(strict_types=1);

return [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'database' => 'CHANGE_ME',
    'username' => 'CHANGE_ME',
    'password' => 'CHANGE_ME',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => 'acg_',
];
PHP
    chown 0:0 \
        "$FIXTURE_ROOT/$scenario/site/config/terms" \
        "$FIXTURE_ROOT/$scenario/site/kernel/Install/Lock" \
        "$FIXTURE_ROOT/$scenario/site/config/database.php"
    chmod 0644 \
        "$FIXTURE_ROOT/$scenario/site/config/terms" \
        "$FIXTURE_ROOT/$scenario/site/kernel/Install/Lock" \
        "$FIXTURE_ROOT/$scenario/site/config/database.php"
done
mkdir -p "$BACKUP_FIXTURE_ROOT"
chmod 0700 "$BACKUP_FIXTURE_ROOT"
trap 'rm -rf -- "$BACKUP_FIXTURE_ROOT"' EXIT

fail() {
    printf 'INSTALL_INTEGRATION_FAIL: %s\n' "$*" >&2
    exit 1
}

command -v logrotate >/dev/null || fail 'real logrotate is required for payment log lifecycle coverage'
readonly PAYMENT_LOGROTATE_STATE=/opt/pika-test-space/payment-logrotate.state
printf 'logrotate state -- version 2\n' > "$PAYMENT_LOGROTATE_STATE"
chown 0:0 "$PAYMENT_LOGROTATE_STATE"
chmod 0600 "$PAYMENT_LOGROTATE_STATE"

malicious_path="$FIXTURE_ROOT/malicious-path"
malicious_marker="$FIXTURE_ROOT/malicious-path-executed"
mkdir -p "$malicious_path"
for command_name in dirname id git php stat find runuser; do
    printf '#!/bin/bash\nprintf attacked > %q\nexit 99\n' "$malicious_marker" \
        > "$malicious_path/$command_name"
    chmod 0755 "$malicious_path/$command_name"
done
malicious_path_log="$FIXTURE_ROOT/malicious-path.log"
if PATH="$malicious_path" "$RELEASE_ROOT/scripts/install.sh" \
    --site-root /definitely-missing-pika-site \
    --web-user www-data \
    --confirm-maintenance \
    >"$malicious_path_log" 2>&1; then
    fail 'malicious PATH preflight unexpectedly succeeded'
fi
[[ ! -e "$malicious_marker" ]] \
    || fail 'installer executed a command from the inherited malicious PATH'
grep -q 'site root does not exist' "$malicious_path_log" \
    || fail 'malicious PATH test did not reach the trusted site-root gate'

assert_absent() {
    local site_root="$1"
    shift
    local relative
    for relative in "$@"; do
        [[ ! -e "$site_root/$relative" && ! -L "$site_root/$relative" ]] \
            || fail "rollback left payload path: $relative"
    done
}

assert_owner_mode() {
    local path="$1" expected_uid="$2" expected_gid="$3" expected_mode="$4"
    local actual
    actual="$(stat -c '%u:%g:%a' "$path")"
    [[ "$actual" == "$expected_uid:$expected_gid:$expected_mode" ]] \
        || fail "owner/mode mismatch for $path: expected=$expected_uid:$expected_gid:$expected_mode actual=$actual"
}

prepare_official_smarty_tree() {
    local site_root="$1" prepare_runtime="${2:-1}"
    [[ ! -e "$site_root/runtime/view" && ! -L "$site_root/runtime/view" ]] \
        || fail 'official Smarty fixture requires an absent view tree'
    # Only prepare the shallow runtime root. The pinned official Smarty must
    # create view/compile with its own default permissions during a real fetch.
    if [[ "$prepare_runtime" == 1 ]]; then
        mkdir -p "$site_root/runtime"
        chown "$WEB_UID:$WEB_GID" "$site_root/runtime"
        chmod 0750 "$site_root/runtime"
    else
        [[ "$prepare_runtime" == 0 ]] || fail 'invalid Smarty runtime preparation policy'
        assert_owner_mode "$site_root/runtime" "$WEB_UID" "$WEB_GID" 750
    fi
    # Keep CLI diagnostics visible, outside Smarty's template output buffer.
    runuser -u "$WEB_USER" -- /usr/local/bin/php -d display_errors=stderr -r '
        $site = $argv[1];
        require $site . "/vendor/autoload.php";
        $smarty = new Smarty();
        $smarty->setTemplateDir($site . "/app/View");
        $smarty->setCacheDir($site . "/runtime/view/cache");
        $smarty->setCompileDir($site . "/runtime/view/compile");
        if ($smarty->fetch("string:official-smarty-permission-fixture")
            !== "official-smarty-permission-fixture") {
            fwrite(STDERR, "official Smarty fixture fetch failed\n");
            exit(1);
        }
    ' "$site_root"
    assert_owner_mode "$site_root/runtime/view" "$WEB_UID" "$WEB_GID" 771
    assert_owner_mode "$site_root/runtime/view/compile" "$WEB_UID" "$WEB_GID" 771
}

assert_official_runtime_root_contract() {
    local site_root="$1"
    assert_owner_mode "$site_root/assets/cache" "$WEB_UID" "$WEB_GID" 755
    assert_owner_mode "$site_root/app/Pay" "$WEB_UID" "$WEB_GID" 755
    assert_owner_mode "$site_root/app/Plugin" "$WEB_UID" "$WEB_GID" 755
    assert_owner_mode "$site_root/app/View/User/Theme" "$WEB_UID" "$WEB_GID" 755
    assert_owner_mode "$site_root/config" 0 "$WEB_GID" 750
    assert_owner_mode "$site_root/kernel/Install" 0 "$WEB_GID" 750
    assert_owner_mode "$site_root/runtime" "$WEB_UID" "$WEB_GID" 750
}

bridge_hashes() {
    local site_root="$1"
    sha256sum \
        "$site_root/kernel/Kernel.php" \
        "$site_root/kernel/Helper.php" \
        "$site_root/app/Controller/Admin/Api/Config.php" \
        "$site_root/app/View/Admin/Footer.html" \
        "$site_root/assets/common/js/editor/markdown/editorv2.js" \
        | awk '{print $1}'
}

site_snapshot() {
    local site_root="$1"
    (
        cd -P -- "$site_root"
        tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner -cf - . \
            | sha256sum \
            | awk '{print $1}'
    )
}

site_exact_snapshot() {
    local site_root="$1"
    (
        cd -P -- "$site_root"
        tar --sort=name --mtime='@0' --numeric-owner -cf - . \
            | sha256sum \
            | awk '{print $1}'
    )
}

site_snapshot_without_mutable() {
    local site_root="$1"
    (
        cd -P -- "$site_root"
        tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner \
            --exclude='./mutable-site-files' --exclude='./payment-runtime-logs' -cf - . \
            | sha256sum \
            | awk '{print $1}'
    )
}

payment_log_snapshot() {
    local site_root="$1" name path
    for name in runtime.log runtime.log.1 runtime.log.{2..7}.gz; do
        path="$site_root/app/Pay/PikaBEpusdtAdapter/$name"
        if [[ -e "$path" || -L "$path" ]]; then
            printf '%s\t%s\t%s\n' "$name" \
                "$(stat -c '%d:%i:%h:%u:%g:%a:%s:%Y:%Z' -- "$path")" \
                "$(sha256sum -- "$path" | awk '{print $1}')"
        fi
    done
}

prepare_payment_rule_isolation() {
    local site_root="$1" label="$2"
    export PIKA_PAYMENT_LOGROTATE_RULE="/etc/logrotate.d/pika-$label"
    export PIKA_PAYMENT_LOGROTATE_RULE_BACKUP="$PAYMENT_RULE_BACKUP_ROOT/pika-$label"
    mkdir -p "$PAYMENT_RULE_BACKUP_ROOT"
    chown 0:0 "$PAYMENT_RULE_BACKUP_ROOT"
    chmod 0700 "$PAYMENT_RULE_BACKUP_ROOT"
    [[ ! -e "$PIKA_PAYMENT_LOGROTATE_RULE" && ! -e "$PIKA_PAYMENT_LOGROTATE_RULE_BACKUP" ]] \
        || fail 'payment rule isolation fixture already exists'
    sed -e "s|__SITE_ROOT__|$site_root|g" \
        -e "s|__WEB_USER__|$WEB_USER|g" -e "s|__WEB_GROUP__|$WEB_USER|g" \
        "$RELEASE_ROOT/packaging/logrotate/pika-bepusdt-adapter.conf.example" > "$PIKA_PAYMENT_LOGROTATE_RULE"
    chown 0:0 "$PIKA_PAYMENT_LOGROTATE_RULE"
    chmod 0644 "$PIKA_PAYMENT_LOGROTATE_RULE"
    [[ "$(stat -c '%d' "$PIKA_PAYMENT_LOGROTATE_RULE")" == "$(stat -c '%d' "$PAYMENT_RULE_BACKUP_ROOT")" ]] \
        || fail 'payment rule isolation fixture does not share the include filesystem'
    PAYMENT_RULE_IDENTITY="$(stat -c '%d:%i:%h:%u:%g:%a:%s:%Y' "$PIKA_PAYMENT_LOGROTATE_RULE"):$(sha256sum "$PIKA_PAYMENT_LOGROTATE_RULE" | awk '{print $1}')"
    mv -- "$PIKA_PAYMENT_LOGROTATE_RULE" "$PIKA_PAYMENT_LOGROTATE_RULE_BACKUP"
}

assert_payment_rule_restored() {
    mv -- "$PIKA_PAYMENT_LOGROTATE_RULE_BACKUP" "$PIKA_PAYMENT_LOGROTATE_RULE"
    [[ "$(stat -c '%d:%i:%h:%u:%g:%a:%s:%Y' "$PIKA_PAYMENT_LOGROTATE_RULE"):$(sha256sum "$PIKA_PAYMENT_LOGROTATE_RULE" | awk '{print $1}')" == "$PAYMENT_RULE_IDENTITY" ]] \
        || fail 'payment rule rollback changed its bytes, inode or required metadata'
}

prepare_payment_log_rotations() {
    local site_root="$1" label="$2" rotations="$3" index path config identity
    path="$site_root/app/Pay/PikaBEpusdtAdapter/runtime.log"
    config="$FIXTURE_ROOT/$label-logrotate.conf"
    sed -e "s|__SITE_ROOT__|$site_root|g" \
        -e "s|__WEB_USER__|$WEB_USER|g" -e "s|__WEB_GROUP__|$WEB_USER|g" \
        "$RELEASE_ROOT/packaging/logrotate/pika-bepusdt-adapter.conf.example" > "$config"
    chown 0:0 "$config"
    chmod 0600 "$config"
    identity="$(stat -c '%d:%i:%h:%u:%g:%a' -- "$path")"
    for ((index = 1; index <= rotations; index++)); do
        printf 'synthetic-payment-log-%s-%s\n' "$label" "$index" > "$path"
        logrotate --force --state "$PAYMENT_LOGROTATE_STATE" "$config"
        [[ "$(stat -c '%d:%i:%h:%u:%g:%a' -- "$path")" == "$identity" && ! -s "$path" ]] \
            || fail 'copytruncate changed active payment log identity, permissions, or retained bytes'
        [[ "$(<"$path.1")" == "synthetic-payment-log-$label-$index" ]] \
            || fail 'first-generation payment log does not contain the latest synthetic bytes'
        if [[ "$index" == 1 ]]; then
            [[ ! -e "$path.2.gz" ]] || fail 'first rotation compressed prematurely'
        elif [[ "$index" == 2 ]]; then
            [[ "$(gzip -cd -- "$path.2.gz")" == "synthetic-payment-log-$label-1" ]] \
                || fail 'second rotation did not delay-compress the first generation'
        fi
    done
    if ((rotations >= 8)); then
        [[ ! -e "$path.8.gz" && ! -e "$path.1.gz" ]] \
            || fail 'completed logrotate left a transient or over-retention filename'
        [[ "$(gzip -cd -- "$path.7.gz")" == "synthetic-payment-log-$label-$((rotations - 6))" ]] \
            || fail 'rotate 7 retention did not preserve the expected oldest remaining generation'
        [[ "$(payment_log_snapshot "$site_root" | wc -l | tr -d ' ')" == 8 ]] \
            || fail 'rotate 7 did not leave exactly one active plus seven retained payment logs'
    fi
    printf 'synthetic-payment-log-%s-active\n' "$label" > "$path"
    printf 'PAYMENT_LOGROTATE_PASS case=%s rotations=%s\n' "$label" "$rotations"
}

assert_payment_log_backup() {
    local receipt="$1" snapshot="$2" backup_root name metadata digest
    backup_root="$(dirname -- "$receipt")/payment-runtime-logs"
    assert_owner_mode "$backup_root" 0 0 700
    assert_owner_mode "$backup_root/manifest.json" 0 0 600
    while IFS=$'\t' read -r name metadata digest; do
        [[ -n "$name" ]] || continue
        assert_owner_mode "$backup_root/$name" 0 0 600
        [[ "$(sha256sum -- "$backup_root/$name" | awk '{print $1}')" == "$digest" ]] \
            || fail "protected payment log bytes differ: $name"
    done <<< "$snapshot"
    php -r '
        $manifest = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
        if (($manifest["schema"] ?? null) !== 1 || !is_array($manifest["files"] ?? null)) {
            fwrite(STDERR, "invalid payment log preservation manifest\n"); exit(1);
        }
        $expected = [];
        foreach (explode("\n", trim($argv[2])) as $line) {
            [$name, $metadata, $hash] = explode("\t", $line);
            [$dev, $ino, $nlink, $uid, $gid, $mode, $size, $mtime, $ctime] = explode(":", $metadata);
            $relative = "app/Pay/PikaBEpusdtAdapter/" . $name;
            $entry = $manifest["files"][$relative] ?? null;
            if (!is_array($entry) || ($entry["sha256"] ?? null) !== $hash
                || ($entry["size"] ?? null) !== (int)$size
                || ($entry["original_uid"] ?? null) !== (int)$uid
                || ($entry["original_gid"] ?? null) !== (int)$gid
                || ($entry["original_mode"] ?? null) !== octdec($mode)
                || ($entry["original_mtime"] ?? null) !== (int)$mtime
                || ($entry["original_dev"] ?? null) !== (int)$dev
                || ($entry["original_ino"] ?? null) !== (int)$ino
                || ($entry["original_nlink"] ?? null) !== (int)$nlink
                || ($entry["original_ctime"] ?? null) !== (int)$ctime) {
                fwrite(STDERR, "payment log preservation manifest mismatch: " . $name . "\n"); exit(1);
            }
            $expected[] = $relative;
        }
        $actual = array_keys($manifest["files"]); sort($actual); sort($expected);
        if ($actual !== $expected) { fwrite(STDERR, "payment log preservation set mismatch\n"); exit(1); }
    ' "$backup_root/manifest.json" "$snapshot"
}

payment_log_reversible_snapshot() {
    # Rename rollback necessarily changes ctime; content, mtime and inode remain bound.
    payment_log_snapshot "$1" | sed -E 's/:[0-9]+\t/\t/'
}

assert_payment_restore_rejected() {
    local site_root="$1" receipt="$2" label="$3" expected="$4"
    local before backup_before state_before identities log
    before="$(site_exact_snapshot "$site_root")"
    backup_before="$(site_exact_snapshot "$(dirname -- "$receipt")")"
    state_before="$(site_exact_snapshot "$(site_state_dir "$site_root")")"
    identities="$(find -P "$site_root/app/Pay/PikaBEpusdtAdapter" -printf '%P %D:%i:%n:%U:%G:%m:%s:%T@\n' | LC_ALL=C sort)"
    log="$FIXTURE_ROOT/$label-restore-reject.log"
    if php "$RELEASE_ROOT/scripts/restore-install.php" \
        --site-root "$site_root" --receipt "$receipt" > "$log" 2>&1; then
        fail "restore unexpectedly accepted payment lifecycle violation: $label"
    fi
    grep -Eiq "$expected" "$log" \
        || fail "payment lifecycle rejection returned the wrong failure: $label"
    [[ "$(site_exact_snapshot "$site_root")" == "$before" \
        && "$(site_exact_snapshot "$(dirname -- "$receipt")")" == "$backup_before" \
        && "$(site_exact_snapshot "$(site_state_dir "$site_root")")" == "$state_before" ]] \
        || fail "payment lifecycle rejection mutated site, receipt state, or protected backup: $label"
    [[ "$(find -P "$site_root/app/Pay/PikaBEpusdtAdapter" -printf '%P %D:%i:%n:%U:%G:%m:%s:%T@\n' | LC_ALL=C sort)" == "$identities" ]] \
        || fail "payment lifecycle rejection changed an original node identity: $label"
}

receipt_backup_path() {
    local site_root="$1"
    php -r '
        $receipt = json_decode(
            file_get_contents(
                "/var/lib/pika-local-extensions/sites/" . hash("sha256", $argv[1])
                    . "/install-receipt.json"
            ),
            true,
            64,
            JSON_THROW_ON_ERROR
        );
        $backup = $receipt["backup_dir"] ?? null;
        if (!is_string($backup) || $backup === "") {
            exit(1);
        }
        echo $backup . "/install-receipt.json";
    ' "$site_root"
}

site_state_dir() {
    local site_root="$1" digest
    digest="$(printf '%s' "$site_root" | sha256sum | awk '{print $1}')"
    printf '/var/lib/pika-local-extensions/sites/%s\n' "$digest"
}

remove_test_state() {
    local state="$1"
    [[ "$state" =~ ^/var/lib/pika-local-extensions/sites/[a-f0-9]{64}$ ]] \
        || fail "refusing to remove unexpected test state path: $state"
    rm -rf -- "$state"
}

remove_retained_install_state_exact() {
    local state="$1" expect_receipt="$2" path metadata
    local -a actual_entries expected_entries files directories
    [[ "$expect_receipt" == 0 || "$expect_receipt" == 1 ]] \
        || fail 'invalid retained-state receipt expectation'
    [[ "$state" =~ ^/var/lib/pika-local-extensions/sites/[a-f0-9]{64}$ \
        && -d "$state" && ! -L "$state" \
        && "$(realpath -e -- "$state")" == "$state" ]] \
        || fail 'retained disposable state path is not canonical and exact'
    mapfile -t actual_entries < <(find -P "$state" -mindepth 1 -printf '%P\n' | LC_ALL=C sort)
    expected_entries=(runtime runtime/config runtime/csrf.key secrets)
    files=("$state/runtime/csrf.key")
    if [[ "$expect_receipt" == 1 ]]; then
        expected_entries=(install-receipt.json "${expected_entries[@]}")
        files=("$state/install-receipt.json" "${files[@]}")
    fi
    [[ "${actual_entries[*]}" == "${expected_entries[*]}" ]] \
        || fail 'retained disposable state contains unexpected evidence paths'
    [[ "$(stat -c '%u:%g:%a' -- "$state")" == '0:0:755' ]] \
        || fail 'retained disposable state root metadata drifted'
    [[ "$(stat -c '%u:%g:%a' -- "$state/secrets")" == "0:$WEB_GID:750" ]] \
        || fail 'retained disposable secrets metadata drifted'
    for path in "$state/runtime" "$state/runtime/config"; do
        [[ "$(stat -c '%u:%g:%a' -- "$path")" == "$WEB_UID:$WEB_GID:750" ]] \
            || fail "retained disposable runtime metadata drifted: $path"
    done
    for path in "${files[@]}"; do
        [[ -f "$path" && ! -L "$path" && "$(realpath -e -- "$path")" == "$path" \
            && "$(stat -c '%h' -- "$path")" == 1 ]] \
            || fail "retained disposable state file is unsafe: $path"
        metadata="$(stat -c '%u:%g:%a' -- "$path")"
        if [[ "$path" == */install-receipt.json ]]; then
            [[ "$metadata" == '0:0:400' ]] \
                || fail 'retained install receipt metadata drifted'
        else
            [[ "$metadata" == "$WEB_UID:$WEB_GID:600" ]] \
                || fail 'retained CSRF key metadata drifted'
        fi
        rm -- "$path"
    done
    directories=("$state/runtime/config" "$state/runtime" "$state/secrets" "$state")
    for path in "${directories[@]}"; do
        [[ -d "$path" && ! -L "$path" && "$(realpath -e -- "$path")" == "$path" ]] \
            || fail "retained disposable state directory is unsafe: $path"
        rmdir -- "$path"
    done
}

# Official first-request creation can leave a safe Web-owned throttle directory
# at 0700 or 0750. Exercise both modes before collecting an install failure so
# the unpatched installer reports both regressions in one bounded run.
throttle_mode_install_failures=()
for throttle_mode in 0700 0750; do
    throttle_mode_site="$FIXTURE_ROOT/throttle-mode-$throttle_mode/site"
    throttle_mode_state="$(site_state_dir "$throttle_mode_site")"
    throttle_mode_directory="$throttle_mode_site/runtime/throttle"
    throttle_mode_file="$throttle_mode_directory/abababababababababababababababab"
    throttle_mode_backup="$BACKUP_FIXTURE_ROOT/throttle-mode-$throttle_mode"
    throttle_mode_log="$FIXTURE_ROOT/throttle-mode-$throttle_mode/install.log"
    throttle_mode_rollback_log="$FIXTURE_ROOT/throttle-mode-$throttle_mode/rollback.log"
    throttle_mode_restore_log="$FIXTURE_ROOT/throttle-mode-$throttle_mode/restore.log"
    mkdir -p "$throttle_mode_directory" "$throttle_mode_backup/rollback" "$throttle_mode_backup/install"
    printf '%s\n' "official-throttle-mode-$throttle_mode" > "$throttle_mode_file"
    chown "$WEB_UID:$WEB_GID" \
        "$throttle_mode_site/runtime" "$throttle_mode_directory" "$throttle_mode_file"
    chmod 0750 "$throttle_mode_site/runtime"
    chmod "$throttle_mode" "$throttle_mode_directory"
    chmod 0777 "$throttle_mode_file"
    throttle_mode_before="$(site_exact_snapshot "$throttle_mode_site")"
    throttle_mode_bridge_hashes="$(bridge_hashes "$throttle_mode_site")"
    throttle_mode_identity="$(stat -c '%d:%i:%h:%u:%g' -- "$throttle_mode_directory" "$throttle_mode_file")"
    throttle_mode_metadata="$(stat -c '%d:%i:%h:%u:%g:%a' -- "$throttle_mode_directory" "$throttle_mode_file")"
    throttle_mode_hash="$(sha256sum "$throttle_mode_file" | awk '{print $1}')"

    # Reuse the existing final-verification failure injection after receipt and
    # Web authorization. Rollback must restore the original modes and identity.
    if "$RELEASE_ROOT/scripts/install.sh" \
        --site-root "$throttle_mode_site" \
        --backup-dir "$throttle_mode_backup/rollback" \
        --web-user "$WEB_USER" --confirm-maintenance \
        --php "$PHP_FAIL_VERIFY_INSTALL_FIXTURE" \
        >"$throttle_mode_rollback_log" 2>&1; then
        fail "throttle mode rollback injection unexpectedly installed: $throttle_mode"
    fi
    grep -q 'INJECTED_VERIFY_INSTALL_FAILURE' "$throttle_mode_rollback_log" \
        || fail "throttle mode rollback did not reach final verification: $throttle_mode"
    grep -q 'install failed; original bridge files restored' "$throttle_mode_rollback_log" \
        || fail "throttle mode rollback did not report complete restoration: $throttle_mode"
    if grep -q 'ROLLBACK_INCOMPLETE' "$throttle_mode_rollback_log"; then
        fail "throttle mode rollback was incomplete: $throttle_mode"
    fi
    [[ "$(site_exact_snapshot "$throttle_mode_site")" == "$throttle_mode_before" \
        && "$(stat -c '%d:%i:%h:%u:%g:%a' -- "$throttle_mode_directory" "$throttle_mode_file")" == "$throttle_mode_metadata" ]] \
        || fail "throttle mode rollback changed content, identity or metadata: $throttle_mode"
    [[ ! -e "$throttle_mode_state" && ! -L "$throttle_mode_state" ]] \
        || fail "throttle mode rollback left external state: $throttle_mode"
    if find "$throttle_mode_backup/rollback" \
        \( -name install-receipt.json -o -name installed-files.txt \) -print -quit | grep -q .; then
        fail "throttle mode rollback left successful receipt artifacts: $throttle_mode"
    fi
    printf 'THROTTLE_MODE_ROLLBACK_PASS mode=%s content=preserved identity=preserved metadata=restored\n' "$throttle_mode"

    throttle_mode_exit=0
    "$RELEASE_ROOT/scripts/install.sh" \
        --site-root "$throttle_mode_site" \
        --backup-dir "$throttle_mode_backup/install" \
        --web-user "$WEB_USER" --confirm-maintenance \
        >"$throttle_mode_log" 2>&1 || throttle_mode_exit=$?
    if ((throttle_mode_exit != 0)); then
        grep -q 'official throttle directory is unsafe:' "$throttle_mode_log" \
            || fail "throttle mode install failed outside the intended verifier gate: $throttle_mode"
        grep -q 'install failed; original bridge files restored' "$throttle_mode_log" \
            || fail "throttle mode verifier failure did not report complete rollback: $throttle_mode"
        [[ "$(site_exact_snapshot "$throttle_mode_site")" == "$throttle_mode_before" \
            && "$(stat -c '%d:%i:%h:%u:%g:%a' -- "$throttle_mode_directory" "$throttle_mode_file")" == "$throttle_mode_metadata" \
            && ! -e "$throttle_mode_state" && ! -L "$throttle_mode_state" ]] \
            || fail "throttle mode verifier failure did not restore the exact site: $throttle_mode"
        printf 'THROTTLE_MODE_INSTALL_FAIL mode=%s exit=%s gate=official-throttle-directory\n' \
            "$throttle_mode" "$throttle_mode_exit"
        throttle_mode_install_failures+=("$throttle_mode")
        continue
    fi
    grep -q 'INSTALL_PASS ' "$throttle_mode_log" \
        || fail "throttle mode install did not report success: $throttle_mode"
    assert_owner_mode "$throttle_mode_site/runtime" "$WEB_UID" "$WEB_GID" 750
    assert_owner_mode "$throttle_mode_directory" "$WEB_UID" "$WEB_GID" 755
    assert_owner_mode "$throttle_mode_file" "$WEB_UID" "$WEB_GID" 755
    [[ "$(stat -c '%d:%i:%h:%u:%g' -- "$throttle_mode_directory" "$throttle_mode_file")" == "$throttle_mode_identity" \
        && "$(sha256sum "$throttle_mode_file" | awk '{print $1}')" == "$throttle_mode_hash" ]] \
        || fail "throttle mode install changed content, identity or ownership: $throttle_mode"
    printf 'THROTTLE_MODE_INSTALL_PASS mode=%s normalized=0755 content=preserved identity=preserved runtime_mode=0750\n' "$throttle_mode"

    # Standard restore archives this site's state before the same dedicated
    # Web identity is used by the next isolated fixture.
    throttle_mode_receipt="$(receipt_backup_path "$throttle_mode_site")"
    prepare_payment_rule_isolation "$throttle_mode_site" "throttle-mode-$throttle_mode"
    "$RELEASE_ROOT/scripts/restore.sh" \
        --site-root "$throttle_mode_site" --receipt "$throttle_mode_receipt" \
        --confirm-maintenance >"$throttle_mode_restore_log" 2>&1
    [[ ! -e "$throttle_mode_state" && ! -L "$throttle_mode_state" \
        && "$(bridge_hashes "$throttle_mode_site")" == "$throttle_mode_bridge_hashes" ]] \
        || fail "throttle mode restore left live state or changed original bridges: $throttle_mode"
    assert_absent "$throttle_mode_site" local-extensions app/Pay/PikaBEpusdtAdapter \
        app/Controller/User/Api/PikaBEpusdt.php
    [[ "$(stat -c '%d:%i:%h:%u:%g' -- "$throttle_mode_directory" "$throttle_mode_file")" == "$throttle_mode_identity" \
        && "$(sha256sum "$throttle_mode_file" | awk '{print $1}')" == "$throttle_mode_hash" ]] \
        || fail "throttle mode restore changed content, identity or ownership: $throttle_mode"
    printf 'THROTTLE_MODE_RESTORE_PASS mode=%s live_state=absent bridges=restored content=preserved identity=preserved\n' "$throttle_mode"
done
[[ "${#throttle_mode_install_failures[@]}" == 0 ]] \
    || fail "safe preexisting throttle modes did not install: ${throttle_mode_install_failures[*]}"

stage_install="$FIXTURE_ROOT/stage-install"
stage_update="$FIXTURE_ROOT/stage-update"
mirror_payload_files=(
    PikaCatalogHub/Hook/SharedCategoryTree.php
    PikaCatalogHub/Service/SharedCategoryTree.php
    PikaSupplySync/Service/UpstreamCategoryTree.php
)
mkdir -p "$stage_install" "$stage_update"
php "$RELEASE_ROOT/scripts/stage-payload.php" \
    --repo-root "$RELEASE_ROOT" --stage-root "$stage_install" --mode install
php "$RELEASE_ROOT/scripts/stage-payload.php" \
    --repo-root "$RELEASE_ROOT" --stage-root "$stage_update" --mode update
[[ -f "$stage_install/app/View/User/Theme/Pika/Setting.php" ]] \
    || fail 'first install did not stage Pika Setting.php'
[[ ! -e "$stage_update/app/View/User/Theme/Pika/Setting.php" ]] \
    || fail 'update staging would overwrite Pika Setting.php'
for stage_root in "$stage_install" "$stage_update"; do
    for mirror_payload in "${mirror_payload_files[@]}"; do
        [[ -f "$stage_root/local-extensions/extensions/$mirror_payload" \
            && ! -L "$stage_root/local-extensions/extensions/$mirror_payload" ]] \
            || fail 'mirror capability payload was not staged as a regular file'
        cmp -- "$RELEASE_ROOT/extensions/$mirror_payload" "$stage_root/local-extensions/extensions/$mirror_payload" \
            || fail 'mirror capability staged bytes changed'
    done
    [[ -f "$stage_root/app/Controller/User/Api/PikaBEpusdt.php" ]] \
        || fail 'BE callback controller payload was not staged'
    [[ -f "$stage_root/app/Pay/PikaBEpusdtAdapter/Config/Info.php" ]] \
        || fail 'payment adapter payload was not staged'
    for excluded in payment-adapter.json README.md runtime.log; do
        [[ ! -e "$stage_root/app/Pay/PikaBEpusdtAdapter/$excluded" ]] \
            || fail "payment adapter metadata or runtime file was staged: $excluded"
    done
done
for included in \
    Assets/pika-favicon-1.0.3.png \
    Assets/topfans-bg-poster.jpg \
    Assets/topfans-bg.mp4 \
    Assets/topfans-logo.png; do
    [[ -f "$stage_install/app/View/User/Theme/Pika/$included" ]] \
        || fail "install did not stage active theme asset: $included"
    [[ -f "$stage_update/app/View/User/Theme/Pika/$included" ]] \
        || fail "update did not stage active theme asset: $included"
done
[[ ! -e "$stage_install/app/View/User/Theme/Pika/Assets/storefront-bg.jpg" ]] \
    || fail 'install staged excluded superseded theme asset: Assets/storefront-bg.jpg'
[[ ! -e "$stage_update/app/View/User/Theme/Pika/Assets/storefront-bg.jpg" ]] \
    || fail 'update staged excluded superseded theme asset: Assets/storefront-bg.jpg'

assert_web_writable_parent_rejected() {
    local scenario="$1" expected_channel="$2" unsafe_path="$3"
    local site="$FIXTURE_ROOT/$scenario/site"
    local backup="$FIXTURE_ROOT/$scenario/backups"
    local state expected_error
    state="$(site_state_dir "$site")"
    local log="$FIXTURE_ROOT/$scenario-install.log"
    local before
    before="$(site_snapshot "$site")"
    if "$RELEASE_ROOT/scripts/install.sh" \
        --site-root "$site" \
        --backup-dir "$backup" \
        --web-user "$WEB_USER" \
        --confirm-maintenance \
        >"$log" 2>&1; then
        fail "web-writable ancestor scenario unexpectedly installed: $scenario"
    fi
    if [[ "$scenario" == 'writable-world' ]]; then
        expected_error='official runtime directory must not be group/world writable: app/View/User/Theme'
    elif [[ "$scenario" == 'writable-site-parent' ]]; then
        expected_error='site root ancestor must be root-owned with a valid mode'
    else
        expected_error="writable by --web-user through $expected_channel permissions: $unsafe_path"
    fi
    grep -q "$expected_error" "$log" \
        || {
            sed -n '1,20p' "$log" >&2
            fail "web-writable ancestor did not fail through $expected_channel permissions: $scenario"
        }
    [[ "$(site_snapshot "$site")" == "$before" ]] \
        || fail "web-writable ancestor rejection changed the site: $scenario"
    [[ ! -e "$backup" && ! -L "$backup" ]] \
        || fail "web-writable ancestor rejection wrote a backup path: $scenario"
    [[ ! -e "$state" && ! -L "$state" ]] \
        || fail "web-writable ancestor rejection wrote external state: $scenario"
}

writable_owner_path="$FIXTURE_ROOT/writable-owner/site/kernel"
chown "$WEB_UID:$WEB_GID" "$writable_owner_path"
chmod 0755 "$writable_owner_path"
assert_web_writable_parent_rejected \
    writable-owner owner "$writable_owner_path"

writable_group_path="$FIXTURE_ROOT/writable-group/site/app/Controller/Admin"
chown 0:"$WEB_GID" "$writable_group_path"
chmod 0775 "$writable_group_path"
assert_web_writable_parent_rejected \
    writable-group group "$writable_group_path"

writable_world_path="$FIXTURE_ROOT/writable-world/site/app/View/User/Theme"
chown 0:0 "$writable_world_path"
chmod 0777 "$writable_world_path"
assert_web_writable_parent_rejected \
    writable-world world "$writable_world_path"

writable_site_parent_path="$FIXTURE_ROOT/writable-site-parent"
chown "$WEB_UID:$WEB_GID" "$writable_site_parent_path"
chmod 0755 "$writable_site_parent_path"
assert_web_writable_parent_rejected \
    writable-site-parent owner "$writable_site_parent_path"

writable_assets_path="$FIXTURE_ROOT/writable-assets/site/assets"
chown "$WEB_UID:$WEB_GID" "$writable_assets_path"
chmod 0755 "$writable_assets_path"
assert_web_writable_parent_rejected \
    writable-assets owner "$writable_assets_path"

assert_untrusted_mutable_group_world_write_rejected() {
    local scenario="$1" unsafe_uid="$2" unsafe_gid="$3" unsafe_mode="$4"
    local expected_error="$5" unsafe_type="${6:-file}" relative="${7:-runtime/throttle/untrusted.lock}"
    local site="$FIXTURE_ROOT/$scenario/site"
    local backup="$BACKUP_FIXTURE_ROOT/$scenario"
    local state log node before identity_before
    state="$(site_state_dir "$site")"
    log="$FIXTURE_ROOT/$scenario/install.log"
    node="$site/$relative"
    mkdir -p "$(dirname -- "$node")" "$backup"
    chown 0:0 "$site/runtime" "$(dirname -- "$node")"
    chmod 0755 "$site/runtime" "$(dirname -- "$node")"
    if [[ "$unsafe_type" == directory ]]; then
        mkdir -- "$node"
        printf '%s\n' 'untrusted-world-write-fixture' > "$node/sentinel.txt"
    else
        [[ "$unsafe_type" == file ]] || fail 'invalid mutable rejection fixture type'
        printf '%s\n' 'untrusted-world-write-fixture' > "$node"
    fi
    chown "$unsafe_uid:$unsafe_gid" "$node"
    chmod "$unsafe_mode" "$node"
    before="$(site_exact_snapshot "$site")"
    identity_before="$(stat -c '%d:%i:%h:%u:%g:%a' -- "$node")"
    if "$RELEASE_ROOT/scripts/install.sh" \
        --site-root "$site" \
        --backup-dir "$backup" \
        --web-user "$WEB_USER" \
        --confirm-maintenance \
        >"$log" 2>&1; then
        fail "installer unexpectedly accepted unsafe group/world write: $scenario"
    fi
    grep -Fq "$expected_error" "$log" \
        || fail "unsafe group/world write did not fail at the mutable-tree gate: $scenario"
    grep -q 'install failed; original bridge files restored' "$log" \
        || fail "unsafe group/world write did not complete exact rollback: $scenario"
    if grep -q 'ROLLBACK_INCOMPLETE' "$log"; then
        fail "unsafe group/world write reported incomplete rollback: $scenario"
    fi
    [[ "$(site_exact_snapshot "$site")" == "$before" \
        && "$(stat -c '%d:%i:%h:%u:%g:%a' -- "$node")" == "$identity_before" ]] \
        || fail "unsafe group/world write rollback drifted metadata or inode: $scenario"
    [[ ! -e "$state" && ! -L "$state" ]] \
        || fail "unsafe group/world write left external state: $scenario"
}

assert_untrusted_mutable_group_world_write_rejected \
    mutable-root-write 0 0 0777 \
    'group/world write requires the dedicated Web identity'
assert_untrusted_mutable_group_world_write_rejected \
    mutable-other-write 65534 65534 0777 \
    'group/world write requires the dedicated Web identity'
assert_untrusted_mutable_group_world_write_rejected \
    mutable-web-0666 "$WEB_UID" "$WEB_GID" 0666 \
    'group/world write compatibility requires exact mode 0777'
assert_untrusted_mutable_group_world_write_rejected \
    mutable-web-0770 "$WEB_UID" "$WEB_GID" 0770 \
    'group/world write compatibility requires exact mode 0777'
assert_untrusted_mutable_group_world_write_rejected \
    mutable-web-0771-file "$WEB_UID" "$WEB_GID" 0771 \
    'group/world write compatibility requires exact mode 0777' file runtime/view/compiled.php
assert_untrusted_mutable_group_world_write_rejected \
    mutable-web-0771-outside "$WEB_UID" "$WEB_GID" 0771 \
    'group/world write compatibility requires exact mode 0777' directory runtime/other-view
assert_untrusted_mutable_group_world_write_rejected \
    mutable-web-0771-prefix "$WEB_UID" "$WEB_GID" 0771 \
    'group/world write compatibility requires exact mode 0777' directory runtime/view-extra
assert_untrusted_mutable_group_world_write_rejected \
    mutable-root-0771-view 0 0 0771 \
    'group/world write requires the dedicated Web identity' directory runtime/view
assert_untrusted_mutable_group_world_write_rejected \
    mutable-other-0771-view 65534 65534 0771 \
    'group/world write requires the dedicated Web identity' directory runtime/view
assert_untrusted_mutable_group_world_write_rejected \
    mutable-gid-0771-view "$WEB_UID" 0 0771 \
    'group/world write requires the dedicated Web identity' directory runtime/view

assert_bridge_metadata_rejected() {
    local scenario="$1" unsafe_uid="$2" unsafe_gid="$3" unsafe_mode="$4"
    local site="$FIXTURE_ROOT/failure/site"
    local backup="$BACKUP_FIXTURE_ROOT/bridge-$scenario"
    local bridge="$site/kernel/Kernel.php"
    local log="$FIXTURE_ROOT/failure/bridge-$scenario.log"
    local before
    chown "$unsafe_uid:$unsafe_gid" "$bridge"
    chmod "$unsafe_mode" "$bridge"
    before="$(site_exact_snapshot "$site")"
    if "$RELEASE_ROOT/scripts/install.sh" \
        --site-root "$site" \
        --backup-dir "$backup" \
        --web-user "$WEB_USER" \
        --confirm-maintenance \
        >"$log" 2>&1; then
        fail "installer unexpectedly accepted unsafe bridge metadata: $scenario"
    fi
    grep -q 'protected target must be root:root mode 0644 with exactly one hard link' "$log" \
        || fail "unsafe bridge metadata returned the wrong failure: $scenario"
    [[ "$(site_exact_snapshot "$site")" == "$before" ]] \
        || fail "unsafe bridge metadata rejection changed the site: $scenario"
    [[ ! -e "$(site_state_dir "$site")" && ! -L "$(site_state_dir "$site")" ]] \
        || fail "unsafe bridge metadata rejection created external state: $scenario"
    chown 0:0 "$bridge"
    chmod 0644 "$bridge"
}

assert_bridge_metadata_rejected bridge-owner 65534 65534 0644
assert_bridge_metadata_rejected bridge-mode 0 0 0664
assert_bridge_metadata_rejected bridge-owner-only 0 0 0600
assert_bridge_metadata_rejected bridge-special 0 0 04644

success_site="$FIXTURE_ROOT/success/site"
success_backup="$BACKUP_FIXTURE_ROOT/success"
mkdir -p "$success_site/app/Pay/Epusdt"
printf '%s\n' 'existing-official-adapter-sentinel' \
    > "$success_site/app/Pay/Epusdt/sentinel.txt"
existing_payment_sentinel_hash="$(sha256sum "$success_site/app/Pay/Epusdt/sentinel.txt" | awk '{print $1}')"
success_original_hashes="$(bridge_hashes "$success_site")"
success_callback_parent_identity="$(stat -c '%d:%i:%h:%u:%g:%a' -- \
    "$success_site/app/Controller/User" "$success_site/app/Controller/User/Api")"
success_official_callback_hashes="$(sha256sum \
    "$success_site/app/Controller/User/Api/Order.php" \
    "$success_site/app/Controller/User/Api/RechargeNotification.php")"

# The fake Git reports the exact allowlisted HEAD for this fixture.  A dirty
# payment contract must still fail the preinstall doctor before any state or
# bridge write, even though the commit identity itself remains correct.
payment_core_path="$success_site/app/Pay/Base.php"
payment_core_backup="$BACKUP_FIXTURE_ROOT/payment-core-base.php"
payment_core_dirty_log="$FIXTURE_ROOT/success/payment-core-dirty.log"
payment_core_before="$(site_snapshot "$success_site")"
cp --preserve=all -- "$payment_core_path" "$payment_core_backup"
printf '\n// injected payment compatibility drift\n' >> "$payment_core_path"
if "$RELEASE_ROOT/scripts/doctor.sh" --site-root "$success_site" \
    >"$payment_core_dirty_log" 2>&1; then
    fail 'preinstall doctor unexpectedly accepted a dirty payment core at the pinned HEAD'
fi
grep -q 'compatibility hash mismatch: app/Pay/Base.php' "$payment_core_dirty_log" \
    || fail 'dirty payment core did not fail at the payment compatibility hash gate'
cp --preserve=all -- "$payment_core_backup" "$payment_core_path"
rm -- "$payment_core_backup"
[[ "$(site_snapshot "$success_site")" == "$payment_core_before" ]] \
    || fail 'dirty payment core negative gate changed the official site fixture'
[[ ! -e "$(site_state_dir "$success_site")" && ! -L "$(site_state_dir "$success_site")" ]] \
    || fail 'dirty payment core negative gate created external state'

identity_conflict_state="/var/lib/pika-local-extensions/sites/$(printf 'a%.0s' $(seq 1 64))"
mkdir -p "$identity_conflict_state/runtime"
chown 0:0 "$identity_conflict_state"
chmod 0755 "$identity_conflict_state"
chown "$WEB_UID:$WEB_GID" "$identity_conflict_state/runtime"
chmod 0750 "$identity_conflict_state/runtime"
identity_conflict_log="$FIXTURE_ROOT/success/identity-conflict.log"
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" \
    --backup-dir "$success_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance \
    >"$identity_conflict_log" 2>&1; then
    fail 'installer unexpectedly accepted a web UID/GID reused by another site'
fi
grep -q -- '--web-user UID is already assigned to another local-extension site' "$identity_conflict_log" \
    || fail 'identity reuse did not fail at the UID isolation gate'
[[ ! -e "$success_backup" && ! -L "$success_backup" ]] \
    || fail 'identity reuse rejection wrote a backup path'
remove_test_state "$identity_conflict_state"

callback_parent="$success_site/app/Controller/User/Api"
callback_parent_metadata="$(stat -c '%u:%g:%a' -- "$callback_parent")"
chown 0:"$WEB_GID" "$callback_parent"
chmod 0775 "$callback_parent"
callback_parent_before="$(site_snapshot "$success_site")"
callback_parent_log="$FIXTURE_ROOT/success/callback-parent.log"
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" --backup-dir "$success_backup" \
    --web-user "$WEB_USER" --confirm-maintenance \
    >"$callback_parent_log" 2>&1; then
    fail 'installer unexpectedly accepted a web-writable BE callback ancestor'
fi
grep -q "writable by --web-user through group permissions: $callback_parent" "$callback_parent_log" \
    || fail 'BE callback ancestor did not fail at the protected ancestry gate'
[[ "$(site_snapshot "$success_site")" == "$callback_parent_before" \
    && ! -e "$success_backup" && ! -L "$success_backup" \
    && ! -e "$(site_state_dir "$success_site")" ]] \
    || fail 'BE callback ancestor rejection mutated site or external state'
IFS=: read -r callback_parent_uid callback_parent_gid callback_parent_mode <<< "$callback_parent_metadata"
chown "$callback_parent_uid:$callback_parent_gid" "$callback_parent"
chmod "$callback_parent_mode" "$callback_parent"

callback_target="$success_site/app/Controller/User/Api/PikaBEpusdt.php"
printf '%s\n' 'preexisting-callback-controller' > "$callback_target"
chown 0:0 "$callback_target"
chmod 0644 "$callback_target"
same_callback_before="$(site_snapshot "$success_site")"
same_callback_log="$FIXTURE_ROOT/success/same-callback.log"
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" --backup-dir "$success_backup" \
    --web-user "$WEB_USER" --confirm-maintenance \
    >"$same_callback_log" 2>&1; then
    fail 'installer unexpectedly overwrote a preexisting BE callback controller'
fi
grep -q 'target already exists; use the future update command:.*app/Controller/User/Api/PikaBEpusdt.php' "$same_callback_log" \
    || fail 'preexisting BE callback controller did not fail at the exact target gate'
[[ "$(site_snapshot "$success_site")" == "$same_callback_before" \
    && ! -e "$(site_state_dir "$success_site")" ]] \
    || fail 'BE callback conflict rejection mutated site or external state'
[[ -d "$success_backup" && ! -L "$success_backup" ]] \
    || fail 'BE callback conflict rejection did not leave the validated backup root'
if find "$success_backup" -mindepth 1 -print -quit | grep -q .; then
    fail 'BE callback conflict rejection wrote a backup payload'
fi
rm -- "$callback_target"
rmdir -- "$success_backup"

mkdir -p "$success_site/app/Pay/PikaBEpusdtAdapter"
printf '%s\n' 'preexisting-adapter' \
    > "$success_site/app/Pay/PikaBEpusdtAdapter/preexisting.txt"
same_adapter_before="$(site_snapshot "$success_site")"
same_adapter_log="$FIXTURE_ROOT/success/same-adapter.log"
malicious_php_env_root="$FIXTURE_ROOT/success/malicious-php-env"
malicious_php_env_marker="$FIXTURE_ROOT/success/malicious-php-env-executed"
malicious_tmp="$FIXTURE_ROOT/success/malicious-tmp"
mkdir -p "$malicious_php_env_root/scan" "$malicious_tmp"
printf '%s\n' '<?php file_put_contents(getenv("PIKA_TEST_PHP_ENV_MARKER"), "executed");' \
    > "$malicious_php_env_root/prepend.php"
printf 'auto_prepend_file=%s\n' "$malicious_php_env_root/prepend.php" \
    > "$malicious_php_env_root/php.ini"
cp "$malicious_php_env_root/php.ini" "$malicious_php_env_root/scan/evil.ini"
if PIKA_TEST_PHP_ENV_MARKER="$malicious_php_env_marker" \
    TMPDIR="$malicious_tmp" TMP="$malicious_tmp" TEMP="$malicious_tmp" \
    PHPRC="$malicious_php_env_root/php.ini" \
    PHP_INI_SCAN_DIR="$malicious_php_env_root/scan" \
    "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" \
    --backup-dir "$success_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance \
    >"$same_adapter_log" 2>&1; then
    fail 'installer unexpectedly overwrote a preexisting same-name payment adapter'
fi
[[ ! -e "$malicious_php_env_marker" ]] \
    || fail 'installer honored inherited PHPRC/PHP_INI_SCAN_DIR'
if find "$malicious_tmp" -mindepth 1 -print -quit | grep -q .; then
    fail 'installer honored inherited TMPDIR/TMP/TEMP'
fi
grep -q 'target already exists; use the future update command:.*app/Pay/PikaBEpusdtAdapter' "$same_adapter_log" \
    || fail 'preexisting same-name payment adapter did not fail at the exact target gate'
[[ "$(site_snapshot "$success_site")" == "$same_adapter_before" ]] \
    || fail 'same-name payment adapter rejection changed the site'
[[ -d "$success_backup" && ! -L "$success_backup" ]] \
    || fail 'same-name payment adapter rejection did not leave only the validated backup root'
if find "$success_backup" -mindepth 1 -print -quit | grep -q .; then
    fail 'same-name payment adapter rejection wrote a backup payload'
fi
[[ ! -e "$(site_state_dir "$success_site")" ]] \
    || fail 'same-name payment adapter rejection wrote external state'
rm -f -- "$success_site/app/Pay/PikaBEpusdtAdapter/preexisting.txt"
rmdir -- "$success_site/app/Pay/PikaBEpusdtAdapter"
rmdir -- "$success_backup"

untrusted_php_dir="/opt/pika-test-space/untrusted"
untrusted_php="$untrusted_php_dir/php"
untrusted_php_marker="/tmp/pika-integration-untrusted-php-executed"
mkdir -p "$untrusted_php_dir"
chown 0:0 "$untrusted_php_dir"
chmod 0755 "$untrusted_php_dir"
printf '#!/bin/bash\nprintf attacked > %q\nexit 99\n' "$untrusted_php_marker" > "$untrusted_php"
chown "$WEB_UID:$WEB_GID" "$untrusted_php"
chmod 0755 "$untrusted_php"
untrusted_php_log="$FIXTURE_ROOT/success/untrusted-php.log"
if "$RELEASE_ROOT/scripts/doctor.sh" \
    --site-root "$success_site" \
    --php "$untrusted_php" \
    >"$untrusted_php_log" 2>&1; then
    fail 'doctor unexpectedly accepted a web-owned PHP executable'
fi
[[ ! -e "$untrusted_php_marker" ]] \
    || fail 'doctor executed a web-owned PHP executable before rejecting it'
if ! grep -q 'PHP binary must be root:root with a valid mode' "$untrusted_php_log"; then
    sed -n '1,8p' "$untrusted_php_log" >&2
    fail 'web-owned PHP executable did not fail at the shared trust gate'
fi

rm_probe_hash="$(sha256sum "$RELEASE_ROOT/compatibility.json" | awk '{print $1}')"
rm_probe_log="$FIXTURE_ROOT/success/rm-probe.log"
if "$RELEASE_ROOT/scripts/doctor.sh" \
    --site-root "$success_site" \
    --php /bin/rm \
    >"$rm_probe_log" 2>&1; then
    fail 'doctor unexpectedly accepted /bin/rm as PHP'
fi
grep -q 'PHP CLI basename is not allowed' "$rm_probe_log" \
    || fail '/bin/rm did not fail before the PHP identity probe'
[[ -f "$RELEASE_ROOT/compatibility.json" ]] \
    || fail '/bin/rm probe deleted the writable compatibility manifest'
[[ "$(sha256sum "$RELEASE_ROOT/compatibility.json" | awk '{print $1}')" == "$rm_probe_hash" ]] \
    || fail '/bin/rm probe changed the writable compatibility manifest'

acl_php_root="/opt/pika-test-space/acl"
mkdir -p "$acl_php_root/file" "$acl_php_root/ancestor/bin"
cat > "$acl_php_root/set-posix-acl.c" <<'EOF'
#include <endian.h>
#include <errno.h>
#include <linux/posix_acl.h>
#include <linux/posix_acl_xattr.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <sys/types.h>
#include <sys/xattr.h>

int main(int argc, char **argv) {
    if (argc != 3) return 2;
    unsigned long uid = strtoul(argv[2], NULL, 10);
    if (uid > UINT32_MAX) return 3;
    struct {
        struct posix_acl_xattr_header header;
        struct posix_acl_xattr_entry entries[5];
    } acl = {0};
    acl.header.a_version = htole32(POSIX_ACL_XATTR_VERSION);
    acl.entries[0] = (struct posix_acl_xattr_entry){htole16(ACL_USER_OBJ), htole16(7), htole32(UINT32_MAX)};
    acl.entries[1] = (struct posix_acl_xattr_entry){htole16(ACL_USER), htole16(4), htole32((uint32_t)uid)};
    acl.entries[2] = (struct posix_acl_xattr_entry){htole16(ACL_GROUP_OBJ), htole16(5), htole32(UINT32_MAX)};
    acl.entries[3] = (struct posix_acl_xattr_entry){htole16(ACL_MASK), htole16(5), htole32(UINT32_MAX)};
    acl.entries[4] = (struct posix_acl_xattr_entry){htole16(ACL_OTHER), htole16(5), htole32(UINT32_MAX)};
    if (setxattr(argv[1], "system.posix_acl_access", &acl, sizeof(acl), 0) != 0) {
        perror("setxattr");
        return 4;
    }
    return 0;
}
EOF
gcc -O2 -Wall -Wextra -o "$acl_php_root/set-posix-acl" "$acl_php_root/set-posix-acl.c"

receipt_input_root="$BACKUP_FIXTURE_ROOT/receipt-input"
receipt_input_site="$receipt_input_root/site"
receipt_input_backup="$receipt_input_root/backup"
mkdir -p \
    "$receipt_input_site" \
    "$receipt_input_backup/site" \
    "$receipt_input_site/local-extensions"
chmod 0755 \
    "$receipt_input_root" \
    "$receipt_input_site" \
    "$receipt_input_backup" \
    "$receipt_input_backup/site" \
    "$receipt_input_site/local-extensions"
for bridge in \
    kernel/Kernel.php \
    kernel/Helper.php \
    app/Controller/Admin/Api/Config.php \
    app/View/Admin/Footer.html \
    assets/common/js/editor/markdown/editorv2.js; do
    mkdir -p \
        "$receipt_input_site/$(dirname -- "$bridge")" \
        "$receipt_input_backup/site/$(dirname -- "$bridge")"
    printf 'current:%s\n' "$bridge" > "$receipt_input_site/$bridge"
    printf 'before:%s\n' "$bridge" > "$receipt_input_backup/site/$bridge"
done
printf 'installed\n' > "$receipt_input_site/local-extensions/bootstrap.php"
printf 'local-extensions/bootstrap.php\n' > "$receipt_input_root/installed-files.txt"
find "$receipt_input_root" -type d -exec chown 0:0 -- {} +
find "$receipt_input_root" -type d -exec chmod 0755 -- {} +
find "$receipt_input_root" -type f -exec chown 0:0 -- {} +
find "$receipt_input_root" -type f -exec chmod 0644 -- {} +

receipt_writer_args=(
    --output "$receipt_input_root/install-receipt.json"
    --site-root "$receipt_input_site"
    --backup-dir "$receipt_input_backup"
    --bridge-version 3.6.4
    --upstream-commit fixture
    --file-list "$receipt_input_root/installed-files.txt"
    --web-uid "$WEB_UID"
    --web-gid "$WEB_GID"
)
receipt_bridge="$receipt_input_site/assets/common/js/editor/markdown/editorv2.js"
receipt_hardlink="$receipt_input_site/assets/common/js/editor/markdown/editorv2.hardlink"
ln -- "$receipt_bridge" "$receipt_hardlink"
receipt_hardlink_log="$receipt_input_root/hardlink.log"
if php "$RELEASE_ROOT/scripts/write-receipt.php" \
    "${receipt_writer_args[@]}" >"$receipt_hardlink_log" 2>&1; then
    fail 'receipt writer unexpectedly accepted a hard-linked bridge input'
fi
grep -q 'current bridge receipt input must be a canonical regular file with exactly one hard link' \
    "$receipt_hardlink_log" \
    || fail 'receipt writer hardlink gate returned the wrong failure'
rm -- "$receipt_hardlink"

"$acl_php_root/set-posix-acl" "$receipt_bridge" "$WEB_UID"
receipt_acl_log="$receipt_input_root/acl.log"
if php "$RELEASE_ROOT/scripts/write-receipt.php" \
    "${receipt_writer_args[@]}" >"$receipt_acl_log" 2>&1; then
    fail 'receipt writer unexpectedly accepted a bridge input with an extended ACL'
fi
grep -q 'current bridge receipt input must not have an extended POSIX ACL' \
    "$receipt_acl_log" \
    || fail 'receipt writer ACL gate returned the wrong failure'
mv "$receipt_bridge" "$receipt_bridge.acl"
install -o root -g root -m 0644 "$receipt_bridge.acl" "$receipt_bridge"
rm -- "$receipt_bridge.acl"

for receipt_metadata_case in owner mode-0664 mode-0600 special; do
    case "$receipt_metadata_case" in
        owner) chown 65534:65534 "$receipt_bridge" ;;
        mode-0664) chmod 0664 "$receipt_bridge" ;;
        mode-0600) chmod 0600 "$receipt_bridge" ;;
        special) chmod 04644 "$receipt_bridge" ;;
    esac
    receipt_metadata_log="$receipt_input_root/metadata-$receipt_metadata_case.log"
    if php "$RELEASE_ROOT/scripts/write-receipt.php" \
        "${receipt_writer_args[@]}" >"$receipt_metadata_log" 2>&1; then
        fail "receipt writer unexpectedly accepted unsafe bridge metadata: $receipt_metadata_case"
    fi
    grep -q 'current bridge receipt input must be root:root mode 0644 without special bits' \
        "$receipt_metadata_log" \
        || fail "receipt writer bridge metadata gate returned the wrong failure: $receipt_metadata_case"
    chown 0:0 "$receipt_bridge"
    chmod 0644 "$receipt_bridge"
done

install -o root -g root -m 0755 "$(realpath -e /usr/local/bin/php)" "$acl_php_root/file/php"
"$acl_php_root/set-posix-acl" "$acl_php_root/file/php" "$WEB_UID"
acl_file_log="$FIXTURE_ROOT/success/acl-file.log"
if "$RELEASE_ROOT/scripts/doctor.sh" \
    --site-root "$success_site" \
    --php "$acl_php_root/file/php" \
    >"$acl_file_log" 2>&1; then
    fail 'doctor unexpectedly accepted a PHP executable with an extended ACL'
fi
grep -q 'PHP binary must not have an extended POSIX ACL' "$acl_file_log" \
    || fail 'PHP executable ACL did not fail at the shared trust gate'

install -o root -g root -m 0755 "$(realpath -e /usr/local/bin/php)" "$acl_php_root/ancestor/bin/php"
"$acl_php_root/set-posix-acl" "$acl_php_root/ancestor/bin" "$WEB_UID"
acl_ancestor_log="$FIXTURE_ROOT/success/acl-ancestor.log"
if "$RELEASE_ROOT/scripts/doctor.sh" \
    --site-root "$success_site" \
    --php "$acl_php_root/ancestor/bin/php" \
    >"$acl_ancestor_log" 2>&1; then
    fail 'doctor unexpectedly accepted a PHP ancestor with an extended ACL'
fi
grep -q 'PHP binary ancestor must not have an extended POSIX ACL' "$acl_ancestor_log" \
    || fail 'PHP ancestor ACL did not fail at the shared trust gate'

php80_log="$FIXTURE_ROOT/success/php80.log"
if "$RELEASE_ROOT/scripts/doctor.sh" \
    --site-root "$success_site" \
    --php "$PHP80_FIXTURE" \
    >"$php80_log" 2>&1; then
    fail 'simulated PHP 8.0 unexpectedly passed the doctor gate'
fi
grep -q 'PHP 8.1 or newer is required, found 8.0.30' "$php80_log" \
    || fail 'simulated PHP 8.0 did not fail at the minimum-version gate'

non_root_log="$FIXTURE_ROOT/success/non-root.log"
if runuser -u "$WEB_USER" -- "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" \
    --backup-dir "$success_backup" \
    --web-user "$WEB_USER" \
    >"$non_root_log" 2>&1; then
    fail 'non-root installer invocation unexpectedly succeeded'
fi
grep -q 'installer must run as root (use sudo)' "$non_root_log" \
    || fail 'non-root installer invocation did not fail at the root gate'
assert_absent "$success_site" local-extensions app/View/User/Theme/Pika app/Pay/PikaBEpusdtAdapter runtime/local-extensions

missing_web_user_log="$FIXTURE_ROOT/success/missing-web-user.log"
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" \
    --backup-dir "$success_backup" \
    >"$missing_web_user_log" 2>&1; then
    fail 'installer invocation without --web-user unexpectedly succeeded'
fi
grep -q -- '--web-user USER' "$missing_web_user_log" \
    || fail 'missing --web-user invocation did not fail at argument validation'
assert_absent "$success_site" local-extensions app/View/User/Theme/Pika app/Pay/PikaBEpusdtAdapter runtime/local-extensions

missing_install_maintenance_log="$FIXTURE_ROOT/success/missing-install-maintenance.log"
before_missing_install_maintenance="$(site_snapshot "$success_site")"
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" \
    --backup-dir "$success_backup" \
    --web-user "$WEB_USER" \
    >"$missing_install_maintenance_log" 2>&1; then
    fail 'install without --confirm-maintenance unexpectedly succeeded'
fi
grep -q -- '--confirm-maintenance is required' "$missing_install_maintenance_log" \
    || fail 'install without --confirm-maintenance did not fail at the maintenance gate'
[[ "$(site_snapshot "$success_site")" == "$before_missing_install_maintenance" ]] \
    || fail 'missing install maintenance confirmation changed the site'
[[ ! -e "$success_backup" && ! -L "$success_backup" ]] \
    || fail 'missing install maintenance confirmation created a backup path'
[[ ! -e "$(site_state_dir "$success_site")" ]] \
    || fail 'missing install maintenance confirmation created external state'

trusted_release_before="$(site_snapshot "$success_site")"
chmod 0777 "$RELEASE_ROOT/manager"
untrusted_release_log="$FIXTURE_ROOT/success/untrusted-release.log"
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" \
    --backup-dir "$success_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance \
    >"$untrusted_release_log" 2>&1; then
    chmod 0755 "$RELEASE_ROOT/manager"
    fail 'installer unexpectedly accepted a writable release tree'
fi
chmod 0755 "$RELEASE_ROOT/manager"
grep -q 'trusted release path must not be group/world writable' "$untrusted_release_log" \
    || fail 'writable release tree did not fail at the trusted release gate'
[[ "$(site_snapshot "$success_site")" == "$trusted_release_before" ]] \
    || fail 'writable release rejection changed the site'
[[ ! -e "$success_backup" && ! -L "$success_backup" ]] \
    || fail 'writable release rejection created a backup path'
[[ ! -e "$(site_state_dir "$success_site")" ]] \
    || fail 'writable release rejection created external state'

unsafe_backup_parent="$BACKUP_FIXTURE_ROOT/unsafe-parent"
mkdir "$unsafe_backup_parent"
chown "$WEB_UID:$WEB_GID" "$unsafe_backup_parent"
chmod 0755 "$unsafe_backup_parent"
unsafe_backup_log="$FIXTURE_ROOT/success/unsafe-backup-parent.log"
unsafe_backup_before="$(site_snapshot "$success_site")"
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" \
    --backup-dir "$unsafe_backup_parent/new-backups" \
    --web-user "$WEB_USER" \
    --confirm-maintenance \
    >"$unsafe_backup_log" 2>&1; then
    fail 'installer unexpectedly accepted a web-owned backup ancestor'
fi
grep -q 'backup directory ancestor must be root:root' "$unsafe_backup_log" \
    || fail 'unsafe backup ancestor did not fail before mkdir'
[[ "$(site_snapshot "$success_site")" == "$unsafe_backup_before" ]] \
    || fail 'unsafe backup ancestor rejection changed the site'
[[ ! -e "$unsafe_backup_parent/new-backups" && ! -L "$unsafe_backup_parent/new-backups" ]] \
    || fail 'unsafe backup ancestor rejection created a child path'
[[ ! -e "$(site_state_dir "$success_site")" ]] \
    || fail 'unsafe backup ancestor rejection created external state'
chown 0:0 "$unsafe_backup_parent"
rmdir "$unsafe_backup_parent"

# Official 3.6.4 runtime roots must fail closed before any installer write when
# an exact root is a symlink, dangerously writable, or carries an extended ACL.
mkdir -p "$success_site/assets/cache"
printf '%s\n' 'preexisting-cache-content' > "$success_site/assets/cache/preexisting.txt"
cache_sentinel_hash="$(sha256sum "$success_site/assets/cache/preexisting.txt" | awk '{print $1}')"
mv "$success_site/assets/cache" "$success_site/assets/cache.real"
ln -s cache.real "$success_site/assets/cache"
runtime_symlink_before="$(site_snapshot "$success_site")"
runtime_symlink_log="$FIXTURE_ROOT/success/runtime-symlink.log"
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" \
    --backup-dir "$success_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance \
    >"$runtime_symlink_log" 2>&1; then
    fail 'installer unexpectedly accepted a symlinked official runtime root'
fi
grep -q 'official runtime directory is not a real directory: assets/cache' "$runtime_symlink_log" \
    || fail 'symlinked official runtime root did not fail at the compatibility contract gate'
[[ "$(site_snapshot "$success_site")" == "$runtime_symlink_before" ]] \
    || fail 'symlinked official runtime rejection changed the site'
[[ ! -e "$success_backup" && ! -L "$success_backup" ]] \
    || fail 'symlinked official runtime rejection wrote a backup path'
rm "$success_site/assets/cache"
mv "$success_site/assets/cache.real" "$success_site/assets/cache"

mkdir "$success_site/runtime"
chown 0:0 "$success_site/runtime"
chmod 0777 "$success_site/runtime"
runtime_mode_before="$(site_snapshot "$success_site")"
runtime_mode_log="$FIXTURE_ROOT/success/runtime-mode.log"
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" \
    --backup-dir "$success_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance \
    >"$runtime_mode_log" 2>&1; then
    fail 'installer unexpectedly accepted a world-writable official runtime root'
fi
grep -q 'official runtime directory must not be group/world writable: runtime' "$runtime_mode_log" \
    || fail 'world-writable official runtime root did not fail at the mode gate'
[[ "$(site_snapshot "$success_site")" == "$runtime_mode_before" ]] \
    || fail 'world-writable official runtime rejection changed the site'
chmod 0755 "$success_site/runtime"
rmdir "$success_site/runtime"

"$acl_php_root/set-posix-acl" "$success_site/assets/cache" "$WEB_UID"
runtime_acl_log="$FIXTURE_ROOT/success/runtime-acl.log"
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" \
    --backup-dir "$success_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance \
    >"$runtime_acl_log" 2>&1; then
    fail 'installer unexpectedly accepted an official runtime root with an extended ACL'
fi
grep -q 'official runtime directory must not have an extended POSIX ACL' "$runtime_acl_log" \
    || fail 'official runtime ACL did not fail at the compatibility contract gate'
mv "$success_site/assets/cache" "$success_site/assets/cache.acl"
mkdir "$success_site/assets/cache"
chown 0:0 "$success_site/assets/cache"
chmod 0755 "$success_site/assets/cache"
mv "$success_site/assets/cache.acl/preexisting.txt" "$success_site/assets/cache/preexisting.txt"
rmdir "$success_site/assets/cache.acl"

# An official mutable directory may already belong to the dedicated Web
# identity with a stricter safe mode.  Reject a missing owner-rwx bit before
# any write, then prove that a canonical 0700 directory installs and is
# normalized to the contract's final 0750 mode.
mkdir -p "$success_site/kernel/Install/OS"
chown "$WEB_UID:$WEB_GID" "$success_site/kernel/Install/OS"
chmod 0500 "$success_site/kernel/Install/OS"
runtime_owner_mode_before="$(site_snapshot "$success_site")"
runtime_owner_mode_log="$FIXTURE_ROOT/success/runtime-owner-mode.log"
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" \
    --backup-dir "$success_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance \
    >"$runtime_owner_mode_log" 2>&1; then
    fail 'installer unexpectedly accepted an official Web-owned runtime root without owner rwx'
fi
grep -q 'official runtime directory owner must have rwx permissions: kernel/Install/OS' \
    "$runtime_owner_mode_log" \
    || fail 'Web-owned official runtime root without owner rwx did not fail at the mode gate'
[[ "$(site_snapshot "$success_site")" == "$runtime_owner_mode_before" ]] \
    || fail 'unsafe Web-owned official runtime mode rejection changed the site'
[[ ! -e "$success_backup" && ! -L "$success_backup" ]] \
    || fail 'unsafe Web-owned official runtime mode rejection wrote a backup path'
chmod 1700 "$success_site/kernel/Install/OS"
runtime_special_mode_before="$(site_snapshot "$success_site")"
runtime_special_mode_log="$FIXTURE_ROOT/success/runtime-special-mode.log"
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" \
    --backup-dir "$success_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance \
    >"$runtime_special_mode_log" 2>&1; then
    fail 'installer unexpectedly accepted special permission bits on an official runtime root'
fi
grep -q 'official runtime directory must not have special permission bits: kernel/Install/OS' \
    "$runtime_special_mode_log" \
    || fail 'special permission bits did not fail at the official runtime mode gate'
[[ "$(site_snapshot "$success_site")" == "$runtime_special_mode_before" ]] \
    || fail 'special permission bit rejection changed the site'
[[ ! -e "$success_backup" && ! -L "$success_backup" ]] \
    || fail 'special permission bit rejection wrote a backup path'
chmod 0700 "$success_site/kernel/Install/OS"

# A real Web-UID process invalidates maintenance even when the caller supplied
# the confirmation flag. The installer must stop before backup or site writes
# and must never terminate the process itself.
runuser -u "$WEB_USER" -- sleep 60 &
active_web_pid=$!
for _ in $(seq 1 100); do
    [[ -r "/proc/$active_web_pid/status" ]] && break
    sleep 0.01
done
active_web_log="$FIXTURE_ROOT/success/active-web-process.log"
active_web_before="$(site_snapshot "$success_site")"
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" \
    --backup-dir "$success_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance \
    >"$active_web_log" 2>&1; then
    kill "$active_web_pid" 2>/dev/null || true
    wait "$active_web_pid" 2>/dev/null || true
    fail 'installer unexpectedly accepted an active Web-UID process'
fi
kill "$active_web_pid" 2>/dev/null || true
wait "$active_web_pid" 2>/dev/null || true
grep -q 'dedicated Web identity must have zero processes' "$active_web_log" \
    || fail 'active Web-UID process did not fail at the real maintenance gate'
[[ "$(site_snapshot "$success_site")" == "$active_web_before" ]] \
    || fail 'active Web-UID process rejection changed the site'
[[ ! -e "$success_backup" && ! -L "$success_backup" ]] \
    || fail 'active Web-UID process rejection wrote a backup path'

# Existing official mutable content may be read-only in the clean baseline.
# Installation must preserve bytes while granting the dedicated Web identity
# the exact write contract needed by store, plugin, pay, theme and runtime.
mkdir -p \
    "$success_site/app/Plugin/FixturePlugin/Nested" \
    "$success_site/runtime/throttle"
printf '%s\n' 'existing-plugin-config' \
    > "$success_site/app/Plugin/FixturePlugin/Nested/Config.php"
printf '%s\n' '<?php return [];' > "$success_site/config/store.php"
printf '%s\n' 'trusted-proxy-fixture' > "$success_site/runtime/trusted_proxies"
for throttle_name in \
    11111111111111111111111111111111 \
    22222222222222222222222222222222 \
    33333333333333333333333333333333; do
    printf '%s\n' "official-throttle-$throttle_name" \
        > "$success_site/runtime/throttle/$throttle_name"
done
chown "$WEB_UID:$WEB_GID" \
    "$success_site/runtime/throttle" \
    "$success_site/runtime/throttle/11111111111111111111111111111111" \
    "$success_site/runtime/throttle/22222222222222222222222222222222" \
    "$success_site/runtime/throttle/33333333333333333333333333333333"
chmod 0777 \
    "$success_site/runtime/throttle" \
    "$success_site/runtime/throttle/11111111111111111111111111111111" \
    "$success_site/runtime/throttle/22222222222222222222222222222222" \
    "$success_site/runtime/throttle/33333333333333333333333333333333"
chmod 0555 \
    "$success_site/app/Plugin/FixturePlugin" \
    "$success_site/app/Plugin/FixturePlugin/Nested"
chmod 0444 \
    "$success_site/app/Plugin/FixturePlugin/Nested/Config.php" \
    "$success_site/app/Pay/Epusdt/sentinel.txt" \
    "$success_site/config/store.php" \
    "$success_site/runtime/trusted_proxies"
prepare_official_smarty_tree "$success_site"
success_smarty_identity="$(
    find -P "$success_site/runtime/view" -printf '%P %D:%i:%n:%U:%G\n' | LC_ALL=C sort
)"
mapfile -d '' -t success_smarty_files < <(find -P "$success_site/runtime/view" -type f -print0)
[[ "${#success_smarty_files[@]}" == 1 ]] \
    || fail 'official Smarty fetch did not create exactly one compiled file'
success_smarty_file="${success_smarty_files[0]}"
assert_owner_mode "$success_smarty_file" "$WEB_UID" "$WEB_GID" 644
success_smarty_hash="$(sha256sum "$success_smarty_file" | awk '{print $1}')"

# A disposable root-owned wrapper records only the success install's chown
# sequence while delegating every operation to the original trusted binary.
mv /usr/bin/chown /opt/pika-test-space/bin/chown.real
cat > /usr/bin/chown <<'EOF'
#!/bin/bash
set -euo pipefail
if [[ -n "${PIKA_TEST_CHOWN_LOG:-}" ]]; then
    printf '%s\n' "$*" >> "$PIKA_TEST_CHOWN_LOG"
fi
if [[ -n "${PIKA_TEST_CHOWN_FAIL_OWNER:-}" \
    && -n "${PIKA_TEST_CHOWN_FAIL_PATH:-}" \
    && -n "${PIKA_TEST_CHOWN_FAIL_MARKER:-}" \
    && "$#" -eq 4 && "$1" == -h \
    && "$2" == "$PIKA_TEST_CHOWN_FAIL_OWNER" \
    && "$3" == -- && "$4" == "$PIKA_TEST_CHOWN_FAIL_PATH" ]]; then
    if (set -o noclobber; : > "$PIKA_TEST_CHOWN_FAIL_MARKER") 2>/dev/null; then
        printf 'INJECTED_FINAL_AUTHORIZATION_CHOWN_FAILURE\n' >&2
        exit 95
    fi
fi
exec /opt/pika-test-space/bin/chown.real "$@"
EOF
chmod 0755 /usr/bin/chown

# Fail after final authorization has already handed earlier shallow roots to
# the Web UID.  Rollback must first reclaim every bound shallow root, then
# restore the exact pre-install bytes, ownership and modes.
authorization_failure_site="$FIXTURE_ROOT/authorization-failure/site"
authorization_failure_backup="$BACKUP_FIXTURE_ROOT/authorization-failure"
authorization_failure_state="$(site_state_dir "$authorization_failure_site")"
authorization_failure_log="$FIXTURE_ROOT/authorization-failure/install.log"
authorization_failure_trace="$FIXTURE_ROOT/authorization-failure/chown-order.log"
authorization_failure_marker="$FIXTURE_ROOT/authorization-failure/chown-failed"
chown "$WEB_UID:$WEB_GID" "$authorization_failure_site/app/Pay"
chmod 0700 "$authorization_failure_site/app/Pay"
mkdir -p "$authorization_failure_site/app/Pay/Epusdt"
printf '%s\n' 'rollback-payment-adapter-sentinel' \
    > "$authorization_failure_site/app/Pay/Epusdt/sentinel.txt"
chown "$WEB_UID:$WEB_GID" "$authorization_failure_site/app/Pay/Epusdt"
chmod 0777 "$authorization_failure_site/app/Pay/Epusdt"
authorization_payment_adapter_identity="$(
    stat -c '%d:%i:%h:%u:%g:%a' -- "$authorization_failure_site/app/Pay/Epusdt"
)"
mkdir -p "$authorization_failure_site/runtime/throttle"
for throttle_name in \
    44444444444444444444444444444444 \
    55555555555555555555555555555555 \
    66666666666666666666666666666666; do
    printf '%s\n' "rollback-throttle-$throttle_name" \
        > "$authorization_failure_site/runtime/throttle/$throttle_name"
done
chown "$WEB_UID:$WEB_GID" \
    "$authorization_failure_site/runtime" \
    "$authorization_failure_site/runtime/throttle" \
    "$authorization_failure_site/runtime/throttle/44444444444444444444444444444444" \
    "$authorization_failure_site/runtime/throttle/55555555555555555555555555555555" \
    "$authorization_failure_site/runtime/throttle/66666666666666666666666666666666"
chmod 0750 "$authorization_failure_site/runtime"
chmod 0777 \
    "$authorization_failure_site/runtime/throttle" \
    "$authorization_failure_site/runtime/throttle/44444444444444444444444444444444" \
    "$authorization_failure_site/runtime/throttle/55555555555555555555555555555555" \
    "$authorization_failure_site/runtime/throttle/66666666666666666666666666666666"
authorization_throttle_paths=(
    "$authorization_failure_site/runtime/throttle"
    "$authorization_failure_site/runtime/throttle/44444444444444444444444444444444"
    "$authorization_failure_site/runtime/throttle/55555555555555555555555555555555"
    "$authorization_failure_site/runtime/throttle/66666666666666666666666666666666"
)
authorization_throttle_identities=()
for throttle_path in "${authorization_throttle_paths[@]}"; do
    authorization_throttle_identities+=("$(stat -c '%d:%i:%h:%u:%g:%a' -- "$throttle_path")")
done
prepare_official_smarty_tree "$authorization_failure_site"
authorization_smarty_identity="$(
    find -P "$authorization_failure_site/runtime/view" -printf '%P %D:%i:%n:%U:%G:%m\n' | LC_ALL=C sort
)"
authorization_failure_before="$(site_snapshot "$authorization_failure_site")"
authorization_failure_exact_before="$(site_exact_snapshot "$authorization_failure_site")"
mkdir -p "$authorization_failure_backup"
if PIKA_TEST_CHOWN_LOG="$authorization_failure_trace" \
    PIKA_TEST_CHOWN_FAIL_OWNER="$WEB_UID:$WEB_GID" \
    PIKA_TEST_CHOWN_FAIL_PATH="$authorization_failure_site/app/Plugin" \
    PIKA_TEST_CHOWN_FAIL_MARKER="$authorization_failure_marker" \
    "$RELEASE_ROOT/scripts/install.sh" \
        --site-root "$authorization_failure_site" \
        --backup-dir "$authorization_failure_backup" \
        --web-user "$WEB_USER" \
        --confirm-maintenance \
        >"$authorization_failure_log" 2>&1; then
    fail 'injected mid-authorization chown failure unexpectedly succeeded'
fi
grep -q 'INJECTED_FINAL_AUTHORIZATION_CHOWN_FAILURE' "$authorization_failure_log" \
    || fail 'mid-authorization failure did not reach the exact chown injection'
grep -q 'install failed; original bridge files restored' "$authorization_failure_log" \
    || fail 'mid-authorization failure did not report a complete rollback'
if grep -q 'ROLLBACK_INCOMPLETE' "$authorization_failure_log"; then
    fail 'mid-authorization failure reported an incomplete rollback'
fi
[[ -f "$authorization_failure_marker" && ! -L "$authorization_failure_marker" ]] \
    || fail 'mid-authorization chown marker is missing or unsafe'
authorization_target_trace="-h $WEB_UID:$WEB_GID -- $authorization_failure_site/app/Plugin"
authorization_prior_trace="-h $WEB_UID:$WEB_GID -- $authorization_failure_site/app/View/User/Theme"
authorization_target_count="$(grep -Fxc -- "$authorization_target_trace" "$authorization_failure_trace" || true)"
authorization_target_line="$(grep -nF -- "$authorization_target_trace" "$authorization_failure_trace" | cut -d: -f1)"
authorization_prior_line="$(grep -nF -- "$authorization_prior_trace" "$authorization_failure_trace" | tail -n 1 | cut -d: -f1)"
[[ "$authorization_target_count" == 1 \
    && "$authorization_target_line" =~ ^[0-9]+$ \
    && "$authorization_prior_line" =~ ^[0-9]+$ \
    && "$authorization_prior_line" -lt "$authorization_target_line" ]] \
    || fail 'mid-authorization injection did not occur after a prior shallow-root handoff'
[[ "$(site_snapshot "$authorization_failure_site")" == "$authorization_failure_before" \
    && "$(site_exact_snapshot "$authorization_failure_site")" == "$authorization_failure_exact_before" ]] \
    || fail 'mid-authorization rollback did not restore the exact site snapshot'
[[ ! -e "$authorization_failure_state" && ! -L "$authorization_failure_state" ]] \
    || fail 'mid-authorization rollback left external state'
if find "$authorization_failure_backup" \
    \( -name install-receipt.json -o -name installed-files.txt \) -print -quit | grep -q .; then
    fail 'mid-authorization rollback left successful receipt artifacts'
fi
for rolled_back_new in assets/cache app/Plugin kernel/Install/OS; do
    [[ ! -e "$authorization_failure_site/$rolled_back_new" \
        && ! -L "$authorization_failure_site/$rolled_back_new" ]] \
        || fail "mid-authorization rollback left a new runtime directory: $rolled_back_new"
done
assert_owner_mode "$authorization_failure_site/app/Pay" "$WEB_UID" "$WEB_GID" 700
[[ "$(stat -c '%d:%i:%h:%u:%g:%a' \
    -- "$authorization_failure_site/app/Pay/Epusdt")" \
    == "$authorization_payment_adapter_identity" ]] \
    || fail 'mid-authorization rollback did not restore payment adapter inode and mode'
assert_owner_mode \
    "$authorization_failure_site/app/Pay/Epusdt" "$WEB_UID" "$WEB_GID" 777
assert_owner_mode "$authorization_failure_site/runtime" "$WEB_UID" "$WEB_GID" 750
for throttle_index in "${!authorization_throttle_paths[@]}"; do
    throttle_path="${authorization_throttle_paths[$throttle_index]}"
    [[ "$(stat -c '%d:%i:%h:%u:%g:%a' -- "$throttle_path")" \
        == "${authorization_throttle_identities[$throttle_index]}" ]] \
        || fail "mid-authorization rollback did not restore throttle inode and mode: $throttle_path"
    assert_owner_mode "$throttle_path" "$WEB_UID" "$WEB_GID" 777
done
[[ "$(find -P "$authorization_failure_site/runtime/view" -printf '%P %D:%i:%n:%U:%G:%m\n' | LC_ALL=C sort)" \
    == "$authorization_smarty_identity" ]] \
    || fail 'mid-authorization rollback did not restore official Smarty inode and mode'
assert_owner_mode "$authorization_failure_site/runtime/view" "$WEB_UID" "$WEB_GID" 771
assert_owner_mode "$authorization_failure_site/runtime/view/compile" "$WEB_UID" "$WEB_GID" 771
for rolled_back_existing in app/View/User/Theme config kernel/Install; do
    assert_owner_mode "$authorization_failure_site/$rolled_back_existing" 0 0 755
done

chown_trace="$FIXTURE_ROOT/success/chown-order.log"
mkdir -p "$success_backup"
chown "$WEB_UID:$WEB_GID" "$success_site/app/Pay"
chmod 0700 "$success_site/app/Pay"
chown "$WEB_UID:$WEB_GID" "$success_site/app/Pay/Epusdt"
chmod 0777 "$success_site/app/Pay/Epusdt"
chown "$WEB_UID:$WEB_GID" "$success_site/app/View/User/Theme"
chmod 0750 "$success_site/app/View/User/Theme"
PIKA_TEST_CHOWN_LOG="$chown_trace" "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" \
    --backup-dir "$success_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance
prepare_payment_rule_isolation "$success_site" success
"$RELEASE_ROOT/scripts/doctor.sh" --site-root "$success_site" --installed
php "$RELEASE_SOURCE_ROOT/tests/installed-theme-smarty-compile.php" "$success_site"

for immutable_root in \
    local-extensions \
    app/View/Admin/LocalExtensions \
    app/View/User/Theme/Pika \
    app/Pay/PikaBEpusdtAdapter \
    assets/admin/controller/local-extensions \
    assets/local-extensions; do
    if find "$success_site/$immutable_root" \
        ! -path '*/Pika/Setting.php' \
        ! -path '*/PikaBEpusdtAdapter/runtime.log' \
        \( ! -user root -o ! -group root \) -print -quit | grep -q .; then
        fail "immutable payload is not root-owned: $immutable_root"
    fi
    if find "$success_site/$immutable_root" -type d ! -perm 0755 -print -quit | grep -q .; then
        fail "immutable payload directory mode is not 0755: $immutable_root"
    fi
    if find "$success_site/$immutable_root" -type f \
        ! -path '*/Pika/Setting.php' \
        ! -path '*/PikaBEpusdtAdapter/runtime.log' \
        ! -perm 0644 -print -quit | grep -q .; then
        fail "immutable payload file mode is not 0644: $immutable_root"
    fi
done

for bridge in \
    kernel/Kernel.php \
    kernel/Helper.php \
    app/Controller/Admin/Api/Config.php \
    app/View/Admin/Footer.html \
    assets/common/js/editor/markdown/editorv2.js \
    app/Controller/Admin/LocalExtensions.php \
    app/Controller/Admin/Api/LocalExtensions.php \
    app/Controller/User/Api/PikaBEpusdt.php; do
    assert_owner_mode "$success_site/$bridge" 0 0 644
done
assert_owner_mode "$success_site/app/View/User/Theme/Pika/Setting.php" "$WEB_UID" "$WEB_GID" 640
assert_owner_mode "$success_site/app/Pay/PikaBEpusdtAdapter/runtime.log" "$WEB_UID" "$WEB_GID" 640
for runtime_contract in \
    assets/cache:755 \
    assets/cache/general:755 \
    assets/cache/general/image:755 \
    assets/cache/pika-supply-sync:755 \
    app/Pay:755 \
    app/Plugin:755 \
    app/View/User/Theme:755 \
    kernel/Install/OS:750 \
    runtime:750; do
    runtime_relative="${runtime_contract%:*}"
    runtime_mode="${runtime_contract##*:}"
    assert_owner_mode "$success_site/$runtime_relative" "$WEB_UID" "$WEB_GID" "$runtime_mode"
done
assert_owner_mode "$success_site/config" 0 "$WEB_GID" 750
assert_owner_mode "$success_site/kernel/Install" 0 "$WEB_GID" 750
for normalized_mutable in \
    app/Plugin/FixturePlugin:755 \
    app/Plugin/FixturePlugin/Nested:755 \
    app/Plugin/FixturePlugin/Nested/Config.php:644 \
    app/Pay/Epusdt:755 \
    app/Pay/Epusdt/sentinel.txt:644 \
    config/store.php:640 \
    config/mcp.php:640 \
    config/terms:640 \
    kernel/Install/Lock:640 \
    runtime/throttle:755 \
    runtime/throttle/11111111111111111111111111111111:755 \
    runtime/throttle/22222222222222222222222222222222:755 \
    runtime/throttle/33333333333333333333333333333333:755 \
    runtime/view:751 \
    runtime/view/compile:751 \
    runtime/trusted_proxies:644; do
    normalized_relative="${normalized_mutable%:*}"
    normalized_mode="${normalized_mutable##*:}"
    assert_owner_mode "$success_site/$normalized_relative" "$WEB_UID" "$WEB_GID" "$normalized_mode"
done
[[ "$(find -P "$success_site/runtime/view" -printf '%P %D:%i:%n:%U:%G\n' | LC_ALL=C sort)" \
    == "$success_smarty_identity" ]] \
    || fail 'install changed an official Smarty node identity'
[[ "$(sha256sum "$success_smarty_file" | awk '{print $1}')" == "$success_smarty_hash" ]] \
    || fail 'install changed official Smarty compiled content'
assert_owner_mode "$success_smarty_file" "$WEB_UID" "$WEB_GID" 644
trace_line() {
    local needle="$1" line
    line="$(grep -nF -- "$needle" "$chown_trace" | tail -n 1 | cut -d: -f1)"
    [[ "$line" =~ ^[0-9]+$ ]] || fail "missing chown-order trace: $needle"
    printf '%s\n' "$line"
}
mutable_file_line="$(trace_line "$WEB_UID:$WEB_GID -- $success_site/app/Plugin/FixturePlugin/Nested/Config.php")"
mutable_deep_line="$(trace_line "$WEB_UID:$WEB_GID -- $success_site/app/Plugin/FixturePlugin/Nested")"
mutable_parent_line="$(trace_line "$WEB_UID:$WEB_GID -- $success_site/app/Plugin/FixturePlugin")"
mutable_root_line="$(trace_line "$WEB_UID:$WEB_GID -- $success_site/app/Plugin")"
((mutable_file_line < mutable_deep_line \
    && mutable_deep_line < mutable_parent_line \
    && mutable_parent_line < mutable_root_line)) \
    || fail 'mutable authorization was not file-first and deepest-to-shallowest'
runuser -u "$WEB_USER" -- sh -eu -c '
    site=$1
    for path in config/store.php config/mcp.php config/terms kernel/Install/Lock; do
        printf "%s\n" first > "$site/$path"
        printf "%s\n" second > "$site/$path"
    done
    for tree in \
        app/Plugin/ContractProbe \
        app/Pay/ContractProbe \
        app/View/User/Theme/ContractProbe; do
        mkdir -- "$site/$tree"
        printf "%s\n" first > "$site/$tree/value"
        printf "%s\n" second > "$site/$tree/value"
        rm -- "$site/$tree/value"
        rmdir -- "$site/$tree"
    done
    for tree in runtime/contract-probe assets/cache/contract-probe kernel/Install/OS/contract-probe; do
        mkdir -- "$site/$tree"
        printf "%s\n" first > "$site/$tree/value"
        printf "%s\n" second > "$site/$tree/value"
        rm -- "$site/$tree/value"
        rmdir -- "$site/$tree"
    done
' pika-official-write-contract "$success_site" \
    || fail 'dedicated Web identity cannot exercise the official mutable write contract'
if runuser -u "$WEB_USER" -- rm -- "$success_site/config/mcp.php" 2>/dev/null; then
    fail 'dedicated Web identity replaced an exact mutable config through its protected parent'
fi
runuser -u "$WEB_USER" -- sh -c \
    'probe="$1/.pika-write-probe-$$"; : > "$probe" && rm -- "$probe"' \
    pika-runtime-write "$success_site/assets/cache/general/image" \
    || fail 'dedicated Web identity cannot write the official remote-image cache leaf'
[[ "$(sha256sum "$success_site/assets/cache/preexisting.txt" | awk '{print $1}')" == "$cache_sentinel_hash" ]] \
    || fail 'official runtime preparation changed pre-existing cache content'
[[ "$(sha256sum "$success_site/app/Pay/Epusdt/sentinel.txt" | awk '{print $1}')" == "$existing_payment_sentinel_hash" ]] \
    || fail 'install changed an unrelated existing payment adapter'
success_state="$(site_state_dir "$success_site")"
assert_owner_mode "$success_state" 0 0 755
assert_owner_mode "$success_state/secrets" 0 "$WEB_GID" 750
assert_owner_mode "$success_state/runtime" "$WEB_UID" "$WEB_GID" 750
assert_owner_mode "$success_state/runtime/config" "$WEB_UID" "$WEB_GID" 750
assert_owner_mode "$success_state/runtime/csrf.key" "$WEB_UID" "$WEB_GID" 600
assert_owner_mode "$success_state/install-receipt.json" 0 0 400

receipt="$success_state/install-receipt.json"
php -r '
    $receipt = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
    if (($receipt["web_uid"] ?? null) !== (int)$argv[2]
        || ($receipt["web_gid"] ?? null) !== (int)$argv[3]) {
        fwrite(STDERR, "receipt web identity mismatch\n");
        exit(1);
    }
    $callbackEntries = [];
    foreach (($receipt["installed_files"] ?? []) as $entry) {
        if (($entry["path"] ?? null) === "app/Controller/User/Api/PikaBEpusdt.php") {
            $callbackEntries[] = $entry;
        }
        if (in_array(($entry["path"] ?? null), [
            "app/View/User/Theme/Pika/Setting.php",
            "app/Pay/PikaBEpusdtAdapter/runtime.log",
        ], true)) {
            fwrite(STDERR, "mutable file entered the immutable receipt\n");
            exit(1);
        }
    }
    if (count($callbackEntries) !== 1
        || !hash_equals(hash_file("sha256", $argv[4]), (string)($callbackEntries[0]["sha256"] ?? ""))) {
        fwrite(STDERR, "BE callback controller receipt must bind exactly one installed file\n");
        exit(1);
    }
' "$receipt" "$WEB_UID" "$WEB_GID" "$success_site/app/Controller/User/Api/PikaBEpusdt.php"

success_restore_receipt="$(receipt_backup_path "$success_site")"
exact_mutable_restore_log="$FIXTURE_ROOT/success/exact-mutable-restore.log"
chmod 04640 "$success_site/config/store.php"
if php "$RELEASE_ROOT/scripts/restore-install.php" \
    --site-root "$success_site" \
    --receipt "$success_restore_receipt" \
    >"$exact_mutable_restore_log" 2>&1; then
    fail 'restore helper unexpectedly accepted a special-bit exact mutable config'
fi
grep -q 'official exact mutable file does not match the installed contract: config/store.php' \
    "$exact_mutable_restore_log" \
    || fail 'restore helper did not reject the special-bit exact mutable config'
chmod 0640 "$success_site/config/store.php"

bridge_integrity_path="$success_site/assets/common/js/editor/markdown/editorv2.js"
bridge_integrity_hardlink="$success_site/assets/common/js/editor/markdown/editorv2.hardlink"
ln -- "$bridge_integrity_path" "$bridge_integrity_hardlink"
bridge_hardlink_verify_log="$FIXTURE_ROOT/success/bridge-hardlink-verify.log"
if php "$RELEASE_ROOT/scripts/verify-install.php" \
    --site-root "$success_site" >"$bridge_hardlink_verify_log" 2>&1; then
    fail 'installed verifier unexpectedly accepted a hard-linked bridge file'
fi
grep -q 'bridge file must be a canonical regular file with exactly one hard link' \
    "$bridge_hardlink_verify_log" \
    || fail 'installed verifier hard-linked bridge gate returned the wrong failure'
bridge_hardlink_restore_log="$FIXTURE_ROOT/success/bridge-hardlink-restore.log"
if php "$RELEASE_ROOT/scripts/restore-install.php" \
    --site-root "$success_site" \
    --receipt "$success_restore_receipt" \
    >"$bridge_hardlink_restore_log" 2>&1; then
    fail 'restore helper unexpectedly accepted a hard-linked bridge file'
fi
grep -q 'current bridge file must be a canonical ACL-free regular file with exactly one hard link' \
    "$bridge_hardlink_restore_log" \
    || fail 'restore helper hard-linked bridge gate returned the wrong failure'
rm -- "$bridge_integrity_hardlink"

chmod 0600 "$bridge_integrity_path"
bridge_mode_restore_log="$FIXTURE_ROOT/success/bridge-mode-restore.log"
if php "$RELEASE_ROOT/scripts/restore-install.php" \
    --site-root "$success_site" \
    --receipt "$success_restore_receipt" \
    >"$bridge_mode_restore_log" 2>&1; then
    fail 'restore helper unexpectedly accepted a 0600 bridge file'
fi
grep -q 'current bridge file must be a canonical ACL-free regular file with exactly one hard link' \
    "$bridge_mode_restore_log" \
    || fail 'restore helper bridge mode gate returned the wrong failure'
chmod 0644 "$bridge_integrity_path"

chown 65534:65534 "$bridge_integrity_path"
bridge_owner_restore_log="$FIXTURE_ROOT/success/bridge-owner-restore.log"
if php "$RELEASE_ROOT/scripts/restore-install.php" \
    --site-root "$success_site" \
    --receipt "$success_restore_receipt" \
    >"$bridge_owner_restore_log" 2>&1; then
    fail 'restore helper unexpectedly accepted a non-root-owned bridge file'
fi
grep -q 'current bridge file must be a canonical ACL-free regular file with exactly one hard link' \
    "$bridge_owner_restore_log" \
    || fail 'restore helper bridge owner gate returned the wrong failure'
chown 0:0 "$bridge_integrity_path"

bridge_backup_integrity_path="$(dirname -- "$success_restore_receipt")/site/assets/common/js/editor/markdown/editorv2.js"
chmod 0664 "$bridge_backup_integrity_path"
bridge_backup_mode_restore_log="$FIXTURE_ROOT/success/bridge-backup-mode-restore.log"
if php "$RELEASE_ROOT/scripts/restore-install.php" \
    --site-root "$success_site" \
    --receipt "$success_restore_receipt" \
    >"$bridge_backup_mode_restore_log" 2>&1; then
    fail 'restore helper unexpectedly accepted an unsafe backup bridge mode'
fi
grep -q 'backup bridge file must be a canonical ACL-free regular file with exactly one hard link' \
    "$bridge_backup_mode_restore_log" \
    || fail 'restore helper backup bridge mode gate returned the wrong failure'
chmod 0644 "$bridge_backup_integrity_path"

"$acl_php_root/set-posix-acl" "$bridge_integrity_path" "$WEB_UID"
bridge_acl_verify_log="$FIXTURE_ROOT/success/bridge-acl-verify.log"
if php "$RELEASE_ROOT/scripts/verify-install.php" \
    --site-root "$success_site" >"$bridge_acl_verify_log" 2>&1; then
    fail 'installed verifier unexpectedly accepted a bridge file with an extended ACL'
fi
grep -q 'bridge file must not have an extended POSIX ACL' \
    "$bridge_acl_verify_log" \
    || fail 'installed verifier bridge ACL gate returned the wrong failure'
bridge_acl_restore_log="$FIXTURE_ROOT/success/bridge-acl-restore.log"
if php "$RELEASE_ROOT/scripts/restore-install.php" \
    --site-root "$success_site" \
    --receipt "$success_restore_receipt" \
    >"$bridge_acl_restore_log" 2>&1; then
    fail 'restore helper unexpectedly accepted a bridge file with an extended ACL'
fi
grep -q 'current bridge file must be a canonical ACL-free regular file with exactly one hard link' \
    "$bridge_acl_restore_log" \
    || fail 'restore helper bridge ACL gate returned the wrong failure'
mv "$bridge_integrity_path" "$bridge_integrity_path.acl"
install -o root -g root -m 0644 "$bridge_integrity_path.acl" "$bridge_integrity_path"
rm -- "$bridge_integrity_path.acl"

bridge_parent="$success_site/assets/common/js/editor/markdown"
"$acl_php_root/set-posix-acl" "$bridge_parent" "$WEB_UID"
bridge_parent_acl_log="$FIXTURE_ROOT/success/bridge-parent-acl.log"
if php "$RELEASE_ROOT/scripts/verify-install.php" \
    --site-root "$success_site" >"$bridge_parent_acl_log" 2>&1; then
    fail 'installed verifier unexpectedly accepted an immutable bridge parent with an extended ACL'
fi
grep -q 'bridge file parent must not have an extended POSIX ACL' \
    "$bridge_parent_acl_log" \
    || fail 'installed verifier bridge-parent ACL gate returned the wrong failure'
mv "$bridge_parent" "$bridge_parent.acl"
mkdir "$bridge_parent"
chown 0:0 "$bridge_parent"
chmod 0755 "$bridge_parent"
for bridge_parent_file in "$bridge_parent.acl"/*; do
    [[ -f "$bridge_parent_file" && ! -L "$bridge_parent_file" ]] \
        || fail 'bridge-parent ACL fixture contains an unexpected node'
    install -o root -g root -m 0644 \
        "$bridge_parent_file" "$bridge_parent/$(basename -- "$bridge_parent_file")"
    rm -- "$bridge_parent_file"
done
rmdir "$bridge_parent.acl"
php "$RELEASE_ROOT/scripts/verify-install.php" --site-root "$success_site"

setting_path="$success_site/app/View/User/Theme/Pika/Setting.php"
"$acl_php_root/set-posix-acl" "$setting_path" "$WEB_UID"
setting_acl_verify_log="$FIXTURE_ROOT/success/setting-acl-verify.log"
if php "$RELEASE_ROOT/scripts/verify-install.php" \
    --site-root "$success_site" >"$setting_acl_verify_log" 2>&1; then
    fail 'installed verifier unexpectedly accepted a Pika setting ACL'
fi
grep -q 'Pika mutable setting must not have an extended POSIX ACL' "$setting_acl_verify_log" \
    || fail 'installed verifier did not reject the Pika setting ACL'
setting_acl_restore_log="$FIXTURE_ROOT/success/setting-acl-restore.log"
if php "$RELEASE_ROOT/scripts/restore-install.php" \
    --site-root "$success_site" \
    --receipt "$success_restore_receipt" \
    >"$setting_acl_restore_log" 2>&1; then
    fail 'restore helper unexpectedly accepted a Pika setting ACL'
fi
grep -q 'Pika mutable setting does not match the receipt web identity' "$setting_acl_restore_log" \
    || fail 'restore helper did not reject the Pika setting ACL'
mv "$setting_path" "$setting_path.acl"
install -o "$WEB_UID" -g "$WEB_GID" -m 0640 "$setting_path.acl" "$setting_path"
rm -- "$setting_path.acl"

runtime_log_path="$success_site/app/Pay/PikaBEpusdtAdapter/runtime.log"
runtime_log_hardlink="$success_site/app/Pay/PikaBEpusdtAdapter/runtime.hardlink"
ln -- "$runtime_log_path" "$runtime_log_hardlink"
runtime_hardlink_restore_log="$FIXTURE_ROOT/success/runtime-hardlink-restore.log"
if php "$RELEASE_ROOT/scripts/restore-install.php" \
    --site-root "$success_site" \
    --receipt "$success_restore_receipt" \
    >"$runtime_hardlink_restore_log" 2>&1; then
    fail 'restore helper unexpectedly accepted a hard-linked payment runtime log'
fi
grep -q 'payment adapter runtime log does not match the receipt web identity' \
    "$runtime_hardlink_restore_log" \
    || fail 'restore helper did not reject the hard-linked payment runtime log'
rm -- "$runtime_log_hardlink"
php "$RELEASE_ROOT/scripts/verify-install.php" --site-root "$success_site"

runtime_owner_drift_log="$FIXTURE_ROOT/success/runtime-owner-drift.log"
chown 0:0 "$success_site/assets/cache/general/image"
if php "$RELEASE_ROOT/scripts/verify-install.php" \
    --site-root "$success_site" >"$runtime_owner_drift_log" 2>&1; then
    fail 'installed verifier unexpectedly accepted official runtime owner/GID drift'
fi
grep -q 'official runtime directory assets/cache/general/image must be a canonical' \
    "$runtime_owner_drift_log" \
    || fail 'installed verifier did not reject official runtime owner/GID drift'
chown "$WEB_UID:$WEB_GID" "$success_site/assets/cache/general/image"

"$acl_php_root/set-posix-acl" "$success_site/assets/cache/general/image" "$WEB_UID"
runtime_acl_drift_log="$FIXTURE_ROOT/success/runtime-acl-drift.log"
if php "$RELEASE_ROOT/scripts/verify-install.php" \
    --site-root "$success_site" >"$runtime_acl_drift_log" 2>&1; then
    fail 'installed verifier unexpectedly accepted an official runtime extended ACL'
fi
grep -q 'official runtime directory assets/cache/general/image must not have an extended POSIX ACL' \
    "$runtime_acl_drift_log" \
    || fail 'installed verifier did not reject the post-install official runtime ACL'
mv "$success_site/assets/cache/general/image" "$success_site/assets/cache/general/image.acl"
mkdir "$success_site/assets/cache/general/image"
chown "$WEB_UID:$WEB_GID" "$success_site/assets/cache/general/image"
chmod 0755 "$success_site/assets/cache/general/image"
rmdir "$success_site/assets/cache/general/image.acl" \
    || fail 'post-install ACL fixture unexpectedly gained content'
php "$RELEASE_ROOT/scripts/verify-install.php" --site-root "$success_site"

# The installer transaction normalizes existing Smarty directories to 0751.
# Later official cache clearing and Smarty fetch legitimately recreate 0771:
# this new cache generation has its own identity, not the deleted cache's inode.
smarty_view="$success_site/runtime/view"
[[ "$success_site" == "$FIXTURE_ROOT/success/site" \
    && "$(realpath -e -- "$success_site")" == "$success_site" \
    && "$(realpath -e -- "$smarty_view")" == "$smarty_view" \
    && ! -L "$smarty_view" \
    && "$(find -P "$smarty_view" -printf '%P %D:%i:%n:%U:%G\n' | LC_ALL=C sort)" == "$success_smarty_identity" \
    && "$(sha256sum "$success_smarty_file" | awk '{print $1}')" == "$success_smarty_hash" ]] \
    || fail 'official cache clearing target escaped the exact original Smarty fixture'
assert_owner_mode "$smarty_view" "$WEB_UID" "$WEB_GID" 751
assert_owner_mode "$smarty_view/compile" "$WEB_UID" "$WEB_GID" 751
runuser -u "$WEB_USER" -- /usr/local/bin/php -r '
    require $argv[1] . "/vendor/autoload.php";
    \App\Util\File::delDirectory($argv[1] . "/runtime/view");
' "$success_site"
[[ ! -e "$smarty_view" && ! -L "$smarty_view" ]] \
    || fail 'official File::delDirectory did not clear the exact Smarty fixture'
prepare_official_smarty_tree "$success_site" 0
regenerated_smarty_identity="$(
    find -P "$smarty_view" -printf '%P %D:%i:%n:%U:%G\n' | LC_ALL=C sort
)"
mapfile -d '' -t regenerated_smarty_files < <(find -P "$smarty_view" -type f -print0)
[[ "${#regenerated_smarty_files[@]}" == 1 ]] \
    || fail 'regenerated Smarty fetch did not create exactly one compiled file'
regenerated_smarty_file="${regenerated_smarty_files[0]}"
regenerated_smarty_hash="$(sha256sum "$regenerated_smarty_file" | awk '{print $1}')"
assert_owner_mode "$regenerated_smarty_file" "$WEB_UID" "$WEB_GID" 644
"$RELEASE_ROOT/scripts/doctor.sh" --site-root "$success_site" --installed

smarty_reject_log="$FIXTURE_ROOT/success/smarty-reject.log"
expect_smarty_reject() {
    local expected="$1"
    if php "$RELEASE_ROOT/scripts/verify-install.php" \
        --site-root "$success_site" >"$smarty_reject_log" 2>&1; then
        fail "installed verifier unexpectedly accepted unsafe Smarty state: $expected"
    fi
    grep -Fq "$expected" "$smarty_reject_log" \
        || fail "unsafe Smarty state did not fail at the expected gate: $expected"
}
for smarty_unsafe_mode in 0777 0770 01771; do
    chmod "$smarty_unsafe_mode" "$smarty_view"
    expect_smarty_reject 'official runtime tree must not be group/world writable'
done
chmod 0771 "$smarty_view"
chown "0:$WEB_GID" "$smarty_view"
expect_smarty_reject 'official runtime tree must be owned by the dedicated Web identity'
chown "$WEB_UID:0" "$smarty_view"
expect_smarty_reject 'official runtime tree must be owned by the dedicated Web identity'
chown "$WEB_UID:$WEB_GID" "$smarty_view"

smarty_unsafe_file="$smarty_view/untrusted.php"
install -o "$WEB_UID" -g "$WEB_GID" -m 0771 /dev/null "$smarty_unsafe_file"
expect_smarty_reject 'official runtime tree must not be group/world writable'
rm -- "$smarty_unsafe_file"
for smarty_outside in "$success_site/runtime/other-view" "$success_site/runtime/view-extra"; do
    mkdir -- "$smarty_outside"
    chown "$WEB_UID:$WEB_GID" "$smarty_outside"
    chmod 0771 "$smarty_outside"
    expect_smarty_reject 'official runtime tree must not be group/world writable'
    rmdir -- "$smarty_outside"
done
smarty_other_tree="$success_site/assets/cache/view"
mkdir -- "$smarty_other_tree"
chown "$WEB_UID:$WEB_GID" "$smarty_other_tree"
chmod 0771 "$smarty_other_tree"
expect_smarty_reject 'official cache tree must not be group/world writable'
rmdir -- "$smarty_other_tree"

smarty_acl_probe="$smarty_view/acl-probe"
mkdir -- "$smarty_acl_probe"
chown "$WEB_UID:$WEB_GID" "$smarty_acl_probe"
"$acl_php_root/set-posix-acl" "$smarty_acl_probe" "$WEB_UID"
chmod 0771 "$smarty_acl_probe"
expect_smarty_reject 'official runtime tree must not have an extended POSIX ACL'
rmdir -- "$smarty_acl_probe"
smarty_symlink_probe="$smarty_view/link-probe"
ln -s -- "$smarty_view/compile" "$smarty_symlink_probe"
expect_smarty_reject 'official runtime tree must be owned by the dedicated Web identity'
rm -- "$smarty_symlink_probe"
"$RELEASE_ROOT/scripts/doctor.sh" --site-root "$success_site" --installed

# Acg-Faka 3.6.4 creates throttle cache files after installation with an
# exact lowercase MD5 filename and mode 0777. Keep this compatibility exception
# confined to that one data-only directory and reject every nearby variant.
live_throttle="$success_site/runtime/throttle/77777777777777777777777777777777"
printf '%s\n' 'official-live-throttle' > "$live_throttle"
chown "$WEB_UID:$WEB_GID" "$live_throttle"
chmod 0777 "$live_throttle"
php "$RELEASE_ROOT/scripts/verify-install.php" --site-root "$success_site"

throttle_reject_log="$FIXTURE_ROOT/success/throttle-reject.log"
expect_throttle_reject() {
    local expected="$1"
    if php "$RELEASE_ROOT/scripts/verify-install.php" \
        --site-root "$success_site" >"$throttle_reject_log" 2>&1; then
        fail "installed verifier unexpectedly accepted unsafe throttle state: $expected"
    fi
    grep -q "$expected" "$throttle_reject_log" \
        || fail "unsafe throttle state did not fail at the expected gate: $expected"
}

chmod 0666 "$live_throttle"
expect_throttle_reject 'official throttle cache file is unsafe'
chmod 0770 "$live_throttle"
expect_throttle_reject 'official throttle cache file is unsafe'
chmod 01777 "$live_throttle"
expect_throttle_reject 'official throttle cache file is unsafe'
chmod 0777 "$live_throttle"

chown 0:0 "$live_throttle"
expect_throttle_reject 'official throttle cache file is unsafe'
chown "$WEB_UID:$WEB_GID" "$live_throttle"

wrong_throttle_name="$success_site/runtime/throttle/untrusted.lock"
install -o "$WEB_UID" -g "$WEB_GID" -m 0777 /dev/null "$wrong_throttle_name"
expect_throttle_reject 'official throttle cache name is invalid'
rm -- "$wrong_throttle_name"

uppercase_throttle="$success_site/runtime/throttle/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
install -o "$WEB_UID" -g "$WEB_GID" -m 0777 /dev/null "$uppercase_throttle"
expect_throttle_reject 'official throttle cache name is invalid'
rm -- "$uppercase_throttle"

symlink_throttle="$success_site/runtime/throttle/88888888888888888888888888888888"
ln -s -- "$live_throttle" "$symlink_throttle"
expect_throttle_reject 'official throttle cache file is unsafe'
rm -- "$symlink_throttle"

hardlink_throttle="$success_site/runtime/throttle/99999999999999999999999999999999"
ln -- "$live_throttle" "$hardlink_throttle"
expect_throttle_reject 'official throttle cache file is unsafe'
rm -- "$hardlink_throttle"

directory_throttle="$success_site/runtime/throttle/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
mkdir "$directory_throttle"
chown "$WEB_UID:$WEB_GID" "$directory_throttle"
chmod 0755 "$directory_throttle"
expect_throttle_reject 'official throttle cache file is unsafe'
rmdir "$directory_throttle"

fifo_throttle="$success_site/runtime/throttle/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
mkfifo "$fifo_throttle"
chown "$WEB_UID:$WEB_GID" "$fifo_throttle"
chmod 0755 "$fifo_throttle"
expect_throttle_reject 'official throttle cache file is unsafe'
rm -- "$fifo_throttle"

chmod 0777 "$success_site/runtime/throttle"
expect_throttle_reject 'official throttle directory is unsafe'
chmod 0755 "$success_site/runtime/throttle"

outside_throttle="$success_site/runtime/cccccccccccccccccccccccccccccccc"
install -o "$WEB_UID" -g "$WEB_GID" -m 0777 /dev/null "$outside_throttle"
expect_throttle_reject 'official runtime tree must not be group/world writable'
rm -- "$outside_throttle"

"$acl_php_root/set-posix-acl" "$live_throttle" "$WEB_UID"
expect_throttle_reject 'official throttle cache file must not have an extended POSIX ACL'
rm -- "$live_throttle"
install -o "$WEB_UID" -g "$WEB_GID" -m 0777 /dev/null "$live_throttle"
php "$RELEASE_ROOT/scripts/verify-install.php" --site-root "$success_site"

unicode_token_fixture=/opt/pika-test-space/bepusdt-token-unicode-fixture
printf '%s' 'éééééééééééééééé' > "$unicode_token_fixture"
chown 0:0 "$unicode_token_fixture"
chmod 0600 "$unicode_token_fixture"
unicode_token_log="$FIXTURE_ROOT/success/configure-bepusdt-unicode.log"
if LC_ALL=C.UTF-8 "$RELEASE_ROOT/scripts/configure-bepusdt.sh" \
    --site-root "$success_site" \
    --namespace s0u1 \
    --token-file "$unicode_token_fixture" \
    >"$unicode_token_log" 2>&1; then
    fail 'BEpusdt configurator unexpectedly accepted a non-ASCII token under UTF-8 locale'
fi
grep -q 'BEpusdt token must contain 16-256 printable non-space characters' "$unicode_token_log" \
    || fail 'non-ASCII BEpusdt token did not fail at the deterministic token gate'
[[ ! -e "$success_state/secrets/bepusdt-token" \
    && ! -e "$success_state/secrets/bepusdt-namespace" ]] \
    || fail 'rejected non-ASCII token left a published secret'

token_fixture=/opt/pika-test-space/bepusdt-token-fixture
printf '%s' 'integration-token-value-123456789' > "$token_fixture"
chown 0:0 "$token_fixture"
chmod 0600 "$token_fixture"
configure_log="$FIXTURE_ROOT/success/configure-bepusdt.log"
"$RELEASE_ROOT/scripts/configure-bepusdt.sh" \
    --site-root "$success_site" \
    --namespace s0a1 \
    --token-file "$token_fixture" \
    >"$configure_log" 2>&1
grep -q 'BEPUSDT_CONFIG_PASS' "$configure_log" \
    || fail 'BEpusdt configurator did not report PASS'
if grep -q 'LC_ALL: readonly variable' "$configure_log"; then
    fail 'BEpusdt configurator emitted a readonly LC_ALL warning'
fi
if grep -Fq 'integration-token-value-123456789' "$configure_log" "$receipt"; then
    fail 'BEpusdt token leaked into configurator output or install receipt'
fi
assert_owner_mode "$success_state/secrets/bepusdt-token" 0 "$WEB_GID" 640
assert_owner_mode "$success_state/secrets/bepusdt-namespace" 0 "$WEB_GID" 640
[[ "$(<"$success_state/secrets/bepusdt-token")" == 'integration-token-value-123456789' ]] \
    || fail 'BEpusdt configurator wrote an unexpected token value'
[[ "$(<"$success_state/secrets/bepusdt-namespace")" == 's0a1' ]] \
    || fail 'BEpusdt configurator wrote an unexpected namespace value'
runuser -u "$WEB_USER" -- php -r '
    define("BASE_PATH", $argv[1]);
    require $argv[1] . "/vendor/autoload.php";
    $secret = \App\Pay\PikaBEpusdtAdapter\Support\SecretStore::load();
    if (($secret["namespace"] ?? null) !== "s0a1"
        || !is_string($secret["token"] ?? null)
        || strlen($secret["token"]) !== 33) {
        fwrite(STDERR, "web identity could not load exact configured BEpusdt secrets\n");
        exit(1);
    }
' "$success_site"
configured_secrets_before="$(site_snapshot "$success_state/secrets")"
configure_repeat_log="$FIXTURE_ROOT/success/configure-bepusdt-repeat.log"
if "$RELEASE_ROOT/scripts/configure-bepusdt.sh" \
    --site-root "$success_site" \
    --namespace s0a1 \
    --token-file "$token_fixture" \
    >"$configure_repeat_log" 2>&1; then
    fail 'BEpusdt configurator unexpectedly overwrote existing secrets'
fi
grep -q 'BEpusdt secrets already exist; this first-install command will not overwrite them' "$configure_repeat_log" \
    || fail 'BEpusdt configurator repeat did not fail at first-install gate'
[[ "$(site_snapshot "$success_state/secrets")" == "$configured_secrets_before" ]] \
    || fail 'BEpusdt configurator repeat changed existing secrets'

chgrp 0 "$success_state/runtime"
identity_verify_log="$FIXTURE_ROOT/success/identity-verify.log"
if php "$RELEASE_ROOT/scripts/verify-install.php" \
    --site-root "$success_site" >"$identity_verify_log" 2>&1; then
    fail 'installed verifier unexpectedly accepted runtime identity drift'
fi
grep -q 'external runtime does not match the receipt web identity' "$identity_verify_log" \
    || fail 'installed verifier did not bind the runtime to the receipt identity'
identity_restore_log="$FIXTURE_ROOT/success/identity-restore-helper.log"
if php "$RELEASE_ROOT/scripts/restore-install.php" \
    --site-root "$success_site" \
    --receipt "$(receipt_backup_path "$success_site")" \
    >"$identity_restore_log" 2>&1; then
    fail 'restore helper unexpectedly accepted runtime identity drift'
fi
grep -q 'external runtime does not match the receipt web identity' "$identity_restore_log" \
    || fail 'restore helper did not bind the runtime to the receipt identity'
chgrp "$WEB_GID" "$success_state/runtime"
printf '%s\n' '<?php declare(strict_types=1); return ['"'"'icp'"'"' => '"'"'mutable-test'"'"'];' \
    > "$success_site/app/View/User/Theme/Pika/Setting.php"
chown "$WEB_UID:$WEB_GID" "$success_site/app/View/User/Theme/Pika/Setting.php"
chmod 0640 "$success_site/app/View/User/Theme/Pika/Setting.php"
php "$RELEASE_ROOT/scripts/verify-install.php" --site-root "$success_site"

success_receipt="$(receipt_backup_path "$success_site")"
[[ -f "$success_receipt" && ! -L "$success_receipt" ]] \
    || fail 'protected success receipt path is missing or unsafe'

missing_maintenance_log="$FIXTURE_ROOT/success/missing-maintenance.log"
before_missing_maintenance="$(site_snapshot "$success_site")"
if "$RELEASE_ROOT/scripts/restore.sh" \
    --site-root "$success_site" \
    --receipt "$success_receipt" \
    >"$missing_maintenance_log" 2>&1; then
    fail 'restore without --confirm-maintenance unexpectedly succeeded'
fi
grep -q -- '--confirm-maintenance is required' "$missing_maintenance_log" \
    || fail 'restore without --confirm-maintenance did not fail at the maintenance gate'
[[ "$(site_snapshot "$success_site")" == "$before_missing_maintenance" ]] \
    || fail 'missing maintenance confirmation changed the site'

scheduler_fixture=/etc/systemd/system/pika-supply-sync-pika-integration.timer
[[ ! -e "$scheduler_fixture" && ! -L "$scheduler_fixture" ]] \
    || fail 'scheduler fixture path unexpectedly exists'
printf '%s\n' \
    '# Managed by Pika LocalExtensions scheduler.sh' \
    "# Pika-SiteRoot: $success_site" \
    > "$scheduler_fixture"
chmod 0644 "$scheduler_fixture"
scheduler_site_before="$(site_snapshot "$success_site")"
scheduler_log="$FIXTURE_ROOT/success/scheduler-remains.log"
if "$RELEASE_ROOT/scripts/restore.sh" \
    --site-root "$success_site" \
    --receipt "$success_receipt" \
    --confirm-maintenance \
    >"$scheduler_log" 2>&1; then
    fail 'restore with a managed SupplySync timer unexpectedly succeeded'
fi
grep -q 'managed LocalExtensions scheduler must be removed before restore' "$scheduler_log" \
    || fail 'remaining managed timer did not fail at the quiescence gate'
[[ "$(site_snapshot "$success_site")" == "$scheduler_site_before" ]] \
    || fail 'remaining scheduler rejection changed the site'
rm -- "$scheduler_fixture"

catalog_scheduler_fixture=/etc/systemd/system/pika-catalog-worker-pika-integration.service
[[ ! -e "$catalog_scheduler_fixture" && ! -L "$catalog_scheduler_fixture" ]] \
    || fail 'CatalogHub scheduler fixture path unexpectedly exists'
printf '%s\n' \
    '# Managed by Pika LocalExtensions scheduler.sh' \
    "# Pika-SiteRoot: $success_site" \
    > "$catalog_scheduler_fixture"
chmod 0644 "$catalog_scheduler_fixture"
catalog_scheduler_site_before="$(site_snapshot "$success_site")"
catalog_scheduler_log="$FIXTURE_ROOT/success/catalog-scheduler-remains.log"
if "$RELEASE_ROOT/scripts/restore.sh" \
    --site-root "$success_site" \
    --receipt "$success_receipt" \
    --confirm-maintenance \
    >"$catalog_scheduler_log" 2>&1; then
    fail 'restore with a managed CatalogHub service unexpectedly succeeded'
fi
grep -q 'managed LocalExtensions scheduler must be removed before restore' "$catalog_scheduler_log" \
    || fail 'remaining managed CatalogHub service did not fail at the quiescence gate'
[[ "$(site_snapshot "$success_site")" == "$catalog_scheduler_site_before" ]] \
    || fail 'remaining CatalogHub scheduler rejection changed the site'
rm -- "$catalog_scheduler_fixture"

for scheduler_symlink_name in \
    pika-supply-sync-pika-integration.service \
    pika-supply-sync-pika-integration.timer \
    pika-catalog-worker-pika-integration.service \
    pika-catalog-worker-pika-integration.timer; do
    scheduler_symlink_fixture="/etc/systemd/system/$scheduler_symlink_name"
    [[ ! -e "$scheduler_symlink_fixture" && ! -L "$scheduler_symlink_fixture" ]] \
        || fail "scheduler symlink fixture path unexpectedly exists: $scheduler_symlink_name"
    ln -s /dev/null "$scheduler_symlink_fixture"
    scheduler_symlink_site_before="$(site_snapshot "$success_site")"
    scheduler_symlink_log="$FIXTURE_ROOT/success/scheduler-symlink-${scheduler_symlink_name}.log"
    if "$RELEASE_ROOT/scripts/restore.sh" \
        --site-root "$success_site" \
        --receipt "$success_receipt" \
        --confirm-maintenance \
        >"$scheduler_symlink_log" 2>&1; then
        fail "restore with a scheduler symlink unexpectedly succeeded: $scheduler_symlink_name"
    fi
    grep -q 'LocalExtensions scheduler unit path must not be a symbolic link before restore' \
        "$scheduler_symlink_log" \
        || fail "scheduler symlink did not fail at the quiescence gate: $scheduler_symlink_name"
    [[ "$(site_snapshot "$success_site")" == "$scheduler_symlink_site_before" ]] \
        || fail "scheduler symlink rejection changed the site: $scheduler_symlink_name"
    rm -- "$scheduler_symlink_fixture"
done

catalog_worker_bin="$success_site/local-extensions/extensions/PikaCatalogHub/bin/worker.php"
php -r 'sleep(60);' "$catalog_worker_bin" &
catalog_worker_pid=$!
catalog_worker_ready=0
for _ in $(seq 1 50); do
    if [[ -r "/proc/$catalog_worker_pid/cmdline" ]] \
        && tr '\0' '\n' < "/proc/$catalog_worker_pid/cmdline" | grep -Fqx -- "$catalog_worker_bin"; then
        catalog_worker_ready=1
        break
    fi
    sleep 0.02
done
if ((catalog_worker_ready != 1)); then
    kill "$catalog_worker_pid" 2>/dev/null || true
    wait "$catalog_worker_pid" 2>/dev/null || true
    fail 'active CatalogHub worker process fixture did not become observable'
fi
catalog_process_before="$(site_snapshot "$success_site")"
catalog_process_log="$FIXTURE_ROOT/success/catalog-worker-process.log"
if "$RELEASE_ROOT/scripts/restore.sh" \
    --site-root "$success_site" \
    --receipt "$success_receipt" \
    --confirm-maintenance \
    >"$catalog_process_log" 2>&1; then
    kill "$catalog_worker_pid" 2>/dev/null || true
    wait "$catalog_worker_pid" 2>/dev/null || true
    fail 'restore with an active CatalogHub worker process unexpectedly succeeded'
fi
kill "$catalog_worker_pid" 2>/dev/null || true
wait "$catalog_worker_pid" 2>/dev/null || true
grep -q 'active CatalogHub worker must stop before restore' "$catalog_process_log" \
    || fail 'active CatalogHub worker process did not fail at the quiescence gate'
[[ "$(site_snapshot "$success_site")" == "$catalog_process_before" ]] \
    || fail 'active CatalogHub worker process rejection changed the site'

active_lock_dir="$success_state/runtime/extensions/PikaSupplySync"
active_lock="$active_lock_dir/source-1.run.lock"
mkdir -p "$active_lock_dir"
touch "$active_lock"
chown "$WEB_UID:$WEB_GID" \
    "$success_state/runtime/extensions" \
    "$active_lock_dir" \
    "$active_lock"
chmod 0750 "$success_state/runtime/extensions" "$active_lock_dir"
chmod 0600 "$active_lock"
exec {active_lock_fd}<>"$active_lock"
flock -n "$active_lock_fd" || fail 'unable to hold the active-lock fixture'
active_lock_before="$(site_snapshot "$success_site")"
active_lock_log="$FIXTURE_ROOT/success/active-lock.log"
if "$RELEASE_ROOT/scripts/restore.sh" \
    --site-root "$success_site" \
    --receipt "$success_receipt" \
    --confirm-maintenance \
    >"$active_lock_log" 2>&1; then
    fail 'restore with an active SupplySync lock unexpectedly succeeded'
fi
grep -q 'active SupplySync run lock must clear before restore' "$active_lock_log" \
    || fail 'active SupplySync lock did not fail at the quiescence gate'
[[ "$(site_snapshot "$success_site")" == "$active_lock_before" ]] \
    || fail 'active-lock rejection changed the site'
exec {active_lock_fd}>&-
rm -- "$active_lock"
rmdir -- "$active_lock_dir" "$success_state/runtime/extensions"

catalog_lock_root="$success_state/runtime/extensions/PikaCatalogHub"
catalog_lock="$catalog_lock_root/worker.run.lock"
mkdir -p "$catalog_lock_root"
touch "$catalog_lock"
chown "$WEB_UID:$WEB_GID" \
    "$success_state/runtime/extensions" \
    "$catalog_lock_root" \
    "$catalog_lock"
chmod 0700 \
    "$success_state/runtime/extensions" \
    "$catalog_lock_root"
chmod 0600 "$catalog_lock"
exec {catalog_lock_fd}<>"$catalog_lock"
flock -n "$catalog_lock_fd" || fail 'unable to hold the CatalogHub worker-lock fixture'
catalog_lock_before="$(site_snapshot "$success_site")"
catalog_lock_log="$FIXTURE_ROOT/success/catalog-worker-lock.log"
if "$RELEASE_ROOT/scripts/restore.sh" \
    --site-root "$success_site" \
    --receipt "$success_receipt" \
    --confirm-maintenance \
    >"$catalog_lock_log" 2>&1; then
    fail 'restore with an active CatalogHub worker lock unexpectedly succeeded'
fi
grep -q 'active CatalogHub worker run lock must clear before restore' "$catalog_lock_log" \
    || fail 'active CatalogHub worker lock did not fail at the quiescence gate'
[[ "$(site_snapshot "$success_site")" == "$catalog_lock_before" ]] \
    || fail 'active CatalogHub worker-lock rejection changed the site'
exec {catalog_lock_fd}>&-
rm -- "$catalog_lock"
rmdir -- \
    "$catalog_lock_root" \
    "$success_state/runtime/extensions"

non_root_restore_log="$FIXTURE_ROOT/success/non-root-restore.log"
before_non_root_restore="$(site_snapshot "$success_site")"
if runuser -u "$WEB_USER" -- "$RELEASE_ROOT/scripts/restore.sh" \
    --site-root "$success_site" \
    --receipt "$success_receipt" \
    --confirm-maintenance \
    >"$non_root_restore_log" 2>&1; then
    fail 'non-root restore invocation unexpectedly succeeded'
fi
grep -q 'restore must run as root (use sudo)' "$non_root_restore_log" \
    || fail 'non-root restore invocation did not fail at the root gate'
[[ "$(site_snapshot "$success_site")" == "$before_non_root_restore" ]] \
    || fail 'non-root restore changed the site'

wrong_receipt_root="$BACKUP_FIXTURE_ROOT/wrong-backup"
mkdir -p "$wrong_receipt_root"
chmod 0700 "$wrong_receipt_root"
cp -p -- "$success_receipt" "$wrong_receipt_root/install-receipt.json"
wrong_receipt_log="$FIXTURE_ROOT/success/wrong-receipt.log"
before_wrong_receipt="$(site_snapshot "$success_site")"
if "$RELEASE_ROOT/scripts/restore.sh" \
    --site-root "$success_site" \
    --receipt "$wrong_receipt_root/install-receipt.json" \
    --confirm-maintenance \
    >"$wrong_receipt_log" 2>&1; then
    fail 'restore unexpectedly accepted a copied receipt at a different backup path'
fi
grep -q 'install receipt schema or exact backup path is invalid' "$wrong_receipt_log" \
    || fail 'copied receipt did not fail at the exact backup-path gate'
[[ "$(site_snapshot "$success_site")" == "$before_wrong_receipt" ]] \
    || fail 'copied-receipt rejection changed the site'

# An ordinary documented canary rotation must not strand the next installation.
prepare_payment_log_rotations "$success_site" success 9
configured_rule_backup="$PIKA_PAYMENT_LOGROTATE_RULE_BACKUP"
export PIKA_PAYMENT_LOGROTATE_RULE_BACKUP=not-configured
assert_payment_restore_rejected "$success_site" "$success_receipt" \
    rotated-files-without-configured-rule 'ERROR:.*(configured|payment|logrotate)'
export PIKA_PAYMENT_LOGROTATE_RULE_BACKUP="$configured_rule_backup"
assert_payment_rule_restored
assert_payment_restore_rejected "$success_site" "$success_receipt" \
    payment-rule-still-included 'ERROR:.*logrotate'
mv -- "$PIKA_PAYMENT_LOGROTATE_RULE" "$PIKA_PAYMENT_LOGROTATE_RULE_BACKUP"

# A real invocation that already loaded the site's rule must drain first.
loaded_rotate_config=/opt/pika-test-space/loaded-before-isolation.conf
php -r '
    $hook = "\n    firstaction\n        /usr/bin/touch /tmp/pika-loaded-logrotate-ready\n"
        . "        /usr/bin/timeout 30 /bin/sh -c \"while [ ! -e /tmp/pika-loaded-logrotate-done ]; do /bin/sleep 0.01; done\"\n"
        . "    endscript\n}";
    $raw = file_get_contents($argv[1]);
    if (substr_count($raw, "\n}") !== 1
        || file_put_contents($argv[2], str_replace("\n}", $hook, $raw)) === false) { exit(1); }
' "$PIKA_PAYMENT_LOGROTATE_RULE_BACKUP" "$loaded_rotate_config"
chown 0:0 "$loaded_rotate_config"
chmod 0644 "$loaded_rotate_config"
logrotate --force --state "$PAYMENT_LOGROTATE_STATE" "$loaded_rotate_config" \
    > "$FIXTURE_ROOT/loaded-before-isolation.log" 2>&1 &
loaded_rotate_pid=$!
for _ in $(seq 1 1000); do
    [[ -f /tmp/pika-loaded-logrotate-ready ]] && break
    sleep 0.01
done
[[ -f /tmp/pika-loaded-logrotate-ready ]] || fail 'real logrotate did not reach the bounded loaded-rule handshake'
assert_payment_restore_rejected "$success_site" "$success_receipt" \
    old-logrotate-still-running 'ERROR:.*logrotate'
: > /tmp/pika-loaded-logrotate-done
wait "$loaded_rotate_pid" || fail 'the drained pre-isolation logrotate invocation failed'
rm -- /tmp/pika-loaded-logrotate-ready /tmp/pika-loaded-logrotate-done

# Removing only this exact include leaves another site's native rotation live.
other_site=/opt/pika-test-space/other-site
mkdir -p "$other_site/app/Pay/PikaBEpusdtAdapter"
other_runtime="$other_site/app/Pay/PikaBEpusdtAdapter/runtime.log"
printf 'synthetic-other-site-still-rotates\n' > "$other_runtime"
chown "$WEB_UID:$WEB_GID" "$other_runtime"
chmod 0640 "$other_runtime"
sed -e "s|__SITE_ROOT__|$other_site|g" \
    -e "s|__WEB_USER__|$WEB_USER|g" -e "s|__WEB_GROUP__|$WEB_USER|g" \
    "$RELEASE_ROOT/packaging/logrotate/pika-bepusdt-adapter.conf.example" > /etc/logrotate.d/pika-other-site
chown 0:0 /etc/logrotate.d/pika-other-site
chmod 0644 /etc/logrotate.d/pika-other-site
printf 'include /etc/logrotate.d\n' > /opt/pika-test-space/global-logrotate.conf
isolated_payment_before="$(payment_log_snapshot "$success_site")"
logrotate --force --state "$PAYMENT_LOGROTATE_STATE" /opt/pika-test-space/global-logrotate.conf
[[ "$(payment_log_snapshot "$success_site")" == "$isolated_payment_before" \
    && "$(<"$other_runtime.1")" == synthetic-other-site-still-rotates && ! -s "$other_runtime" ]] \
    || fail 'exact rule isolation either rotated this site or blocked another site'
printf 'PAYMENT_LOGROTATE_ISOLATION_PASS old_process=drained other_site=rotated\n'

for unknown_name in runtime.log.1.gz runtime.log.8.gz runtime.log.01 \
    runtime.log.20260915.gz runtime.log.bak preexisting.txt; do
    unknown_path="$success_site/app/Pay/PikaBEpusdtAdapter/$unknown_name"
    printf 'synthetic-unknown-payment-entry\n' > "$unknown_path"
    chown "$WEB_UID:$WEB_GID" "$unknown_path"
    chmod 0640 "$unknown_path"
    assert_payment_restore_rejected "$success_site" "$success_receipt" \
        "unknown-$unknown_name" 'ERROR:.*(payment|logrotate)'
    rm -- "$unknown_path"
done
unknown_directory="$success_site/app/Pay/PikaBEpusdtAdapter/unrecognized-directory"
mkdir "$unknown_directory"
assert_payment_restore_rejected "$success_site" "$success_receipt" \
    unknown-directory 'ERROR:.*(payment|logrotate)'
rmdir "$unknown_directory"

rotated_path="$success_site/app/Pay/PikaBEpusdtAdapter/runtime.log.1"
rotated_original="$FIXTURE_ROOT/success-original-runtime.log.1"
mv -- "$rotated_path" "$rotated_original"
ln -s -- "$rotated_original" "$rotated_path"
assert_payment_restore_rejected "$success_site" "$success_receipt" \
    rotated-symlink 'ERROR:.*payment'
rm -- "$rotated_path"
mv -- "$rotated_original" "$rotated_path"
ln -- "$rotated_path" "$rotated_original"
assert_payment_restore_rejected "$success_site" "$success_receipt" \
    rotated-hardlink 'ERROR:.*payment'
rm -- "$rotated_original"
for rotated_metadata in '0:0:0640' "$WEB_UID:0:0640" \
    "$WEB_UID:$WEB_GID:0666" "$WEB_UID:$WEB_GID:04640" "$WEB_UID:$WEB_GID:0600"; do
    IFS=: read -r unsafe_uid unsafe_gid unsafe_mode <<< "$rotated_metadata"
    chown "$unsafe_uid:$unsafe_gid" "$rotated_path"
    chmod "$unsafe_mode" "$rotated_path"
    assert_payment_restore_rejected "$success_site" "$success_receipt" \
        "rotated-metadata-$unsafe_uid-$unsafe_gid-$unsafe_mode" 'ERROR:.*payment'
done
chown "$WEB_UID:$WEB_GID" "$rotated_path"
chmod 0640 "$rotated_path"
"$acl_php_root/set-posix-acl" "$rotated_path" "$WEB_UID"
assert_payment_restore_rejected "$success_site" "$success_receipt" rotated-acl 'ERROR:.*payment'
mv -- "$rotated_path" "$rotated_original"
install -o "$WEB_UID" -g "$WEB_GID" -m 0640 "$rotated_original" "$rotated_path"
rm -- "$rotated_original"

success_payment_logs="$(payment_log_snapshot "$success_site")"
success_restore_output="$("$RELEASE_ROOT/scripts/restore.sh" \
    --site-root "$success_site" \
    --receipt "$success_receipt" \
    --confirm-maintenance)"
printf '%s\n' "$success_restore_output"
assert_payment_log_backup "$success_receipt" "$success_payment_logs"
success_archive="${success_restore_output##* archive=}"
success_mutable_setting="${success_restore_output#* mutable_setting=}"
success_mutable_setting="${success_mutable_setting%% archive=*}"
[[ "$success_archive" =~ ^/var/lib/pika-local-extensions/archives/[a-f0-9]{64}-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}$ ]] \
    || fail 'successful restore did not report an exact protected archive path'
[[ "$success_mutable_setting" == "$(dirname -- "$success_receipt")/mutable-site-files/app/View/User/Theme/Pika/Setting.php" ]] \
    || fail 'successful restore did not report the exact protected mutable setting path'
[[ "$(bridge_hashes "$success_site")" == "$success_original_hashes" ]] \
    || fail 'successful restore did not restore all bridge files byte-for-byte'
assert_official_runtime_root_contract "$success_site"
assert_owner_mode "$smarty_view" "$WEB_UID" "$WEB_GID" 771
assert_owner_mode "$smarty_view/compile" "$WEB_UID" "$WEB_GID" 771
[[ "$(find -P "$smarty_view" -printf '%P %D:%i:%n:%U:%G\n' | LC_ALL=C sort)" == "$regenerated_smarty_identity" \
    && "$(sha256sum "$regenerated_smarty_file" | awk '{print $1}')" == "$regenerated_smarty_hash" ]] \
    || fail 'restore changed the regenerated official Smarty identity or content'
assert_absent "$success_site" \
    local-extensions \
    app/Controller/Admin/LocalExtensions.php \
    app/Controller/Admin/Api/LocalExtensions.php \
    app/Controller/User/Api/PikaBEpusdt.php \
    app/View/Admin/LocalExtensions \
    app/View/User/Theme/Pika \
    app/Pay/PikaBEpusdtAdapter \
    assets/admin/controller/local-extensions \
    assets/local-extensions
[[ "$(stat -c '%d:%i:%h:%u:%g:%a' -- \
    "$success_site/app/Controller/User" "$success_site/app/Controller/User/Api")" \
    == "$success_callback_parent_identity" ]] \
    || fail 'BE callback restore changed an official controller parent'
[[ "$(sha256sum "$success_site/app/Controller/User/Api/Order.php" \
    "$success_site/app/Controller/User/Api/RechargeNotification.php")" == "$success_official_callback_hashes" ]] \
    || fail 'BE callback restore changed an official callback controller'
[[ ! -e "$success_state" && ! -L "$success_state" ]] \
    || fail 'successful restore left live external site state'
assert_owner_mode /var/lib/pika-local-extensions/archives 0 0 700
assert_owner_mode "$success_archive" 0 0 755
assert_owner_mode "$success_archive/runtime" "$WEB_UID" "$WEB_GID" 750
assert_owner_mode "$success_archive/secrets" 0 "$WEB_GID" 750
assert_owner_mode "$success_archive/secrets/bepusdt-token" 0 "$WEB_GID" 640
assert_owner_mode "$success_archive/secrets/bepusdt-namespace" 0 "$WEB_GID" 640
[[ "$(<"$success_archive/secrets/bepusdt-token")" == 'integration-token-value-123456789' ]] \
    || fail 'successful restore did not archive the BEpusdt token exactly'
assert_owner_mode "$success_archive/install-receipt.json" 0 0 400
assert_owner_mode "$success_mutable_setting" 0 0 600
assert_owner_mode "$(dirname -- "$success_receipt")/mutable-site-files/manifest.json" 0 0 600
grep -q "'icp' => 'mutable-test'" "$success_mutable_setting" \
    || fail 'successful restore did not preserve the latest Pika mutable setting'
php -r '
    $manifest = json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
    if (($manifest["schema"] ?? null) !== 1
        || ($manifest["path"] ?? null) !== "app/View/User/Theme/Pika/Setting.php"
        || !hash_equals((string)($manifest["sha256"] ?? ""), hash_file("sha256", $argv[2]))) {
        fwrite(STDERR, "mutable setting manifest does not bind the preserved bytes\n");
        exit(1);
    }
' "$(dirname -- "$success_receipt")/mutable-site-files/manifest.json" "$success_mutable_setting"
[[ "$(sha256sum "$success_site/app/Pay/Epusdt/sentinel.txt" | awk '{print $1}')" == "$existing_payment_sentinel_hash" ]] \
    || fail 'restore changed an unrelated existing payment adapter'
[[ -f "$success_receipt" ]] || fail 'successful restore removed the protected backup receipt'
for mirror_payload in "${mirror_payload_files[@]}"; do
    [[ ! -e "$success_site/local-extensions/extensions/$mirror_payload" ]] \
        || fail 'successful restore left a mirror capability payload behind'
done
"$RELEASE_ROOT/scripts/doctor.sh" --site-root "$success_site"

success_reinstall_backup="$BACKUP_FIXTURE_ROOT/success-reinstall"
mkdir -p "$success_reinstall_backup"
"$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$success_site" \
    --backup-dir "$success_reinstall_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance
"$RELEASE_ROOT/scripts/doctor.sh" --site-root "$success_site" --installed
assert_owner_mode "$smarty_view" "$WEB_UID" "$WEB_GID" 751
for mirror_payload in "${mirror_payload_files[@]}"; do
    cmp -- "$RELEASE_ROOT/extensions/$mirror_payload" "$success_site/local-extensions/extensions/$mirror_payload" \
        || fail 'mirror capability was not restored by reinstall'
done
assert_owner_mode "$smarty_view/compile" "$WEB_UID" "$WEB_GID" 751
[[ "$(find -P "$smarty_view" -printf '%P %D:%i:%n:%U:%G\n' | LC_ALL=C sort)" == "$regenerated_smarty_identity" \
    && "$(sha256sum "$regenerated_smarty_file" | awk '{print $1}')" == "$regenerated_smarty_hash" ]] \
    || fail 'reinstall changed the regenerated official Smarty identity or content'
success_reinstall_receipt="$(receipt_backup_path "$success_site")"
prepare_payment_log_rotations "$success_site" success-reinstall 2
success_reinstall_payment_logs="$(payment_log_snapshot "$success_site")"
success_reinstall_output="$("$RELEASE_ROOT/scripts/restore.sh" \
    --site-root "$success_site" \
    --receipt "$success_reinstall_receipt" \
    --confirm-maintenance)"
printf '%s\n' "$success_reinstall_output"
assert_payment_log_backup "$success_reinstall_receipt" "$success_reinstall_payment_logs"
[[ ! -e "$success_state" && ! -L "$success_state" ]] \
    || fail 'same-site reinstall restore left live external state'
assert_absent "$success_site" app/Pay/PikaBEpusdtAdapter
[[ "$(bridge_hashes "$success_site")" == "$success_original_hashes" ]] \
    || fail 'same-site reinstall cycle did not restore all bridge files'

tamper_site="$FIXTURE_ROOT/tamper/site"
tamper_backup="$BACKUP_FIXTURE_ROOT/tamper"
mkdir -p "$tamper_backup"
"$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$tamper_site" \
    --backup-dir "$tamper_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance
tamper_receipt="$(receipt_backup_path "$tamper_site")"
tamper_state="$(site_state_dir "$tamper_site")"
prepare_payment_rule_isolation "$tamper_site" tamper
printf '\n// tampered integration fixture\n' >> "$tamper_site/local-extensions/bootstrap.php"
tamper_site_before="$(site_snapshot "$tamper_site")"
tamper_state_before="$(site_snapshot "$tamper_state")"
tamper_backup_before="$(site_snapshot "$(dirname -- "$tamper_receipt")")"
tamper_restore_log="$FIXTURE_ROOT/tamper/restore.log"
if "$RELEASE_ROOT/scripts/restore.sh" \
    --site-root "$tamper_site" \
    --receipt "$tamper_receipt" \
    --confirm-maintenance \
    >"$tamper_restore_log" 2>&1; then
    fail 'restore unexpectedly accepted a tampered installed file'
fi
grep -q 'installed file verification failed: local-extensions/bootstrap.php' "$tamper_restore_log" \
    || fail 'tampered restore did not fail at the immutable-file gate'
[[ "$(site_snapshot "$tamper_site")" == "$tamper_site_before" ]] \
    || fail 'tampered restore changed the site before failing'
[[ "$(site_snapshot "$tamper_state")" == "$tamper_state_before" ]] \
    || fail 'tampered restore changed external state before failing'
[[ "$(site_snapshot "$(dirname -- "$tamper_receipt")")" == "$tamper_backup_before" ]] \
    || fail 'tampered restore changed the protected backup before failing'
remove_test_state "$tamper_state"

rollback_site="$FIXTURE_ROOT/restore-rollback/site"
rollback_backup="$BACKUP_FIXTURE_ROOT/restore-rollback"
mkdir -p "$rollback_backup"
"$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$rollback_site" \
    --backup-dir "$rollback_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance
rollback_receipt="$(receipt_backup_path "$rollback_site")"
rollback_state="$(site_state_dir "$rollback_site")"
prepare_payment_rule_isolation "$rollback_site" restore-rollback
printf '%s\n' '<?php declare(strict_types=1); return ['"'"'icp'"'"' => '"'"'rollback-fixture'"'"'];' \
    > "$rollback_site/app/View/User/Theme/Pika/Setting.php"
chown "$WEB_UID:$WEB_GID" "$rollback_site/app/View/User/Theme/Pika/Setting.php"
chmod 0640 "$rollback_site/app/View/User/Theme/Pika/Setting.php"
prepare_payment_log_rotations "$rollback_site" restore-rollback 2
rollback_payment_logs="$(payment_log_snapshot "$rollback_site")"
rollback_payment_identity="$(payment_log_reversible_snapshot "$rollback_site")"
php "$RELEASE_ROOT/scripts/verify-install.php" --site-root "$rollback_site"
rollback_before="$(site_snapshot "$rollback_site")"
rollback_state_before="$(site_snapshot "$rollback_state")"
rollback_backup_root="$(dirname -- "$rollback_receipt")"
rollback_backup_before_without_mutable="$(site_snapshot_without_mutable "$rollback_backup_root")"
rollback_log="$FIXTURE_ROOT/restore-rollback/restore-failure.log"
if PIKA_RESTORE_TESTING=1 \
    PIKA_RESTORE_TEST_FAIL_AT=after-first-bridge-swap \
    "$RELEASE_ROOT/scripts/restore.sh" \
        --site-root "$rollback_site" \
        --receipt "$rollback_receipt" \
        --confirm-maintenance \
        >"$rollback_log" 2>&1; then
    fail 'injected mid-restore failure unexpectedly succeeded'
fi
grep -q 'RESTORE_ROLLBACK_PASS cause=injected restore failure at after-first-bridge-swap' "$rollback_log" \
    || fail 'mid-restore failure did not report rollback PASS'
assert_official_runtime_root_contract "$rollback_site"
[[ "$(site_snapshot "$rollback_site")" == "$rollback_before" ]] \
    || fail 'mid-restore rollback did not preserve a byte-level site snapshot'
[[ "$(site_snapshot "$rollback_state")" == "$rollback_state_before" ]] \
    || fail 'mid-restore rollback did not preserve the external state snapshot'
[[ "$(site_snapshot_without_mutable "$rollback_backup_root")" == "$rollback_backup_before_without_mutable" ]] \
    || fail 'mid-restore rollback changed protected backup content outside the exact mutable backups'
[[ "$(payment_log_reversible_snapshot "$rollback_site")" == "$rollback_payment_identity" ]] \
    || fail 'mid-restore rollback changed payment log bytes, inode or required metadata'
assert_payment_log_backup "$rollback_receipt" "$rollback_payment_logs"
assert_payment_rule_restored
mv -- "$PIKA_PAYMENT_LOGROTATE_RULE" "$PIKA_PAYMENT_LOGROTATE_RULE_BACKUP"
rollback_mutable_setting="$rollback_backup_root/mutable-site-files/app/View/User/Theme/Pika/Setting.php"
rollback_mutable_manifest="$rollback_backup_root/mutable-site-files/manifest.json"
assert_owner_mode "$rollback_mutable_setting" 0 0 600
assert_owner_mode "$rollback_mutable_manifest" 0 0 600
grep -q "'icp' => 'rollback-fixture'" "$rollback_mutable_setting" \
    || fail 'mid-restore rollback did not preserve the latest Pika setting recovery copy'
php -r '
    $manifest = json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
    if (($manifest["schema"] ?? null) !== 1
        || ($manifest["path"] ?? null) !== "app/View/User/Theme/Pika/Setting.php"
        || !hash_equals((string)($manifest["sha256"] ?? ""), hash_file("sha256", $argv[2]))) {
        fwrite(STDERR, "rollback mutable setting manifest does not bind the preserved bytes\n");
        exit(1);
    }
' "$rollback_mutable_manifest" "$rollback_mutable_setting"
rollback_backup_after_preserve="$(site_snapshot "$rollback_backup_root")"
php "$RELEASE_ROOT/scripts/verify-install.php" --site-root "$rollback_site"
rollback_archive_root_before="$(site_snapshot /var/lib/pika-local-extensions/archives)"
rollback_archive_log="$FIXTURE_ROOT/restore-rollback/after-state-archive.log"
if PIKA_RESTORE_TESTING=1 \
    PIKA_RESTORE_TEST_FAIL_AT=after-state-archive \
    "$RELEASE_ROOT/scripts/restore.sh" \
        --site-root "$rollback_site" \
        --receipt "$rollback_receipt" \
        --confirm-maintenance \
        >"$rollback_archive_log" 2>&1; then
    fail 'injected post-state-archive failure unexpectedly succeeded'
fi
grep -q 'RESTORE_ROLLBACK_PASS cause=injected restore failure at after-state-archive' "$rollback_archive_log" \
    || fail 'post-state-archive failure did not report rollback PASS'
assert_official_runtime_root_contract "$rollback_site"
[[ "$(site_snapshot "$rollback_site")" == "$rollback_before" ]] \
    || fail 'post-state-archive rollback did not preserve the site snapshot'
[[ "$(site_snapshot "$rollback_state")" == "$rollback_state_before" ]] \
    || fail 'post-state-archive rollback did not restore external state exactly'
[[ "$(site_snapshot /var/lib/pika-local-extensions/archives)" == "$rollback_archive_root_before" ]] \
    || fail 'post-state-archive rollback left an archive residue'
[[ "$(site_snapshot "$rollback_backup_root")" == "$rollback_backup_after_preserve" ]] \
    || fail 'post-state-archive rollback changed the established protected backup snapshot'
[[ "$(payment_log_reversible_snapshot "$rollback_site")" == "$rollback_payment_identity" ]] \
    || fail 'post-state-archive rollback changed payment log bytes, inode or required metadata'
assert_payment_log_backup "$rollback_receipt" "$rollback_payment_logs"
assert_payment_rule_restored
mv -- "$PIKA_PAYMENT_LOGROTATE_RULE" "$PIKA_PAYMENT_LOGROTATE_RULE_BACKUP"
php "$RELEASE_ROOT/scripts/verify-install.php" --site-root "$rollback_site"

"$RELEASE_ROOT/scripts/restore.sh" \
    --site-root "$rollback_site" \
    --receipt "$rollback_receipt" \
    --confirm-maintenance
assert_payment_log_backup "$rollback_receipt" "$rollback_payment_logs"
assert_absent "$rollback_site" \
    local-extensions \
    app/Controller/Admin/LocalExtensions.php \
    app/Controller/Admin/Api/LocalExtensions.php \
    app/Controller/User/Api/PikaBEpusdt.php \
    app/View/Admin/LocalExtensions \
    app/View/User/Theme/Pika \
    app/Pay/PikaBEpusdtAdapter \
    assets/admin/controller/local-extensions \
    assets/local-extensions
[[ ! -e "$rollback_state" && ! -L "$rollback_state" ]] \
    || fail 'successful rollback-fixture restore left live external state'

failure_site="$FIXTURE_ROOT/failure/site"
failure_backup="$BACKUP_FIXTURE_ROOT/failure"
failure_state="$(site_state_dir "$failure_site")"
mkdir -p "$failure_backup"
before_hashes="$(bridge_hashes "$failure_site")"
failure_log="$FIXTURE_ROOT/failure/install.log"
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$failure_site" \
    --backup-dir "$failure_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance \
    --php "$PHP_FAIL_WRITE_RECEIPT_FIXTURE" \
    >"$failure_log" 2>&1; then
    fail 'injected post-patch install failure unexpectedly succeeded'
fi
grep -q 'INJECTED_WRITE_RECEIPT_FAILURE' "$failure_log" \
    || fail 'failure injection did not reach the receipt phase'
grep -q 'install failed; original bridge files restored' "$failure_log" \
    || fail 'rollback result was not reported'
after_hashes="$(bridge_hashes "$failure_site")"
[[ "$after_hashes" == "$before_hashes" ]] || fail 'bridge files were not restored byte-for-byte'
assert_absent "$failure_site" \
    local-extensions \
    app/Controller/Admin/LocalExtensions.php \
    app/Controller/Admin/Api/LocalExtensions.php \
    app/Controller/User/Api/PikaBEpusdt.php \
    app/View/Admin/LocalExtensions \
    app/View/User/Theme/Pika \
    app/Pay/PikaBEpusdtAdapter \
    assets/admin/controller/local-extensions \
    assets/local-extensions \
    runtime/local-extensions
if find "$failure_backup" -name install-receipt.json -o -name installed-files.txt | grep -q .; then
    fail 'failed install left a successful receipt marker in the backup'
fi
[[ ! -e "$failure_state" && ! -L "$failure_state" ]] \
    || fail 'failed install left the per-site external state directory'
for rolled_back_new in assets/cache app/Plugin kernel/Install/OS runtime; do
    [[ ! -e "$failure_site/$rolled_back_new" && ! -L "$failure_site/$rolled_back_new" ]] \
        || fail "failed install left a newly-created official runtime directory: $rolled_back_new"
done
for rolled_back_existing in app/Pay app/View/User/Theme config kernel/Install; do
    assert_owner_mode "$failure_site/$rolled_back_existing" 0 0 755
done

# A concurrent writer violates maintenance, but rollback must still preserve
# its unexpected file, report residue, and never claim a complete rollback.
rollback_residue_log="$FIXTURE_ROOT/failure/install-rollback-residue.log"
(
    for _ in $(seq 1 1000); do
        if [[ -d "$failure_site/assets/cache" ]]; then
            printf '%s\n' 'unexpected-test-residue' \
                > "$failure_site/assets/cache/unexpected-residue"
            exit 0
        fi
        sleep 0.01
    done
    exit 1
) &
rollback_residue_writer=$!
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$failure_site" \
    --backup-dir "$failure_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance \
    --php "$PHP_FAIL_WRITE_RECEIPT_FIXTURE" \
    >"$rollback_residue_log" 2>&1; then
    wait "$rollback_residue_writer" || true
    fail 'rollback-residue failure injection unexpectedly succeeded'
fi
wait "$rollback_residue_writer" \
    || fail 'rollback-residue writer did not reach the newly-created official directory'
grep -q 'newly-created official runtime directory retained unexpected residue' \
    "$rollback_residue_log" \
    || fail 'rollback did not report the unexpected official runtime residue'
grep -q 'ROLLBACK_INCOMPLETE official runtime residue remains' "$rollback_residue_log" \
    || fail 'rollback did not fail closed with ROLLBACK_INCOMPLETE'
if grep -q 'install failed; original bridge files restored' "$rollback_residue_log"; then
    fail 'incomplete rollback emitted the complete-rollback message'
fi
[[ -f "$failure_site/assets/cache/unexpected-residue" ]] \
    || fail 'rollback recursively deleted unexpected official runtime residue'
[[ -d "$failure_state" && ! -L "$failure_state" ]] \
    || fail 'incomplete rollback did not preserve live external state evidence'
failure_incomplete_backup_evidence="$(find "$failure_backup" -mindepth 4 -maxdepth 4 \
    -type f -path '*/site/kernel/Kernel.php' -print -quit)"
[[ -n "$failure_incomplete_backup_evidence" \
    && -f "$failure_incomplete_backup_evidence" \
    && ! -L "$failure_incomplete_backup_evidence" ]] \
    || fail 'incomplete rollback did not preserve protected backup evidence'
rm "$failure_site/assets/cache/unexpected-residue"
rmdir "$failure_site/assets/cache"
for rolled_back_existing in app/Pay app/View/User/Theme config kernel/Install; do
    assert_owner_mode "$failure_site/$rolled_back_existing" 0 0 755
done

# The retained state above is intentional production evidence. Only after all
# assertions, remove this disposable fixture through the exact-state helper so
# it cannot interfere with the next isolated Web-identity case.
remove_retained_install_state_exact "$failure_state" 0

post_doctor_site="$FIXTURE_ROOT/post-doctor-failure/site"
post_doctor_backup="$BACKUP_FIXTURE_ROOT/post-doctor-failure"
post_doctor_state="$(site_state_dir "$post_doctor_site")"
mkdir -p "$post_doctor_backup"
post_doctor_before="$(site_snapshot "$post_doctor_site")"
post_doctor_log="$FIXTURE_ROOT/post-doctor-failure/install.log"
if "$RELEASE_ROOT/scripts/install.sh" \
    --site-root "$post_doctor_site" \
    --backup-dir "$post_doctor_backup" \
    --web-user "$WEB_USER" \
    --confirm-maintenance \
    --php "$PHP_FAIL_VERIFY_INSTALL_FIXTURE" \
    >"$post_doctor_log" 2>&1; then
    fail 'injected post-receipt doctor failure unexpectedly succeeded'
fi
grep -q 'INJECTED_VERIFY_INSTALL_FAILURE' "$post_doctor_log" \
    || fail 'post-receipt failure injection did not reach installed doctor verification'
grep -q 'install failed; original bridge files restored' "$post_doctor_log" \
    || fail 'post-receipt doctor failure did not report installer rollback'
[[ "$(site_snapshot "$post_doctor_site")" == "$post_doctor_before" ]] \
    || fail 'post-receipt doctor failure did not restore the site snapshot'
[[ ! -e "$post_doctor_state" && ! -L "$post_doctor_state" ]] \
    || fail 'post-receipt doctor failure left external state'
if find "$post_doctor_backup" -name install-receipt.json -o -name installed-files.txt | grep -q .; then
    fail 'post-receipt doctor failure left a successful receipt marker'
fi

run_install_identity_drift_case() {
    local scenario="$1" mutation="$2" phase="$3"
    local site="$FIXTURE_ROOT/$scenario/site"
    local backup="$BACKUP_FIXTURE_ROOT/$scenario"
    local state target replacement original log php_fixture expected_receipt=0
    local mutation_writer backup_kernel receipt_count list_count
    state="$(site_state_dir "$site")"
    target="$site/local-extensions/bootstrap.php"
    replacement="$FIXTURE_ROOT/$scenario/replacement-bootstrap.php"
    original="$FIXTURE_ROOT/$scenario/original-bootstrap.php"
    log="$FIXTURE_ROOT/$scenario/install.log"
    mkdir -p "$backup"
    rm -f -- /tmp/pika-install-mutation-ready /tmp/pika-install-mutation-done
    case "$phase" in
        pre-receipt) php_fixture="$PHP_FAIL_WRITE_RECEIPT_DELAYED_FIXTURE" ;;
        post-receipt)
            php_fixture="$PHP_FAIL_VERIFY_INSTALL_DELAYED_FIXTURE"
            expected_receipt=1
            ;;
        *) fail "invalid identity-drift phase: $phase" ;;
    esac
    if [[ "$mutation" == inode ]]; then
        printf '%s\n' '<?php /* replacement inode fixture */' > "$replacement"
        chown 0:0 "$replacement"
        chmod 0644 "$replacement"
    fi
    if [[ "$mutation" == symlink ]]; then
        printf '%s\n' 'symlink target sentinel' > /opt/pika-test-space/rollback-symlink-target
        chown 0:0 /opt/pika-test-space/rollback-symlink-target
        chmod 0644 /opt/pika-test-space/rollback-symlink-target
    fi
    (
        # Docker Desktop may need more than ten seconds to reach the injected
        # install phase under load. Keep this fixture-only handshake bounded
        # without changing any installer or production timeout.
        for _ in $(seq 1 6000); do
            [[ -f /tmp/pika-install-mutation-ready ]] && break
            sleep 0.01
        done
        [[ -f /tmp/pika-install-mutation-ready ]] || exit 71
        [[ -f "$target" && ! -L "$target" ]] || exit 72
        case "$mutation" in
            inode)
                mv -- "$replacement" "$target"
                ;;
            symlink)
                mv -- "$target" "$original"
                ln -s /opt/pika-test-space/rollback-symlink-target "$target"
                ;;
            acl)
                "$acl_php_root/set-posix-acl" "$target" "$WEB_UID"
                ;;
            *) exit 73 ;;
        esac
        : > /tmp/pika-install-mutation-done
    ) &
    mutation_writer=$!
    if "$RELEASE_ROOT/scripts/install.sh" \
        --site-root "$site" \
        --backup-dir "$backup" \
        --web-user "$WEB_USER" \
        --confirm-maintenance \
        --php "$php_fixture" \
        >"$log" 2>&1; then
        wait "$mutation_writer" || true
        fail "identity-drift install unexpectedly succeeded: $scenario"
    fi
    wait "$mutation_writer" \
        || fail "identity-drift writer failed before mutation: $scenario"
    if [[ "$phase" == post-receipt ]]; then
        grep -q 'INJECTED_VERIFY_INSTALL_FAILURE' "$log" \
            || fail "post-receipt drift did not reach installed verification: $scenario"
    else
        grep -q 'INJECTED_WRITE_RECEIPT_FAILURE' "$log" \
            || fail "pre-receipt drift did not reach receipt failure: $scenario"
    fi
    grep -q 'ROLLBACK_INCOMPLETE official runtime residue remains' "$log" \
        || fail "identity drift did not fail closed with ROLLBACK_INCOMPLETE: $scenario"
    if grep -q 'install failed; original bridge files restored' "$log"; then
        fail "identity drift emitted a complete-rollback message: $scenario"
    fi
    case "$mutation" in
        inode)
            grep -q 'created payload file identity drifted or could not be removed' "$log" \
                || fail 'inode replacement did not fail at created-file identity binding'
            [[ -f "$target" && ! -L "$target" ]] \
                || fail 'inode replacement evidence was not preserved'
            ;;
        symlink)
            grep -q 'created payload file became unsafe' "$log" \
                || fail 'symlink swap did not fail at created-file safety binding'
            [[ -L "$target" && "$(readlink -- "$target")" \
                == /opt/pika-test-space/rollback-symlink-target ]] \
                || fail 'symlink swap evidence was not preserved'
            ;;
        acl)
            grep -q 'must not have an extended POSIX ACL' "$log" \
                || fail 'ACL drift did not fail at the ACL rollback gate'
            [[ "$(LC_ALL=C ls -ld -- "$target" | awk '{print $1}')" == *+ ]] \
                || fail 'ACL drift evidence was not preserved'
            ;;
    esac
    [[ -d "$state" && ! -L "$state" ]] \
        || fail "identity drift did not preserve live state evidence: $scenario"
    backup_kernel="$(find "$backup" -mindepth 4 -maxdepth 4 \
        -type f -path '*/site/kernel/Kernel.php' -print -quit)"
    [[ -n "$backup_kernel" && -f "$backup_kernel" && ! -L "$backup_kernel" ]] \
        || fail "identity drift did not preserve backup evidence: $scenario"
    receipt_count="$(find "$backup" -type f -name install-receipt.json | wc -l | tr -d ' ')"
    list_count="$(find "$backup" -type f -name installed-files.txt | wc -l | tr -d ' ')"
    if [[ "$expected_receipt" == 1 ]]; then
        [[ "$receipt_count" == 1 && "$list_count" == 1 ]] \
            || fail 'post-receipt incomplete rollback did not preserve both receipt artifacts'
    else
        [[ "$receipt_count" == 0 && "$list_count" == 0 ]] \
            || fail 'pre-receipt incomplete rollback unexpectedly published receipt artifacts'
    fi
    remove_retained_install_state_exact "$state" "$expected_receipt"
    rm -f -- /tmp/pika-install-mutation-ready /tmp/pika-install-mutation-done
}

# Fault-injection coverage for all three identity channels. The inode case is
# deliberately post-receipt to prove an incomplete rollback preserves both the
# protected receipt evidence and live external state.
run_install_identity_drift_case rollback-inode inode post-receipt
run_install_identity_drift_case rollback-symlink symlink pre-receipt
run_install_identity_drift_case rollback-acl acl pre-receipt

# A fresh installation without any configured rotation remains a valid lifecycle.
unconfigured_site="$FIXTURE_ROOT/unconfigured-logs/site"
unconfigured_backup="$BACKUP_FIXTURE_ROOT/unconfigured-logs"
mkdir "$unconfigured_backup"
"$RELEASE_ROOT/scripts/install.sh" --site-root "$unconfigured_site" \
    --backup-dir "$unconfigured_backup" --web-user "$WEB_USER" --confirm-maintenance
export PIKA_PAYMENT_LOGROTATE_RULE=/etc/logrotate.d/pika-unconfigured-logs
export PIKA_PAYMENT_LOGROTATE_RULE_BACKUP=not-configured
[[ ! -e "$PIKA_PAYMENT_LOGROTATE_RULE" && ! -L "$PIKA_PAYMENT_LOGROTATE_RULE" ]] \
    || fail 'the no-rotation fixture unexpectedly has a configured include rule'
printf 'synthetic-unconfigured-active-log\n' > "$unconfigured_site/app/Pay/PikaBEpusdtAdapter/runtime.log"
unconfigured_receipt="$(receipt_backup_path "$unconfigured_site")"
unconfigured_payment_logs="$(payment_log_snapshot "$unconfigured_site")"
[[ "$(printf '%s\n' "$unconfigured_payment_logs" | wc -l | tr -d ' ')" == 1 ]] \
    || fail 'the no-rotation fixture contains a rotated archive'
"$RELEASE_ROOT/scripts/restore.sh" --site-root "$unconfigured_site" \
    --receipt "$unconfigured_receipt" --confirm-maintenance
assert_payment_log_backup "$unconfigured_receipt" "$unconfigured_payment_logs"
assert_absent "$unconfigured_site" app/Pay/PikaBEpusdtAdapter
printf 'UNCONFIGURED_PAYMENT_LOG_LIFECYCLE_PASS active_log=preserved\n'

if [[ -d /legacy-release && ! -L /legacy-release ]]; then
    # The release owner supplies a separately pinned, read-only bdb artifact.
    # No production receipt or payment data enters this disposable scenario.
    legacy_root=/opt/pika-test-space/legacy-release
    mkdir "$legacy_root"
    for release_entry in bridge manager extensions themes payment-adapters scripts packaging; do
        cp -R --no-preserve=ownership "/legacy-release/$release_entry" "$legacy_root/"
    done
    cp --no-preserve=ownership /legacy-release/compatibility.json /legacy-release/release.json "$legacy_root/"
    legacy_site="$FIXTURE_ROOT/legacy-upgrade/site"
    legacy_backup="$BACKUP_FIXTURE_ROOT/legacy-upgrade"
    mkdir "$legacy_backup"
    "$legacy_root/scripts/install.sh" --site-root "$legacy_site" \
        --backup-dir "$legacy_backup" --web-user "$WEB_USER" --confirm-maintenance
    legacy_receipt="$(receipt_backup_path "$legacy_site")"
    prepare_payment_rule_isolation "$legacy_site" legacy-upgrade
    php -r '
        $receipt = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
        if (($receipt["schema"] ?? null) !== 1) { exit(1); }
        foreach ($receipt["installed_files"] as $entry) {
            if (str_starts_with($entry["path"], "app/Pay/PikaBEpusdtAdapter/runtime.log")) { exit(1); }
        }
    ' "$legacy_receipt" || fail 'legacy installer did not generate the expected schema-1 immutable receipt'
    prepare_payment_log_rotations "$legacy_site" legacy-upgrade 9
    legacy_payment_logs="$(payment_log_snapshot "$legacy_site")"
    "$RELEASE_ROOT/scripts/restore.sh" --site-root "$legacy_site" \
        --receipt "$legacy_receipt" --confirm-maintenance
    assert_payment_log_backup "$legacy_receipt" "$legacy_payment_logs"
    assert_absent "$legacy_site" app/Pay/PikaBEpusdtAdapter
    legacy_reinstall_backup="$BACKUP_FIXTURE_ROOT/legacy-reinstall"
    mkdir "$legacy_reinstall_backup"
    "$RELEASE_ROOT/scripts/install.sh" --site-root "$legacy_site" \
        --backup-dir "$legacy_reinstall_backup" --web-user "$WEB_USER" --confirm-maintenance
    "$RELEASE_ROOT/scripts/doctor.sh" --site-root "$legacy_site" --installed
    legacy_reinstall_receipt="$(receipt_backup_path "$legacy_site")"
    prepare_payment_log_rotations "$legacy_site" legacy-reinstall 2
    legacy_reinstall_logs="$(payment_log_snapshot "$legacy_site")"
    "$RELEASE_ROOT/scripts/restore.sh" --site-root "$legacy_site" \
        --receipt "$legacy_reinstall_receipt" --confirm-maintenance
    assert_payment_log_backup "$legacy_reinstall_receipt" "$legacy_reinstall_logs"
    assert_absent "$legacy_site" app/Pay/PikaBEpusdtAdapter
    printf 'LEGACY_RECEIPT_UPGRADE_PASS schema=1 source=/legacy-release\n'
else
    printf 'LEGACY_RECEIPT_UPGRADE_NOT_RUN reason=separately-pinned-legacy-release-not-mounted\n'
fi

# Loss of rule isolation after staging cannot safely republish live logs. Keep
# this final disposable fixture intact until container exit for fail-stop proof.
rule_drift_site="$FIXTURE_ROOT/payment-rule-drift/site"
rule_drift_backup="$BACKUP_FIXTURE_ROOT/payment-rule-drift"
mkdir "$rule_drift_backup"
"$RELEASE_ROOT/scripts/install.sh" --site-root "$rule_drift_site" \
    --backup-dir "$rule_drift_backup" --web-user "$WEB_USER" --confirm-maintenance
prepare_payment_rule_isolation "$rule_drift_site" payment-rule-drift
prepare_payment_log_rotations "$rule_drift_site" payment-rule-drift 2
rule_drift_receipt="$(receipt_backup_path "$rule_drift_site")"
rule_drift_logs="$(payment_log_snapshot "$rule_drift_site")"
rule_drift_exit=0
PIKA_RESTORE_TESTING=1 PIKA_RESTORE_TEST_FAIL_AT=reinclude-payment-rule \
    "$RELEASE_ROOT/scripts/restore.sh" --site-root "$rule_drift_site" \
    --receipt "$rule_drift_receipt" --confirm-maintenance \
    > "$FIXTURE_ROOT/payment-rule-drift/restore.log" 2>&1 || rule_drift_exit=$?
[[ "$rule_drift_exit" == 70 ]] \
    || fail 'mid-restore rule reinclusion did not stop with manual-recovery exit 70'
grep -q 'RESTORE_ROLLBACK_FAIL' "$FIXTURE_ROOT/payment-rule-drift/restore.log" \
    || fail 'mid-restore rule reinclusion did not explicitly report retained unsafe-to-rollback state'
assert_payment_log_backup "$rule_drift_receipt" "$rule_drift_logs"
while IFS=$'\t' read -r log_name log_metadata log_digest; do
    [[ -n "$log_name" ]] || continue
    [[ ! -e "$rule_drift_site/app/Pay/PikaBEpusdtAdapter/$log_name" \
        && ! -L "$rule_drift_site/app/Pay/PikaBEpusdtAdapter/$log_name" ]] \
        || fail 'rule reinclusion failure republished a live payment log'
    IFS=: read -r log_dev log_ino _ <<< "$log_metadata"
    mapfile -t retained_log_stages < <(find -P "$rule_drift_site/app/Pay/PikaBEpusdtAdapter" \
        -maxdepth 1 -type f -name '.pika-restore-payload.*' -inum "$log_ino" -print)
    [[ "${#retained_log_stages[@]}" == 1 ]] \
        || fail "rule reinclusion did not retain exactly one original payment log inode: $log_name"
    retained_log_stage="${retained_log_stages[0]}"
    [[ "$(stat -c '%d:%i:%h:%u:%g:%a:%s:%Y' "$retained_log_stage")" == "${log_metadata%:*}" \
        && "$(sha256sum "$retained_log_stage" | awk '{print $1}')" == "$log_digest" ]] \
        || fail "rule reinclusion changed staged log bytes or required metadata: $log_name"
done <<< "$rule_drift_logs"
[[ "$(stat -c '%d:%i:%h:%u:%g:%a:%s:%Y' "$PIKA_PAYMENT_LOGROTATE_RULE"):$(sha256sum "$PIKA_PAYMENT_LOGROTATE_RULE" | awk '{print $1}')" == "$PAYMENT_RULE_IDENTITY" \
    && ! -e "$PIKA_PAYMENT_LOGROTATE_RULE_BACKUP" ]] \
    || fail 'rule reinclusion hook did not preserve the exact original rule inode and bytes'
for retained_barrier in assets/cache app/Pay app/Plugin app/View/User/Theme config kernel/Install runtime; do
    assert_owner_mode "$rule_drift_site/$retained_barrier" 0 0 700
done
[[ -d "$(site_state_dir "$rule_drift_site")" && -f "$rule_drift_receipt" ]] \
    || fail 'rule reinclusion failure lost its live receipt state or protected recovery receipt'
printf 'PAYMENT_RULE_DRIFT_FAIL_STOP_PASS exit=%s retained_original_log_inodes=all\n' "$rule_drift_exit"

printf 'INSTALL_INTEGRATION_PASS web_uid=%s web_gid=%s\n' "$WEB_UID" "$WEB_GID"
