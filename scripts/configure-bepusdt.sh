#!/bin/bash
set -euo pipefail

export LC_ALL=C

readonly PIKA_BEPUSDT_TRUSTED_PATH='/usr/sbin:/usr/bin:/sbin:/bin'
if (( EUID == 0 )); then
    PATH="$PIKA_BEPUSDT_TRUSTED_PATH"
    export PATH
    builtin unalias -a 2>/dev/null || true
    while IFS= read -r imported_function; do
        builtin unset -f -- "$imported_function"
    done < <(builtin compgen -A function)
    builtin hash -r
    builtin readonly PATH
fi
set +x
umask 0077

PIKA_CONFIG_SCRIPT_PATH="$(realpath -- "${BASH_SOURCE[0]}")" || {
    printf 'ERROR: unable to resolve BEpusdt configurator path\n' >&2
    exit 1
}
[[ -f "$PIKA_CONFIG_SCRIPT_PATH" && ! -L "$PIKA_CONFIG_SCRIPT_PATH" ]] || {
    printf 'ERROR: BEpusdt configurator path is not a canonical regular file\n' >&2
    exit 1
}
SCRIPT_DIR="$(cd -P -- "$(dirname -- "$PIKA_CONFIG_SCRIPT_PATH")" && pwd)"

pika_bepusdt_bootstrap_assert_root_path() {
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

if (( EUID == 0 )); then
    pika_bepusdt_bootstrap_assert_root_path "$PIKA_CONFIG_SCRIPT_PATH" 'BEpusdt configurator entrypoint'
    pika_bepusdt_bootstrap_assert_root_path "$SCRIPT_DIR/lib.sh" 'configurator library'
    bootstrap_cursor="$SCRIPT_DIR"
    while :; do
        pika_bepusdt_bootstrap_assert_root_path "$bootstrap_cursor" 'configurator release ancestor'
        [[ "$bootstrap_cursor" == / ]] && break
        bootstrap_cursor="$(dirname -- "$bootstrap_cursor")"
    done
fi
unset -f pika_bepusdt_bootstrap_assert_root_path

# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
pika_assert_trusted_release_tree

site_arg=''
namespace=''
token_file=''
while (($#)); do
    case "$1" in
        --site-root)
            (($# >= 2)) || pika_die '--site-root requires a value'
            site_arg="$2"
            shift 2
            ;;
        --namespace)
            (($# >= 2)) || pika_die '--namespace requires a value'
            namespace="$2"
            shift 2
            ;;
        --token-file)
            (($# >= 2)) || pika_die '--token-file requires a value'
            token_file="$2"
            shift 2
            ;;
        *)
            pika_die "unknown argument: $1"
            ;;
    esac
done

[[ "$(id -u)" -eq 0 ]] || pika_die 'BEpusdt configurator must run as root (use sudo)'
[[ -n "$site_arg" && -n "$namespace" ]] \
    || pika_die 'usage: configure-bepusdt.sh --site-root /absolute/acg-faka/path --namespace SITE [--token-file /root/protected-file]'
[[ "$namespace" =~ ^[a-z0-9]{4,12}$ ]] \
    || pika_die '--namespace must be 4-12 lowercase letters or digits'

site_root="$(pika_real_dir "$site_arg")"
[[ "${site_arg%/}" == "$site_root" && ! -L "${site_arg%/}" ]] \
    || pika_die '--site-root must be the exact canonical non-symbolic-link path'
pika_assert_safe_site_root "$site_root"

php_bin="$(pika_php)" || exit 1
"$php_bin" "${SCRIPT_DIR}/verify-install.php" --site-root "$site_root"
state_dir="$(pika_site_state_dir "$site_root")" \
    || pika_die 'unable to derive external site state directory'
secret_directory="$state_dir/secrets"
[[ -d "$secret_directory" && ! -L "$secret_directory" \
    && "$(realpath -- "$secret_directory")" == "$secret_directory" ]] \
    || pika_die 'payment secret directory is missing or unsafe'
secret_metadata="$(stat -c '%u:%g:%a' -- "$secret_directory")" \
    || pika_die 'unable to inspect payment secret directory'
IFS=: read -r secret_uid secret_gid secret_mode <<<"$secret_metadata"
[[ "$secret_uid" == 0 && "$secret_gid" =~ ^[1-9][0-9]*$ && "$secret_mode" == 750 ]] \
    || pika_die 'payment secret directory owner or mode is unsafe'
runtime_metadata="$(stat -c '%u:%g:%a' -- "$state_dir/runtime")" \
    || pika_die 'unable to inspect site runtime identity'
IFS=: read -r runtime_uid runtime_gid runtime_mode <<<"$runtime_metadata"
[[ "$runtime_gid" == "$secret_gid" && "$runtime_mode" == 750 ]] \
    || pika_die 'site runtime and payment secret group identities do not match'
pika_assert_isolated_web_identity "$runtime_uid" "$runtime_gid" 'site web identity'
pika_assert_external_state_no_acl "$state_dir"

token_path="$secret_directory/bepusdt-token"
namespace_path="$secret_directory/bepusdt-namespace"
[[ ! -e "$token_path" && ! -L "$token_path" \
    && ! -e "$namespace_path" && ! -L "$namespace_path" ]] \
    || pika_die 'BEpusdt secrets already exist; this first-install command will not overwrite them'

while IFS= read -r -d '' existing_namespace_path; do
    [[ "$existing_namespace_path" != "$namespace_path" ]] \
        || continue
    existing_metadata="$(stat -c '%u:%a:%s:%h' -- "$existing_namespace_path")" \
        || pika_die 'unable to inspect an existing BEpusdt namespace'
    IFS=: read -r existing_uid existing_mode existing_size existing_links <<<"$existing_metadata"
    [[ "$existing_uid" == 0 && "$existing_mode" == 640 \
        && "$existing_size" =~ ^[0-9]+$ && "$existing_size" -ge 4 && "$existing_size" -le 12 \
        && "$existing_links" == 1 \
        && -f "$existing_namespace_path" && ! -L "$existing_namespace_path" ]] \
        || pika_die 'an existing BEpusdt namespace file is unsafe'
    existing_namespace="$(<"$existing_namespace_path")"
    [[ "$existing_namespace" != "$namespace" ]] \
        || pika_die "--namespace is already assigned to another installed site: $namespace"
done < <(find -P "$PIKA_STATE_SITES_DIR" -mindepth 3 -maxdepth 3 \
    -path '*/secrets/bepusdt-namespace' -print0)

token=''
if [[ -n "$token_file" ]]; then
    token_real="$(realpath -- "$token_file")" \
        || pika_die '--token-file does not resolve to a regular file'
    [[ "$token_file" == "$token_real" && -f "$token_real" && ! -L "$token_real" ]] \
        || pika_die '--token-file must be an exact canonical regular-file path'
    token_metadata="$(stat -c '%u:%g:%a:%s' -- "$token_real")" \
        || pika_die 'unable to inspect --token-file'
    IFS=: read -r token_uid token_gid token_mode token_size <<<"$token_metadata"
    [[ "$token_uid" == 0 && "$token_gid" == 0 && "$token_mode" =~ ^[0-7]{3,4}$ \
        && "$token_size" =~ ^[0-9]+$ ]] \
        || pika_die '--token-file must be owned by root:root'
    token_mode_value=$((8#$token_mode))
    (( (token_mode_value & 0077) == 0 && token_size >= 16 && token_size <= 256 )) \
        || pika_die '--token-file must be mode 0600/0400-compatible and contain 16-256 bytes'
    token="$(<"$token_real")"
else
    [[ -r /dev/tty && -w /dev/tty ]] \
        || pika_die 'interactive terminal required when --token-file is omitted'
    IFS= read -r -s -p 'BEpusdt API token: ' token </dev/tty
    printf '\n' >/dev/tty
fi

[[ ${#token} -ge 16 && ${#token} -le 256 \
    && "$token" =~ ^[[:graph:]]+$ ]] \
    || pika_die 'BEpusdt token must contain 16-256 printable non-space characters'

token_stage="$(mktemp "$secret_directory/.bepusdt-token.XXXXXX")"
namespace_stage="$(mktemp "$secret_directory/.bepusdt-namespace.XXXXXX")"
cleanup_staged_secrets() {
    rm -f -- "$token_stage" "$namespace_stage"
}
trap cleanup_staged_secrets EXIT INT TERM

printf '%s' "$token" > "$token_stage"
unset token
printf '%s' "$namespace" > "$namespace_stage"
chown "0:$secret_gid" -- "$token_stage" "$namespace_stage"
chmod 0640 -- "$token_stage" "$namespace_stage"
pika_assert_no_extended_acl "$token_stage" 'staged BEpusdt token'
pika_assert_no_extended_acl "$namespace_stage" 'staged BEpusdt namespace'
mv -- "$token_stage" "$token_path"
if ! mv -- "$namespace_stage" "$namespace_path"; then
    rm -f -- "$token_path"
    pika_die 'unable to publish BEpusdt namespace atomically'
fi
trap - EXIT INT TERM

if [[ ! -f "$token_path" || -L "$token_path" \
    || ! -f "$namespace_path" || -L "$namespace_path" \
    || "$(stat -c '%u:%g:%a:%h' -- "$token_path")" != "0:$secret_gid:640:1" \
    || "$(stat -c '%u:%g:%a:%h' -- "$namespace_path")" != "0:$secret_gid:640:1" ]]; then
    rm -f -- "$token_path" "$namespace_path"
    pika_die 'published BEpusdt secret files failed verification'
fi
pika_assert_external_state_no_acl "$state_dir"

pika_info "BEPUSDT_CONFIG_PASS site=$site_root namespace=$namespace"
