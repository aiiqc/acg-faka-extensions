#!/bin/bash
set -euo pipefail

readonly PIKA_SCHEDULER_TRUSTED_PATH='/usr/sbin:/usr/bin:/sbin:/bin'
if (( EUID == 0 )) || [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    PATH="$PIKA_SCHEDULER_TRUSTED_PATH"
    export PATH
    builtin unalias -a 2>/dev/null || true
    while IFS= read -r imported_function; do
        builtin unset -f -- "$imported_function"
    done < <(builtin compgen -A function)
    builtin hash -r
    builtin readonly PATH
fi

umask 0077

PIKA_SCHEDULER_SCRIPT_PATH="$(realpath -- "${BASH_SOURCE[0]}")" || {
    printf 'ERROR: unable to resolve scheduler path\n' >&2
    exit 1
}
[[ -f "$PIKA_SCHEDULER_SCRIPT_PATH" && ! -L "$PIKA_SCHEDULER_SCRIPT_PATH" ]] || {
    printf 'ERROR: scheduler path is not a canonical regular file\n' >&2
    exit 1
}
readonly PIKA_SCHEDULER_SCRIPT_DIR="$(cd -P -- "$(dirname -- "$PIKA_SCHEDULER_SCRIPT_PATH")" && pwd)"

pika_scheduler_bootstrap_assert_root_path() {
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

if (( EUID == 0 )) || [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    pika_scheduler_bootstrap_assert_root_path "$PIKA_SCHEDULER_SCRIPT_PATH" 'scheduler entrypoint'
    pika_scheduler_bootstrap_assert_root_path "$PIKA_SCHEDULER_SCRIPT_DIR/lib.sh" 'scheduler library'
    bootstrap_cursor="$PIKA_SCHEDULER_SCRIPT_DIR"
    while :; do
        pika_scheduler_bootstrap_assert_root_path "$bootstrap_cursor" 'scheduler release ancestor'
        [[ "$bootstrap_cursor" == / ]] && break
        bootstrap_cursor="$(dirname -- "$bootstrap_cursor")"
    done
fi
unset -f pika_scheduler_bootstrap_assert_root_path
# shellcheck source=lib.sh
source "${PIKA_SCHEDULER_SCRIPT_DIR}/lib.sh"

readonly PIKA_SCHEDULER_UNIT_DIR='/etc/systemd/system'
readonly PIKA_SCHEDULER_SERVICE_TEMPLATE="${PIKA_REPO_DIR}/packaging/systemd/pika-supply-sync.service.in"
readonly PIKA_SCHEDULER_TIMER_TEMPLATE="${PIKA_REPO_DIR}/packaging/systemd/pika-supply-sync.timer.in"
readonly PIKA_SCHEDULER_CATALOG_SERVICE_TEMPLATE="${PIKA_REPO_DIR}/packaging/systemd/pika-catalog-worker.service.in"
readonly PIKA_SCHEDULER_CATALOG_TIMER_TEMPLATE="${PIKA_REPO_DIR}/packaging/systemd/pika-catalog-worker.timer.in"

PIKA_SCHEDULER_STAGE_DIR=''
PIKA_SCHEDULER_SYSTEMCTL=''
PIKA_SCHEDULER_SYSTEMD_ANALYZE=''
PIKA_SCHEDULER_SERVICE_UNIT=''
PIKA_SCHEDULER_TIMER_UNIT=''
PIKA_SCHEDULER_SERVICE_TARGET=''
PIKA_SCHEDULER_TIMER_TARGET=''
PIKA_SCHEDULER_CATALOG_SERVICE_UNIT=''
PIKA_SCHEDULER_CATALOG_TIMER_UNIT=''
PIKA_SCHEDULER_CATALOG_SERVICE_TARGET=''
PIKA_SCHEDULER_CATALOG_TIMER_TARGET=''
PIKA_SCHEDULER_PREV_ENABLED=0
PIKA_SCHEDULER_PREV_ACTIVE=0
PIKA_SCHEDULER_CATALOG_PREV_ENABLED=0
PIKA_SCHEDULER_CATALOG_PREV_ACTIVE=0
PIKA_SCHEDULER_INSTALL_MUTATED=0
PIKA_SCHEDULER_INSTALL_CREATED_SERVICE=0
PIKA_SCHEDULER_INSTALL_CREATED_TIMER=0
PIKA_SCHEDULER_INSTALL_CREATED_CATALOG_SERVICE=0
PIKA_SCHEDULER_INSTALL_CREATED_CATALOG_TIMER=0
PIKA_SCHEDULER_ENABLE_ATTEMPTED=0
PIKA_SCHEDULER_CATALOG_ENABLE_ATTEMPTED=0
PIKA_SCHEDULER_REMOVE_MUTATED=0
PIKA_SCHEDULER_REMOVE_DELETED_SERVICE=0
PIKA_SCHEDULER_REMOVE_DELETED_TIMER=0
PIKA_SCHEDULER_REMOVE_DELETED_CATALOG_SERVICE=0
PIKA_SCHEDULER_REMOVE_DELETED_CATALOG_TIMER=0
PIKA_SCHEDULER_SUCCESS=0
PIKA_SCHEDULER_SITE_ROOT=''
PIKA_SCHEDULER_UNITS_PREEXISTED=0
PIKA_SCHEDULER_CATALOG_UNITS_PREEXISTED=0
PIKA_SCHEDULER_CONFIG_DEV=''
PIKA_SCHEDULER_CONFIG_INODE=''

pika_scheduler_usage() {
    cat <<'EOF'
Usage:
  sudo scripts/scheduler.sh install --site-root /absolute/acg-faka/path --web-user USER --instance SAFE_ID [--minutes 10] [--php /absolute/php]
  sudo scripts/scheduler.sh remove  --site-root /absolute/acg-faka/path --web-user USER --instance SAFE_ID [--php /absolute/php]

The SupplySync interval defaults to 10 minutes and must be between 5 and 1440 minutes.
The CatalogHub background worker wakes about one minute after the prior run becomes inactive, with a batch limit of 20.
EOF
}

pika_scheduler_validate_instance() {
    local instance="$1"
    [[ "$instance" =~ ^[a-z0-9][a-z0-9-]{0,62}$ ]] \
        || pika_die "--instance must match ^[a-z0-9][a-z0-9-]{0,62}$"
}

pika_scheduler_validate_web_user() {
    local web_user="$1"
    [[ "$web_user" =~ ^[a-z_][a-z0-9_.-]{0,30}\$?$ ]] \
        || pika_die "--web-user contains unsupported characters"
}

pika_scheduler_resolve_path_command() {
    local name="$1" candidate resolved directory
    [[ "$name" =~ ^[A-Za-z0-9][A-Za-z0-9._+-]*$ ]] \
        || pika_die "command name contains unsupported characters: $name"
    candidate="$(type -P -- "$name" || true)"
    [[ -n "$candidate" && "$candidate" = /* ]] \
        || pika_die "required command not found in trusted PATH: $name"
    resolved="$(realpath -e -- "$candidate")" \
        || pika_die "unable to resolve trusted PATH command: $candidate"
    [[ "$resolved" = /* && -f "$resolved" && ! -L "$resolved" && -x "$resolved" ]] \
        || pika_die "trusted PATH command is not a canonical executable file: $candidate"
    directory="$(dirname -- "$resolved")"
    case ":$PIKA_SCHEDULER_TRUSTED_PATH:" in
        *":$directory:"*) ;;
        *) pika_die "trusted PATH command resolves outside trusted PATH: $candidate" ;;
    esac
    printf '%s\n' "$resolved"
}

pika_scheduler_parse_metadata() {
    local path="$1" metadata
    metadata="$(stat -c '%u:%g:%a' -- "$path")" \
        || pika_die "unable to inspect path metadata: $path"
    [[ "$metadata" =~ ^([0-9]+):([0-9]+):([0-7]{3,4})$ ]] \
        || pika_die "invalid path metadata: $path"
    PIKA_SCHEDULER_META_UID="${BASH_REMATCH[1]}"
    PIKA_SCHEDULER_META_GID="${BASH_REMATCH[2]}"
    PIKA_SCHEDULER_META_MODE="${BASH_REMATCH[3]}"
}

pika_scheduler_assert_no_extended_acl() {
    local path="$1" label="$2" permission_token
    permission_token="$(LC_ALL=C ls -ld -- "$path")" \
        || pika_die "unable to inspect $label ACL marker: $path"
    permission_token="${permission_token%%[[:space:]]*}"
    [[ "$permission_token" != *+ ]] \
        || pika_die "$label must not have an extended POSIX ACL: $path"
}

pika_scheduler_assert_secure_directory_chain() {
    local target="$1" require_root_owner="$2" label="$3"
    local resolved cursor='/' segment mode_value
    local -a segments=()
    [[ "$target" = /* && -d "$target" && ! -L "$target" ]] \
        || pika_die "$label is missing, not absolute, or a symbolic link: $target"
    resolved="$(realpath -e -- "$target")" \
        || pika_die "unable to resolve $label: $target"
    [[ "$resolved" == "$target" ]] \
        || pika_die "$label is not canonical: $target"
    IFS=/ read -r -a segments <<<"${target#/}"
    pika_scheduler_parse_metadata "$cursor"
    (( require_root_owner == 0 \
        || (PIKA_SCHEDULER_META_UID == 0 && PIKA_SCHEDULER_META_GID == 0) )) \
        || pika_die "$label must have root-owned ancestors: $cursor"
    mode_value=$((8#$PIKA_SCHEDULER_META_MODE))
    (( (mode_value & 0022) == 0 )) \
        || pika_die "$label has a group/world-writable ancestor: $cursor"
    for segment in "${segments[@]}"; do
        [[ -n "$segment" && "$segment" != '.' && "$segment" != '..' ]] \
            || pika_die "$label contains a non-canonical component: $target"
        [[ "$cursor" == '/' ]] && cursor="/$segment" || cursor="$cursor/$segment"
        [[ -d "$cursor" && ! -L "$cursor" ]] \
            || pika_die "$label contains an unsafe or symbolic-link ancestor: $cursor"
        pika_scheduler_parse_metadata "$cursor"
        (( require_root_owner == 0 \
            || (PIKA_SCHEDULER_META_UID == 0 && PIKA_SCHEDULER_META_GID == 0) )) \
            || pika_die "$label must have root-owned ancestors: $cursor"
        mode_value=$((8#$PIKA_SCHEDULER_META_MODE))
        (( (mode_value & 0022) == 0 )) \
            || pika_die "$label has a group/world-writable ancestor: $cursor"
    done
}

pika_scheduler_assert_trusted_php_binary() {
    local path="$1" resolved mode_value
    [[ "$path" = /* && -f "$path" && ! -L "$path" && -x "$path" ]] \
        || pika_die "PHP CLI must be an absolute executable regular file: $path"
    resolved="$(realpath -e -- "$path")" \
        || pika_die "unable to resolve PHP CLI: $path"
    [[ "$resolved" == "$path" ]] \
        || pika_die "PHP CLI path must be canonical after resolution: $path"
    pika_scheduler_parse_metadata "$path"
    [[ "$PIKA_SCHEDULER_META_UID:$PIKA_SCHEDULER_META_GID" == '0:0' ]] \
        || pika_die "PHP CLI must be owned by root:root: $path"
    mode_value=$((8#$PIKA_SCHEDULER_META_MODE))
    (( (mode_value & 07000) == 0 )) \
        || pika_die "PHP CLI must not have setuid, setgid, or sticky permission bits: $path"
    (( (mode_value & 0022) == 0 )) \
        || pika_die "PHP CLI must not be group/world writable: $path"
    pika_scheduler_assert_secure_directory_chain "$(dirname -- "$path")" 1 'trusted PHP ancestor'
}

pika_scheduler_assert_no_control() {
    local label="$1" value="$2"
    if [[ "$value" =~ [[:cntrl:]] ]]; then
        pika_die "$label contains a control character"
    fi
}

pika_scheduler_systemd_quote() {
    local value="$1"
    pika_scheduler_assert_no_control 'systemd value' "$value"
    [[ "$value" != *'$'* ]] || pika_die "systemd value contains unsupported dollar expansion"
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    value="${value//%/%%}"
    printf '"%s"' "$value"
}

pika_scheduler_systemd_scalar_path() {
    local value="$1"
    pika_scheduler_assert_no_control 'systemd path' "$value"
    [[ "$value" = /* ]] || pika_die "systemd path must be absolute"
    [[ "$value" != *'$'* ]] || pika_die "systemd path contains unsupported dollar expansion"
    [[ "$value" != *\\ ]] || pika_die "systemd path must not end in a backslash"
    [[ "$value" != *[[:space:]] ]] || pika_die "systemd path must not end in whitespace"
    value="${value//%/%%}"
    printf '%s' "$value"
}

pika_scheduler_render_template() {
    local php_bin="$1" template="$2"
    shift 2
    [[ -f "$template" && ! -L "$template" ]] || pika_die "systemd template is missing or unsafe: $template"
    (( $# > 0 && $# % 2 == 0 )) || pika_die "invalid systemd template values"
    "$php_bin" -r '
        $template = file_get_contents($argv[1]);
        if (!is_string($template)) {
            fwrite(STDERR, "unable to read systemd template\n");
            exit(2);
        }
        for ($index = 2; $index < $argc; $index += 2) {
            $token = $argv[$index];
            $value = $argv[$index + 1];
            if (preg_match("/^@@PIKA_[A-Z_]+@@$/D", $token) !== 1 || !str_contains($template, $token)) {
                fwrite(STDERR, "invalid or absent systemd template token\n");
                exit(3);
            }
            $template = str_replace($token, $value, $template);
        }
        if (preg_match("/@@PIKA_[A-Z_]+@@/", $template) === 1) {
            fwrite(STDERR, "unresolved systemd template token\n");
            exit(4);
        }
        echo $template;
    ' "$template" "$@"
}

pika_scheduler_render_service() {
    local php_bin="$1" output="$2" instance="$3" site_root="$4" web_user="$5" web_group="$6" runtime_dir="$7"
    local config_dev="$8" config_inode="$9"
    local image_cache="$site_root/assets/cache/pika-supply-sync"
    local site_config="$site_root/runtime/config"
    pika_scheduler_render_template "$php_bin" "$PIKA_SCHEDULER_SERVICE_TEMPLATE" \
        '@@PIKA_INSTANCE@@' "$instance" \
        '@@PIKA_SITE_ROOT_RAW@@' "$site_root" \
        '@@PIKA_WEB_USER@@' "$web_user" \
        '@@PIKA_WEB_GROUP@@' "$web_group" \
        '@@PIKA_SITE_ROOT_SCALAR@@' "$(pika_scheduler_systemd_scalar_path "$site_root")" \
        '@@PIKA_SCHEDULER_Q@@' "$(pika_scheduler_systemd_quote "$PIKA_SCHEDULER_SCRIPT_PATH")" \
        '@@PIKA_SITE_ROOT_ARG_Q@@' "$(pika_scheduler_systemd_quote "--site-root=$site_root")" \
        '@@PIKA_WEB_USER_ARG_Q@@' "$(pika_scheduler_systemd_quote "--web-user=$web_user")" \
        '@@PIKA_CONFIG_DEV_ARG_Q@@' "$(pika_scheduler_systemd_quote "--expected-dev=$config_dev")" \
        '@@PIKA_CONFIG_INODE_ARG_Q@@' "$(pika_scheduler_systemd_quote "--expected-inode=$config_inode")" \
        '@@PIKA_PHP_ARG_Q@@' "$(pika_scheduler_systemd_quote "--php=$php_bin")" \
        '@@PIKA_RUNTIME_Q@@' "$(pika_scheduler_systemd_quote "$runtime_dir")" \
        '@@PIKA_IMAGE_CACHE_Q@@' "$(pika_scheduler_systemd_quote "$image_cache")" \
        '@@PIKA_SITE_CONFIG_Q@@' "$(pika_scheduler_systemd_quote "$site_config")" \
        >"$output"
}

pika_scheduler_render_timer() {
    local php_bin="$1" output="$2" instance="$3" site_root="$4" web_user="$5" minutes="$6" service_unit="$7"
    pika_scheduler_render_template "$php_bin" "$PIKA_SCHEDULER_TIMER_TEMPLATE" \
        '@@PIKA_INSTANCE@@' "$instance" \
        '@@PIKA_SITE_ROOT_RAW@@' "$site_root" \
        '@@PIKA_WEB_USER@@' "$web_user" \
        '@@PIKA_MINUTES@@' "$minutes" \
        '@@PIKA_SERVICE_UNIT@@' "$service_unit" \
        >"$output"
}

pika_scheduler_render_catalog_service() {
    local php_bin="$1" output="$2" instance="$3" site_root="$4" web_user="$5" web_group="$6" runtime_dir="$7"
    local config_dev="$8" config_inode="$9"
    local image_cache="$site_root/assets/cache/pika-supply-sync"
    local site_config="$site_root/runtime/config"
    pika_scheduler_render_template "$php_bin" "$PIKA_SCHEDULER_CATALOG_SERVICE_TEMPLATE" \
        '@@PIKA_INSTANCE@@' "$instance" \
        '@@PIKA_SITE_ROOT_RAW@@' "$site_root" \
        '@@PIKA_WEB_USER@@' "$web_user" \
        '@@PIKA_WEB_GROUP@@' "$web_group" \
        '@@PIKA_SITE_ROOT_SCALAR@@' "$(pika_scheduler_systemd_scalar_path "$site_root")" \
        '@@PIKA_SCHEDULER_Q@@' "$(pika_scheduler_systemd_quote "$PIKA_SCHEDULER_SCRIPT_PATH")" \
        '@@PIKA_SITE_ROOT_ARG_Q@@' "$(pika_scheduler_systemd_quote "--site-root=$site_root")" \
        '@@PIKA_WEB_USER_ARG_Q@@' "$(pika_scheduler_systemd_quote "--web-user=$web_user")" \
        '@@PIKA_CONFIG_DEV_ARG_Q@@' "$(pika_scheduler_systemd_quote "--expected-dev=$config_dev")" \
        '@@PIKA_CONFIG_INODE_ARG_Q@@' "$(pika_scheduler_systemd_quote "--expected-inode=$config_inode")" \
        '@@PIKA_PHP_ARG_Q@@' "$(pika_scheduler_systemd_quote "--php=$php_bin")" \
        '@@PIKA_RUNTIME_Q@@' "$(pika_scheduler_systemd_quote "$runtime_dir")" \
        '@@PIKA_IMAGE_CACHE_Q@@' "$(pika_scheduler_systemd_quote "$image_cache")" \
        '@@PIKA_SITE_CONFIG_Q@@' "$(pika_scheduler_systemd_quote "$site_config")" \
        >"$output"
}

pika_scheduler_render_catalog_timer() {
    local php_bin="$1" output="$2" instance="$3" site_root="$4" web_user="$5" service_unit="$6"
    pika_scheduler_render_template "$php_bin" "$PIKA_SCHEDULER_CATALOG_TIMER_TEMPLATE" \
        '@@PIKA_INSTANCE@@' "$instance" \
        '@@PIKA_SITE_ROOT_RAW@@' "$site_root" \
        '@@PIKA_WEB_USER@@' "$web_user" \
        '@@PIKA_SERVICE_UNIT@@' "$service_unit" \
        >"$output"
}

pika_scheduler_atomic_install() {
    local source="$1" target="$2" temporary
    temporary="$(mktemp "${PIKA_SCHEDULER_UNIT_DIR}/.$(basename -- "$target").XXXXXX")"
    if ! install -o 0 -g 0 -m 0644 -- "$source" "$temporary"; then
        rm -f -- "$temporary"
        return 1
    fi
    if ! mv -f -- "$temporary" "$target"; then
        rm -f -- "$temporary"
        return 1
    fi
}

pika_scheduler_check_managed_unit() {
    local path="$1" instance="$2" site_root="$3" web_user="$4"
    [[ -f "$path" && ! -L "$path" ]] || pika_die "managed unit is missing or unsafe: $path"
    [[ "$(stat -c '%u:%g:%a' -- "$path")" == '0:0:644' ]] \
        || pika_die "managed unit ownership or mode is unsafe: $path"
    grep -Fqx '# Managed by Pika LocalExtensions scheduler.sh' "$path" \
        || pika_die "refusing unmanaged unit: $path"
    grep -Fqx "# Pika-Instance: $instance" "$path" \
        || pika_die "unit instance marker mismatch: $path"
    grep -Fqx "# Pika-SiteRoot: $site_root" "$path" \
        || pika_die "unit site marker mismatch: $path"
    grep -Fqx "# Pika-WebUser: $web_user" "$path" \
        || pika_die "unit web-user marker mismatch: $path"
}

pika_scheduler_snapshot_timer_state() {
    PIKA_SCHEDULER_PREV_ENABLED=0
    PIKA_SCHEDULER_PREV_ACTIVE=0
    PIKA_SCHEDULER_CATALOG_PREV_ENABLED=0
    PIKA_SCHEDULER_CATALOG_PREV_ACTIVE=0
    if (( PIKA_SCHEDULER_UNITS_PREEXISTED == 1 )); then
        if "$PIKA_SCHEDULER_SYSTEMCTL" is-enabled --quiet "$PIKA_SCHEDULER_TIMER_UNIT"; then
            PIKA_SCHEDULER_PREV_ENABLED=1
        fi
        if "$PIKA_SCHEDULER_SYSTEMCTL" is-active --quiet "$PIKA_SCHEDULER_TIMER_UNIT"; then
            PIKA_SCHEDULER_PREV_ACTIVE=1
        fi
    fi
    if (( PIKA_SCHEDULER_CATALOG_UNITS_PREEXISTED == 1 )); then
        if "$PIKA_SCHEDULER_SYSTEMCTL" is-enabled --quiet "$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"; then
            PIKA_SCHEDULER_CATALOG_PREV_ENABLED=1
        fi
        if "$PIKA_SCHEDULER_SYSTEMCTL" is-active --quiet "$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"; then
            PIKA_SCHEDULER_CATALOG_PREV_ACTIVE=1
        fi
    fi
}

pika_scheduler_restore_timer_state() {
    local failed=0
    if (( PIKA_SCHEDULER_UNITS_PREEXISTED == 1 )); then
        if (( PIKA_SCHEDULER_PREV_ENABLED == 1 )); then
            "$PIKA_SCHEDULER_SYSTEMCTL" enable "$PIKA_SCHEDULER_TIMER_UNIT" >/dev/null 2>&1 || failed=1
        else
            "$PIKA_SCHEDULER_SYSTEMCTL" disable "$PIKA_SCHEDULER_TIMER_UNIT" >/dev/null 2>&1 || failed=1
        fi
        if (( PIKA_SCHEDULER_PREV_ACTIVE == 1 )); then
            "$PIKA_SCHEDULER_SYSTEMCTL" start "$PIKA_SCHEDULER_TIMER_UNIT" >/dev/null 2>&1 || failed=1
        else
            "$PIKA_SCHEDULER_SYSTEMCTL" stop "$PIKA_SCHEDULER_TIMER_UNIT" >/dev/null 2>&1 || failed=1
        fi
    fi
    if (( PIKA_SCHEDULER_CATALOG_UNITS_PREEXISTED == 1 )); then
        if (( PIKA_SCHEDULER_CATALOG_PREV_ENABLED == 1 )); then
            "$PIKA_SCHEDULER_SYSTEMCTL" enable "$PIKA_SCHEDULER_CATALOG_TIMER_UNIT" >/dev/null 2>&1 || failed=1
        else
            "$PIKA_SCHEDULER_SYSTEMCTL" disable "$PIKA_SCHEDULER_CATALOG_TIMER_UNIT" >/dev/null 2>&1 || failed=1
        fi
        if (( PIKA_SCHEDULER_CATALOG_PREV_ACTIVE == 1 )); then
            "$PIKA_SCHEDULER_SYSTEMCTL" start "$PIKA_SCHEDULER_CATALOG_TIMER_UNIT" >/dev/null 2>&1 || failed=1
        else
            "$PIKA_SCHEDULER_SYSTEMCTL" stop "$PIKA_SCHEDULER_CATALOG_TIMER_UNIT" >/dev/null 2>&1 || failed=1
        fi
    fi
    return "$failed"
}

pika_scheduler_rollback_install() {
    local failed=0
    pika_info 'scheduler install failed; restoring the previous unit state'
    if (( PIKA_SCHEDULER_CATALOG_ENABLE_ATTEMPTED == 1 )) \
        && [[ -f "$PIKA_SCHEDULER_CATALOG_TIMER_TARGET" && ! -L "$PIKA_SCHEDULER_CATALOG_TIMER_TARGET" ]]; then
        "$PIKA_SCHEDULER_SYSTEMCTL" disable --now "$PIKA_SCHEDULER_CATALOG_TIMER_UNIT" >/dev/null 2>&1 || failed=1
    fi
    if (( PIKA_SCHEDULER_ENABLE_ATTEMPTED == 1 )) \
        && [[ -f "$PIKA_SCHEDULER_TIMER_TARGET" && ! -L "$PIKA_SCHEDULER_TIMER_TARGET" ]]; then
        "$PIKA_SCHEDULER_SYSTEMCTL" disable --now "$PIKA_SCHEDULER_TIMER_UNIT" >/dev/null 2>&1 || failed=1
    fi
    if (( PIKA_SCHEDULER_INSTALL_CREATED_CATALOG_TIMER == 1 )); then
        rm -f -- "$PIKA_SCHEDULER_CATALOG_TIMER_TARGET" || failed=1
    fi
    if (( PIKA_SCHEDULER_INSTALL_CREATED_CATALOG_SERVICE == 1 )); then
        rm -f -- "$PIKA_SCHEDULER_CATALOG_SERVICE_TARGET" || failed=1
    fi
    if (( PIKA_SCHEDULER_INSTALL_CREATED_TIMER == 1 )); then
        rm -f -- "$PIKA_SCHEDULER_TIMER_TARGET" || failed=1
    fi
    if (( PIKA_SCHEDULER_INSTALL_CREATED_SERVICE == 1 )); then
        rm -f -- "$PIKA_SCHEDULER_SERVICE_TARGET" || failed=1
    fi
    "$PIKA_SCHEDULER_SYSTEMCTL" daemon-reload >/dev/null 2>&1 || failed=1
    pika_scheduler_restore_timer_state || failed=1
    return "$failed"
}

pika_scheduler_rollback_remove() {
    local failed=0
    pika_info 'scheduler removal failed; restoring the previous unit state'
    if (( PIKA_SCHEDULER_REMOVE_DELETED_CATALOG_SERVICE == 1 )); then
        pika_scheduler_atomic_install "$PIKA_SCHEDULER_STAGE_DIR/original-catalog.service" "$PIKA_SCHEDULER_CATALOG_SERVICE_TARGET" || failed=1
    fi
    if (( PIKA_SCHEDULER_REMOVE_DELETED_CATALOG_TIMER == 1 )); then
        pika_scheduler_atomic_install "$PIKA_SCHEDULER_STAGE_DIR/original-catalog.timer" "$PIKA_SCHEDULER_CATALOG_TIMER_TARGET" || failed=1
    fi
    if (( PIKA_SCHEDULER_REMOVE_DELETED_SERVICE == 1 )); then
        pika_scheduler_atomic_install "$PIKA_SCHEDULER_STAGE_DIR/original-supply.service" "$PIKA_SCHEDULER_SERVICE_TARGET" || failed=1
    fi
    if (( PIKA_SCHEDULER_REMOVE_DELETED_TIMER == 1 )); then
        pika_scheduler_atomic_install "$PIKA_SCHEDULER_STAGE_DIR/original-supply.timer" "$PIKA_SCHEDULER_TIMER_TARGET" || failed=1
    fi
    "$PIKA_SCHEDULER_SYSTEMCTL" daemon-reload >/dev/null 2>&1 || failed=1
    pika_scheduler_restore_timer_state || failed=1
    return "$failed"
}

pika_scheduler_cleanup() {
    [[ -n "$PIKA_SCHEDULER_STAGE_DIR" ]] || return 0
    rm -f -- \
        "$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_SERVICE_UNIT" \
        "$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_TIMER_UNIT" \
        "$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_CATALOG_SERVICE_UNIT" \
        "$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_CATALOG_TIMER_UNIT" \
        "$PIKA_SCHEDULER_STAGE_DIR/original-supply.service" \
        "$PIKA_SCHEDULER_STAGE_DIR/original-supply.timer" \
        "$PIKA_SCHEDULER_STAGE_DIR/original-catalog.service" \
        "$PIKA_SCHEDULER_STAGE_DIR/original-catalog.timer"
    rmdir -- "$PIKA_SCHEDULER_STAGE_DIR" 2>/dev/null || true
}

pika_scheduler_on_exit() {
    local status=$? rollback_failed=0
    trap - EXIT INT TERM
    if (( status != 0 && PIKA_SCHEDULER_SUCCESS == 0 )); then
        if (( PIKA_SCHEDULER_INSTALL_MUTATED == 1 )); then
            pika_scheduler_rollback_install || rollback_failed=1
        elif (( PIKA_SCHEDULER_REMOVE_MUTATED == 1 )); then
            pika_scheduler_rollback_remove || rollback_failed=1
        fi
    fi
    if (( rollback_failed == 1 )); then
        printf 'ERROR: scheduler rollback was incomplete; recovery files remain at %s\n' "$PIKA_SCHEDULER_STAGE_DIR" >&2
        exit 70
    fi
    pika_scheduler_cleanup
    exit "$status"
}

pika_scheduler_assert_image_cache() {
    local path="$1" uid="$2" gid="$3" site_root="$4" parent resolved
    parent="$(dirname -- "$path")"
    [[ -d "$parent" && ! -L "$parent" ]] || pika_die "writable directory parent is missing or unsafe: $parent"
    resolved="$(realpath -e -- "$parent")"
    case "$resolved/" in
        "$site_root/"*) ;;
        *) pika_die "writable directory parent escapes the site root: $parent" ;;
    esac
    [[ -d "$path" && ! -L "$path" ]] || pika_die "writable directory is unsafe: $path"
    resolved="$(realpath -e -- "$path")"
    case "$resolved/" in
        "$site_root/"*) ;;
        *) pika_die "writable directory escapes the site root: $path" ;;
    esac
    pika_scheduler_assert_secure_directory_chain "$parent" 0 'assets cache parent'
    pika_scheduler_parse_metadata "$parent"
    [[ "$PIKA_SCHEDULER_META_UID:$PIKA_SCHEDULER_META_GID:$PIKA_SCHEDULER_META_MODE" == "$uid:$gid:755" ]] \
        || pika_die "assets cache parent must match the installed dedicated-Web contract: $parent"
    pika_scheduler_assert_no_extended_acl "$parent" 'assets cache parent'
    pika_scheduler_parse_metadata "$path"
    [[ "$PIKA_SCHEDULER_META_UID:$PIKA_SCHEDULER_META_GID:$PIKA_SCHEDULER_META_MODE" == "$uid:$gid:755" ]] \
        || pika_die "image cache must be owned by --web-user with mode 0755: $path"
    pika_scheduler_assert_no_extended_acl "$path" 'image cache'
}

pika_scheduler_assert_runtime_dir() {
    local path="$1" uid="$2" gid="$3" state_dir="$4" resolved
    [[ -d "$state_dir" && ! -L "$state_dir" ]] \
        || pika_die "external site state directory is missing or unsafe: $state_dir"
    [[ "$(realpath -e -- "$state_dir")" == "$state_dir" ]] \
        || pika_die "external site state directory is not canonical: $state_dir"
    [[ "$(stat -c '%u:%g:%a' -- "$state_dir")" == '0:0:755' ]] \
        || pika_die "external site state directory must be root:root 0755: $state_dir"
    pika_scheduler_assert_secure_directory_chain "$state_dir" 1 'external site state directory'
    pika_scheduler_assert_no_extended_acl "$state_dir" 'external site state directory'
    [[ -d "$path" && ! -L "$path" ]] \
        || pika_die "external runtime directory is missing or unsafe: $path"
    resolved="$(realpath -e -- "$path")"
    [[ "$resolved" == "$path" ]] \
        || pika_die "external runtime directory is not canonical: $path"
    case "$resolved/" in
        "$state_dir/"*) ;;
        *) pika_die "external runtime directory escaped the site state root: $path" ;;
    esac
    [[ "$(stat -c '%u:%g:%a' -- "$path")" == "$uid:$gid:750" ]] \
        || pika_die "external runtime directory must be owned by --web-user with mode 0750: $path"
    pika_scheduler_assert_no_extended_acl "$path" 'external runtime directory'
}

pika_scheduler_assert_site_config_cache() {
    local path="$1" uid="$2" gid="$3" site_root="$4"
    local expected_dev="${5:-}" expected_inode="${6:-}"
    local resolved metadata file_uid file_gid file_mode file_nlink file_dev file_inode file_type
    [[ ( -z "$expected_dev" && -z "$expected_inode" ) \
        || ( "$expected_dev" =~ ^[0-9]+$ && "$expected_inode" =~ ^[0-9]+$ ) ]] \
        || pika_die "site config cache expected identity is incomplete or invalid"
    [[ "$path" == "$site_root/runtime/config" ]] \
        || pika_die "site config cache path must be derived from the canonical site root"
    [[ -f "$path" && ! -L "$path" ]] \
        || pika_die "site config cache is missing, not a regular file, or a symbolic link: $path"
    resolved="$(realpath -e -- "$path")" \
        || pika_die "unable to resolve site config cache: $path"
    [[ "$resolved" == "$path" ]] \
        || pika_die "site config cache is not canonical: $path"
    pika_scheduler_assert_secure_directory_chain "$(dirname -- "$path")" 0 'site config cache parent'
    pika_scheduler_assert_no_extended_acl "$site_root" 'site config cache site root'
    pika_scheduler_assert_no_extended_acl "$(dirname -- "$path")" 'site config cache parent'
    metadata="$(LC_ALL=C stat -c '%u:%g:%a:%h:%d:%i:%F' -- "$path")" \
        || pika_die "unable to inspect site config cache metadata: $path"
    [[ "$metadata" =~ ^([0-9]+):([0-9]+):([0-7]{3,4}):([0-9]+):([0-9]+):([0-9]+):(.+)$ ]] \
        || pika_die "invalid site config cache metadata: $path"
    file_uid="${BASH_REMATCH[1]}"
    file_gid="${BASH_REMATCH[2]}"
    file_mode="${BASH_REMATCH[3]}"
    file_nlink="${BASH_REMATCH[4]}"
    file_dev="${BASH_REMATCH[5]}"
    file_inode="${BASH_REMATCH[6]}"
    file_type="${BASH_REMATCH[7]}"
    [[ "$file_type" == 'regular file' ]] \
        || pika_die "site config cache is not a regular file: $path"
    [[ "$file_uid:$file_gid" == "$uid:$gid" ]] \
        || pika_die "site config cache must be owned by --web-user and its primary group: $path"
    [[ "$file_mode" == '640' ]] \
        || pika_die "site config cache must have exact mode 0640: $path"
    [[ "$file_nlink" == '1' ]] \
        || pika_die "site config cache must have exactly one hard link: $path"
    if [[ -n "$expected_dev" ]]; then
        [[ "$file_dev:$file_inode" == "$expected_dev:$expected_inode" ]] \
            || pika_die "site config cache device or inode changed after scheduler installation: $path"
    fi
    pika_scheduler_assert_no_extended_acl "$path" 'site config cache'
    PIKA_SCHEDULER_CONFIG_DEV="$file_dev"
    PIKA_SCHEDULER_CONFIG_INODE="$file_inode"
}

pika_scheduler_install() {
    local staged_service="$1" staged_timer="$2" staged_catalog_service="$3" staged_catalog_timer="$4"
    local site_root="$5" web_uid="$6" web_gid="$7" state_dir="$8" runtime_dir="$9"
    local config_dev="${10}" config_inode="${11}"
    local service_exists=0 timer_exists=0 catalog_service_exists=0 catalog_timer_exists=0
    [[ ! -e "$PIKA_SCHEDULER_SERVICE_TARGET" && ! -L "$PIKA_SCHEDULER_SERVICE_TARGET" ]] || service_exists=1
    [[ ! -e "$PIKA_SCHEDULER_TIMER_TARGET" && ! -L "$PIKA_SCHEDULER_TIMER_TARGET" ]] || timer_exists=1
    [[ ! -e "$PIKA_SCHEDULER_CATALOG_SERVICE_TARGET" && ! -L "$PIKA_SCHEDULER_CATALOG_SERVICE_TARGET" ]] || catalog_service_exists=1
    [[ ! -e "$PIKA_SCHEDULER_CATALOG_TIMER_TARGET" && ! -L "$PIKA_SCHEDULER_CATALOG_TIMER_TARGET" ]] || catalog_timer_exists=1
    (( service_exists == timer_exists )) || pika_die "partial SupplySync scheduler unit state; refusing to overwrite"
    (( catalog_service_exists == catalog_timer_exists )) || pika_die "partial CatalogHub scheduler unit state; refusing to overwrite"

    if (( service_exists == 1 )); then
        PIKA_SCHEDULER_UNITS_PREEXISTED=1
        pika_scheduler_check_managed_unit "$PIKA_SCHEDULER_SERVICE_TARGET" "$instance" "$site_root" "$web_user"
        pika_scheduler_check_managed_unit "$PIKA_SCHEDULER_TIMER_TARGET" "$instance" "$site_root" "$web_user"
        cmp -s -- "$staged_service" "$PIKA_SCHEDULER_SERVICE_TARGET" \
            || pika_die "existing service unit differs; refusing to overwrite"
        cmp -s -- "$staged_timer" "$PIKA_SCHEDULER_TIMER_TARGET" \
            || pika_die "existing timer unit differs; refusing to overwrite"
    fi
    if (( catalog_service_exists == 1 )); then
        PIKA_SCHEDULER_CATALOG_UNITS_PREEXISTED=1
        pika_scheduler_check_managed_unit "$PIKA_SCHEDULER_CATALOG_SERVICE_TARGET" "$instance" "$site_root" "$web_user"
        pika_scheduler_check_managed_unit "$PIKA_SCHEDULER_CATALOG_TIMER_TARGET" "$instance" "$site_root" "$web_user"
        cmp -s -- "$staged_catalog_service" "$PIKA_SCHEDULER_CATALOG_SERVICE_TARGET" \
            || pika_die "existing CatalogHub service unit differs; refusing to overwrite"
        cmp -s -- "$staged_catalog_timer" "$PIKA_SCHEDULER_CATALOG_TIMER_TARGET" \
            || pika_die "existing CatalogHub timer unit differs; refusing to overwrite"
    fi

    pika_scheduler_assert_runtime_dir "$runtime_dir" "$web_uid" "$web_gid" "$state_dir"
    pika_scheduler_assert_site_config_cache "$site_root/runtime/config" "$web_uid" "$web_gid" "$site_root" "$config_dev" "$config_inode"
    pika_scheduler_assert_secure_directory_chain "$site_root/assets" 0 'assets directory'
    pika_scheduler_assert_image_cache \
        "$site_root/assets/cache/pika-supply-sync" "$web_uid" "$web_gid" "$site_root"
    pika_scheduler_snapshot_timer_state
    PIKA_SCHEDULER_INSTALL_MUTATED=1

    if (( service_exists == 0 )); then
        pika_scheduler_atomic_install "$staged_service" "$PIKA_SCHEDULER_SERVICE_TARGET"
        PIKA_SCHEDULER_INSTALL_CREATED_SERVICE=1
        pika_scheduler_atomic_install "$staged_timer" "$PIKA_SCHEDULER_TIMER_TARGET"
        PIKA_SCHEDULER_INSTALL_CREATED_TIMER=1
    fi
    if (( catalog_service_exists == 0 )); then
        pika_scheduler_atomic_install "$staged_catalog_service" "$PIKA_SCHEDULER_CATALOG_SERVICE_TARGET"
        PIKA_SCHEDULER_INSTALL_CREATED_CATALOG_SERVICE=1
        pika_scheduler_atomic_install "$staged_catalog_timer" "$PIKA_SCHEDULER_CATALOG_TIMER_TARGET"
        PIKA_SCHEDULER_INSTALL_CREATED_CATALOG_TIMER=1
    fi

    "$PIKA_SCHEDULER_SYSTEMCTL" daemon-reload
    PIKA_SCHEDULER_ENABLE_ATTEMPTED=1
    "$PIKA_SCHEDULER_SYSTEMCTL" enable --now "$PIKA_SCHEDULER_TIMER_UNIT"
    "$PIKA_SCHEDULER_SYSTEMCTL" is-enabled --quiet "$PIKA_SCHEDULER_TIMER_UNIT"
    "$PIKA_SCHEDULER_SYSTEMCTL" is-active --quiet "$PIKA_SCHEDULER_TIMER_UNIT"
    PIKA_SCHEDULER_CATALOG_ENABLE_ATTEMPTED=1
    "$PIKA_SCHEDULER_SYSTEMCTL" enable --now "$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"
    "$PIKA_SCHEDULER_SYSTEMCTL" is-enabled --quiet "$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"
    "$PIKA_SCHEDULER_SYSTEMCTL" is-active --quiet "$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"
    PIKA_SCHEDULER_SUCCESS=1
    pika_info "SCHEDULER_INSTALL_PASS instance=$instance timers=$PIKA_SCHEDULER_TIMER_UNIT,$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"
}

pika_scheduler_run_extension_main() {
    local runner="$1"
    shift
    local runner_label relative_cli
    case "$runner" in
        sync)
            runner_label='run-sync'
            relative_cli='local-extensions/extensions/PikaSupplySync/bin/sync.php'
            ;;
        catalog)
            runner_label='run-catalog'
            relative_cli='local-extensions/extensions/PikaCatalogHub/bin/worker.php'
            ;;
        *) pika_die "unsupported internal scheduler runner" ;;
    esac
    local run_site_arg='' run_web_user_arg='' expected_dev='' expected_inode='' php_arg=''
    local seen_site=0 seen_user=0 seen_dev=0 seen_inode=0 seen_php=0
    while (($#)); do
        case "$1" in
            --site-root=*)
                (( seen_site == 0 )) || pika_die "--site-root must be provided exactly once"
                run_site_arg="${1#*=}"; seen_site=1; shift ;;
            --web-user=*)
                (( seen_user == 0 )) || pika_die "--web-user must be provided exactly once"
                run_web_user_arg="${1#*=}"; seen_user=1; shift ;;
            --expected-dev=*)
                (( seen_dev == 0 )) || pika_die "--expected-dev must be provided exactly once"
                expected_dev="${1#*=}"; seen_dev=1; shift ;;
            --expected-inode=*)
                (( seen_inode == 0 )) || pika_die "--expected-inode must be provided exactly once"
                expected_inode="${1#*=}"; seen_inode=1; shift ;;
            --php=*)
                (( seen_php == 0 )) || pika_die "--php must be provided exactly once"
                php_arg="${1#*=}"; seen_php=1; shift ;;
            *) pika_die "unknown $runner_label argument: $1" ;;
        esac
    done
    (( seen_site == 1 && seen_user == 1 && seen_dev == 1 && seen_inode == 1 && seen_php == 1 )) \
        || pika_die "$runner_label requires site root, web user, device, inode and PHP CLI"
    (( EUID != 0 )) || pika_die "$runner_label must execute as the non-root site web identity"
    pika_scheduler_validate_web_user "$run_web_user_arg"
    pika_scheduler_assert_no_control '--site-root' "$run_site_arg"
    pika_scheduler_assert_no_control '--php' "$php_arg"
    [[ "$expected_dev" =~ ^[0-9]+$ && "$expected_inode" =~ ^[0-9]+$ ]] \
        || pika_die "verify-site-config device and inode must be unsigned integers"
    pika_require_command realpath
    pika_require_command stat
    pika_require_command ls
    pika_require_command id
    local site_root web_uid web_gid current_gid php_bin extension_bin resolved_extension
    site_root="$(pika_real_dir "$run_site_arg")"
    pika_assert_safe_site_root "$site_root"
    web_uid="$(id -u "$run_web_user_arg")" || pika_die "unknown --web-user: $run_web_user_arg"
    web_gid="$(id -g "$run_web_user_arg")" || pika_die "cannot resolve group for --web-user: $run_web_user_arg"
    current_gid="$(id -g)" || pika_die "unable to resolve the current effective group"
    (( web_uid != 0 )) || pika_die "--web-user must not be root"
    [[ "$EUID:$current_gid" == "$web_uid:$web_gid" ]] \
        || pika_die "$runner_label identity does not match --web-user and its primary group"
    [[ "$php_arg" = /* ]] || pika_die "$runner_label PHP CLI path must be absolute"
    php_bin="$(realpath -e -- "$php_arg")" \
        || pika_die "unable to resolve $runner_label PHP CLI: $php_arg"
    [[ "$php_bin" == "$php_arg" ]] \
        || pika_die "$runner_label PHP CLI path must be canonical"
    pika_scheduler_assert_trusted_php_binary "$php_bin"
    pika_scheduler_assert_site_config_cache \
        "$site_root/runtime/config" "$web_uid" "$web_gid" "$site_root" \
        "$expected_dev" "$expected_inode"
    extension_bin="$site_root/$relative_cli"
    [[ -f "$extension_bin" && ! -L "$extension_bin" ]] \
        || pika_die "installed $runner_label CLI is missing or unsafe: $extension_bin"
    resolved_extension="$(realpath -e -- "$extension_bin")" \
        || pika_die "unable to resolve installed $runner_label CLI: $extension_bin"
    [[ "$resolved_extension" == "$extension_bin" ]] \
        || pika_die "installed $runner_label CLI is not canonical: $extension_bin"
    pika_info "SCHEDULER_CONFIG_VERIFY_PASS runner=$runner_label site=$site_root"
    umask 0027
    if [[ "$runner" == 'catalog' ]]; then
        exec -- "$php_bin" "$extension_bin" "--root=$site_root" '--batch=20'
    fi
    exec -- "$php_bin" "$extension_bin" "--root=$site_root"
}

pika_scheduler_run_sync_main() {
    pika_scheduler_run_extension_main sync "$@"
}

pika_scheduler_run_catalog_main() {
    pika_scheduler_run_extension_main catalog "$@"
}

pika_scheduler_remove() {
    local site_root="$1"
    local service_exists=0 timer_exists=0 catalog_service_exists=0 catalog_timer_exists=0
    [[ ! -e "$PIKA_SCHEDULER_SERVICE_TARGET" && ! -L "$PIKA_SCHEDULER_SERVICE_TARGET" ]] || service_exists=1
    [[ ! -e "$PIKA_SCHEDULER_TIMER_TARGET" && ! -L "$PIKA_SCHEDULER_TIMER_TARGET" ]] || timer_exists=1
    [[ ! -e "$PIKA_SCHEDULER_CATALOG_SERVICE_TARGET" && ! -L "$PIKA_SCHEDULER_CATALOG_SERVICE_TARGET" ]] || catalog_service_exists=1
    [[ ! -e "$PIKA_SCHEDULER_CATALOG_TIMER_TARGET" && ! -L "$PIKA_SCHEDULER_CATALOG_TIMER_TARGET" ]] || catalog_timer_exists=1
    (( service_exists == timer_exists )) || pika_die "partial SupplySync scheduler unit state; refusing removal"
    (( catalog_service_exists == catalog_timer_exists )) || pika_die "partial CatalogHub scheduler unit state; refusing removal"
    (( service_exists == 1 || catalog_service_exists == 1 )) \
        || pika_die "no managed scheduler units exist for instance: $instance"

    if (( service_exists == 1 )); then
        PIKA_SCHEDULER_UNITS_PREEXISTED=1
        pika_scheduler_check_managed_unit "$PIKA_SCHEDULER_SERVICE_TARGET" "$instance" "$site_root" "$web_user"
        pika_scheduler_check_managed_unit "$PIKA_SCHEDULER_TIMER_TARGET" "$instance" "$site_root" "$web_user"
        cp -- "$PIKA_SCHEDULER_SERVICE_TARGET" "$PIKA_SCHEDULER_STAGE_DIR/original-supply.service"
        cp -- "$PIKA_SCHEDULER_TIMER_TARGET" "$PIKA_SCHEDULER_STAGE_DIR/original-supply.timer"
    fi
    if (( catalog_service_exists == 1 )); then
        PIKA_SCHEDULER_CATALOG_UNITS_PREEXISTED=1
        pika_scheduler_check_managed_unit "$PIKA_SCHEDULER_CATALOG_SERVICE_TARGET" "$instance" "$site_root" "$web_user"
        pika_scheduler_check_managed_unit "$PIKA_SCHEDULER_CATALOG_TIMER_TARGET" "$instance" "$site_root" "$web_user"
        cp -- "$PIKA_SCHEDULER_CATALOG_SERVICE_TARGET" "$PIKA_SCHEDULER_STAGE_DIR/original-catalog.service"
        cp -- "$PIKA_SCHEDULER_CATALOG_TIMER_TARGET" "$PIKA_SCHEDULER_STAGE_DIR/original-catalog.timer"
    fi
    pika_scheduler_snapshot_timer_state
    PIKA_SCHEDULER_REMOVE_MUTATED=1

    if (( service_exists == 1 )); then
        "$PIKA_SCHEDULER_SYSTEMCTL" disable --now "$PIKA_SCHEDULER_TIMER_UNIT"
    fi
    if (( catalog_service_exists == 1 )); then
        "$PIKA_SCHEDULER_SYSTEMCTL" disable --now "$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"
    fi
    if (( service_exists == 1 )) \
        && "$PIKA_SCHEDULER_SYSTEMCTL" is-active --quiet "$PIKA_SCHEDULER_SERVICE_UNIT"; then
        pika_die "SupplySync service is still running; removal stopped without terminating it"
    fi
    if (( catalog_service_exists == 1 )) \
        && "$PIKA_SCHEDULER_SYSTEMCTL" is-active --quiet "$PIKA_SCHEDULER_CATALOG_SERVICE_UNIT"; then
        pika_die "CatalogHub worker is still running; removal stopped without terminating it"
    fi
    if (( catalog_timer_exists == 1 )); then
        rm -f -- "$PIKA_SCHEDULER_CATALOG_TIMER_TARGET"
        PIKA_SCHEDULER_REMOVE_DELETED_CATALOG_TIMER=1
        rm -f -- "$PIKA_SCHEDULER_CATALOG_SERVICE_TARGET"
        PIKA_SCHEDULER_REMOVE_DELETED_CATALOG_SERVICE=1
    fi
    if (( timer_exists == 1 )); then
        rm -f -- "$PIKA_SCHEDULER_TIMER_TARGET"
        PIKA_SCHEDULER_REMOVE_DELETED_TIMER=1
        rm -f -- "$PIKA_SCHEDULER_SERVICE_TARGET"
        PIKA_SCHEDULER_REMOVE_DELETED_SERVICE=1
    fi
    "$PIKA_SCHEDULER_SYSTEMCTL" daemon-reload
    PIKA_SCHEDULER_SUCCESS=1
    pika_info "SCHEDULER_REMOVE_PASS instance=$instance"
}

pika_scheduler_main() {
    (($# > 0)) || { pika_scheduler_usage >&2; exit 1; }
    local action="$1"
    shift
    case "$action" in
        install|remove|run-sync|run-catalog) ;;
        --help|-h) pika_scheduler_usage; return 0 ;;
        *) pika_die "first argument must be install, remove, or an internal runner" ;;
    esac

    # The scheduler executes release-owned PHP helpers and systemd templates as
    # a privileged installer or as the site-scoped runner. Refuse a release
    # tree that another identity can replace before any site state is read.
    pika_assert_trusted_release_tree

    if [[ "$action" == 'run-sync' ]]; then
        pika_scheduler_run_sync_main "$@"
        return 0
    fi
    if [[ "$action" == 'run-catalog' ]]; then
        pika_scheduler_run_catalog_main "$@"
        return 0
    fi

    (( EUID == 0 )) || pika_die "scheduler must run as root (use sudo)"

    local site_arg='' web_user_arg='' instance_arg='' minutes='10' php_arg='php'
    local seen_site=0 seen_user=0 seen_instance=0 seen_minutes=0 seen_php=0
    while (($#)); do
        case "$1" in
            --site-root)
                (($# >= 2 && seen_site == 0)) || pika_die "--site-root requires one value"
                site_arg="$2"; seen_site=1; shift 2 ;;
            --web-user)
                (($# >= 2 && seen_user == 0)) || pika_die "--web-user requires one value"
                web_user_arg="$2"; seen_user=1; shift 2 ;;
            --instance)
                (($# >= 2 && seen_instance == 0)) || pika_die "--instance requires one value"
                instance_arg="$2"; seen_instance=1; shift 2 ;;
            --minutes)
                [[ "$action" == 'install' ]] || pika_die "--minutes is valid only for install"
                (($# >= 2 && seen_minutes == 0)) || pika_die "--minutes requires one value"
                minutes="$2"; seen_minutes=1; shift 2 ;;
            --php)
                (($# >= 2 && seen_php == 0)) || pika_die "--php requires one value"
                php_arg="$2"; seen_php=1; shift 2 ;;
            --help|-h)
                pika_scheduler_usage; return 0 ;;
            *) pika_die "unknown argument: $1" ;;
        esac
    done

    [[ -n "$site_arg" && -n "$web_user_arg" && -n "$instance_arg" ]] \
        || pika_die "--site-root, --web-user and --instance are required"
    pika_scheduler_validate_instance "$instance_arg"
    pika_scheduler_validate_web_user "$web_user_arg"
    [[ "$minutes" =~ ^[0-9]+$ ]] || pika_die "--minutes must be an integer"
    (( ${#minutes} <= 4 )) || pika_die "--minutes must be between 5 and 1440"
    (( 10#$minutes >= 5 && 10#$minutes <= 1440 )) || pika_die "--minutes must be between 5 and 1440"
    pika_scheduler_assert_no_control '--site-root' "$site_arg"
    pika_scheduler_assert_no_control '--php' "$php_arg"

    local site_root php_candidate php_bin web_uid web_gid state_dir runtime_dir
    site_root="$(pika_real_dir "$site_arg")"
    pika_assert_safe_site_root "$site_root"
    if [[ "$php_arg" == */* ]]; then
        [[ "$php_arg" = /* ]] || pika_die "--php path must be absolute"
        php_candidate="$php_arg"
    else
        php_candidate="$(pika_scheduler_resolve_path_command "$php_arg")"
    fi
    pika_require_command realpath
    php_bin="$(realpath -e -- "$php_candidate")"
    pika_scheduler_assert_trusted_php_binary "$php_bin"
    PIKA_PHP_BIN="$php_bin"
    export PIKA_PHP_BIN
    web_uid="$(id -u "$web_user_arg")" || pika_die "unknown --web-user: $web_user_arg"
    web_gid="$(id -g "$web_user_arg")" || pika_die "cannot resolve group for --web-user: $web_user_arg"
    (( web_uid != 0 )) || pika_die "--web-user must not be root"

    "${PIKA_SCHEDULER_SCRIPT_DIR}/doctor.sh" --site-root "$site_root" --installed --php "$php_bin"
    state_dir="$(pika_site_state_dir "$site_root")" \
        || pika_die "unable to derive external state directory"
    runtime_dir="$state_dir/runtime"

    pika_require_command systemctl
    pika_require_command systemd-analyze
    pika_require_command install
    pika_require_command cmp
    pika_require_command stat
    pika_require_command ls
    PIKA_SCHEDULER_SYSTEMCTL="$(pika_scheduler_resolve_path_command systemctl)"
    PIKA_SCHEDULER_SYSTEMD_ANALYZE="$(pika_scheduler_resolve_path_command systemd-analyze)"
    [[ -d "$PIKA_SCHEDULER_UNIT_DIR" && ! -L "$PIKA_SCHEDULER_UNIT_DIR" ]] \
        || pika_die "systemd unit directory is missing or unsafe"
    [[ "$(realpath -e -- "$PIKA_SCHEDULER_UNIT_DIR")" == "$PIKA_SCHEDULER_UNIT_DIR" ]] \
        || pika_die "systemd unit directory must resolve exactly to $PIKA_SCHEDULER_UNIT_DIR"

    local instance="$instance_arg" web_user="$web_user_arg"
    PIKA_SCHEDULER_SITE_ROOT="$site_root"
    PIKA_SCHEDULER_SERVICE_UNIT="pika-supply-sync-${instance}.service"
    PIKA_SCHEDULER_TIMER_UNIT="pika-supply-sync-${instance}.timer"
    PIKA_SCHEDULER_SERVICE_TARGET="$PIKA_SCHEDULER_UNIT_DIR/$PIKA_SCHEDULER_SERVICE_UNIT"
    PIKA_SCHEDULER_TIMER_TARGET="$PIKA_SCHEDULER_UNIT_DIR/$PIKA_SCHEDULER_TIMER_UNIT"
    PIKA_SCHEDULER_CATALOG_SERVICE_UNIT="pika-catalog-worker-${instance}.service"
    PIKA_SCHEDULER_CATALOG_TIMER_UNIT="pika-catalog-worker-${instance}.timer"
    PIKA_SCHEDULER_CATALOG_SERVICE_TARGET="$PIKA_SCHEDULER_UNIT_DIR/$PIKA_SCHEDULER_CATALOG_SERVICE_UNIT"
    PIKA_SCHEDULER_CATALOG_TIMER_TARGET="$PIKA_SCHEDULER_UNIT_DIR/$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"
    pika_require_command flock
    exec 9>/run/pika-local-extensions-scheduler.lock
    flock -n 9 || pika_die "another scheduler operation is already running"
    PIKA_SCHEDULER_STAGE_DIR="$(mktemp -d /run/pika-scheduler.XXXXXX)"
    chmod 0700 "$PIKA_SCHEDULER_STAGE_DIR"
    trap pika_scheduler_on_exit EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM

    local config_dev config_inode
    if [[ "$action" == 'install' ]]; then
        pika_scheduler_assert_site_config_cache "$site_root/runtime/config" "$web_uid" "$web_gid" "$site_root"
        config_dev="$PIKA_SCHEDULER_CONFIG_DEV"
        config_inode="$PIKA_SCHEDULER_CONFIG_INODE"
    fi

    local staged_service="$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_SERVICE_UNIT"
    local staged_timer="$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_TIMER_UNIT"
    local staged_catalog_service="$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_CATALOG_SERVICE_UNIT"
    local staged_catalog_timer="$PIKA_SCHEDULER_STAGE_DIR/$PIKA_SCHEDULER_CATALOG_TIMER_UNIT"
    if [[ "$action" == 'install' ]]; then
        pika_scheduler_render_service "$php_bin" "$staged_service" "$instance" "$site_root" "$web_user" "$web_gid" "$runtime_dir" "$config_dev" "$config_inode"
        pika_scheduler_render_timer "$php_bin" "$staged_timer" "$instance" "$site_root" "$web_user" "$minutes" "$PIKA_SCHEDULER_SERVICE_UNIT"
        pika_scheduler_render_catalog_service "$php_bin" "$staged_catalog_service" "$instance" "$site_root" "$web_user" "$web_gid" "$runtime_dir" "$config_dev" "$config_inode"
        pika_scheduler_render_catalog_timer "$php_bin" "$staged_catalog_timer" "$instance" "$site_root" "$web_user" "$PIKA_SCHEDULER_CATALOG_SERVICE_UNIT"
        chmod 0644 "$staged_service" "$staged_timer" "$staged_catalog_service" "$staged_catalog_timer"
        "$PIKA_SCHEDULER_SYSTEMD_ANALYZE" verify \
            "$staged_service" "$staged_timer" "$staged_catalog_service" "$staged_catalog_timer"
        pika_scheduler_install \
            "$staged_service" "$staged_timer" "$staged_catalog_service" "$staged_catalog_timer" \
            "$site_root" "$web_uid" "$web_gid" "$state_dir" "$runtime_dir" "$config_dev" "$config_inode"
    else
        pika_scheduler_remove "$site_root"
    fi
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    pika_scheduler_main "$@"
fi
