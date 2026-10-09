#!/usr/bin/env bash
# Install/update the latest stable Linux x86_64/aarch64 GitHub Release.
# Usage: curl -fsSL https://raw.githubusercontent.com/runchance/rcmaker-codex-gateway/master/.github/scripts/install.sh | bash
set -euo pipefail

fail() { printf 'Install failed: %s\n' "$*" >&2; exit 1; }
for tool in curl tar sha256sum awk mktemp install uname readlink grep cmp; do
    command -v "$tool" >/dev/null 2>&1 || fail "Required command not found: $tool"
done

case "$(uname -s)" in
    Linux) ;;
    *) fail 'This installer is for Linux. Use install.ps1 on Windows.' ;;
esac
case "$(uname -m)" in
    x86_64|amd64) arch=x86_64 ;;
    aarch64|arm64) arch=aarch64 ;;
    *) fail 'Only Linux x86_64 and ARM64 builds are currently published.' ;;
esac

repository='https://github.com/runchance/rcmaker-codex-gateway'
latest="$(curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' \
    --output /dev/null --write-out '%{url_effective}' "$repository/releases/latest")" \
    || fail 'Cannot resolve the latest published stable GitHub Release.'
prefix="$repository/releases/tag/"
[[ "$latest" == "$prefix"* ]] || fail "Unexpected latest Release redirect: $latest"
tag="${latest#"$prefix"}"
[[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "Unexpected latest Release tag: $tag"

install_dir="${RCMAKER_INSTALL_DIR:-$HOME/.local/share/rcmaker-codex-gateway}"
mkdir -p -- "$install_dir"
install_dir="$(cd -- "$install_dir" && pwd -P)"
target="$install_dir/rcmaker-codex-gateway"
stage="$(mktemp -d "$install_dir/.install.XXXXXXXX")"
cleanup_stage() {
    if [[ -f "$stage/rcmaker-codex-gateway.old" && ! -e "$target" ]]; then
        printf 'Recovery needed: the previous executable is preserved at %s\n' "$stage/rcmaker-codex-gateway.old" >&2
    else
        rm -rf -- "$stage"
    fi
}
trap cleanup_stage EXIT

asset="rcmaker-codex-gateway-$tag-linux-$arch.tar.gz"
download="$repository/releases/download/$tag"
printf 'Downloading %s for Linux %s...\n' "$tag" "$arch"
curl --fail --silent --show-error --location --retry 3 --proto '=https' --proto-redir '=https' \
    --output "$stage/$asset" "$download/$asset" || fail "Cannot download $asset"
curl --fail --silent --show-error --location --retry 3 --proto '=https' --proto-redir '=https' \
    --output "$stage/SHA256SUMS" "$download/SHA256SUMS" || fail 'Cannot download SHA256SUMS'

verify_hash() {
    local name="$1" path="$2" expected count
    count="$(awk -v name="$name" '$2 == name { n++ } END { print n+0 }' "$stage/SHA256SUMS")"
    [[ "$count" == 1 ]] || fail "Missing or duplicate SHA256SUMS entry: $name"
    expected="$(awk -v name="$name" '$2 == name { print $1 }' "$stage/SHA256SUMS")"
    [[ "$expected" =~ ^[a-f0-9]{64}$ ]] || fail "Invalid SHA-256 for $name"
    printf '%s  %s\n' "$expected" "$path" | sha256sum --check --status \
        || fail "SHA-256 verification failed: $name"
}
verify_hash "$asset" "$stage/$asset"

mkdir -- "$stage/extracted"
tar -xzf "$stage/$asset" -C "$stage/extracted" -- \
    'rcmaker-codex-gateway' 'LICENSE' 'NOTICE' 'THIRD_PARTY_NOTICES' \
    || fail 'The release archive is incomplete or invalid.'
if tar -tzf "$stage/$asset" | grep -Fx '.env.example' >/dev/null; then
    tar -xzf "$stage/$asset" -C "$stage/extracted" -- '.env.example' \
        || fail 'Could not extract .env.example'
fi
binary="$stage/extracted/rcmaker-codex-gateway"
[[ -f "$binary" && ! -L "$binary" ]] || fail 'Missing regular executable in the Release archive.'
verify_hash "rcmaker-codex-gateway-$tag-linux-$arch" "$binary"

if [[ -f "$target" ]] && cmp -s -- "$target" "$binary"; then
    printf 'Already up to date: %s (%s)\n' "$tag" "$target"
    exit 0
fi

# Refuse to replace the executable of an active gateway. Never kill it automatically.
for process in /proc/[0-9]*/exe; do
    [[ -e "$process" ]] || continue
    running="$(readlink "$process" 2>/dev/null || true)"
    [[ "$running" != "$target" ]] || fail 'Gateway is running. Finish tasks and stop it before updating.'
done

for extra in .env.example LICENSE NOTICE THIRD_PARTY_NOTICES; do
    [[ "$extra" == '.env.example' && ! -e "$stage/extracted/$extra" ]] && continue
    [[ -f "$stage/extracted/$extra" && ! -L "$stage/extracted/$extra" ]] \
        || fail "Missing release file: $extra"
    [[ -e "$install_dir/$extra" ]] || cp -- "$stage/extracted/$extra" "$install_dir/$extra"
done

install -m 0755 -- "$binary" "$stage/rcmaker-codex-gateway.new"
if [[ -f "$target" ]]; then
    mv -- "$target" "$stage/rcmaker-codex-gateway.old"
fi
if ! mv -- "$stage/rcmaker-codex-gateway.new" "$target"; then
    if [[ -f "$stage/rcmaker-codex-gateway.old" ]]; then
        mv -- "$stage/rcmaker-codex-gateway.old" "$target"
    fi
    fail 'Could not install executable; old version restored.'
fi
if [[ -f "$stage/rcmaker-codex-gateway.old" ]]; then
    cp -p -- "$stage/rcmaker-codex-gateway.old" "$target.previous" \
        || printf 'Warning: update succeeded, but previous executable backup failed.\n' >&2
fi
printf 'Installed %s at %s\n' "$tag" "$target"
printf 'The gateway was not started. Start it and wait for the Ready message before starting/restarting Codex.\n'
