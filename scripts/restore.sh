#!/bin/bash
set -euo pipefail

readonly PIKA_RESTORE_TRUSTED_PATH='/usr/sbin:/usr/bin:/sbin:/bin'
if ((EUID == 0)); then
    PATH="$PIKA_RESTORE_TRUSTED_PATH"
    export PATH
    builtin unalias -a 2>/dev/null || true
    while IFS= read -r imported_function; do
        builtin unset -f -- "$imported_function"
    done < <(builtin compgen -A function)
    builtin hash -r
    builtin readonly PATH
fi

PIKA_RESTORE_SCRIPT_PATH="$(realpath -- "${BASH_SOURCE[0]}")" || {
    printf 'ERROR: unable to resolve restore path\n' >&2
    exit 1
}
[[ -f "$PIKA_RESTORE_SCRIPT_PATH" && ! -L "$PIKA_RESTORE_SCRIPT_PATH" ]] || {
    printf 'ERROR: restore path is not a canonical regular file\n' >&2
    exit 1
}
SCRIPT_DIR="$(cd -P -- "$(dirname -- "$PIKA_RESTORE_SCRIPT_PATH")" && pwd)"

pika_restore_bootstrap_assert_root_path() {
    local path="$1" label="$2" metadata owner group mode mode_value permission_token
    [[ ! -L "$path" && ( -f "$path" || -d "$path" ) ]] || {
        printf 'ERROR: %s is missing, unsupported, or a symbolic link: %s\n' "$label" "$path" >&2
        exit 1
    }
    metadata="$(stat -c '%u:%g:%a' -- "$path")" || exit 1
    IFS=: read -r owner group mode <<<"$metadata"
    [[ "$owner" == 0 && "$group" == 0 && "$mode" =~ ^[0-7]{3,4}$ ]] || {
        printf 'ERROR: %s must be root:root with a valid mode: %s\n' "$label" "$path" >&2
        exit 1
    }
    mode_value=$((8#$mode))
    (( (mode_value & 0022) == 0 )) || {
        printf 'ERROR: %s must not be group/world writable: %s\n' "$label" "$path" >&2
        exit 1
    }
    permission_token="$(LC_ALL=C ls -ld -- "$path")" || exit 1
    permission_token="${permission_token%%[[:space:]]*}"
    [[ "$permission_token" != *+ ]] || {
        printf 'ERROR: %s must not have an extended POSIX ACL: %s\n' "$label" "$path" >&2
        exit 1
    }
}

if ((EUID == 0)); then
    pika_restore_bootstrap_assert_root_path "$PIKA_RESTORE_SCRIPT_PATH" 'restore entrypoint'
    pika_restore_bootstrap_assert_root_path "$SCRIPT_DIR/lib.sh" 'restore library'
    bootstrap_cursor="$SCRIPT_DIR"
    while :; do
        pika_restore_bootstrap_assert_root_path "$bootstrap_cursor" 'restore release ancestor'
        [[ "$bootstrap_cursor" == / ]] && break
        bootstrap_cursor="$(dirname -- "$bootstrap_cursor")"
    done
fi
unset -f pika_restore_bootstrap_assert_root_path
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
pika_assert_trusted_release_tree

site_arg=""
receipt_arg=""
maintenance_confirmed=0

while (($#)); do
    case "$1" in
        --site-root)
            (($# >= 2)) || pika_die "--site-root requires a value"
            site_arg="$2"
            shift 2
            ;;
        --receipt)
            (($# >= 2)) || pika_die "--receipt requires a value"
            receipt_arg="$2"
            shift 2
            ;;
        --php)
            (($# >= 2)) || pika_die "--php requires a value"
            PIKA_PHP_BIN="$2"
            export PIKA_PHP_BIN
            shift 2
            ;;
        --confirm-maintenance)
            maintenance_confirmed=1
            shift
            ;;
        *)
            pika_die "unknown argument: $1"
            ;;
    esac
done

[[ -n "$site_arg" && -n "$receipt_arg" ]] \
    || pika_die "usage: restore.sh --site-root /absolute/acg-faka/path --receipt /absolute/backup/install-receipt.json --confirm-maintenance"
[[ "$(id -u)" -eq 0 ]] || pika_die "restore must run as root (use sudo)"
((maintenance_confirmed == 1)) \
    || pika_die "--confirm-maintenance is required after removing the scheduler and quiescing web/FPM and CLI writers"

site_root="$(pika_real_dir "$site_arg")"
[[ "$site_arg" == "$site_root" ]] || pika_die "--site-root must be an exact canonical path"
pika_assert_safe_site_root "$site_root"

[[ "$receipt_arg" = /* && -f "$receipt_arg" && ! -L "$receipt_arg" ]] \
    || pika_die "--receipt must be an exact absolute regular-file path"
receipt_dir="$(cd -P -- "$(dirname -- "$receipt_arg")" && pwd)"
receipt_path="$receipt_dir/$(basename -- "$receipt_arg")"
[[ "$receipt_arg" == "$receipt_path" && "$(basename -- "$receipt_path")" == "install-receipt.json" ]] \
    || pika_die "--receipt must name the exact canonical install-receipt.json path"
case "$receipt_dir/" in
    "$site_root/"* ) pika_die "receipt backup must be outside the site root" ;;
esac
case "$site_root/" in
    "$receipt_dir/"* ) pika_die "site root must be outside the receipt backup" ;;
esac
pika_assert_root_directory_chain "$receipt_dir" 'receipt backup directory'

php_bin="$(pika_php)" || exit 1

pika_assert_scheduler_removed() {
    local unit
    [[ -d /etc/systemd/system ]] || return 0
    while IFS= read -r -d '' unit; do
        [[ ! -L "$unit" ]] \
            || pika_die "LocalExtensions scheduler unit path must not be a symbolic link before restore: $unit"
        if grep -Fqx -- "# Pika-SiteRoot: $site_root" "$unit"; then
            pika_die "managed LocalExtensions scheduler must be removed before restore: $unit"
        fi
    done < <(find /etc/systemd/system -maxdepth 1 \
        \( -type f -o -type l \) \
        \( -name 'pika-supply-sync-*.service' -o -name 'pika-supply-sync-*.timer' \
            -o -name 'pika-catalog-worker-*.service' -o -name 'pika-catalog-worker-*.timer' \) \
        -print0)
}

pika_assert_no_sync_process() {
    local cmdline argument sync_bin catalog_worker_bin
    local -a arguments=()
    sync_bin="$site_root/local-extensions/extensions/PikaSupplySync/bin/sync.php"
    catalog_worker_bin="$site_root/local-extensions/extensions/PikaCatalogHub/bin/worker.php"
    [[ -d /proc ]] \
        || pika_die "restore requires /proc to verify that no LocalExtensions CLI is active"
    for cmdline in /proc/[0-9]*/cmdline; do
        [[ -r "$cmdline" ]] || continue
        arguments=()
        mapfile -d '' -t arguments < "$cmdline" 2>/dev/null || true
        for argument in "${arguments[@]}"; do
            if [[ "$argument" == "$sync_bin" ]]; then
                pika_die "active SupplySync CLI must stop before restore: ${cmdline%/cmdline}"
            fi
            if [[ "$argument" == "$catalog_worker_bin" ]]; then
                pika_die "active CatalogHub worker must stop before restore: ${cmdline%/cmdline}"
            fi
        done
    done
}

declare -a PIKA_RESTORE_LOCK_FDS=()
pika_hold_sync_locks() {
    local state_dir lock_root lock_path lock_fd
    state_dir="$(pika_site_state_dir "$site_root")" \
        || pika_die "unable to derive external state directory"
    lock_root="$state_dir/runtime/extensions/PikaSupplySync"
    [[ ! -e "$lock_root" && ! -L "$lock_root" ]] && return 0
    [[ -d "$lock_root" && ! -L "$lock_root" ]] \
        || pika_die "SupplySync lock directory is unsafe: $lock_root"
    while IFS= read -r -d '' lock_path; do
        [[ -f "$lock_path" && ! -L "$lock_path" ]] \
            || pika_die "SupplySync run lock is unsafe: $lock_path"
        exec {lock_fd}<>"$lock_path" \
            || pika_die "unable to open SupplySync run lock: $lock_path"
        if ! flock -n "$lock_fd"; then
            exec {lock_fd}>&-
            pika_die "active SupplySync run lock must clear before restore: $lock_path"
        fi
        PIKA_RESTORE_LOCK_FDS+=("$lock_fd")
    done < <(find "$lock_root" -maxdepth 1 \
        \( -type f -o -type l \) -name 'source-*.run.lock' -print0)
}

pika_hold_catalog_worker_lock() {
    local state_dir lock_root lock_path lock_fd runtime_owner
    local handle_metadata path_metadata
    local handle_type handle_owner handle_mode handle_nlink handle_dev handle_ino
    local path_type path_owner path_mode path_nlink path_dev path_ino
    state_dir="$(pika_site_state_dir "$site_root")" \
        || pika_die "unable to derive external state directory"
    lock_root="$state_dir/runtime/extensions/PikaCatalogHub"
    [[ ! -e "$lock_root" && ! -L "$lock_root" ]] && return 0
    [[ -d "$lock_root" && ! -L "$lock_root" \
        && "$(realpath -e -- "$lock_root")" == "$lock_root" ]] \
        || pika_die "CatalogHub worker lock directory is unsafe: $lock_root"
    lock_path="$lock_root/worker.run.lock"
    [[ ! -e "$lock_path" && ! -L "$lock_path" ]] && return 0
    [[ -f "$lock_path" && ! -L "$lock_path" ]] \
        || pika_die "CatalogHub worker run lock is unsafe: $lock_path"
    runtime_owner="$(stat -c '%u' -- "$state_dir/runtime")" \
        || pika_die "unable to inspect CatalogHub runtime owner"
    exec {lock_fd}<>"$lock_path" \
        || pika_die "unable to open CatalogHub worker run lock: $lock_path"
    handle_metadata="$(stat -L -c '%F:%u:%a:%h:%d:%i' -- "/proc/$$/fd/$lock_fd")" \
        || {
            exec {lock_fd}>&-
            pika_die "unable to inspect opened CatalogHub worker run lock: $lock_path"
        }
    path_metadata="$(stat -L -c '%F:%u:%a:%h:%d:%i' -- "$lock_path")" \
        || {
            exec {lock_fd}>&-
            pika_die "unable to inspect CatalogHub worker run lock path: $lock_path"
        }
    IFS=: read -r handle_type handle_owner handle_mode handle_nlink handle_dev handle_ino \
        <<<"$handle_metadata"
    IFS=: read -r path_type path_owner path_mode path_nlink path_dev path_ino \
        <<<"$path_metadata"
    if [[ "$handle_type" != regular*file \
        || "$path_type" != regular*file \
        || "$handle_owner" != "$runtime_owner" \
        || "$path_owner" != "$runtime_owner" \
        || "$handle_mode" != 600 \
        || "$path_mode" != 600 \
        || "$handle_nlink" != 1 \
        || "$path_nlink" != 1 \
        || "$handle_dev" != "$path_dev" \
        || "$handle_ino" != "$path_ino" \
        || -L "$lock_path" ]]; then
        exec {lock_fd}>&-
        pika_die "CatalogHub worker run lock ownership, permissions, or identity are unsafe: $lock_path"
    fi
    if ! flock -n "$lock_fd"; then
        exec {lock_fd}>&-
        pika_die "active CatalogHub worker run lock must clear before restore: $lock_path"
    fi
    path_metadata="$(stat -L -c '%F:%u:%a:%h:%d:%i' -- "$lock_path")" \
        || {
            exec {lock_fd}>&-
            pika_die "unable to revalidate CatalogHub worker run lock path: $lock_path"
        }
    if [[ "$path_metadata" != "$handle_metadata" || -L "$lock_path" ]]; then
        exec {lock_fd}>&-
        pika_die "CatalogHub worker run lock changed while restore acquired it: $lock_path"
    fi
    PIKA_RESTORE_LOCK_FDS+=("$lock_fd")
}

pika_require_command flock
pika_assert_scheduler_removed
pika_assert_no_sync_process
pika_hold_sync_locks
pika_hold_catalog_worker_lock
# Rescan after acquiring every extant run lock. The external maintenance gate
# represented by --confirm-maintenance prevents creation of a new source/lock;
# the CatalogHub global lock blocks a worker that races this final process scan.
pika_assert_no_sync_process

# This is the public verification gate. The restore helper independently
# revalidates the explicit protected receipt before performing its first write.
"$php_bin" "${SCRIPT_DIR}/verify-install.php" --site-root "$site_root"
state_dir="$(pika_site_state_dir "$site_root")" \
    || pika_die "unable to derive external state directory"
runtime_metadata="$(stat -c '%u:%g' -- "$state_dir/runtime")" \
    || pika_die "unable to inspect installed runtime identity"
IFS=: read -r runtime_uid runtime_gid <<<"$runtime_metadata"
pika_assert_isolated_web_identity "$runtime_uid" "$runtime_gid" 'installed site web identity'
pika_assert_external_state_no_acl "$state_dir"
"$php_bin" "${SCRIPT_DIR}/restore-install.php" \
    --site-root "$site_root" \
    --receipt "$receipt_path"
