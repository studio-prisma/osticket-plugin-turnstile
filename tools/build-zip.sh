#!/usr/bin/env bash
# Builds the install package: dist/turnstile-<version>.zip
#
#   ./tools/build-zip.sh            # version read from plugin.php
#   ./tools/build-zip.sh 1.0.1      # explicit version (must match plugin.php)
#
# The archive contains exactly the production files, nested in a `turnstile/`
# folder so it can be unzipped straight into `include/plugins/`.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MANIFEST_VERSION="$(grep -oE "'version'[[:space:]]*=>[[:space:]]*'[^']+'" plugin.php | head -1 | sed -E "s/.*'([^']+)'\$/\1/")"
VERSION="${1:-$MANIFEST_VERSION}"

if [ "$VERSION" != "$MANIFEST_VERSION" ]; then
  echo "Version mismatch: requested '$VERSION', plugin.php declares '$MANIFEST_VERSION'." >&2
  exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/turnstile/src"
cp plugin.php config.php class.TurnstilePlugin.php "$STAGE/turnstile/"
cp src/*.php "$STAGE/turnstile/src/"
cp LICENSE "$STAGE/turnstile/"

# Reject anything that must never ship.
if grep -rniE "secret_key[[:space:]]*=[[:space:]]*['\"][^'\"]" "$STAGE/turnstile" ; then
  echo "Refusing to build: literal secret found in staged files." >&2
  exit 1
fi

mkdir -p dist
rm -f "dist/turnstile-${VERSION}.zip"
( cd "$STAGE" && zip -qr "turnstile-${VERSION}.zip" turnstile )
mv "$STAGE/turnstile-${VERSION}.zip" "dist/"

echo "Built dist/turnstile-${VERSION}.zip"
unzip -l "dist/turnstile-${VERSION}.zip"
