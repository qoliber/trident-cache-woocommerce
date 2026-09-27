#!/usr/bin/env bash
# Builds the release zip of the plugin: the plugin's own code plus
# qoliber/trident-php and its PSR dependencies, PREFIXED with Strauss into
# vendor-prefixed/ (namespace Qoliber\TridentWoo\Vendor\…).
#
# Why prefixed: WordPress loads every plugin's autoloader into one process.
# Two plugins bundling different versions of the same library (or of
# psr/http-message, which half the ecosystem ships) would load each other's
# classes and fail on someone's shop. Prefixed, this plugin's copy is its own.
#
#   bin/build-zip.sh [output directory]      (default: dist/)
#
# Needs PHP 8.1+, Composer and zip on the machine that builds; nothing is
# installed globally — Strauss is a dev dependency of the plugin.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
ROOT="$PWD"
SLUG=trident-cache-woocommerce
VERSION=$(sed -n "s/^define( 'TRIDENT_WOO_VERSION', '\(.*\)' );$/\1/p" "$SLUG.php")
[ -n "$VERSION" ] || { echo "cannot read TRIDENT_WOO_VERSION from $SLUG.php" >&2; exit 1; }
OUT="$(mkdir -p "${1:-dist}" && cd "${1:-dist}" && pwd)"

echo "== dependencies (dev: Strauss, the library via the path repository)"
composer install --no-interaction --no-progress --quiet
# A path repository is MIRRORED at install time and not refreshed when the
# library changes without a new commit — reinstall so the zip carries the
# library as it is in the tree now.
composer reinstall qoliber/trident-php --no-interaction --quiet

echo "== prefixing with Strauss"
rm -rf vendor-prefixed
vendor/bin/strauss --quiet 2>/dev/null || vendor/bin/strauss

STAGE_ROOT="$(mktemp -d)"
trap 'rm -rf "$STAGE_ROOT"' EXIT
STAGE="$STAGE_ROOT/$SLUG"
mkdir -p "$STAGE"
cp -r "$SLUG.php" uninstall.php readme.txt README.md includes assets vendor-prefixed "$STAGE/"
# The platform-neutral browser script ships with the library; the plugin serves
# its copy from assets/lib/ (Plugin::library_asset_url()).
mkdir -p "$STAGE/assets/lib"
cp -r vendor/qoliber/trident-php/assets/js "$STAGE/assets/lib/"

echo "== rewriting call sites to the prefixed namespace"
# `Qoliber\Trident\` (the library) — never `Qoliber\TridentWoo\` (the plugin).
grep -rlF 'Qoliber\Trident\' "$STAGE/includes" "$STAGE/$SLUG.php" "$STAGE/uninstall.php" \
  | xargs -r sed -i 's/Qoliber\\Trident\\/Qoliber\\TridentWoo\\Vendor\\Qoliber\\Trident\\/g'

echo "== checks"
LEFT=$(grep -rnF 'Qoliber\Trident\' "$STAGE/includes" "$STAGE/$SLUG.php" | grep -vF 'Qoliber\TridentWoo\Vendor\Qoliber\Trident\' || true)
if [ -n "$LEFT" ]; then
  echo "unprefixed library references left:" >&2; echo "$LEFT" >&2; exit 1
fi
while IFS= read -r f; do
  php -l "$f" >/dev/null || { echo "syntax error: $f" >&2; exit 1; }
done < <(find "$STAGE" -name '*.php')
# The bundled library must load under its prefix and nowhere else.
php -r '
  require $argv[1] . "/vendor-prefixed/autoload.php";
  if (!class_exists("Qoliber\\TridentWoo\\Vendor\\Qoliber\\Trident\\Delivery\\Purger")) { fwrite(STDERR, "prefixed Purger not autoloadable\n"); exit(1); }
  if (class_exists("Qoliber\\Trident\\Delivery\\Purger", false)) { fwrite(STDERR, "unprefixed class leaked\n"); exit(1); }
' "$STAGE"

ZIP="$OUT/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE_ROOT" && zip -qr "$ZIP" "$SLUG" -x '*/.DS_Store' )
echo "built $ZIP ($(du -h "$ZIP" | cut -f1))"
