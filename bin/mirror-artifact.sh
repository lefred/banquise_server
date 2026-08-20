#!/usr/bin/env bash

set -Eeuo pipefail

usage() {
  cat <<'EOF'
Usage: mirror-artifact.sh --dir ARTIFACTS_DIR --sha256 HEX URL

Downloads URL, verifies its SHA-256 matches --sha256, and installs it into
ARTIFACTS_DIR under a content-addressed filename (the hash plus the source
extension). Prints just that filename on stdout.

If a file already exists at that content-addressed path, nothing is
downloaded — its name already encodes the hash it was verified against when
it was written, so re-fetching it would be redundant.

Set GITHUB_TOKEN for private-repository release assets.
EOF
}

die() { printf 'error: %s\n' "$*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1 || die "required command not found: $1"; }

dir=
sha256=

while (($#)); do
  case "$1" in
    --dir) (($# >= 2)) || die "$1 requires a value"; dir=$2; shift 2 ;;
    --sha256) (($# >= 2)) || die "$1 requires a value"; sha256=$2; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    --) shift; break ;;
    -*) die "unknown option: $1" ;;
    *) break ;;
  esac
done

(($# == 1)) || { usage >&2; exit 2; }
url=$1
[[ -n "$dir" ]] || die "--dir is required"
[[ "$sha256" =~ ^[A-Fa-f0-9]{64}$ ]] || die "--sha256 must be 64 hexadecimal characters"
sha256=${sha256,,}
[[ "$url" =~ ^https:// ]] || die "URL must use HTTPS"

need curl
need sha256sum
need mktemp
need mv

case "$url" in
  *.tar.gz) ext=tar.gz ;;
  *.so) ext=so ;;
  *) ext=bin ;;
esac

mkdir -p -- "$dir"
destination="$dir/$sha256.$ext"
if [[ -e "$destination" ]]; then
  printf '%s\n' "$(basename -- "$destination")"
  exit 0
fi

# Download into the destination directory itself so the final mv is an atomic
# rename (same filesystem), never a partially-written file visible at its
# permanent name.
downloaded=$(mktemp "$dir/.mirror.XXXXXX")
cleanup() { [[ -z "$downloaded" || ! -e "$downloaded" ]] || rm -f -- "$downloaded"; }
trap cleanup EXIT

curl_args=(-fsSL --connect-timeout 8 --max-time 120 --retry 3)
if [[ -n "${GITHUB_TOKEN:-}" ]]; then
  curl_args+=(-H "Authorization: Bearer ${GITHUB_TOKEN}")
fi
curl "${curl_args[@]}" -o "$downloaded" "$url"

actual_sha256=$(sha256sum "$downloaded" | awk '{print $1}')
[[ "$actual_sha256" == "$sha256" ]] ||
  die "downloaded file does not match the expected SHA-256 (got $actual_sha256)"

chmod 0644 "$downloaded"
mv -- "$downloaded" "$destination"
downloaded=
printf '%s\n' "$(basename -- "$destination")"
