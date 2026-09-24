#!/bin/bash
set -euo pipefail

readonly PIKA_SCRIPT_DIR="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly PIKA_REPO_DIR="$(cd -P -- "${PIKA_SCRIPT_DIR}/.." && pwd)"
readonly PIKA_COMPAT_FILE="${PIKA_REPO_DIR}/compatibility.json"
readonly PIKA_STATE_SITES_DIR="/var/lib/pika-local-extensions/sites"

if ((EUID == 0)); then
    unset TMPDIR TMP TEMP PHPRC PHP_INI_SCAN_DIR
fi

pika_die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

pika_info() {
    printf 'INFO: %s\n' "$*"
}

pika_require_command() {
    command -v "$1" >/dev/null 2>&1 || pika_die "required command not found: $1"
}

pika_sha256() {
    local file="$1"
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum -- "$file" | awk '{print $1}'
        return
    fi
    if command -v shasum >/dev/null 2>&1; then
        shasum -a 256 -- "$file" | awk '{print $1}'
        return
    fi
    pika_die "sha256sum or shasum is required"
}

pika_sha256_string() {
    local value="$1"
    if command -v sha256sum >/dev/null 2>&1; then
        printf '%s' "$value" | sha256sum | awk '{print $1}'
        return
    fi
    if command -v shasum >/dev/null 2>&1; then
        printf '%s' "$value" | shasum -a 256 | awk '{print $1}'
        return
    fi
    pika_die "sha256sum or shasum is required"
}

pika_real_dir() {
    local input="$1"
    [[ -n "$input" ]] || pika_die "site root is empty"
    [[ "$input" = /* ]] || pika_die "site root must be an absolute path"
    [[ -d "$input" ]] || pika_die "site root does not exist: $input"
    (cd -P -- "$input" && pwd)
}

pika_assert_regular_path_below() {
    local root="$1" relative="$2" cursor="$1" segment index
    local -a segments=()
    IFS=/ read -r -a segments <<<"$relative"
    for ((index = 0; index < ${#segments[@]}; index++)); do
        segment="${segments[$index]}"
        [[ -n "$segment" && "$segment" != "." && "$segment" != ".." ]] \
            || pika_die "compatibility path is not canonical: $relative"
        cursor="$cursor/$segment"
        [[ ! -L "$cursor" ]] \
            || pika_die "compatibility path contains a symbolic link: $relative"
        if ((index < ${#segments[@]} - 1)); then
            [[ -d "$cursor" ]] \
                || pika_die "compatibility path parent is missing or not a directory: $relative"
        else
            [[ -f "$cursor" ]] \
                || pika_die "compatibility file is missing or not regular: $relative"
        fi
    done
}

pika_assert_safe_site_root() {
    local root="$1" relative cursor metadata owner mode mode_value
    [[ "$root" != "/" ]] || pika_die "refusing filesystem root"
    [[ "$root" != "${PIKA_REPO_DIR}" ]] || pika_die "extension repository is not a site root"
    if ((EUID == 0)); then
        cursor="$root"
        while :; do
            [[ -d "$cursor" && ! -L "$cursor" && "$(realpath -e -- "$cursor")" == "$cursor" ]] \
                || pika_die "site root ancestor is missing, non-canonical, or unsafe: $cursor"
            metadata="$(stat -c '%u:%a' -- "$cursor")" \
                || pika_die "unable to inspect site root ancestor: $cursor"
            IFS=: read -r owner mode <<<"$metadata"
            [[ "$owner" == 0 && "$mode" =~ ^[0-7]{3,4}$ ]] \
                || pika_die "site root ancestor must be root-owned with a valid mode: $cursor"
            mode_value=$((8#$mode))
            (( (mode_value & 0022) == 0 )) \
                || pika_die "site root ancestor must not be group/world writable: $cursor"
            pika_assert_no_extended_acl "$cursor" 'site root ancestor'
            [[ "$cursor" == / ]] && break
            cursor="$(dirname -- "$cursor")"
        done
    fi
    while IFS= read -r relative; do
        pika_assert_regular_path_below "$root" "$relative"
    done < <(pika_compat_files)
    [[ -d "$root/.git" && ! -L "$root/.git" ]] \
        || pika_die "site must be a non-symbolic-link Git checkout of the official repository"
}

pika_php() {
    local requested="${PIKA_PHP_BIN:-php}" candidate resolved metadata owner group mode mode_value
    local cursor parent known_php php_name php_version_id

    [[ -n "$requested" && ! "$requested" =~ [[:cntrl:]] ]] \
        || pika_die "PHP binary contains an invalid control character"
    if [[ "$requested" == */* ]]; then
        [[ "$requested" = /* ]] || pika_die "PHP binary path must be absolute: $requested"
        candidate="$requested"
    else
        [[ "$requested" =~ ^[A-Za-z0-9][A-Za-z0-9._+-]*$ ]] \
            || pika_die "PHP binary name contains unsupported characters: $requested"
        candidate="$(type -P -- "$requested" || true)"
        if [[ -z "$candidate" && "$requested" == "php" ]]; then
            for known_php in /usr/bin/php /usr/local/bin/php; do
                if [[ -x "$known_php" ]]; then
                    candidate="$known_php"
                    break
                fi
            done
        fi
        [[ -n "$candidate" ]] || pika_die "PHP binary not found: $requested"
    fi

    resolved="$(realpath -e -- "$candidate")" \
        || pika_die "unable to resolve PHP binary: $candidate"
    [[ "$resolved" = /* && -f "$resolved" && ! -L "$resolved" && -x "$resolved" ]] \
        || pika_die "PHP binary is not a canonical executable file: $candidate"
    php_name="$(basename -- "$resolved")"
    [[ "$php_name" =~ ^php([0-9]+([.][0-9]+)*)?$ ]] \
        || pika_die "PHP CLI basename is not allowed: $php_name"

    # install/restore and scheduler call this helper as root. In that context,
    # never execute a PHP wrapper or binary replaceable by the web identity (or
    # any other unprivileged user). A canonical root-owned chain also closes the
    # resolution-to-exec race without loading any target-site PHP.
    if ((EUID == 0)); then
        metadata="$(stat -c '%u:%g:%a' -- "$resolved")" \
            || pika_die "unable to inspect PHP binary: $resolved"
        IFS=: read -r owner group mode <<<"$metadata"
        [[ "$owner" == "0" && "$group" == "0" && "$mode" =~ ^[0-7]{3,4}$ ]] \
            || pika_die "PHP binary must be root:root with a valid mode: $resolved"
        mode_value=$((8#$mode))
        (( (mode_value & 0022) == 0 )) \
            || pika_die "PHP binary must not be group/world writable: $resolved"
        (( (mode_value & 07000) == 0 )) \
            || pika_die "PHP binary must not use special permission bits: $resolved"
        pika_assert_no_extended_acl "$resolved" 'PHP binary'

        cursor="$(dirname -- "$resolved")"
        while :; do
            [[ -d "$cursor" && ! -L "$cursor" ]] \
                || pika_die "PHP binary ancestor is missing or unsafe: $cursor"
            metadata="$(stat -c '%u:%g:%a' -- "$cursor")" \
                || pika_die "unable to inspect PHP binary ancestor: $cursor"
            IFS=: read -r owner group mode <<<"$metadata"
            [[ "$owner" == "0" && "$group" == "0" && "$mode" =~ ^[0-7]{3,4}$ ]] \
                || pika_die "PHP binary ancestors must be root:root: $cursor"
            mode_value=$((8#$mode))
            (( (mode_value & 0022) == 0 )) \
                || pika_die "PHP binary ancestor must not be group/world writable: $cursor"
            pika_assert_no_extended_acl "$cursor" 'PHP binary ancestor'
            [[ "$cursor" == "/" ]] && break
            parent="$(dirname -- "$cursor")"
            [[ "$parent" != "$cursor" ]] || pika_die "unable to validate PHP binary ancestor chain"
            cursor="$parent"
        done
    fi

    php_version_id="$("$resolved" -r 'echo PHP_VERSION_ID;' 2>/dev/null)" \
        || pika_die "PHP CLI identity probe failed: $resolved"
    [[ "$php_version_id" =~ ^[0-9]{5,6}$ ]] \
        || pika_die "PHP CLI identity probe returned an invalid version: $resolved"

    printf '%s\n' "$resolved"
}

pika_assert_no_extended_acl() {
    local path="$1" label="$2" acl_tool acl permission_token
    acl_tool="$(type -P -- getfacl || true)"
    if [[ -n "$acl_tool" ]]; then
        acl="$(LC_ALL=C "$acl_tool" --absolute-names --omit-header -- "$path")" \
            || pika_die "unable to inspect $label ACL: $path"
        if grep -Eq '^(user|group):[^:]+:|^mask::|^default:' <<<"$acl"; then
            pika_die "$label must not have an extended POSIX ACL: $path"
        fi
        return
    fi

    # GNU ls marks an extended POSIX ACL with a trailing '+'. This fallback
    # keeps the gate usable on minimal Debian/RHEL hosts without the optional
    # acl package while still failing closed on any extended ACL. Other
    # security-context markers (for example '.') are not misclassified.
    permission_token="$(LC_ALL=C ls -ld -- "$path")" \
        || pika_die "unable to inspect $label ACL marker: $path"
    permission_token="${permission_token%%[[:space:]]*}"
    [[ "$permission_token" != *+ ]] \
        || pika_die "$label must not have an extended POSIX ACL: $path"
}

pika_assert_isolated_web_identity() {
    local web_uid="$1" web_gid="$2" label="$3"
    local passwd_row group_row web_name resolved_uid primary_gid resolved_gid members group_ids primary_count
    local -a parsed_groups=()

    [[ "$web_uid" =~ ^[1-9][0-9]*$ && "$web_gid" =~ ^[1-9][0-9]*$ ]] \
        || pika_die "$label UID/GID is invalid"
    pika_require_command getent
    passwd_row="$(getent passwd "$web_uid" || true)"
    [[ -n "$passwd_row" && "$passwd_row" != *$'\n'* ]] \
        || pika_die "$label UID must resolve to exactly one local/NSS account"
    IFS=: read -r web_name _ resolved_uid primary_gid _ <<<"$passwd_row"
    [[ "$resolved_uid" == "$web_uid" && "$primary_gid" == "$web_gid" \
        && "$web_name" =~ ^[A-Za-z_][A-Za-z0-9_.-]*$ ]] \
        || pika_die "$label account does not match the expected primary UID/GID"

    group_row="$(getent group "$web_gid" || true)"
    [[ -n "$group_row" && "$group_row" != *$'\n'* ]] \
        || pika_die "$label primary GID must resolve to exactly one group"
    IFS=: read -r _ _ resolved_gid members <<<"$group_row"
    [[ "$resolved_gid" == "$web_gid" && -z "$members" ]] \
        || pika_die "$label primary group must not contain supplementary members"

    group_ids="$(id -G "$web_name")" \
        || pika_die "unable to inspect $label group memberships"
    read -r -a parsed_groups <<<"$group_ids"
    [[ ${#parsed_groups[@]} -eq 1 && "${parsed_groups[0]}" == "$web_gid" ]] \
        || pika_die "$label account must have no supplementary groups"

    primary_count="$(getent passwd | awk -F: -v gid="$web_gid" '$4 == gid { count++ } END { print count + 0 }')" \
        || pika_die "unable to inspect $label primary-group uniqueness"
    [[ "$primary_count" == 1 ]] \
        || pika_die "$label primary GID must belong to exactly one account"
}

# A compatibility-first install or restore temporarily changes ownership below
# project-local directories that the Acg-Faka worker normally owns.  The caller
# must first quiesce that dedicated identity.  Do not trust a command-line
# acknowledgement alone: inspect all four Linux UID slots (real/effective/
# saved/fs) and fail closed while any process for the identity still exists.
pika_assert_uid_quiescent() {
    local wanted_uid="$1" label="$2" status uid_line slot pid found=''
    local -a uid_slots=()
    [[ "$wanted_uid" =~ ^[1-9][0-9]*$ ]] \
        || pika_die "$label UID is invalid"
    [[ -d /proc && -r /proc/self/status ]] \
        || pika_die "$label requires a readable Linux /proc process table"

    for status in /proc/[0-9]*/status; do
        [[ -r "$status" ]] || continue
        uid_line="$(awk '$1 == "Uid:" { print $2 ":" $3 ":" $4 ":" $5; exit }' "$status" 2>/dev/null || true)"
        [[ -n "$uid_line" ]] || continue
        IFS=: read -r -a uid_slots <<<"$uid_line"
        for slot in "${uid_slots[@]}"; do
            if [[ "$slot" == "$wanted_uid" ]]; then
                pid="${status#/proc/}"
                pid="${pid%/status}"
                found="$pid"
                break 2
            fi
        done
    done
    [[ -z "$found" ]] \
        || pika_die "$label must have zero processes before privileged site mutation (found pid $found)"
}

pika_bound_directory_identity() {
    local path="$1" label="$2" expected_dev="${3:-}" expected_inode="${4:-}"
    local resolved metadata dev inode nlink type uid gid mode mode_value
    [[ -d "$path" && ! -L "$path" ]] \
        || pika_die "$label is missing, not a directory, or a symbolic link: $path"
    resolved="$(realpath -e -- "$path")" \
        || pika_die "unable to resolve $label: $path"
    [[ "$resolved" == "$path" ]] \
        || pika_die "$label is not canonical: $path"
    metadata="$(LC_ALL=C stat -c '%d:%i:%h:%F:%u:%g:%a' -- "$path")" \
        || pika_die "unable to inspect $label identity: $path"
    [[ "$metadata" =~ ^([0-9]+):([0-9]+):([0-9]+):directory:([0-9]+):([0-9]+):([0-7]{3,4})$ ]] \
        || pika_die "$label identity is invalid: $path"
    dev="${BASH_REMATCH[1]}"
    inode="${BASH_REMATCH[2]}"
    nlink="${BASH_REMATCH[3]}"
    uid="${BASH_REMATCH[4]}"
    gid="${BASH_REMATCH[5]}"
    mode="${BASH_REMATCH[6]}"
    # Overlay filesystems may report a canonical directory with nlink=1, and
    # child creation/removal can legitimately change the count. Bind directory
    # identity with canonical path plus dev/inode/type/ACL; regular files retain
    # exactly-one-link checks at their call sites.
    (( nlink >= 1 )) || pika_die "$label directory link count is invalid: $path"
    mode_value=$((8#$mode))
    (( (mode_value & 0022) == 0 )) \
        || pika_die "$label must not be group/world writable: $path"
    if [[ -n "$expected_dev" || -n "$expected_inode" ]]; then
        [[ "$expected_dev" =~ ^[0-9]+$ && "$expected_inode" =~ ^[0-9]+$ \
            && "$dev:$inode" == "$expected_dev:$expected_inode" ]] \
            || pika_die "$label device/inode identity drifted: $path"
    fi
    pika_assert_no_extended_acl "$path" "$label"
    PIKA_BOUND_DEV="$dev"
    PIKA_BOUND_INODE="$inode"
    PIKA_BOUND_NLINK="$nlink"
    PIKA_BOUND_UID="$uid"
    PIKA_BOUND_GID="$gid"
    PIKA_BOUND_MODE="$mode"
}

pika_assert_external_state_no_acl() {
    local state_dir="$1" path
    for path in \
        /var/lib/pika-local-extensions \
        /var/lib/pika-local-extensions/sites \
        "$state_dir" \
        "$state_dir/secrets" \
        "$state_dir/runtime" \
        "$state_dir/runtime/config" \
        "$state_dir/install-receipt.json" \
        "$state_dir/secrets/bepusdt-token" \
        "$state_dir/secrets/bepusdt-namespace"; do
        [[ ! -e "$path" && ! -L "$path" ]] && continue
        pika_assert_no_extended_acl "$path" 'external state path'
    done
}

pika_assert_trusted_compatibility_manifest() {
    [[ -f "$PIKA_COMPAT_FILE" && ! -L "$PIKA_COMPAT_FILE" ]] \
        || pika_die "trusted compatibility manifest is missing or unsafe"
}

pika_site_version() {
    local root="$1" php_bin app_config app_hash
    app_config="$root/config/app.php"
    [[ -f "$app_config" && ! -L "$app_config" ]] \
        || pika_die "config/app.php is missing, not regular, or a symbolic link"
    pika_assert_trusted_compatibility_manifest
    app_hash="$(pika_sha256 "$app_config")" || return 1
    php_bin="$(pika_php)" || return 1
    "$php_bin" -r '
        try {
            $doc = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            fwrite(STDERR, "invalid compatibility manifest\n");
            exit(2);
        }
        $matches = [];
        foreach (($doc["acg_faka"] ?? []) as $row) {
            if (!is_array($row)
                || !is_string($row["version"] ?? null)
                || !is_string($row["files"]["config/app.php"] ?? null)
                || !hash_equals($row["files"]["config/app.php"], $argv[2])) {
                continue;
            }
            $matches[] = $row["version"];
        }
        if (count($matches) !== 1 || preg_match("/^[0-9]+(?:\\.[0-9]+){2}$/D", $matches[0]) !== 1) {
            fwrite(STDERR, "config/app.php hash is unsupported or ambiguous\n");
            exit(3);
        }
        echo $matches[0];
    ' "$PIKA_COMPAT_FILE" "$app_hash"
}

pika_compat_value() {
    local version="$1" field="$2" php_bin
    pika_assert_trusted_compatibility_manifest
    php_bin="$(pika_php)" || return 1
    "$php_bin" -r '
        try {
            $doc = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            fwrite(STDERR, "invalid compatibility manifest\n");
            exit(2);
        }
        foreach (($doc["acg_faka"] ?? []) as $row) {
            if (($row["version"] ?? null) !== $argv[2]) {
                continue;
            }
            if ($argv[3] === "commit") {
                echo (string)($row["commit"] ?? "");
                exit;
            }
            $value = $row["files"][$argv[3]]
                ?? $row["payment_files"][$argv[3]]
                ?? "";
            echo is_string($value) ? $value : "";
            exit;
        }
        exit(3);
    ' "$PIKA_COMPAT_FILE" "$version" "$field"
}

pika_site_state_dir() {
    local root="$1" canonical digest
    canonical="$(pika_real_dir "$root")" || return 1
    digest="$(pika_sha256_string "$canonical")" || return 1
    [[ "$digest" =~ ^[a-f0-9]{64}$ ]] || pika_die "invalid site identity digest"
    printf '%s/%s\n' "$PIKA_STATE_SITES_DIR" "$digest"
}

pika_install_receipt_path() {
    local state_dir
    state_dir="$(pika_site_state_dir "$1")" || return 1
    printf '%s/install-receipt.json\n' "$state_dir"
}

pika_bridge_files() {
    printf '%s\n' \
        'kernel/Kernel.php' \
        'kernel/Helper.php' \
        'app/Controller/Admin/Api/Config.php' \
        'app/View/Admin/Footer.html' \
        'assets/common/js/editor/markdown/editorv2.js'
}

pika_payment_compat_files() {
    printf '%s\n' \
        'app/Consts/Pay.php' \
        'app/Controller/User/Api/Order.php' \
        'app/Controller/User/Api/Recharge.php' \
        'app/Controller/User/Api/RechargeNotification.php' \
        'app/Controller/User/Index.php' \
        'app/Controller/User/Personal.php' \
        'app/Controller/User/Recharge.php' \
        'app/Entity/PayEntity.php' \
        'app/Pay/Base.php' \
        'app/Pay/Pay.php' \
        'app/Pay/Signature.php' \
        'app/Service/Bind/Order.php' \
        'app/Service/Bind/Recharge.php' \
        'app/Service/Order.php' \
        'app/Service/Recharge.php' \
        'app/Util/PayConfig.php' \
        'app/Util/PayFactory.php' \
        'app/Util/PayProfile.php'
}

pika_compat_files() {
    printf '%s\n' 'config/app.php'
    pika_bridge_files
    pika_payment_compat_files
}

pika_assert_no_symlinks() {
    local root="$1"
    if find "$root" -type l -print -quit | grep -q .; then
        pika_die "release payload contains a symbolic link: $root"
    fi
}

pika_assert_root_owned_path() {
    local path="$1" label="$2" metadata owner group mode mode_value
    [[ ! -L "$path" && ( -f "$path" || -d "$path" ) ]] \
        || pika_die "$label is missing, unsupported, or a symbolic link: $path"
    metadata="$(stat -c '%u:%g:%a' -- "$path")" \
        || pika_die "unable to inspect $label: $path"
    IFS=: read -r owner group mode <<<"$metadata"
    [[ "$owner" == "0" && "$group" == "0" && "$mode" =~ ^[0-7]{3,4}$ ]] \
        || pika_die "$label must be root:root with a valid mode: $path"
    mode_value=$((8#$mode))
    (( (mode_value & 0022) == 0 )) \
        || pika_die "$label must not be group/world writable: $path"
    pika_assert_no_extended_acl "$path" "$label"
}

pika_assert_root_directory_chain() {
    local target="$1" label="$2" cursor='/' segment
    local -a segments=()
    [[ "$target" = /* && ! "$target" =~ [[:cntrl:]] ]] \
        || pika_die "$label path must be absolute and contain no control characters"
    IFS=/ read -r -a segments <<<"${target#/}"
    for segment in "${segments[@]}"; do
        [[ -n "$segment" && "$segment" != '.' && "$segment" != '..' ]] \
            || pika_die "$label path is not canonical: $target"
    done
    pika_assert_root_owned_path "$cursor" "$label ancestor"
    for segment in "${segments[@]}"; do
        [[ "$cursor" == '/' ]] && cursor="/$segment" || cursor="$cursor/$segment"
        if [[ ! -e "$cursor" && ! -L "$cursor" ]]; then
            break
        fi
        [[ -d "$cursor" ]] || pika_die "$label ancestor is not a directory: $cursor"
        pika_assert_root_owned_path "$cursor" "$label ancestor"
    done
}

pika_assert_trusted_release_tree() {
    ((EUID == 0)) || return 0
    local release_root entry
    release_root="$(cd -P -- "$PIKA_REPO_DIR" && pwd)"
    [[ "$release_root" == "$PIKA_REPO_DIR" ]] \
        || pika_die "release root is not canonical: $PIKA_REPO_DIR"
    pika_assert_root_directory_chain "$release_root" 'release root'
    for entry in \
        "$release_root/compatibility.json" \
        "$release_root/release.json" \
        "$release_root/bridge" \
        "$release_root/manager" \
        "$release_root/extensions" \
        "$release_root/themes" \
        "$release_root/payment-adapters" \
        "$release_root/scripts" \
        "$release_root/packaging/systemd" \
        "$release_root/packaging/nginx" \
        "$release_root/packaging/logrotate"; do
        [[ -e "$entry" && ! -L "$entry" ]] \
            || pika_die "trusted release path is missing or unsafe: $entry"
        while IFS= read -r -d '' release_path; do
            pika_assert_root_owned_path "$release_path" 'trusted release path'
        done < <(find -P "$entry" -print0)
    done
}

pika_assert_outside_site() {
    local site_root="$1" path="$2" resolved_parent lexical_path
    [[ "$path" = /* && ! "$path" =~ [[:cntrl:]] ]] \
        || pika_die "backup directory must be absolute and contain no control characters"
    lexical_path="${path%/}"
    [[ -n "$lexical_path" ]] || lexical_path=/
    case "$lexical_path/" in
        "$site_root/"*) pika_die "backup directory must be outside the site root" ;;
    esac
    case "$site_root/" in
        "$lexical_path/"*) pika_die "site root must be outside the backup directory" ;;
    esac
    pika_assert_root_directory_chain "$path" 'backup directory'
    mkdir -p -- "$path"
    resolved_parent="$(cd -P -- "$path" && pwd)"
    [[ "${path%/}" == "$resolved_parent" ]] \
        || pika_die "backup directory must be an exact canonical path"
    pika_assert_root_directory_chain "$resolved_parent" 'backup directory'
    case "$resolved_parent/" in
        "$site_root/"*) pika_die "backup directory must be outside the site root" ;;
    esac
    case "$site_root/" in
        "$resolved_parent/"*) pika_die "site root must be outside the backup directory" ;;
    esac
}
