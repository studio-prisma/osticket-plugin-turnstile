#!/usr/bin/env bash
# Testlauf für das Turnstile-Plugin.
#   PHP_BIN=/pfad/zu/php ./run-all.sh
# Braucht ausgehendes HTTPS zu challenges.cloudflare.com (Cloudflare-Testkeys).
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(dirname "$HERE")"
PHP_BIN="${PHP_BIN:-php}"
export PLUGIN_DIR
rc=0

echo "### PHP: $("$PHP_BIN" -r 'echo PHP_VERSION;')"

echo; echo "### 1/4  Lint"
n=0
while IFS= read -r -d '' f; do
  n=$((n+1))
  "$PHP_BIN" -l "$f" >/dev/null || { echo "LINT FAIL: $f"; rc=1; }
done < <(find "$PLUGIN_DIR" -name '*.php' -not -path '*/tests/*' -print0)
[ $rc -eq 0 ] && echo "$n Dateien syntaktisch sauber"

echo; echo "### 2/4  Unit"
( cd "$HERE" && "$PHP_BIN" unit.php ) || rc=1

echo; echo "### 3/4  Fail-Mode (Endpoint auf Blackhole umgebogen)"
rm -rf "$HERE/failcopy"; mkdir -p "$HERE/failcopy"
cp -r "$PLUGIN_DIR/src" "$HERE/failcopy/"
sed -i "s|const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';|const ENDPOINT = 'https://192.0.2.1/turnstile/v0/siteverify';|" \
  "$HERE/failcopy/src/TurnstileVerifier.php"
( cd "$HERE" && "$PHP_BIN" failmode.php ) || rc=1
rm -rf "$HERE/failcopy"

echo; echo "### 4/4  Integration (php -S)"
( cd "$HERE" && PHP_BIN="$PHP_BIN" python3 integration.py ) || rc=1

echo; [ $rc -eq 0 ] && echo "GESAMT: grün" || echo "GESAMT: rot"
exit $rc
