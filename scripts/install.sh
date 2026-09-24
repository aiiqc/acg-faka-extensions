#!/bin/bash
set -euo pipefail

readonly PIKA_INSTALL_TRUSTED_PATH='/usr/sbin:/usr/bin:/sbin:/bin'
if (( EUID == 0 )); then
    PATH="$PIKA_INSTALL_TRUSTED_PATH"
    export PATH
    builtin unalias -a 2>/dev/null || true
    while IFS= read -r imported_function; do
        builtin unset -f -- "$imported_function"
    done < <(builtin compgen -A function)
    builtin hash -r
    builtin readonly PATH
fi

umask 0077

PIKA_INSTALL_SCRIPT_PATH="$(realpath -- "${BASH_SOURCE[0]}")" || {
    printf 'ERROR: unable to resolve installer path\n' >&2
    exit 1
}
[[ -f "$PIKA_INSTALL_SCRIPT_PATH" && ! -L "$PIKA_INSTALL_SCRIPT_PATH" ]] || {
    printf 'ERROR: installer path is not a canonical regular file\n' >&2
    exit 1
}
SCRIPT_DIR="$(cd -P -- "$(dirname -- "$PIKA_INSTALL_SCRIPT_PATH")" && pwd)"

pika_install_bootstrap_assert_root_path() {
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
    pika_install_bootstrap_assert_root_path "$PIKA_INSTALL_SCRIPT_PATH" 'installer entrypoint'
    pika_install_bootstrap_assert_root_path "$SCRIPT_DIR/lib.sh" 'installer library'
    bootstrap_cursor="$SCRIPT_DIR"
    while :; do
        pika_install_bootstrap_assert_root_path "$bootstrap_cursor" 'installer release ancestor'
        [[ "$bootstrap_cursor" == / ]] && break
        bootstrap_cursor="$(dirname -- "$bootstrap_cursor")"
    done
fi
unset -f pika_install_bootstrap_assert_root_path
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
pika_assert_trusted_release_tree

site_arg=""
backup_arg="/var/backups/pika-local-extensions"
web_user_arg=""
maintenance_confirmed=0

while (($#)); do
    case "$1" in
        --site-root)
            (($# >= 2)) || pika_die "--site-root requires a value"
            site_arg="$2"
            shift 2
            ;;
        --backup-dir)
            (($# >= 2)) || pika_die "--backup-dir requires a value"
            backup_arg="$2"
            shift 2
            ;;
        --web-user)
            (($# >= 2)) || pika_die "--web-user requires a value"
            web_user_arg="$2"
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

[[ -n "$site_arg" && -n "$web_user_arg" ]] \
    || pika_die "usage: install.sh --site-root /absolute/acg-faka/path --web-user USER --confirm-maintenance [--backup-dir PATH]"
[[ "$(id -u)" -eq 0 ]] || pika_die "installer must run as root (use sudo)"
((maintenance_confirmed == 1)) \
    || pika_die "--confirm-maintenance is required after placing the site behind external 503 maintenance and stopping FPM/web and CLI writers"
id "$web_user_arg" >/dev/null 2>&1 || pika_die "web user does not exist: $web_user_arg"
web_uid="$(id -u "$web_user_arg")"
web_gid="$(id -g "$web_user_arg")"
[[ "$web_uid" -ne 0 ]] || pika_die "--web-user must not be root"
[[ "$web_gid" -ne 0 ]] || pika_die "--web-user primary group must not be root"
pika_assert_isolated_web_identity "$web_uid" "$web_gid" 'new site web identity'
pika_require_command flock
exec 8>/run/pika-local-extensions-maintenance.lock
flock -n 8 || pika_die "another local-extension maintenance operation is already running"
pika_assert_uid_quiescent "$web_uid" 'dedicated Web identity'

site_root="$(pika_real_dir "$site_arg")"
[[ "${site_arg%/}" == "$site_root" && ! -L "${site_arg%/}" ]] \
    || pika_die "--site-root must be the exact canonical non-symbolic-link path"
pika_assert_safe_site_root "$site_root"
state_dir="$(pika_site_state_dir "$site_root")" \
    || pika_die "unable to derive external state directory"
[[ ! -e "$state_dir" && ! -L "$state_dir" ]] \
    || pika_die "external state already exists; use the future update command: $state_dir"

# Acg-Faka 3.6.4 writes directly below these project-local roots. Keep only the
# recursive mutable trees Web-owned. The config and installer parents remain
# root-owned so their root-managed siblings cannot be replaced by rename.
# Keep the list ordered so a missing parent is created before its child.
pika_official_runtime_contract() {
    printf '%s\n' \
        'assets/cache:755' \
        'assets/cache/general:755' \
        'assets/cache/general/image:755' \
        'assets/cache/pika-supply-sync:755' \
        'app/Pay:755' \
        'app/Plugin:755' \
        'app/View/User/Theme:755' \
        'kernel/Install/OS:750' \
        'runtime:750'
}

pika_official_protected_contract() {
    printf '%s\n' \
        'config:750' \
        'kernel/Install:750'
}

pika_official_all_contract() {
    pika_official_runtime_contract
    pika_official_protected_contract
}

# Only these shallow roots cross the trust boundary.  Their parents remain
# root-owned and non-writable, so taking these exact inodes behind a root:root
# 0700 barrier prevents the dedicated Web UID from replacing descendants while
# the privileged installer is operating.
pika_official_runtime_roots() {
    printf '%s\n' \
        'assets/cache:755' \
        'app/Pay:755' \
        'app/Plugin:755' \
        'app/View/User/Theme:755' \
        'config:750' \
        'kernel/Install:750' \
        'runtime:750'
}

pika_assert_safe_web_owned_directory_metadata() {
    local owner="$1" gid="$2" mode="$3" label="$4" display="$5" mode_value
    [[ "$owner" =~ ^[0-9]+$ && "$gid" =~ ^[0-9]+$ && "$mode" =~ ^[0-7]{3,4}$ ]] \
        || pika_die "$label metadata is invalid: $display"
    [[ "$owner" == "$web_uid" && "$gid" == "$web_gid" ]] \
        || pika_die "$label must use the dedicated Web identity: $display"
    mode_value=$((8#$mode))
    (( (mode_value & 07000) == 0 )) \
        || pika_die "$label must not have special permission bits: $display"
    (( (mode_value & 0022) == 0 )) \
        || pika_die "$label must not be group/world writable: $display"
    (( (mode_value & 0700) == 0700 )) \
        || pika_die "$label owner must have rwx permissions: $display"
}

pika_assert_official_runtime_path_preinstall() {
    local relative path
    local metadata owner gid mode mode_value resolved
    relative="$1"
    path="$site_root/$relative"

    if [[ ! -e "$path" && ! -L "$path" ]]; then
        return 0
    fi
    [[ -d "$path" && ! -L "$path" ]] \
        || pika_die "official runtime directory is not a real directory: $relative"
    resolved="$(realpath -e -- "$path")" \
        || pika_die "unable to resolve official runtime directory: $relative"
    [[ "$resolved" == "$path" && "$resolved" == "$site_root/"* ]] \
        || pika_die "official runtime directory escaped the canonical site root: $relative"
    metadata="$(stat -c '%u:%g:%a' -- "$path")" \
        || pika_die "unable to inspect official runtime directory: $relative"
    IFS=: read -r owner gid mode <<<"$metadata"
    [[ "$owner" =~ ^[0-9]+$ && "$gid" =~ ^[0-9]+$ && "$mode" =~ ^[0-7]{3,4}$ ]] \
        || pika_die "official runtime directory metadata is invalid: $relative"
    [[ ( "$owner" == 0 && ( "$gid" == 0 || "$gid" == "$web_gid" ) ) \
        || ( "$owner" == "$web_uid" && "$gid" == "$web_gid" ) ]] \
        || pika_die "official runtime directory must be root-owned or use the dedicated Web identity: $relative"
    if [[ "$owner" == "$web_uid" ]]; then
        pika_assert_safe_web_owned_directory_metadata \
            "$owner" "$gid" "$mode" 'official runtime directory' "$relative"
    else
        mode_value=$((8#$mode))
        (( (mode_value & 07000) == 0 )) \
            || pika_die "official runtime directory must not have special permission bits: $relative"
        (( (mode_value & 0022) == 0 )) \
            || pika_die "official runtime directory must not be group/world writable: $relative"
    fi
    pika_assert_no_extended_acl "$path" 'official runtime directory'
}

while IFS=: read -r official_relative _official_mode; do
    pika_assert_official_runtime_path_preinstall "$official_relative"
done < <(pika_official_all_contract)

for required_marker in config/terms kernel/Install/Lock; do
    required_path="$site_root/$required_marker"
    [[ -f "$required_path" && ! -L "$required_path" \
        && "$(realpath -e -- "$required_path")" == "$required_path" ]] \
        || pika_die "completed official setup marker is missing or unsafe: $required_marker"
    [[ "$(stat -c '%h' -- "$required_path")" == 1 ]] \
        || pika_die "completed official setup marker must have one hard link: $required_marker"
    pika_assert_no_extended_acl "$required_path" 'completed official setup marker'
done
[[ ! -e "$site_root/kernel/Install/Update" \
    && ! -L "$site_root/kernel/Install/Update" ]] \
    || pika_die "official online core update staging is unsupported; finish or remove it before installing the fixed artifact"

pika_assert_unique_site_identity() {
    local sites_root="$PIKA_STATE_SITES_DIR" entry runtime metadata owner gid mode
    [[ ! -L "$(dirname -- "$sites_root")" && ! -L "$sites_root" ]] \
        || pika_die "external state control path contains a symbolic link"
    [[ -e "$sites_root" ]] || return 0
    [[ -d "$sites_root" ]] || pika_die "external state sites path is not a directory"
    metadata="$(stat -c '%u:%g:%a' -- "$sites_root")" \
        || pika_die "unable to inspect external state sites path"
    [[ "$metadata" == "0:0:755" ]] \
        || pika_die "external state sites path must be root:root mode 0755"

    while IFS= read -r -d '' entry; do
        [[ ! -L "$entry" && -d "$entry" ]] \
            || pika_die "external state contains a symbolic link or non-directory entry: $entry"
        [[ "$(basename -- "$entry")" =~ ^[a-f0-9]{64}$ ]] \
            || pika_die "external state contains an unexpected site directory: $entry"
        metadata="$(stat -c '%u:%g:%a' -- "$entry")" \
            || pika_die "unable to inspect external site state: $entry"
        [[ "$metadata" == "0:0:755" ]] \
            || pika_die "external site state must be root:root mode 0755: $entry"
        runtime="$entry/runtime"
        [[ -e "$runtime" || -L "$runtime" ]] || continue
        [[ -d "$runtime" && ! -L "$runtime" ]] \
            || pika_die "external site runtime is unsafe: $runtime"
        metadata="$(stat -c '%u:%g:%a' -- "$runtime")" \
            || pika_die "unable to inspect external site runtime: $runtime"
        IFS=: read -r owner gid mode <<<"$metadata"
        [[ "$owner" =~ ^[0-9]+$ && "$gid" =~ ^[0-9]+$ && "$mode" == "750" ]] \
            || pika_die "external site runtime owner or mode is unsafe: $runtime"
        [[ "$owner" != "0" ]] || pika_die "external site runtime must not be root-owned: $runtime"
        pika_assert_isolated_web_identity "$owner" "$gid" 'existing site web identity'
        [[ "$owner" != "$web_uid" ]] \
            || pika_die "--web-user UID is already assigned to another local-extension site: $runtime"
        [[ "$gid" != "$web_gid" ]] \
            || pika_die "--web-user primary GID is already assigned to another local-extension site: $runtime"
    done < <(find -P "$sites_root" -mindepth 1 -maxdepth 1 -print0)
}

# This identity-isolation gate intentionally runs before backup creation or any
# other installer write. Every site must have a dedicated FPM/systemd UID/GID.
pika_assert_unique_site_identity

pika_web_user_has_group() {
    local wanted_gid="$1" candidate
    for candidate in $(id -G "$web_user_arg"); do
        [[ "$candidate" == "$wanted_gid" ]] && return 0
    done
    return 1
}

pika_assert_not_web_writable() {
    local path="$1" label="$2" metadata owner gid mode mode_value
    [[ ! -L "$path" ]] || pika_die "$label is a symbolic link: $path"
    [[ -e "$path" ]] || pika_die "$label disappeared during permission validation: $path"
    metadata="$(stat -c '%u:%g:%a' -- "$path")" \
        || pika_die "unable to inspect $label permissions: $path"
    IFS=: read -r owner gid mode <<<"$metadata"
    [[ "$mode" =~ ^[0-7]{3,4}$ ]] || pika_die "invalid $label mode: $path"
    mode_value=$((8#$mode))

    if [[ "$owner" == "$web_uid" ]] && (( (mode_value & 0200) != 0 )); then
        pika_die "$label is writable by --web-user through owner permissions: $path"
    fi
    if pika_web_user_has_group "$gid" && (( (mode_value & 0020) != 0 )); then
        pika_die "$label is writable by --web-user through group permissions: $path"
    fi
    if (( (mode_value & 0002) != 0 )); then
        pika_die "$label is writable by --web-user through world permissions: $path"
    fi
    if runuser -u "$web_user_arg" -- test -w "$path"; then
        pika_die "$label is writable by --web-user through effective permissions or ACL: $path"
    fi
}

pika_assert_protected_ancestor() {
    local path="$1" label="$2" metadata owner gid mode
    case "$path" in
        "$site_root/app/Pay"|"$site_root/app/View/User/Theme")
            metadata="$(stat -c '%u:%g:%a' -- "$path")" \
                || pika_die "unable to inspect compatibility-first ancestor: $path"
            IFS=: read -r owner gid mode <<<"$metadata"
            if [[ "$owner" == "$web_uid" ]]; then
                pika_assert_safe_web_owned_directory_metadata \
                    "$owner" "$gid" "$mode" 'compatibility-first ancestor' "$path"
                pika_assert_no_extended_acl "$path" 'compatibility-first ancestor'
                return 0
            fi
            ;;
    esac
    pika_assert_not_web_writable "$path" "$label"
    pika_assert_no_extended_acl "$path" "$label"
}

pika_assert_protected_target() {
    local relative="$1" cursor="$site_root" segment index
    local -a segments=()
    IFS=/ read -r -a segments <<<"$relative"

    pika_assert_protected_ancestor "$cursor" 'protected ancestor directory'
    for ((index = 0; index < ${#segments[@]} - 1; index++)); do
        segment="${segments[$index]}"
        cursor="$cursor/$segment"
        if [[ ! -e "$cursor" && ! -L "$cursor" ]]; then
            break
        fi
        [[ -d "$cursor" && ! -L "$cursor" ]] \
            || pika_die "protected ancestor is missing or unsafe: $cursor"
        pika_assert_protected_ancestor "$cursor" 'protected ancestor directory'
    done

    cursor="$site_root/$relative"
    if [[ -e "$cursor" || -L "$cursor" ]]; then
        [[ -f "$cursor" && ! -L "$cursor" \
            && "$(realpath -e -- "$cursor")" == "$cursor" \
            && "$(LC_ALL=C stat -c '%h:%u:%g:%a:%F' -- "$cursor")" == '1:0:0:644:regular file' ]] \
            || pika_die "protected target must be root:root mode 0644 with exactly one hard link: $cursor"
        pika_assert_not_web_writable "$cursor" 'protected target file'
        pika_assert_no_extended_acl "$cursor" 'protected target file'
    fi
}

pika_require_command runuser
pika_assert_not_web_writable "$(dirname -- "$site_root")" 'canonical site parent directory'
for protected_relative in \
    'kernel/Kernel.php' \
    'kernel/Helper.php' \
    'app/Controller/Admin/Api/Config.php' \
    'app/View/Admin/Footer.html' \
    'assets/common/js/editor/markdown/editorv2.js' \
    'app/Controller/Admin/LocalExtensions.php' \
    'app/Controller/Admin/Api/LocalExtensions.php' \
    'app/Controller/User/Api/PikaBEpusdt.php' \
    'local-extensions/bootstrap.php' \
    'app/View/Admin/LocalExtensions/Index.html' \
    'app/View/User/Theme/Pika/Metadata.php' \
    'app/Pay/PikaBEpusdtAdapter/Config/Info.php' \
    'assets/admin/controller/local-extensions/index.js' \
    'assets/local-extensions/PikaSupplySync/.payload' \
    'assets/local-extensions/PikaOrderReturnWait/.payload'; do
    pika_assert_protected_target "$protected_relative"
done

# No filesystem write is allowed before the protected site ancestry gate above
# has proved that the web identity cannot replace bridge or executable payload.
pika_assert_outside_site "$site_root" "$backup_arg"

doctor_args=(--site-root "$site_root")
if [[ -n "${PIKA_PHP_BIN:-}" ]]; then
    doctor_args+=(--php "$PIKA_PHP_BIN")
fi
"${SCRIPT_DIR}/doctor.sh" "${doctor_args[@]}"

php_bin="$(pika_php)" || exit 1
version="$(pika_site_version "$site_root")"
expected_commit="$(pika_compat_value "$version" commit)"
bridge_patch="${PIKA_REPO_DIR}/bridge/${version}/local-extensions.patch"
[[ -f "$bridge_patch" && ! -L "$bridge_patch" ]] || pika_die "bridge patch is missing: $bridge_patch"
[[ -d "${PIKA_REPO_DIR}/manager/site" ]] || pika_die "manager payload is missing"

pika_assert_no_symlinks "${PIKA_REPO_DIR}/manager/site"
pika_assert_no_symlinks "${PIKA_REPO_DIR}/extensions"
pika_assert_no_symlinks "${PIKA_REPO_DIR}/themes"
pika_assert_no_symlinks "${PIKA_REPO_DIR}/payment-adapters"

for must_be_absent in \
    "$site_root/local-extensions" \
    "$site_root/app/Controller/Admin/LocalExtensions.php" \
    "$site_root/app/Controller/Admin/Api/LocalExtensions.php" \
    "$site_root/app/Controller/User/Api/PikaBEpusdt.php" \
    "$site_root/app/View/Admin/LocalExtensions" \
    "$site_root/assets/admin/controller/local-extensions" \
    "$site_root/app/View/User/Theme/Pika" \
    "$site_root/app/Pay/PikaBEpusdtAdapter" \
    "$site_root/assets/local-extensions"; do
    [[ ! -e "$must_be_absent" && ! -L "$must_be_absent" ]] || pika_die "target already exists; use the future update command: $must_be_absent"
done

stage_root="$(mktemp -d /run/pika-local-extensions.XXXXXX)"
payload_root="$stage_root/payload"
mkdir -p -- "$payload_root"
installed_list="$stage_root/installed-files.txt"
staged_file_list="$stage_root/staged-files.txt"
payload_directory_list="$stage_root/payload-directories.txt"
backup_stamp="$(date -u +%Y%m%dT%H%M%SZ)-$$"
backup_root="$(cd -P -- "$backup_arg" && pwd)/$backup_stamp"
mkdir -- "$backup_root"
chmod 0700 "$backup_root"
mkdir -- "$backup_root/site"

patch_applied=0
payload_copied=0
state_created=0
barriers_taken=0
barriers_authorized=0
authorization_started=0
declare -a official_contract_existing=()
declare -a official_contract_created=()
declare -a mutable_existing=()
declare -a mutable_created_files=()
declare -a created_payload_files=()
declare -a created_payload_directories=()
declare -a external_state_nodes=()
declare -A official_dev=()
declare -A official_inode=()
declare -A official_seen=()

pika_record_existing_node() {
    local path="$1" label="$2" allow_web_group_world_write="${3:-0}"
    local metadata dev inode nlink type uid gid mode mode_value
    [[ "$allow_web_group_world_write" == 0 || "$allow_web_group_world_write" == 1 ]] \
        || pika_die "invalid mutable group/world-write policy: $path"
    [[ "$path" != *$'\t'* && "$path" != *$'\n'* && "$path" != *$'\r'* ]] \
        || pika_die "$label path contains a control character"
    [[ ! -L "$path" && ( -d "$path" || -f "$path" ) ]] \
        || pika_die "$label contains a symbolic link or unsupported node: $path"
    metadata="$(LC_ALL=C stat -c '%d:%i:%h:%u:%g:%a' -- "$path")" \
        || pika_die "unable to inspect $label: $path"
    [[ "$metadata" =~ ^([0-9]+):([0-9]+):([0-9]+):([0-9]+):([0-9]+):([0-7]{3,4})$ ]] \
        || pika_die "$label metadata is invalid: $path"
    dev="${BASH_REMATCH[1]}"; inode="${BASH_REMATCH[2]}"; nlink="${BASH_REMATCH[3]}"
    uid="${BASH_REMATCH[4]}"; gid="${BASH_REMATCH[5]}"; mode="${BASH_REMATCH[6]}"
    if [[ -d "$path" ]]; then
        type=directory
    else
        type='regular file'
    fi
    [[ "$type" == directory || "$nlink" == 1 ]] \
        || pika_die "$label regular file must have exactly one hard link: $path"
    mode_value=$((8#$mode))
    (( (mode_value & 07000) == 0 )) \
        || pika_die "$label must not have special permission bits: $path"
    if (( (mode_value & 0022) != 0 )); then
        [[ "$allow_web_group_world_write" == 1 \
            && "$uid" == "$web_uid" && "$gid" == "$web_gid" ]] \
            || pika_die "$label group/world write requires the dedicated Web identity: $path"
        if ((mode_value != 0777)); then
            # Bundled Smarty creates only its view directories with mode 0771.
            ((mode_value == 0771)) \
                && [[ "$type" == directory \
                    && ( "$path" == "$site_root/runtime/view" \
                        || "$path" == "$site_root/runtime/view/"* ) ]] \
                || pika_die "$label group/world write compatibility requires exact mode 0777 or a Smarty view directory with mode 0771: $path"
        fi
    fi
    pika_assert_no_extended_acl "$path" "$label"
    mutable_existing+=("$path"$'\t'"$uid"$'\t'"$gid"$'\t'"$mode"$'\t'"$dev"$'\t'"$inode"$'\t'"$type")
}

pika_snapshot_tree() {
    local root="$1" label="$2" path
    [[ ! -e "$root" && ! -L "$root" ]] && return 0
    [[ -d "$root" && ! -L "$root" ]] || pika_die "$label is unsafe: $root"
    while IFS= read -r -d '' path; do
        [[ -z "${official_seen[$path]:-}" ]] || continue
        # These descendants are already isolated behind a bound root:root 0700
        # shallow-root barrier. Acg-Faka may have created them as 0777, or Smarty
        # view directories as 0771; both require the dedicated Web identity.
        pika_record_existing_node "$path" "$label" 1
    done < <(find -P "$root" -mindepth 1 -print0)
}

pika_take_official_barrier() {
    local relative="$1" final_mode="$2" path parent
    local uid gid mode dev inode
    path="$site_root/$relative"
    parent="$(dirname -- "$path")"
    pika_assert_root_owned_path "$parent" 'official runtime protected parent'
    if [[ -e "$path" || -L "$path" ]]; then
        pika_bound_directory_identity "$path" 'official runtime root'
        uid="$PIKA_BOUND_UID"; gid="$PIKA_BOUND_GID"; mode="$PIKA_BOUND_MODE"
        dev="$PIKA_BOUND_DEV"; inode="$PIKA_BOUND_INODE"
        [[ "$uid:$gid" == '0:0' || "$uid:$gid" == "0:$web_gid" \
            || "$uid:$gid" == "$web_uid:$web_gid" ]] \
            || pika_die "official runtime root has an unexpected owner: $relative"
        official_contract_existing+=("$path"$'\t'"$uid"$'\t'"$gid"$'\t'"$mode"$'\t'"$dev"$'\t'"$inode")
    else
        install -d -o 0 -g 0 -m 0700 -- "$path"
        pika_bound_directory_identity "$path" 'new official runtime root'
        dev="$PIKA_BOUND_DEV"; inode="$PIKA_BOUND_INODE"
        official_contract_created+=("$path"$'\t'"$dev"$'\t'"$inode")
    fi
    chown -h 0:0 -- "$path"
    chmod 0700 -- "$path"
    pika_bound_directory_identity "$path" 'official runtime root barrier' "$dev" "$inode"
    [[ "$PIKA_BOUND_UID:$PIKA_BOUND_GID:$PIKA_BOUND_MODE" == '0:0:700' ]] \
        || pika_die "unable to establish official runtime root barrier: $relative"
    official_dev["$path"]="$dev"
    official_inode["$path"]="$inode"
    official_seen["$path"]=1
}

pika_ensure_official_contract_directory() {
    local relative="$1" path parent dev inode uid gid mode
    path="$site_root/$relative"
    [[ -n "${official_seen[$path]:-}" ]] && return 0
    parent="$(dirname -- "$path")"
    [[ -d "$parent" && ! -L "$parent" ]] \
        || pika_die "official runtime directory parent is missing or unsafe: $relative"
    if [[ -e "$path" || -L "$path" ]]; then
        pika_bound_directory_identity "$path" 'official runtime directory'
        uid="$PIKA_BOUND_UID"; gid="$PIKA_BOUND_GID"; mode="$PIKA_BOUND_MODE"
        dev="$PIKA_BOUND_DEV"; inode="$PIKA_BOUND_INODE"
        official_contract_existing+=("$path"$'\t'"$uid"$'\t'"$gid"$'\t'"$mode"$'\t'"$dev"$'\t'"$inode")
    else
        install -d -o 0 -g 0 -m 0700 -- "$path"
        pika_bound_directory_identity "$path" 'new official runtime directory'
        dev="$PIKA_BOUND_DEV"; inode="$PIKA_BOUND_INODE"
        official_contract_created+=("$path"$'\t'"$dev"$'\t'"$inode")
    fi
    chown -h 0:0 -- "$path"
    chmod 0700 -- "$path"
    pika_bound_directory_identity "$path" 'official runtime directory barrier' "$dev" "$inode"
    official_dev["$path"]="$dev"
    official_inode["$path"]="$inode"
    official_seen["$path"]=1
}

pika_verify_barriers() {
    local relative _mode path
    while IFS=: read -r relative _mode; do
        path="$site_root/$relative"
        pika_bound_directory_identity "$path" 'official runtime barrier' \
            "${official_dev[$path]}" "${official_inode[$path]}"
        [[ "$PIKA_BOUND_UID:$PIKA_BOUND_GID:$PIKA_BOUND_MODE" == '0:0:700' ]] \
            || pika_die "official runtime barrier ownership or mode drifted: $relative"
    done < <(pika_official_runtime_roots)
}

pika_verify_taken_barriers() {
    local path
    for path in "${!official_dev[@]}"; do
        pika_bound_directory_identity "$path" 'official runtime barrier' \
            "${official_dev[$path]}" "${official_inode[$path]}"
    done
}

pika_snapshot_mutable_contract() {
    local child name path
    pika_snapshot_tree "$site_root/assets/cache" 'official cache tree'
    pika_snapshot_tree "$site_root/app/Plugin" 'official plugin tree'
    pika_snapshot_tree "$site_root/runtime" 'official runtime tree'
    while IFS= read -r -d '' child; do
        name="$(basename -- "$child")"
        case "$name" in
            Base.php|Pay.php|Signature.php)
                [[ -f "$child" && ! -L "$child" ]] \
                    || pika_die "official payment core entry is unsafe: $child"
                ;;
            PikaBEpusdtAdapter)
                pika_die "Pika payment adapter unexpectedly exists during first install"
                ;;
            *)
                [[ -d "$child" && ! -L "$child" ]] \
                    || pika_die "official payment adapter entry is unsupported: $child"
                pika_record_existing_node "$child" 'official payment adapter tree' 1
                pika_snapshot_tree "$child" 'official payment adapter tree'
                ;;
        esac
    done < <(find -P "$site_root/app/Pay" -mindepth 1 -maxdepth 1 -print0)
    while IFS= read -r -d '' child; do
        name="$(basename -- "$child")"
        [[ "$name" != Pika ]] \
            || pika_die "Pika theme unexpectedly exists during first install"
        [[ -d "$child" && ! -L "$child" ]] \
            || pika_die "official theme entry is unsupported: $child"
        pika_record_existing_node "$child" 'official theme tree' 1
        pika_snapshot_tree "$child" 'official theme tree'
    done < <(find -P "$site_root/app/View/User/Theme" -mindepth 1 -maxdepth 1 -print0)
    for path in \
        "$site_root/config/store.php" \
        "$site_root/config/mcp.php" \
        "$site_root/config/terms" \
        "$site_root/kernel/Install/Lock"; do
        [[ ! -e "$path" && ! -L "$path" ]] || pika_record_existing_node "$path" 'official mutable file'
    done
    pika_snapshot_tree "$site_root/kernel/Install/OS" 'official installer OS tree'
}

pika_prepare_missing_mutable_configs() {
    local template path
    template="$stage_root/empty-official-config.php"
    printf '%s\n' \
        '<?php' \
        'declare(strict_types=1);' \
        '' \
        'return [];' > "$template"
    chmod 0600 -- "$template"
    for path in "$site_root/config/store.php" "$site_root/config/mcp.php"; do
        if [[ ! -e "$path" && ! -L "$path" ]]; then
            install -o 0 -g 0 -m 0600 -- "$template" "$path"
            pika_record_created_identity "$path" 'regular file' mutable_created_files
        fi
    done
}

pika_is_exact_mutable_file() {
    case "$1" in
        "$site_root/config/store.php"|\
        "$site_root/config/mcp.php"|\
        "$site_root/config/terms"|\
        "$site_root/kernel/Install/Lock") return 0 ;;
        *) return 1 ;;
    esac
}

pika_authorize_mutable_record() {
    local record="$1" path uid gid mode dev inode type metadata mode_value normalized_mode
    IFS=$'\t' read -r path uid gid mode dev inode type <<<"$record"
    [[ ! -L "$path" && ( -d "$path" || -f "$path" ) ]] \
        || pika_die "official mutable node became unsafe: $path"
    metadata="$(LC_ALL=C stat -c '%d:%i' -- "$path")"
    [[ "$metadata" == "$dev:$inode" \
        && ( ( "$type" == directory && -d "$path" ) \
            || ( "$type" == 'regular file' && -f "$path" ) ) ]] \
        || pika_die "official mutable node identity drifted: $path"
    pika_assert_no_extended_acl "$path" 'official mutable node'
    mode_value=$((8#$mode))
    if [[ "$type" == directory ]]; then
        if [[ "$path" == "$site_root/runtime/throttle" ]]; then
            # Match the installed verifier after the existing identity/ACL gates.
            normalized_mode=0755
        else
            normalized_mode=$(((mode_value & 0755) | 0700))
        fi
    elif pika_is_exact_mutable_file "$path"; then
        normalized_mode=0640
    else
        normalized_mode=$(((mode_value & 0755) | 0600))
    fi
    printf -v normalized_mode '%o' "$normalized_mode"
    chmod "$normalized_mode" -- "$path"
    chown -h "$web_uid:$web_gid" -- "$path"
    metadata="$(LC_ALL=C stat -c '%d:%i:%u:%g:%a' -- "$path")"
    [[ "$metadata" == "$dev:$inode:$web_uid:$web_gid:$normalized_mode" ]] \
        || pika_die "official mutable node authorization failed: $path"
}

pika_normalize_mutable_contract() {
    local record path _uid _gid _mode _dev _inode type depth slash_count max_depth=0

    # Files are authorized while every containing directory is still protected
    # by a root barrier. Directories are then authorized deepest-to-shallowest;
    # root performs no later pathname write below a directory once it is handed
    # to the dedicated Web identity.
    for record in "${mutable_created_files[@]}"; do
        IFS=$'\t' read -r path _dev _inode type <<<"$record"
        [[ "$type" == 'regular file' ]] \
            || pika_die "created official mutable config has the wrong type: $path"
        pika_authorize_created_mutable_file "$record"
    done
    for record in "${mutable_existing[@]}"; do
        IFS=$'\t' read -r path _uid _gid _mode _dev _inode type <<<"$record"
        [[ "$type" == 'regular file' ]] || continue
        pika_authorize_mutable_record "$record"
    done
    for record in "${mutable_existing[@]}"; do
        IFS=$'\t' read -r path _uid _gid _mode _dev _inode type <<<"$record"
        [[ "$type" == directory ]] || continue
        depth="${path//[^\/]/}"
        ((${#depth} > max_depth)) && max_depth=${#depth}
    done
    for ((depth = max_depth; depth >= 0; depth--)); do
        for record in "${mutable_existing[@]}"; do
            IFS=$'\t' read -r path _uid _gid _mode _dev _inode type <<<"$record"
            [[ "$type" == directory ]] || continue
            slash_count="${path//[^\/]/}"
            ((${#slash_count} == depth)) || continue
            pika_authorize_mutable_record "$record"
        done
    done
}

pika_authorize_created_mutable_file() {
    local record="$1" path dev inode type metadata
    IFS=$'\t' read -r path dev inode type <<<"$record"
    [[ "$type" == 'regular file' && -f "$path" && ! -L "$path" ]] \
        || pika_die "created official mutable config became unsafe: $path"
    metadata="$(LC_ALL=C stat -c '%d:%i:%h:%u:%g' -- "$path")"
    [[ "$metadata" == "$dev:$inode:1:0:0" ]] \
        || pika_die "created official mutable config identity drifted: $path"
    pika_assert_no_extended_acl "$path" 'created official mutable config'
    chmod 0640 -- "$path"
    chown -h "$web_uid:$web_gid" -- "$path"
    [[ "$(LC_ALL=C stat -c '%d:%i:%h:%u:%g:%a' -- "$path")" \
        == "$dev:$inode:1:$web_uid:$web_gid:640" ]] \
        || pika_die "created official mutable config authorization failed: $path"
}

pika_authorize_official_contract() {
    local relative mode path expected_identity
    pika_assert_uid_quiescent "$web_uid" 'dedicated Web identity before final authorization'
    pika_verify_barriers
    authorization_started=1
    pika_normalize_mutable_contract
    mapfile -t PIKA_CONTRACT_ROWS < <(pika_official_runtime_contract)
    for ((contract_index = ${#PIKA_CONTRACT_ROWS[@]} - 1; contract_index >= 0; contract_index--)); do
        IFS=: read -r relative mode <<<"${PIKA_CONTRACT_ROWS[$contract_index]}"
        path="$site_root/$relative"
        pika_bound_directory_identity "$path" 'official runtime directory before authorization' \
            "${official_dev[$path]}" "${official_inode[$path]}"
        chmod "$mode" -- "$path"
        chown -h "$web_uid:$web_gid" -- "$path"
        [[ "$(stat -c '%u:%g:%a' -- "$path")" == "$web_uid:$web_gid:$mode" ]] \
            || pika_die "unable to authorize official runtime directory: $relative"
    done
    unset PIKA_CONTRACT_ROWS
    while IFS=: read -r relative mode; do
        path="$site_root/$relative"
        pika_bound_directory_identity "$path" 'protected official directory before authorization' \
            "${official_dev[$path]}" "${official_inode[$path]}"
        chmod "$mode" -- "$path"
        chown -h "0:$web_gid" -- "$path"
        expected_identity="0:$web_gid:$mode"
        [[ "$(stat -c '%u:%g:%a' -- "$path")" == "$expected_identity" ]] \
            || pika_die "unable to finalize protected official directory: $relative"
    done < <(pika_official_protected_contract)
    barriers_authorized=1
    authorization_started=0
    pika_assert_uid_quiescent "$web_uid" 'dedicated Web identity after final authorization'
}

pika_record_created_identity() {
    local path="$1" expected_type="$2" destination_name="$3" metadata dev inode nlink type
    local -n destination="$destination_name"
    [[ ! -L "$path" && ( -d "$path" || -f "$path" ) ]] \
        || pika_die "created installer path is unsafe: $path"
    metadata="$(LC_ALL=C stat -c '%d:%i:%h' -- "$path")"
    [[ "$metadata" =~ ^([0-9]+):([0-9]+):([0-9]+)$ ]] \
        || pika_die "created installer path identity is invalid: $path"
    dev="${BASH_REMATCH[1]}"; inode="${BASH_REMATCH[2]}"; nlink="${BASH_REMATCH[3]}"
    if [[ -d "$path" ]]; then
        type=directory
    else
        type='regular file'
    fi
    [[ "$type" == "$expected_type" ]] \
        || pika_die "created installer path has the wrong type: $path"
    [[ "$type" == directory || "$nlink" == 1 ]] \
        || pika_die "created installer file has multiple hard links: $path"
    destination+=("$path"$'\t'"$dev"$'\t'"$inode"$'\t'"$type")
}

pika_record_external_state_node() {
    local path="$1" expected_type="$2" owner="$3" gid="$4" mode="$5"
    local metadata dev inode nlink actual_owner actual_gid actual_mode actual_type
    [[ ! -L "$path" && ( -d "$path" || -f "$path" ) ]] \
        || pika_die "created external state node is unsafe: $path"
    metadata="$(LC_ALL=C stat -c '%d:%i:%h:%u:%g:%a' -- "$path")"
    [[ "$metadata" =~ ^([0-9]+):([0-9]+):([0-9]+):([0-9]+):([0-9]+):([0-7]{3,4})$ ]] \
        || pika_die "created external state node identity is invalid: $path"
    dev="${BASH_REMATCH[1]}"; inode="${BASH_REMATCH[2]}"; nlink="${BASH_REMATCH[3]}"
    actual_owner="${BASH_REMATCH[4]}"; actual_gid="${BASH_REMATCH[5]}"
    actual_mode="${BASH_REMATCH[6]}"
    if [[ -d "$path" ]]; then
        actual_type=directory
        ((nlink >= 1)) \
            || pika_die "created external state directory link count is invalid: $path"
    else
        actual_type='regular file'
        [[ "$nlink" == 1 ]] \
            || pika_die "created external state file must have exactly one hard link: $path"
    fi
    [[ "$actual_type" == "$expected_type" ]] \
        || pika_die "created external state node has the wrong type: $path"
    pika_assert_no_extended_acl "$path" 'created external state node'
    [[ "$actual_owner:$actual_gid:$actual_mode" == "$owner:$gid:$mode" ]] \
        || pika_die "created external state node has the wrong owner or mode: $path"
    external_state_nodes+=("$path"$'\t'"$dev"$'\t'"$inode"$'\t'"$nlink"$'\t'"$actual_type")
}

pika_preflight_external_state_rollback() {
    local record path dev inode nlink type metadata entry child
    declare -A expected_paths=()
    for record in "${external_state_nodes[@]}"; do
        IFS=$'\t' read -r path dev inode nlink type <<<"$record"
        [[ ! -L "$path" && ( -d "$path" || -f "$path" ) ]] || return 1
        metadata="$(LC_ALL=C stat -c '%d:%i:%h' -- "$path" 2>/dev/null || true)"
        if [[ "$type" == 'regular file' ]]; then
            [[ "$nlink" == 1 && -f "$path" && "$metadata" == "$dev:$inode:$nlink" ]] \
                || return 1
        else
            [[ "$nlink" =~ ^[0-9]+$ && "$nlink" -ge 1 \
                && -d "$path" && "$metadata" == "$dev:$inode:"* \
                && "${metadata##*:}" -ge 1 ]] \
                || return 1
        fi
        (pika_assert_no_extended_acl "$path" 'external state rollback target') || return 1
        expected_paths["$path"]=1
    done
    for record in "${external_state_nodes[@]}"; do
        IFS=$'\t' read -r path _dev _inode _nlink type <<<"$record"
        [[ "$type" == directory ]] || continue
        while IFS= read -r -d '' entry; do
            child="$path/$entry"
            [[ -n "${expected_paths[$child]:-}" ]] || return 1
        done < <(find -P "$path" -mindepth 1 -maxdepth 1 -printf '%f\0')
    done
}

pika_remove_external_state_exact() {
    local index record path dev inode nlink type
    pika_preflight_external_state_rollback || return 1
    for ((index = ${#external_state_nodes[@]} - 1; index >= 0; index--)); do
        record="${external_state_nodes[$index]}"
        IFS=$'\t' read -r path dev inode nlink type <<<"$record"
        if [[ "$type" == 'regular file' ]]; then
            unlink -- "$path" || return 1
        else
            rmdir -- "$path" || return 1
        fi
    done
}

pika_reacquire_barriers_for_rollback() {
    local relative expected_mode path metadata expected_owner
    if ((barriers_authorized || authorization_started)); then
        (pika_assert_uid_quiescent "$web_uid" 'dedicated Web identity during rollback') || return 1
        # Preflight every bound shallow root before changing any of them.  A
        # final authorization can stop between chmod and chown, so each root
        # may still be the root barrier, be at its intermediate root-owned
        # final mode, or already have its exact final owner and mode.
        while IFS=: read -r relative expected_mode; do
            path="$site_root/$relative"
            (pika_bound_directory_identity "$path" 'official runtime rollback root' \
                "${official_dev[$path]}" "${official_inode[$path]}") || return 1
            metadata="$(stat -c '%u:%g:%a' -- "$path")" || return 1
            expected_owner="$web_uid:$web_gid"
            case "$relative" in
                config|kernel/Install) expected_owner="0:$web_gid" ;;
            esac
            [[ "$metadata" == '0:0:700' || "$metadata" == "0:0:$expected_mode" \
                || "$metadata" == "$expected_owner:$expected_mode" ]] \
                || return 1
        done < <(pika_official_runtime_roots)
        while IFS=: read -r relative expected_mode; do
            path="$site_root/$relative"
            (pika_bound_directory_identity "$path" 'official runtime rollback root' \
                "${official_dev[$path]}" "${official_inode[$path]}") || return 1
            chown -h 0:0 -- "$path" || return 1
            chmod 0700 -- "$path" || return 1
            (pika_bound_directory_identity "$path" 'official runtime rollback barrier' \
                "${official_dev[$path]}" "${official_inode[$path]}") || return 1
            metadata="$(stat -c '%u:%g:%a' -- "$path")" || return 1
            [[ "$metadata" == '0:0:700' ]] \
                || return 1
        done < <(pika_official_runtime_roots)
        barriers_authorized=0
        authorization_started=0
        (pika_assert_uid_quiescent "$web_uid" 'dedicated Web identity after rollback barrier') || return 1
    else
        (pika_verify_taken_barriers) || return 1
    fi
}

pika_restore_mutable_record() {
    local record="$1" path owner gid mode dev inode type metadata
    IFS=$'\t' read -r path owner gid mode dev inode type <<<"$record"
    [[ ! -L "$path" && ( -d "$path" || -f "$path" ) ]] || return 1
    metadata="$(LC_ALL=C stat -c '%d:%i' -- "$path" 2>/dev/null || true)"
    [[ "$metadata" == "$dev:$inode" \
        && ( ( "$type" == directory && -d "$path" ) \
            || ( "$type" == 'regular file' && -f "$path" ) ) ]] \
        || return 1
    (pika_assert_no_extended_acl "$path" 'official mutable rollback target') || return 1
    chmod "$mode" -- "$path" 2>/dev/null || return 1
    chown -h "$owner:$gid" -- "$path" 2>/dev/null || return 1
    metadata="$(LC_ALL=C stat -c '%d:%i:%u:%g:%a' -- "$path" 2>/dev/null || true)"
    [[ "$metadata" == "$dev:$inode:$owner:$gid:$mode" ]]
}

rollback_install() {
    local rc=$? index record path owner gid mode dev inode type metadata
    local depth slash_count max_depth=0
    local official_runtime_rollback_incomplete=0
    trap - EXIT INT TERM
    if ((barriers_taken)); then
        if ! pika_reacquire_barriers_for_rollback; then
            printf 'ERROR: unable to re-establish exact official runtime rollback barriers\n' >&2
            official_runtime_rollback_incomplete=1
        fi
    fi
    if ((patch_applied)) && ((official_runtime_rollback_incomplete == 0)); then
        while IFS= read -r relative; do
            [[ -f "$backup_root/site/$relative" ]] || continue
            cp -p -- "$backup_root/site/$relative" "$site_root/$relative"
        done < <(pika_bridge_files)
    fi
    if ((payload_copied)) && ((official_runtime_rollback_incomplete == 0)); then
        for ((index = ${#created_payload_files[@]} - 1; index >= 0; index--)); do
            record="${created_payload_files[$index]}"
            IFS=$'\t' read -r path dev inode type <<<"$record"
            if [[ ! -f "$path" || -L "$path" ]]; then
                printf 'ERROR: created payload file became unsafe: %s\n' "$path" >&2
                official_runtime_rollback_incomplete=1
                continue
            fi
            metadata="$(LC_ALL=C stat -c '%d:%i:%h' -- "$path" 2>/dev/null || true)"
            if [[ "$metadata" != "$dev:$inode:1" ]] \
                || ! (pika_assert_no_extended_acl "$path" 'created payload file') \
                || ! unlink -- "$path" 2>/dev/null; then
                printf 'ERROR: created payload file identity drifted or could not be removed: %s\n' "$path" >&2
                official_runtime_rollback_incomplete=1
            fi
        done
        for ((index = ${#created_payload_directories[@]} - 1; index >= 0; index--)); do
            record="${created_payload_directories[$index]}"
            IFS=$'\t' read -r path dev inode type <<<"$record"
            if [[ ! -d "$path" || -L "$path" ]]; then
                printf 'ERROR: created payload directory became unsafe: %s\n' "$path" >&2
                official_runtime_rollback_incomplete=1
                continue
            fi
            metadata="$(LC_ALL=C stat -c '%d:%i' -- "$path" 2>/dev/null || true)"
            if [[ "$metadata" != "$dev:$inode" ]] \
                || ! (pika_assert_no_extended_acl "$path" 'created payload directory') \
                || ! rmdir -- "$path" 2>/dev/null; then
                printf 'ERROR: created payload directory retained residue or identity drifted: %s\n' "$path" >&2
                official_runtime_rollback_incomplete=1
            fi
        done
    fi
    if ((barriers_taken)) && ((official_runtime_rollback_incomplete == 0)); then
        for ((index = ${#mutable_created_files[@]} - 1; index >= 0; index--)); do
            record="${mutable_created_files[$index]}"
            IFS=$'\t' read -r path dev inode type <<<"$record"
            metadata="$(LC_ALL=C stat -c '%d:%i:%h' -- "$path" 2>/dev/null || true)"
            if [[ ! -f "$path" || -L "$path" || "$metadata" != "$dev:$inode:1" ]] \
                || ! (pika_assert_no_extended_acl "$path" 'created official mutable config') \
                || ! unlink -- "$path" 2>/dev/null; then
                printf 'ERROR: created official mutable config identity drifted or could not be removed: %s\n' "$path" >&2
                official_runtime_rollback_incomplete=1
            fi
        done
    fi
    if ((barriers_taken)) && ((official_runtime_rollback_incomplete == 0)); then
        for record in "${mutable_existing[@]}"; do
            IFS=$'\t' read -r path owner gid mode dev inode type <<<"$record"
            [[ "$type" == 'regular file' ]] || continue
            if ! pika_restore_mutable_record "$record"; then
                official_runtime_rollback_incomplete=1
                printf 'ERROR: official mutable metadata did not return to its snapshot: %s\n' "$path" >&2
            fi
        done
        for record in "${mutable_existing[@]}"; do
            IFS=$'\t' read -r path owner gid mode dev inode type <<<"$record"
            [[ "$type" == directory ]] || continue
            slash_count="${path//[^\/]/}"
            ((${#slash_count} > max_depth)) && max_depth=${#slash_count}
        done
        for ((depth = max_depth; depth >= 0; depth--)); do
            for record in "${mutable_existing[@]}"; do
                IFS=$'\t' read -r path owner gid mode dev inode type <<<"$record"
                [[ "$type" == directory ]] || continue
                slash_count="${path//[^\/]/}"
                ((${#slash_count} == depth)) || continue
                if ! pika_restore_mutable_record "$record"; then
                    official_runtime_rollback_incomplete=1
                    printf 'ERROR: official mutable metadata did not return to its snapshot: %s\n' "$path" >&2
                fi
            done
        done
        for ((index = ${#official_contract_created[@]} - 1; index >= 0; index--)); do
            record="${official_contract_created[$index]}"
            IFS=$'\t' read -r path dev inode <<<"$record"
            if ! (pika_bound_directory_identity "$path" 'new official runtime rollback target' "$dev" "$inode") \
                || ! rmdir -- "$path" 2>/dev/null; then
                printf 'ERROR: newly-created official runtime directory retained unexpected residue or identity drifted: %s\n' "$path" >&2
                official_runtime_rollback_incomplete=1
            fi
        done
        for ((index = ${#official_contract_existing[@]} - 1; index >= 0; index--)); do
            record="${official_contract_existing[$index]}"
            IFS=$'\t' read -r path owner gid mode dev inode <<<"$record"
            if ! (pika_bound_directory_identity "$path" 'official runtime rollback target' "$dev" "$inode") \
                || ! chmod "$mode" -- "$path" 2>/dev/null \
                || ! chown -h "$owner:$gid" -- "$path" 2>/dev/null \
                || [[ "$(stat -c '%d:%i:%u:%g:%a' -- "$path" 2>/dev/null || true)" \
                    != "$dev:$inode:$owner:$gid:$mode" ]]; then
                printf 'ERROR: official runtime metadata did not return to its snapshot: %s\n' "$path" >&2
                official_runtime_rollback_incomplete=1
            fi
        done
    fi
    if ((state_created)) && ((official_runtime_rollback_incomplete == 0)); then
        if ! pika_remove_external_state_exact; then
            printf 'ERROR: external state identity drifted or retained unexpected residue: %s\n' "$state_dir" >&2
            official_runtime_rollback_incomplete=1
        fi
    fi
    if ((official_runtime_rollback_incomplete == 0)); then
        rm -f -- "$backup_root/install-receipt.json" "$backup_root/installed-files.txt"
    fi
    rm -rf -- "$stage_root"
    if ((official_runtime_rollback_incomplete)); then
        printf 'ERROR: ROLLBACK_INCOMPLETE official runtime residue remains; backup retained at %s\n' "$backup_root" >&2
        ((rc != 0)) || rc=1
        exit "$rc"
    fi
    printf 'ERROR: install failed; original bridge files restored from %s\n' "$backup_root" >&2
    exit "$rc"
}
trap rollback_install EXIT INT TERM

"$php_bin" "${SCRIPT_DIR}/stage-payload.php" \
    --repo-root "$PIKA_REPO_DIR" \
    --stage-root "$payload_root" \
    --mode install

find "$payload_root" -type f -print | while IFS= read -r absolute; do
    relative="${absolute#"$payload_root"/}"
    [[ "$relative" != "$absolute" ]] || pika_die "staged file escaped payload root"
    [[ ! -e "$site_root/$relative" && ! -L "$site_root/$relative" ]] || pika_die "payload would overwrite an existing file: $relative"
    if [[ "$relative" != "app/View/User/Theme/Pika/Setting.php" ]]; then
        printf '%s\n' "$relative"
    fi
done | LC_ALL=C sort > "$installed_list"

find "$payload_root" -type f -print | while IFS= read -r absolute; do
    relative="${absolute#"$payload_root"/}"
    [[ "$relative" != "$absolute" ]] || pika_die "staged file escaped payload root"
    printf '%s\n' "$relative"
done | LC_ALL=C sort > "$staged_file_list"

find "$payload_root" -type d -print | while IFS= read -r absolute; do
    relative="${absolute#"$payload_root"/}"
    [[ "$relative" != "$absolute" ]] || continue
    case "$relative/" in
        local-extensions/*|\
        app/View/Admin/LocalExtensions/*|\
        app/View/User/Theme/Pika/*|\
        app/Pay/PikaBEpusdtAdapter/*|\
        assets/admin/controller/local-extensions/*|\
        assets/local-extensions/*)
            printf '%s\n' "$relative"
            ;;
    esac
done | LC_ALL=C sort -u > "$payload_directory_list"

while IFS= read -r relative; do
    mkdir -p -- "$backup_root/site/$(dirname -- "$relative")"
    cp -p -- "$site_root/$relative" "$backup_root/site/$relative"
done < <(pika_bridge_files)

git -C "$site_root" apply --check "$bridge_patch"
pika_assert_uid_quiescent "$web_uid" 'dedicated Web identity before runtime barriers'
barriers_taken=1
while IFS=: read -r official_relative official_mode; do
    pika_take_official_barrier "$official_relative" "$official_mode"
done < <(pika_official_runtime_roots)
pika_assert_uid_quiescent "$web_uid" 'dedicated Web identity after runtime barriers'
while IFS=: read -r official_relative official_mode; do
    pika_ensure_official_contract_directory "$official_relative"
done < <(pika_official_runtime_contract)
pika_verify_barriers
pika_snapshot_mutable_contract
pika_prepare_missing_mutable_configs
payload_copied=1
while IFS= read -r relative; do
    [[ -n "$relative" ]] || continue
    target="$site_root/$relative"
    [[ ! -e "$target" && ! -L "$target" ]] \
        || pika_die "payload directory would overwrite an existing path: $relative"
    mkdir -- "$target"
    pika_record_created_identity "$target" directory created_payload_directories
done < "$payload_directory_list"
while IFS= read -r relative; do
    [[ -n "$relative" ]] || continue
    target="$site_root/$relative"
    [[ ! -e "$target" && ! -L "$target" ]] \
        || pika_die "payload would overwrite an existing file: $relative"
    cp -p -- "$payload_root/$relative" "$target"
    pika_record_created_identity "$target" 'regular file' created_payload_files
done < "$staged_file_list"

"$php_bin" "${SCRIPT_DIR}/init-runtime.php" \
    --site-root "$site_root" \
    --web-uid "$web_uid" \
    --web-gid "$web_gid"
state_created=1
pika_assert_external_state_no_acl "$state_dir"
pika_record_external_state_node "$state_dir" directory 0 0 755
pika_record_external_state_node "$state_dir/secrets" directory 0 "$web_gid" 750
pika_record_external_state_node "$state_dir/runtime" directory "$web_uid" "$web_gid" 750
pika_record_external_state_node "$state_dir/runtime/config" directory "$web_uid" "$web_gid" 750
pika_record_external_state_node "$state_dir/runtime/csrf.key" 'regular file' "$web_uid" "$web_gid" 600

"$php_bin" "${SCRIPT_DIR}/build-registry.php" \
    --site-root "$site_root" \
    --release "${PIKA_REPO_DIR}/release.json" \
    --output "$site_root/local-extensions/registry.json"
pika_record_created_identity "$site_root/local-extensions/registry.json" 'regular file' created_payload_files

patch_applied=1
git -C "$site_root" apply "$bridge_patch"

while IFS= read -r relative; do
    [[ -n "$relative" ]] || continue
    target="$site_root/$relative"
    [[ -d "$target" && ! -L "$target" ]] \
        || pika_die "immutable payload directory is missing or unsafe: $relative"
    chown 0:0 -- "$target"
    chmod 0755 -- "$target"
done < "$payload_directory_list"

while IFS= read -r relative; do
    [[ -n "$relative" ]] || continue
    target="$site_root/$relative"
    [[ -f "$target" && ! -L "$target" ]] \
        || pika_die "immutable payload file is missing or unsafe: $relative"
    chown 0:0 -- "$target"
    chmod 0644 -- "$target"
done < "$installed_list"

registry_path="$site_root/local-extensions/registry.json"
[[ -f "$registry_path" && ! -L "$registry_path" ]] \
    || pika_die "generated registry is missing or unsafe"
chown 0:0 -- "$registry_path"
chmod 0644 -- "$registry_path"

while IFS= read -r relative; do
    chown 0:0 -- "$site_root/$relative"
    chmod 0644 -- "$site_root/$relative"
done < <(pika_bridge_files)
chown 0:0 -- \
    "$site_root/app/Controller/Admin/LocalExtensions.php" \
    "$site_root/app/Controller/Admin/Api/LocalExtensions.php"
chmod 0644 -- \
    "$site_root/app/Controller/Admin/LocalExtensions.php" \
    "$site_root/app/Controller/Admin/Api/LocalExtensions.php"

setting_path="$site_root/app/View/User/Theme/Pika/Setting.php"
[[ -f "$setting_path" && ! -L "$setting_path" \
    && "$(realpath -e -- "$setting_path")" == "$setting_path" \
    && "$(stat -c '%h:%F' -- "$setting_path")" == '1:regular file' ]] \
    || pika_die "Pika Setting.php is missing, non-canonical, or has multiple hard links"
pika_assert_no_extended_acl "$setting_path" 'Pika mutable setting'
chown "$web_uid:$web_gid" -- "$setting_path"
chmod 0640 -- "$setting_path"
[[ "$(stat -c '%h:%u:%g:%a:%F' -- "$setting_path")" \
    == "1:$web_uid:$web_gid:640:regular file" ]] \
    || pika_die "Pika Setting.php authorization drifted"
pika_assert_no_extended_acl "$setting_path" 'Pika mutable setting'

payment_runtime_log="$site_root/app/Pay/PikaBEpusdtAdapter/runtime.log"
[[ ! -e "$payment_runtime_log" && ! -L "$payment_runtime_log" ]] \
    || pika_die "payment adapter runtime log already exists or is unsafe"
pika_require_command install
install -o "$web_uid" -g "$web_gid" -m 0640 /dev/null "$payment_runtime_log"
pika_record_created_identity "$payment_runtime_log" 'regular file' created_payload_files
[[ "$(realpath -e -- "$payment_runtime_log")" == "$payment_runtime_log" \
    && "$(stat -c '%h:%u:%g:%a:%F' -- "$payment_runtime_log")" \
        == "1:$web_uid:$web_gid:640:regular empty file" ]] \
    || pika_die "payment adapter runtime log authorization drifted"
pika_assert_no_extended_acl "$payment_runtime_log" 'payment adapter runtime log'

bridge_version="$($php_bin -r '
    $doc = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
    echo (string)($doc["bridge_version"] ?? "");
' "$PIKA_COMPAT_FILE")"

"$php_bin" "${SCRIPT_DIR}/write-receipt.php" \
    --output "$backup_root/install-receipt.json" \
    --site-root "$site_root" \
    --backup-dir "$backup_root" \
    --bridge-version "$bridge_version" \
    --upstream-commit "$expected_commit" \
    --file-list "$installed_list" \
    --web-uid "$web_uid" \
    --web-gid "$web_gid"

chown 0:0 -- "$backup_root/install-receipt.json"
chmod 0400 "$backup_root/install-receipt.json"
cp -p -- "$backup_root/install-receipt.json" "$state_dir/install-receipt.json"
chown 0:0 -- "$state_dir/install-receipt.json"
chmod 0400 "$state_dir/install-receipt.json"
pika_record_external_state_node "$state_dir/install-receipt.json" 'regular file' 0 0 400
cp -p -- "$installed_list" "$backup_root/installed-files.txt"
chmod 0600 "$backup_root/installed-files.txt"

# This is the last privileged write below any official mutable project root.
# After the deepest-to-shallow authorization below, the installer performs
# verification only; a failed verification first reclaims the exact bound
# roots before rollback.
pika_authorize_official_contract

installed_doctor_args=(--site-root "$site_root" --installed)
if [[ -n "${PIKA_PHP_BIN:-}" ]]; then
    installed_doctor_args+=(--php "$PIKA_PHP_BIN")
fi
"${SCRIPT_DIR}/doctor.sh" "${installed_doctor_args[@]}"

trap - EXIT INT TERM
rm -rf -- "$stage_root"
pika_info "INSTALL_PASS version=$version commit=$expected_commit backup=$backup_root"
