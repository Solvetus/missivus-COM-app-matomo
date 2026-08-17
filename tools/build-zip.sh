#!/usr/bin/env bash
#
# Missivus — send Matomo email through the Microsoft Graph API.
# @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
#
# Builds dist/Missivus-<version>.zip: the plugin laid out exactly as Matomo expects it, with a
# single top-level Missivus/ folder, so it can be unzipped straight into plugins/ or uploaded
# through the Marketplace.
#
#     ./tools/build-zip.sh
#
# The file list below is an ALLOWLIST rather than a list of exclusions. A denylist is one forgotten
# pattern away from shipping a PEM, a .env, or a local config.ini.php; an allowlist can only ship
# what it was told to.

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

version="$(sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' plugin.json | head -1)"

if [ -z "$version" ]; then
  echo "Could not read \"version\" from plugin.json" >&2
  exit 1
fi

# What ships. Everything Matomo loads at runtime, plus the licence and the two documents an
# installer actually needs.
#
# vue/src is NOT optional despite containing only sources: Matomo's
# PluginUmdAssetFetcher::getUmdFileToUseForPlugin() gates on is_dir(vue/src) before it will serve
# vue/dist/Missivus.umd.min.js. Drop the folder and the "Send test email" button silently fails to
# render, with no error anywhere.
contents=(
  plugin.json
  LICENSE
  README.md
  CHANGELOG.md
  Missivus.php
  API.php
  SystemSettings.php
  Adapter
  Configuration
  Mail
  config
  lang
  libs
  stylesheets
  vue
  docs/INSTALL.md
  docs/SECURITY.md
)

# What deliberately does not ship: tests/, tools/, PLAN.md, docs/BRIEF.md, .git, .gitignore, dist/.

staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT

mkdir -p "$staging/Missivus"

for item in "${contents[@]}"; do
  if [ ! -e "$item" ]; then
    echo "Expected to package '$item' but it does not exist" >&2
    exit 1
  fi
  mkdir -p "$staging/Missivus/$(dirname "$item")"
  cp -R "$item" "$staging/Missivus/$(dirname "$item")/"
done

# Belt and braces: nothing credential-shaped, and no editor or OS litter, whatever the allowlist
# picked up from a working tree.
find "$staging" \( \
  -name '*.pem' -o -name '*.key' -o -name '*.pfx' -o -name '*.p12' -o \
  -name '*.crt' -o -name '*.cer' -o -name '.env*' -o -name 'config.ini.php' -o \
  -name '.DS_Store' -o -name '*.swp' \
\) -print -delete

archive="$repo_root/dist/Missivus-$version.zip"
mkdir -p "$repo_root/dist"
rm -f "$archive"

( cd "$staging" && zip -q -r -X "$archive" Missivus )

echo "Built $archive"
echo
unzip -l "$archive"
