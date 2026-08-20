#!/usr/bin/env bash

set -Eeuo pipefail

usage() {
  cat <<'EOF'
Usage: update-catalog.sh [options] GITHUB_REPOSITORY

Create or update catalog entries from the latest GitHub release. Two asset
naming conventions are recognized; a release is scanned for the first, and
only if none match, for the second:

  NAME-vVERSION-mariadbMAJOR.MINOR-OS-ARCH.tar.gz   (a tar.gz archive)
  NAME-VERSION-mariadbMAJOR.MINOR-OS.so             (a standalone .so file)

OS is a free-form target tag such as "linux", "el8", or "ubuntu24.04"; each
distinct OS (and, for the archive form, ARCH) for the same MariaDB version
becomes its own catalog entry. The standalone-.so form has no architecture in
its name, so --architecture must be passed (or it defaults to x86_64); the
soname installed on the server defaults to NAME.so, downloaded as-is with no
archive to unpack.

Options:
  -c, --catalog FILE       Catalog to create/update (default: catalog.json)
      --name NAME          Catalog plugin name (default: asset name with - -> _)
      --soname FILE        Select this .so when an archive contains several,
                           or override the installed filename for a
                           standalone .so (default: NAME.so)
      --architecture ARCH  Architecture for standalone .so assets, which
                           don't encode it in the filename (default: x86_64)
      --plugin-types TEXT  Override inferred types, e.g. "FUNCTION"
      --license TEXT       Override GitHub's SPDX license
      --maturity TEXT      Override inferred maturity (prerelease/0.x -> beta)
      --description TEXT   Override the GitHub repository description
      --author TEXT        Override the detected author (default: repository owner)
      --dependencies TEXT  Catalog dependency message
      --message TEXT       Message displayed after installation
      --sign-key FILE      Sign the finished catalog with this Minisign key
      --sign-password-stdin
                           Read the Minisign key password from standard input
      --dry-run            Detect and print the resulting entries as a JSON
                           array on stdout; never reads or writes --catalog
                           and never signs
      --latest-version-only
                           Print just the latest release version on stdout
                           and exit; skips assets, source inspection, and
                           the repository API call entirely (fast, for
                           checking many repositories for updates)
  -h, --help               Show this help

GITHUB_REPOSITORY may be https://github.com/OWNER/REPO or OWNER/REPO.
Set GITHUB_TOKEN to raise GitHub API limits or access a private repository.
EOF
}

die() { printf 'error: %s\n' "$*" >&2; exit 1; }
note() { printf '%s\n' "$*" >&2; }
need() { command -v "$1" >/dev/null 2>&1 || die "required command not found: $1"; }

catalog=catalog.json
name_override=
soname_override=
architecture_override=
types_override=
license_override=
maturity_override=
description_override=
author_override=
dependencies=
install_message=
sign_key=
sign_password_stdin=0
dry_run=0
latest_version_only=0

while (($#)); do
  case "$1" in
    -c|--catalog) (($# >= 2)) || die "$1 requires a value"; catalog=$2; shift 2 ;;
    --name) (($# >= 2)) || die "$1 requires a value"; name_override=$2; shift 2 ;;
    --soname) (($# >= 2)) || die "$1 requires a value"; soname_override=$2; shift 2 ;;
    --architecture) (($# >= 2)) || die "$1 requires a value"; architecture_override=$2; shift 2 ;;
    --plugin-types) (($# >= 2)) || die "$1 requires a value"; types_override=$2; shift 2 ;;
    --license) (($# >= 2)) || die "$1 requires a value"; license_override=$2; shift 2 ;;
    --maturity) (($# >= 2)) || die "$1 requires a value"; maturity_override=$2; shift 2 ;;
    --description) (($# >= 2)) || die "$1 requires a value"; description_override=$2; shift 2 ;;
    --author) (($# >= 2)) || die "$1 requires a value"; author_override=$2; shift 2 ;;
    --dependencies) (($# >= 2)) || die "$1 requires a value"; dependencies=$2; shift 2 ;;
    --message) (($# >= 2)) || die "$1 requires a value"; install_message=$2; shift 2 ;;
    --sign-key) (($# >= 2)) || die "$1 requires a value"; sign_key=$2; shift 2 ;;
    --sign-password-stdin) sign_password_stdin=1; shift ;;
    --dry-run) dry_run=1; shift ;;
    --latest-version-only) latest_version_only=1; shift ;;
    -h|--help) usage; exit 0 ;;
    --) shift; break ;;
    -*) die "unknown option: $1" ;;
    *) break ;;
  esac
done

(($# == 1)) || { usage >&2; exit 2; }
repository_input=${1%/}
repository_input=${repository_input%/releases}
repository_input=${repository_input%.git}
case "$repository_input" in
  https://github.com/*) github_repo=${repository_input#https://github.com/} ;;
  *) github_repo=$repository_input ;;
esac
[[ "$github_repo" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] ||
  die "expected https://github.com/OWNER/REPO or OWNER/REPO"
[[ -z "$architecture_override" || "$architecture_override" =~ ^[A-Za-z0-9_]+$ ]] ||
  die "unsafe architecture: $architecture_override"

for command in curl jq tar sha256sum mktemp mv; do need "$command"; done
[[ -z "$sign_key" ]] || { need minisign; [[ -r "$sign_key" ]] || die "cannot read signing key: $sign_key"; }

work_dir=$(mktemp -d "${TMPDIR:-/tmp}/lefred-catalog.XXXXXX")
output_tmp=
cleanup() {
  [[ -z "$output_tmp" || ! -e "$output_tmp" ]] || rm -f -- "$output_tmp"
  rm -rf -- "$work_dir"
}
trap cleanup EXIT

curl_args=(-fsSL --connect-timeout 8 --max-time 20 --retry 3
  -H 'Accept: application/vnd.github+json'
  -H 'X-GitHub-Api-Version: 2022-11-28')
if [[ -n "${GITHUB_TOKEN:-}" ]]; then
  curl_args+=(-H "Authorization: Bearer ${GITHUB_TOKEN}")
fi

api_base="https://api.github.com/repos/$github_repo"
curl "${curl_args[@]}" "$api_base/releases/latest" -o "$work_dir/release.json"

jq -e '.draft == false and (.assets | type == "array")' "$work_dir/release.json" >/dev/null ||
  die "GitHub did not return a usable latest release"

tag=$(jq -r '.tag_name' "$work_dir/release.json")
version=${tag#v}
[[ "$version" =~ ^[0-9]+([.][0-9A-Za-z]+)*([-+][0-9A-Za-z.-]+)?$ ]] ||
  die "cannot derive a safe plugin version from release tag: $tag"

if ((latest_version_only)); then
  printf '%s\n' "$version"
  exit 0
fi

curl "${curl_args[@]}" "$api_base" -o "$work_dir/repository.json"

description=$description_override
[[ -n "$description" ]] || description=$(jq -r '.description // ""' "$work_dir/repository.json")
license=$license_override
[[ -n "$license" ]] || license=$(jq -r '.license.spdx_id // "unknown"' "$work_dir/repository.json")
maturity=$maturity_override

# Inspect the source associated with the exact release. MariaDB retains the
# MYSQL_* names for the original plugin types and uses MariaDB_* for newer
# types, so both declaration families must be recognized.
plugin_types=$types_override
source_url=$(jq -r '.tarball_url' "$work_dir/release.json")
curl "${curl_args[@]}" "$source_url" -o "$work_dir/source.tar.gz"
mkdir "$work_dir/source"
tar -xzf "$work_dir/source.tar.gz" -C "$work_dir/source"
declarations=$(grep -RhoE '(MYSQL|MariaDB)_[A-Z_]+_PLUGIN' "$work_dir/source" \
  --include='*.c' --include='*.cc' --include='*.cpp' --include='*.h' 2>/dev/null || true)

if [[ -z "$plugin_types" ]]; then
  types=()
  grep -q 'MYSQL_STORAGE_ENGINE_PLUGIN' <<<"$declarations" && types+=("STORAGE ENGINE")
  grep -q 'MYSQL_FTPARSER_PLUGIN' <<<"$declarations" && types+=("FULLTEXT PARSER")
  grep -q 'MYSQL_DAEMON_PLUGIN' <<<"$declarations" && types+=("DAEMON")
  grep -q 'MYSQL_INFORMATION_SCHEMA_PLUGIN' <<<"$declarations" && types+=("INFORMATION SCHEMA")
  grep -q 'MYSQL_AUDIT_PLUGIN' <<<"$declarations" && types+=("AUDIT")
  grep -q 'MYSQL_REPLICATION_PLUGIN' <<<"$declarations" && types+=("REPLICATION")
  grep -q 'MYSQL_AUTHENTICATION_PLUGIN' <<<"$declarations" && types+=("AUTHENTICATION")
  grep -q 'MariaDB_PASSWORD_VALIDATION_PLUGIN' <<<"$declarations" && types+=("PASSWORD VALIDATION")
  grep -q 'MariaDB_ENCRYPTION_PLUGIN' <<<"$declarations" && types+=("ENCRYPTION")
  grep -q 'MariaDB_DATA_TYPE_PLUGIN' <<<"$declarations" && types+=("DATA TYPE")
  grep -q 'MariaDB_FUNCTION_PLUGIN' <<<"$declarations" && types+=("FUNCTION")
  ((${#types[@]})) || die "could not infer plugin types; pass --plugin-types"
  plugin_types=${types[0]}
  for ((type_index=1; type_index < ${#types[@]}; ++type_index)); do
    plugin_types+=", ${types[type_index]}"
  done
fi

if [[ -z "$maturity" ]]; then
  maturity_declarations=$(grep -RhoE 'MariaDB_PLUGIN_MATURITY_(UNKNOWN|EXPERIMENTAL|ALPHA|BETA|GAMMA|STABLE)' \
    "$work_dir/source" --include='*.c' --include='*.cc' --include='*.cpp' --include='*.h' 2>/dev/null || true)
  for maturity_level in unknown experimental alpha beta gamma stable; do
    if grep -q "MariaDB_PLUGIN_MATURITY_${maturity_level^^}" <<<"$maturity_declarations"; then
      maturity=$maturity_level
      break
    fi
  done
  if [[ -z "$maturity" ]]; then
    if [[ $(jq -r '.prerelease' "$work_dir/release.json") == true || "$version" == 0.* ]]; then
      maturity=beta
    else
      maturity=stable
    fi
  fi
fi

if [[ -z "$license_override" && "$license" == unknown ]] &&
   grep -Rqs 'PLUGIN_LICENSE_GPL' "$work_dir/source"; then
  license=GPL
fi

author=$author_override
[[ -n "$author" ]] || author=$(jq -r '.owner.login // ""' "$work_dir/repository.json")
last_release_at=$(jq -r '.published_at // ""' "$work_dir/release.json")

# Best-effort: the latest commit on the default branch. Never fails the import.
last_commit_at=
default_branch=$(jq -r '.default_branch // "main"' "$work_dir/repository.json")
if curl "${curl_args[@]}" "$api_base/commits/$(printf '%s' "$default_branch" | jq -sRr '@uri')" \
     -o "$work_dir/commit.json" 2>/dev/null; then
  last_commit_at=$(jq -r '.commit.committer.date // .commit.author.date // ""' "$work_dir/commit.json" 2>/dev/null || true)
fi

if ((dry_run)); then
  # A preview never reads the real catalog: the output below is exactly the
  # set of entries this run would add or update, not the whole catalog.
  printf '{"schema_version":1,"plugins":[]}\n' >"$work_dir/catalog.json"
elif [[ -e "$catalog" ]]; then
  jq -e '.schema_version == 1 and (.plugins | type == "array")' "$catalog" >/dev/null ||
    die "$catalog is not a schema-version 1 catalog"
  cp -- "$catalog" "$work_dir/catalog.json"
else
  printf '{"schema_version":1,"plugins":[]}\n' >"$work_dir/catalog.json"
fi

mapfile -t assets < <(jq -r '.assets[] |
  select(.name | test("-mariadb[0-9]+\\.[0-9]+-[A-Za-z0-9_.]+-[A-Za-z0-9_]+\\.tar\\.gz$")) |
  [.name, .browser_download_url] | @tsv' "$work_dir/release.json")
asset_format=tar.gz
if ((${#assets[@]} == 0)); then
  mapfile -t assets < <(jq -r '.assets[] |
    select(.name | test("-mariadb[0-9]+\\.[0-9]+-[A-Za-z0-9_.]+\\.so$")) |
    [.name, .browser_download_url] | @tsv' "$work_dir/release.json")
  asset_format=so
fi
((${#assets[@]})) || die "latest release $tag has no matching tar.gz or standalone .so assets"

for asset_record in "${assets[@]}"; do
  IFS=$'\t' read -r asset_name download_url <<<"$asset_record"

  if [[ "$asset_format" == tar.gz ]]; then
    if [[ ! "$asset_name" =~ ^(.+)-v?${version//./\\.}-mariadb([0-9]+\.[0-9]+)-([A-Za-z0-9_.]+)-([A-Za-z0-9_]+)\.tar\.gz$ ]]; then
      die "asset does not match release version and naming contract: $asset_name"
    fi
    asset_stem=${BASH_REMATCH[1]}
    mariadb_version=${BASH_REMATCH[2]}
    os=${BASH_REMATCH[3]}
    architecture=${BASH_REMATCH[4]}
  else
    if [[ ! "$asset_name" =~ ^(.+)-v?${version//./\\.}-mariadb([0-9]+\.[0-9]+)-([A-Za-z0-9_.]+)\.so$ ]]; then
      die "asset does not match release version and naming contract: $asset_name"
    fi
    asset_stem=${BASH_REMATCH[1]}
    mariadb_version=${BASH_REMATCH[2]}
    os=${BASH_REMATCH[3]}
    architecture=${architecture_override:-x86_64}
  fi
  plugin_name=${name_override:-${asset_stem//-/_}}
  [[ "$plugin_name" =~ ^[A-Za-z0-9_-]+$ ]] || die "unsafe plugin name: $plugin_name"

  downloaded="$work_dir/$asset_name"
  curl "${curl_args[@]}" "$download_url" -o "$downloaded"
  sha256=$(sha256sum "$downloaded" | awk '{print $1}')

  if [[ "$asset_format" == tar.gz ]]; then
    mapfile -t members < <(tar -tzf "$downloaded" | grep -E '/lib/mariadb/plugin/[^/]+\.so$' || true)
    ((${#members[@]})) || die "$asset_name contains no lib/mariadb/plugin/*.so"
    archive_member=
    if [[ -n "$soname_override" ]]; then
      for member in "${members[@]}"; do
        [[ "${member##*/}" == "$soname_override" ]] && archive_member=$member
      done
      [[ -n "$archive_member" ]] || die "$asset_name does not contain $soname_override"
    elif ((${#members[@]} == 1)); then
      archive_member=${members[0]}
    else
      die "$asset_name contains multiple plugins; select one with --soname"
    fi
    soname=${archive_member##*/}
    archive_type_value=tar.gz
  else
    # A standalone .so release asset: no archive to unpack, the download is the
    # plugin file itself, installed under a clean name rather than the asset's
    # version/MariaDB/OS-decorated filename.
    archive_member=
    soname=${soname_override:-${plugin_name}.so}
    [[ "$soname" =~ ^[A-Za-z0-9_.+-]+\.so$ ]] || die "unsafe soname: $soname"
    archive_type_value=
  fi

  entry=$(jq -n \
    --arg name "$plugin_name" \
    --arg repository "https://github.com/$github_repo/releases" \
    --arg version "$version" \
    --arg mariadb_version "$mariadb_version" \
    --arg architecture "$architecture" \
    --arg os "$os" \
    --arg soname "$soname" \
    --arg download_url "$download_url" \
    --arg sha256 "$sha256" \
    --arg archive_type "$archive_type_value" \
    --arg archive_member "$archive_member" \
    --arg plugin_types "$plugin_types" \
    --arg license "$license" \
    --arg maturity "$maturity" \
    --arg description "$description" \
    --arg author "$author" \
    --arg last_release_at "$last_release_at" \
    --arg last_commit_at "$last_commit_at" \
    --arg dependencies "$dependencies" \
    --arg message "$install_message" \
    '{name:$name, repository:$repository, version:$version,
      mariadb_version:$mariadb_version, architecture:$architecture,
      soname:$soname, download_url:$download_url, sha256:$sha256,
      plugin_types:$plugin_types, license:$license, maturity:$maturity,
      description:$description}
     + (if $archive_type == "" then {} else {archive_type:$archive_type} end)
     + (if $archive_member == "" then {} else {archive_member:$archive_member} end)
     + (if $os == "" then {} else {os:$os} end)
     + (if $author == "" then {} else {author:$author} end)
     + (if $last_release_at == "" then {} else {last_release_at:$last_release_at} end)
     + (if $last_commit_at == "" then {} else {last_commit_at:$last_commit_at} end)
     + (if $dependencies == "" then {} else {dependencies:$dependencies} end)
     + (if $message == "" then {} else {message:$message} end)')

  # An existing entry is replaced by this one when its name/MariaDB
  # version/architecture/soname match and either its OS matches too, or it
  # predates OS tracking (no OS recorded at all) and is being claimed by
  # whichever OS-tagged asset is processed first. Any other existing OS
  # variant for the same plugin is left untouched.
  jq --argjson entry "$entry" '
    .plugins = ([.plugins[] |
      select(.name != $entry.name or
             .mariadb_version != $entry.mariadb_version or
             .architecture != $entry.architecture or
             .soname != $entry.soname or
             ((.os // "") != "" and (.os // "") != ($entry.os // "")))] + [$entry])
    | .plugins |= sort_by([(.name | ascii_downcase), .name,
                           .mariadb_version, .architecture, (.os // ""), .soname])' \
    "$work_dir/catalog.json" >"$work_dir/catalog.next.json"
  mv -- "$work_dir/catalog.next.json" "$work_dir/catalog.json"
  note "detected $plugin_name $version for MariaDB $mariadb_version $os/$architecture"
done

if ((dry_run)); then
  jq -c '.plugins' "$work_dir/catalog.json"
  note "dry run: ${#assets[@]} release asset(s) processed, catalog untouched"
  exit 0
fi

catalog_dir=$(dirname -- "$catalog")
catalog_base=$(basename -- "$catalog")
mkdir -p -- "$catalog_dir"
output_tmp=$(mktemp "$catalog_dir/.${catalog_base}.tmp.XXXXXX")
jq --indent 2 . "$work_dir/catalog.json" >"$output_tmp"
chmod 0644 "$output_tmp"
mv -- "$output_tmp" "$catalog"
output_tmp=
note "wrote $catalog (${#assets[@]} release asset(s) processed)"

if [[ -n "$sign_key" ]]; then
  if ((sign_password_stdin)); then
    IFS= read -r sign_password || die "could not read the Minisign password from standard input"
    minisign -S -s "$sign_key" -m "$catalog" -x "${catalog}.minisig" <<<"$sign_password"
    unset sign_password
  else
    minisign -S -s "$sign_key" -m "$catalog" -x "${catalog}.minisig"
  fi
  note "wrote ${catalog}.minisig"
elif [[ -e "${catalog}.minisig" ]]; then
  note "WARNING: ${catalog}.minisig is now stale; sign the updated catalog before publishing"
fi
