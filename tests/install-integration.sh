#!/bin/bash
set -euo pipefail

readonly SCRIPT_DIR="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly REPO_ROOT="$(cd -P -- "$SCRIPT_DIR/.." && pwd)"
readonly OFFICIAL_ROOT="${ACG_FAKA_OFFICIAL_ROOT:-$(cd -P -- "$REPO_ROOT/../faka-s0-next-private" && pwd)}"
readonly INSTALL_IMAGE="${PIKA_INSTALL_IMAGE:-acg-faka-php83-integration:20260828}"

fixture_sha256() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum | awk '{print $1}'
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 | awk '{print $1}'
    else
        printf 'ERROR: sha256sum or shasum is required\n' >&2
        return 1
    fi
}

git -C "$OFFICIAL_ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1 || {
    printf 'ERROR: official Acg-Faka fixture is not a Git checkout: %s\n' "$OFFICIAL_ROOT" >&2
    exit 1
}
fixture_commit="$(git -C "$OFFICIAL_ROOT" rev-parse HEAD^{commit})"
[[ "$fixture_commit" =~ ^[a-f0-9]{40}$ ]] || {
    printf 'ERROR: official fixture HEAD is not a full lowercase commit SHA\n' >&2
    exit 1
}

fixture_metadata="$(node -e '
    const fs = require("fs");
    const manifest = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
    const matches = manifest.acg_faka.filter(row => row.commit === process.argv[2]);
    if (matches.length !== 1 || !matches[0].version || !matches[0].files) process.exit(1);
    process.stdout.write(matches[0].version + "\n");
    for (const [path, digest] of Object.entries(matches[0].files)) {
        process.stdout.write(path + "\t" + digest + "\n");
    }
' "$REPO_ROOT/compatibility.json" "$fixture_commit")" || {
    printf 'ERROR: official fixture HEAD is not a uniquely supported Acg-Faka commit: %s\n' "$fixture_commit" >&2
    exit 1
}
fixture_version="${fixture_metadata%%$'\n'*}"
fixture_files="${fixture_metadata#*$'\n'}"
[[ "$fixture_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ && "$fixture_files" != "$fixture_metadata" ]] || {
    printf 'ERROR: official fixture compatibility metadata is invalid\n' >&2
    exit 1
}
case "$fixture_version:$fixture_commit" in
    3.6.4:4ff6ba9b28af8e529fc54f643eea5f8bf0bb02d6 | \
    3.7.0:6e48aab25f08f4d1fc0dda6af84fa6852fbb37e4 | \
    3.7.5:3430d0a881c4dccbdee1513473a402bcb1b1773d | \
    3.7.9:5120942d2c13ac900d614b09cfd6fbf672b62840)
        ;;
    *)
        printf 'ERROR: official fixture version/commit pair is not allowlisted\n' >&2
        exit 1
        ;;
esac
while IFS=$'\t' read -r relative expected_hash; do
    [[ -n "$relative" && "$expected_hash" =~ ^[a-f0-9]{64}$ ]] || {
        printf 'ERROR: official fixture compatibility entry is invalid: %s\n' "$relative" >&2
        exit 1
    }
    actual_hash="$(git -C "$OFFICIAL_ROOT" show "$fixture_commit:$relative" | fixture_sha256)"
    [[ "$actual_hash" == "$expected_hash" ]] || {
        printf 'ERROR: official fixture Git object hash mismatch: %s\n' "$relative" >&2
        exit 1
    }
done <<<"$fixture_files"
docker image inspect "$INSTALL_IMAGE" >/dev/null

mkdir -p "$SCRIPT_DIR/.work"
work_root="$(mktemp -d "$SCRIPT_DIR/.work/install-integration.XXXXXX")"
release_root=''
cleanup() {
    if [[ -n "${work_root:-}" \
        && "$(dirname -- "$work_root")" == "$SCRIPT_DIR/.work" \
        && "$(basename -- "$work_root")" == install-integration.* ]]; then
        rm -rf -- "$work_root"
    fi
    if [[ -n "${release_root:-}" \
        && "$(dirname -- "$release_root")" == "$SCRIPT_DIR/.work" \
        && "$(basename -- "$release_root")" == release-integration.* ]]; then
        rm -rf -- "$release_root"
    fi
}
trap cleanup EXIT
release_root="$(mktemp -d "$SCRIPT_DIR/.work/release-integration.XXXXXX")"

# Exercise exactly the Git-tracked working-tree bytes that are eligible for a
# release. This preserves intentional tracked dirty changes while excluding
# local untracked copies and tests/.work from the integration artifact.
while IFS= read -r -d '' relative; do
    source_path="$REPO_ROOT/$relative"
    target_path="$release_root/$relative"
    [[ -e "$source_path" || -L "$source_path" ]] || continue
    mkdir -p -- "$(dirname -- "$target_path")"
    cp -pP -- "$source_path" "$target_path"
done < <(git -C "$REPO_ROOT" ls-files -z)
for excluded in \
    "$release_root/README 2.md" \
    "$release_root/scripts/init-runtime 2.php" \
    "$release_root/tests/.work"; do
    [[ ! -e "$excluded" && ! -L "$excluded" ]] \
        || {
            printf 'ERROR: untracked local path entered integration release staging: %s\n' "$excluded" >&2
            exit 1
        }
done

for scenario in \
    success failure tamper restore-rollback \
    post-doctor-failure \
    writable-owner writable-group writable-world \
    writable-site-parent writable-assets; do
    mkdir -p "$work_root/$scenario/site/.git"
    git -c tar.umask=0022 -C "$OFFICIAL_ROOT" archive "$fixture_commit" | tar -xf - -C "$work_root/$scenario/site"
done

docker run --rm --init \
    --network none \
    --env "PIKA_TEST_ACG_FAKA_VERSION=$fixture_version" \
    --env "PIKA_TEST_ACG_FAKA_COMMIT=$fixture_commit" \
    --volume "$release_root:/release:ro" \
    --volume "$work_root:/fixture-seed:ro" \
    --tmpfs /fixture:rw,nosuid,nodev,size=2g \
    --tmpfs /opt/pika-test-space:rw,nosuid,nodev,exec,mode=0755,size=128m \
    --tmpfs /tmp:rw,nosuid,nodev,exec,mode=1777,size=128m \
    --tmpfs /var/lib/pika-local-extensions:rw,nosuid,nodev,size=128m,mode=0755,uid=0,gid=0 \
    "$INSTALL_IMAGE" \
    /release/tests/install-integration-container.sh
