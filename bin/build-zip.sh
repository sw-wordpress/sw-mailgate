#!/usr/bin/env bash
# Installable, allow-listed package; no tests, private keys or development vendor tree.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
NAME=sw-mailgate
mkdir "$STAGE/$NAME"
for path in src templates assets compat languages docs sw-mailgate.php uninstall.php README.md LICENSE; do
  if [ -e "$ROOT/$path" ]; then cp -R "$ROOT/$path" "$STAGE/$NAME/"; fi
done
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$STAGE/$NAME/"
# No runtime third-party packages exist. Composer's generated loader is the entire vendor payload.
# Abort if this assumption changes: introduce dependency prefixing before shipping dependencies.
php -r '$lock=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if(count($lock["packages"])!==0) {fwrite(STDERR,"Runtime dependencies require prefixing.\n"); exit(1);}' "$ROOT/composer.lock"
(cd "$STAGE/$NAME" && COMPOSER_VENDOR_DIR=vendor-prefixed composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader)
rm "$STAGE/$NAME/composer.json" "$STAGE/$NAME/composer.lock"
VERSION="$(php -r '$s=file_get_contents($argv[1]); preg_match("/Version:\\s+([0-9.]+)/",$s,$h); preg_match("/define\\( .SW_MAILGATE_VERSION., .([0-9.]+). \\);/",$s,$c); if(!isset($h[1],$c[1])||$h[1]!==$c[1])exit(1); echo $h[1];' "$ROOT/sw-mailgate.php")"
# ZIP starts from an empty file, never retaining removed entries from an old archive.
(cd "$STAGE" && zip -qr "$STAGE/package.zip" "$NAME")
cp "$STAGE/package.zip" "$OUT/$NAME-$VERSION.zip"
echo "$OUT/$NAME-$VERSION.zip"
