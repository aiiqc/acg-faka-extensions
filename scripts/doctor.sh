#!/bin/bash
set -euo pipefail

readonly PIKA_DOCTOR_TRUSTED_PATH='/usr/sbin:/usr/bin:/sbin:/bin'
if ((EUID == 0)); then
    PATH="$PIKA_DOCTOR_TRUSTED_PATH"
    export PATH
    builtin unalias -a 2>/dev/null || true
    while IFS= read -r imported_function; do
        builtin unset -f -- "$imported_function"
    done < <(builtin compgen -A function)
    builtin hash -r
    builtin readonly PATH
fi

PIKA_DOCTOR_SCRIPT_PATH="$(realpath -- "${BASH_SOURCE[0]}")" || {
    printf 'ERROR: unable to resolve doctor path\n' >&2
    exit 1
}
[[ -f "$PIKA_DOCTOR_SCRIPT_PATH" && ! -L "$PIKA_DOCTOR_SCRIPT_PATH" ]] || {
    printf 'ERROR: doctor path is not a canonical regular file\n' >&2
    exit 1
}
SCRIPT_DIR="$(cd -P -- "$(dirname -- "$PIKA_DOCTOR_SCRIPT_PATH")" && pwd)"

pika_doctor_bootstrap_assert_root_path() {
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
    pika_doctor_bootstrap_assert_root_path "$PIKA_DOCTOR_SCRIPT_PATH" 'doctor entrypoint'
    pika_doctor_bootstrap_assert_root_path "$SCRIPT_DIR/lib.sh" 'doctor library'
    bootstrap_cursor="$SCRIPT_DIR"
    while :; do
        pika_doctor_bootstrap_assert_root_path "$bootstrap_cursor" 'doctor release ancestor'
        [[ "$bootstrap_cursor" == / ]] && break
        bootstrap_cursor="$(dirname -- "$bootstrap_cursor")"
    done
fi
unset -f pika_doctor_bootstrap_assert_root_path
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
pika_assert_trusted_release_tree

site_arg=""
mode="preinstall"

while (($#)); do
    case "$1" in
        --site-root)
            (($# >= 2)) || pika_die "--site-root requires a value"
            site_arg="$2"
            shift 2
            ;;
        --installed)
            mode="installed"
            shift
            ;;
        --php)
            (($# >= 2)) || pika_die "--php requires a value"
            PIKA_PHP_BIN="$2"
            export PIKA_PHP_BIN
            shift 2
            ;;
        *)
            pika_die "unknown argument: $1"
            ;;
    esac
done

[[ -n "$site_arg" ]] || pika_die "usage: doctor.sh --site-root /absolute/acg-faka/path [--installed]"

site_root="$(pika_real_dir "$site_arg")"
pika_assert_safe_site_root "$site_root"
pika_require_command git
php_bin="$(pika_php)" || exit 1

version="$(pika_site_version "$site_root")" || pika_die "unsupported Acg-Faka config/app.php"
expected_commit="$(pika_compat_value "$version" commit)" || pika_die "unsupported Acg-Faka version: $version"
[[ -n "$expected_commit" ]] || pika_die "unsupported Acg-Faka version: $version"

actual_commit="$(git -C "$site_root" rev-parse HEAD)"
[[ "$actual_commit" == "$expected_commit" ]] || pika_die "unsupported commit: $actual_commit"

if [[ "$mode" == "preinstall" ]]; then
    while IFS= read -r relative; do
        expected="$(pika_compat_value "$version" "$relative")"
        actual="$(pika_sha256 "$site_root/$relative")"
        [[ -n "$expected" && "$actual" == "$expected" ]] || pika_die "compatibility hash mismatch: $relative"
    done < <(pika_compat_files)
else
    receipt_path="$(pika_install_receipt_path "$site_root")" || pika_die "unable to derive install receipt path"
    [[ -f "$receipt_path" && ! -L "$receipt_path" ]] || pika_die "protected install receipt is missing or unsafe"
    [[ -f "$site_root/local-extensions/registry.json" ]] || pika_die "trusted registry is missing"
    [[ -f "$site_root/local-extensions/bootstrap.php" ]] || pika_die "local bootstrap is missing"
    "$php_bin" "${SCRIPT_DIR}/verify-install.php" --site-root "$site_root"
    state_dir="$(pika_site_state_dir "$site_root")" \
        || pika_die "unable to derive external state directory"
    runtime_metadata="$(stat -c '%u:%g' -- "$state_dir/runtime")" \
        || pika_die "unable to inspect installed runtime identity"
    IFS=: read -r runtime_uid runtime_gid <<<"$runtime_metadata"
    pika_assert_isolated_web_identity "$runtime_uid" "$runtime_gid" 'installed site web identity'
    pika_assert_external_state_no_acl "$state_dir"
    pika_require_command setpriv
    setpriv_bin="$(type -P -- setpriv)"
    [[ -n "$setpriv_bin" ]] || pika_die "required command not found: setpriv"

    bep_origin_result="$( (cd "$site_root" && "$setpriv_bin" \
        --reuid "$runtime_uid" \
        --regid "$runtime_gid" \
        --clear-groups \
        --no-new-privs \
        -- "$php_bin" -r '
        try {
            $siteRoot = $argv[1] ?? "";
            $databaseConfigPath = $siteRoot . "/config/database.php";
            $database = require $databaseConfigPath;
            if (!is_array($database)) {
                throw new RuntimeException("database configuration is invalid");
            }
            if (($database["database"] ?? null) === "CHANGE_ME"
                || ($database["username"] ?? null) === "CHANGE_ME") {
                fwrite(STDOUT, "BEPUSDT_ORIGIN_PASS status=not_configured active_payments=0 profiles=0");
                exit(0);
            }

            require $siteRoot . "/kernel/Console.php";
            $payments = \App\Model\Pay::query()
                ->where("handle", "PikaBEpusdtAdapter")
                ->where("archived", 0)
                ->where(static function ($query): void {
                    $query->where("commodity", 1)->orWhere("recharge", 1);
                })
                ->get(["pay_config_id"]);
            if ($payments->isEmpty()) {
                fwrite(STDOUT, "BEPUSDT_ORIGIN_PASS status=inactive active_payments=0 profiles=0");
                exit(0);
            }

            $callbackDomain = (string)\App\Model\Config::get("callback_domain");
            $profileIds = [];
            foreach ($payments as $payment) {
                $profileId = (int)$payment->pay_config_id;
                if ($profileId <= 0) {
                    throw new RuntimeException("payment profile is missing");
                }
                $profileIds[$profileId] = true;
            }
            foreach (array_keys($profileIds) as $profileId) {
                $profile = \App\Util\PayProfile::raw("PikaBEpusdtAdapter", (int)$profileId);
                if (!is_array($profile)) {
                    throw new RuntimeException("payment profile is missing");
                }
                $settings = \App\Pay\PikaBEpusdtAdapter\Support\Settings::from($profile);
                if ($callbackDomain === ""
                    || !hash_equals($callbackDomain, $settings->merchantOrigin)
                    || !hash_equals($callbackDomain, $settings->checkoutOrigin)) {
                    throw new RuntimeException("payment origins do not match");
                }
            }
            fwrite(
                STDOUT,
                "BEPUSDT_ORIGIN_PASS status=active active_payments="
                    . count($payments) . " profiles=" . count($profileIds)
            );
        } catch (Throwable) {
            fwrite(STDERR, "BEpusdt origin verification failed\n");
            exit(1);
        }
    ' "$site_root") 2>&1)" || pika_die \
        "BEpusdt origin verification failed; callback_domain, merchant_origin and checkout_origin must be the same canonical HTTPS origin"
    [[ "$bep_origin_result" =~ ^BEPUSDT_ORIGIN_PASS\ status=(not_configured|inactive|active)\ active_payments=[0-9]+\ profiles=[0-9]+$ ]] \
        || pika_die "BEpusdt origin verification returned an unsafe result"
    pika_info "$bep_origin_result"
fi

php_version="$($php_bin -r 'echo PHP_VERSION;')"
php_version_id="$($php_bin -r 'echo PHP_VERSION_ID;')"
[[ "$php_version_id" =~ ^[0-9]+$ && "$php_version_id" -ge 80100 ]] \
    || pika_die "PHP 8.1 or newer is required, found $php_version"

required_extensions=(curl json mbstring openssl pdo_mysql posix)
if [[ "$version" == "3.7.9" ]]; then
    required_extensions+=(bcmath)
fi
for extension in "${required_extensions[@]}"; do
    "$php_bin" -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$extension" \
        || pika_die "required PHP extension is missing: $extension"
done

pika_info "DOCTOR_PASS version=$version commit=$actual_commit mode=$mode php=$php_version"
