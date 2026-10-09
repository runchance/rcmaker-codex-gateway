#!/usr/bin/env bash
# Install/update the latest stable Linux/macOS x86_64/ARM64 GitHub Release.
# Usage: curl -fsSL https://raw.githubusercontent.com/runchance/rcmaker-codex-gateway/master/.github/scripts/install.sh | bash
# Set RCMAKER_INSTALL_DIR, or use: bash install.sh /path/to/install
set -euo pipefail

fail() { printf 'Install failed: %s\n' "$*" >&2; exit 1; }
for tool in curl tar awk mktemp install uname cmp; do
    command -v "$tool" >/dev/null 2>&1 || fail "Required command not found: $tool"
done

case "$(uname -s)" in
    Linux) platform=linux; platform_name=Linux; hash_tool=sha256sum ;;
    Darwin) platform=macos; platform_name=macOS; hash_tool=shasum ;;
    *) fail 'This installer supports Linux and macOS. Use install.ps1 on Windows.' ;;
esac
command -v "$hash_tool" >/dev/null 2>&1 || fail "Required command not found: $hash_tool"
if [[ "$platform" == linux ]]; then
    command -v readlink >/dev/null 2>&1 || fail 'Required command not found: readlink'
fi
case "$(uname -m)" in
    x86_64|amd64) arch=x86_64 ;;
    aarch64|arm64) arch=aarch64 ;;
    *) fail 'Only x86_64 and ARM64 builds are currently published.' ;;
esac

# Read any first-install choice from the controlling terminal, never from the
# script's stdin: curl | bash must not consume the following script lines.
[[ "$#" -le 1 ]] || fail 'Usage: bash install.sh [install-directory]'
current_dir="$(pwd -P)"
install_dir="${1:-${RCMAKER_INSTALL_DIR:-}}"
if [[ -z "$install_dir" ]]; then
    if [[ -f "$current_dir/rcmaker-codex-gateway" ]]; then
        install_dir="$current_dir"
    else
        install_dir="$current_dir/rcmaker-codex-gateway"
        if [[ ! -f "$install_dir/rcmaker-codex-gateway" && ( -t 1 || -t 2 ) ]] \
            && { : </dev/tty; } 2>/dev/null; then
            printf 'Installation directory [%s]: ' "$install_dir" >/dev/tty
            selected_dir=''
            if IFS= read -r selected_dir </dev/tty && [[ -n "$selected_dir" ]]; then
                install_dir="$selected_dir"
            fi
        fi
    fi
fi
case "$install_dir" in
    '~') install_dir="$HOME" ;;
    '~/'*) install_dir="$HOME/${install_dir#\~/}" ;;
esac
[[ "$install_dir" == /* ]] || install_dir="$current_dir/$install_dir"

repository='https://github.com/runchance/rcmaker-codex-gateway'
latest="$(curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' \
    --output /dev/null --write-out '%{url_effective}' "$repository/releases/latest")" \
    || fail 'Cannot resolve the latest published stable GitHub Release.'
prefix="$repository/releases/tag/"
[[ "$latest" == "$prefix"* ]] || fail "Unexpected latest Release redirect: $latest"
tag="${latest#"$prefix"}"
[[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "Unexpected latest Release tag: $tag"

mkdir -p "$install_dir"
install_dir="$(cd -- "$install_dir" && pwd -P)"
target="$install_dir/rcmaker-codex-gateway"
[[ ! -e "$target" || ( -f "$target" && ! -L "$target" ) ]] \
    || fail "Installation target is not a regular executable file: $target"
stage="$(mktemp -d "$install_dir/.install.XXXXXXXX")"
cleanup_stage() {
    if [[ -f "$stage/rcmaker-codex-gateway.old" && ! -e "$target" ]]; then
        printf 'Recovery needed: the previous executable is preserved at %s\n' "$stage/rcmaker-codex-gateway.old" >&2
    else
        rm -rf -- "$stage"
    fi
}
trap cleanup_stage EXIT

asset="rcmaker-codex-gateway-$tag-$platform-$arch.tar.gz"
download="$repository/releases/download/$tag"
printf 'Downloading %s for %s %s into %s...\n' "$tag" "$platform_name" "$arch" "$install_dir"
curl --fail --silent --show-error --location --retry 3 --proto '=https' --proto-redir '=https' \
    --output "$stage/$asset" "$download/$asset" || fail "Cannot download $asset"
curl --fail --silent --show-error --location --retry 3 --proto '=https' --proto-redir '=https' \
    --output "$stage/SHA256SUMS" "$download/SHA256SUMS" || fail 'Cannot download SHA256SUMS'

verify_hash() {
    local name="$1" path="$2" expected count actual
    count="$(awk -v name="$name" '$2 == name { n++ } END { print n+0 }' "$stage/SHA256SUMS")"
    [[ "$count" == 1 ]] || fail "Missing or duplicate SHA256SUMS entry: $name"
    expected="$(awk -v name="$name" '$2 == name { print $1 }' "$stage/SHA256SUMS")"
    [[ "$expected" =~ ^[a-f0-9]{64}$ ]] || fail "Invalid SHA-256 for $name"
    if [[ "$hash_tool" == shasum ]]; then
        actual="$(shasum -a 256 < "$path")" || fail "Cannot calculate SHA-256: $name"
    else
        actual="$(sha256sum < "$path")" || fail "Cannot calculate SHA-256: $name"
    fi
    [[ "${actual%% *}" == "$expected" ]] || fail "SHA-256 verification failed: $name"
}
verify_hash "$asset" "$stage/$asset"

mkdir "$stage/extracted"
tar -xzf "$stage/$asset" -C "$stage/extracted" -- \
    'rcmaker-codex-gateway' 'LICENSE' 'NOTICE' 'THIRD_PARTY_NOTICES' \
    || fail 'The release archive is incomplete or invalid.'
tar -tzf "$stage/$asset" > "$stage/archive-members" || fail 'Cannot read release archive members.'
if awk '$0 == ".env.example" { found=1 } END { exit !found }' "$stage/archive-members"; then
    tar -xzf "$stage/$asset" -C "$stage/extracted" -- '.env.example' \
        || fail 'Could not extract .env.example'
fi
binary="$stage/extracted/rcmaker-codex-gateway"
[[ -f "$binary" && ! -L "$binary" ]] || fail 'Missing regular executable in the Release archive.'
verify_hash "rcmaker-codex-gateway-$tag-$platform-$arch" "$binary"

if [[ -f "$target" ]] && cmp -s "$target" "$binary"; then
    printf 'Already up to date: %s (%s)\n' "$tag" "$target"
    exit 0
fi

# Refuse to replace the executable of an active gateway. On macOS, inspect the
# exact file rather than its process name; another installation must not match.
if [[ "$platform" == linux ]]; then
    for process in /proc/[0-9]*/exe; do
        [[ -e "$process" ]] || continue
        running="$(readlink "$process" 2>/dev/null || true)"
        [[ "$running" != "$target" ]] || fail 'Gateway is running. Finish tasks and stop it before updating.'
    done
elif [[ -f "$target" ]]; then
    lsof_tool="$(command -v lsof || true)"
    [[ -n "$lsof_tool" ]] || { [[ ! -x /usr/sbin/lsof ]] || lsof_tool=/usr/sbin/lsof; }
    [[ -n "$lsof_tool" ]] || fail 'Cannot check whether the gateway is running: lsof is unavailable.'
    if "$lsof_tool" -t "$target" > "$stage/running-pids" 2> "$stage/running-error"; then
        [[ ! -s "$stage/running-pids" ]] || fail 'Gateway is running. Finish tasks and stop it before updating.'
    else
        lsof_status="$?"
        [[ "$lsof_status" == 1 && ! -s "$stage/running-error" && ! -s "$stage/running-pids" ]] \
            || fail 'Cannot confirm whether the gateway is running. Stop it before updating and check lsof.'
    fi
fi

for extra in .env.example LICENSE NOTICE THIRD_PARTY_NOTICES; do
    [[ "$extra" == '.env.example' && ! -e "$stage/extracted/$extra" ]] && continue
    [[ -f "$stage/extracted/$extra" && ! -L "$stage/extracted/$extra" ]] \
        || fail "Missing release file: $extra"
    [[ -e "$install_dir/$extra" ]] || cp "$stage/extracted/$extra" "$install_dir/$extra"
done

install -m 0755 "$binary" "$stage/rcmaker-codex-gateway.new"
if [[ -f "$target" ]]; then
    mv "$target" "$stage/rcmaker-codex-gateway.old"
fi
if ! mv "$stage/rcmaker-codex-gateway.new" "$target"; then
    if [[ -f "$stage/rcmaker-codex-gateway.old" ]]; then
        mv "$stage/rcmaker-codex-gateway.old" "$target"
    fi
    fail 'Could not install executable; old version restored.'
fi
if [[ -f "$stage/rcmaker-codex-gateway.old" ]]; then
    cp -p "$stage/rcmaker-codex-gateway.old" "$target.previous" \
        || printf 'Warning: update succeeded, but previous executable backup failed.\n' >&2
fi
printf 'Installed %s at %s\n' "$tag" "$target"
printf 'The gateway was not started. Start it and wait for the Ready message before starting/restarting Codex.\n'
