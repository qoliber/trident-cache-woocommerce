#!/usr/bin/env bash
# The plugin installed the way a Composer-managed WordPress (Bedrock and the
# like) installs it: the site's composer.json requires it, composer/installers
# puts it in web/app/plugins/, its dependencies go to the SITE's vendor/, and
# the site's autoloader is loaded before WordPress. No release zip, no vendor/
# inside the plugin. check.php then loads the plugin's main file with just
# enough WordPress to see it boot on the site's autoloader.
#
# The plugin and the library come from this tree (the files a clone would have:
# tracked plus new, not ignored), through path repositories versioned 1.8.99 —
# as Packagist would serve the released tags. Everything else (PSR interfaces,
# composer/installers) comes from Packagist. Unprivileged containers.
set -euo pipefail
PLUGIN="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LIB="$(cd "$PLUGIN/../../../php-library" && pwd)"
U="$(id -u):$(id -g)"

SITE="$(mktemp -d)"
trap 'rm -rf "$SITE"' EXIT
snapshot() {  # snapshot <package dir> <target>
  mkdir -p "$2"
  ( cd "$1" && git ls-files -co --exclude-standard -z . | tar --null -T - -cf - ) | tar -xf - -C "$2"
}
snapshot "$PLUGIN" "$SITE/packages/plugin"
snapshot "$LIB" "$SITE/packages/library"

cat > "$SITE/composer.json" <<'JSON'
{
    "name": "example/shop",
    "type": "project",
    "repositories": [
        { "type": "path", "url": "packages/plugin", "options": { "symlink": false, "versions": { "qoliber/trident-cache-woocommerce": "1.8.99" } } },
        { "type": "path", "url": "packages/library", "options": { "symlink": false, "versions": { "qoliber/trident-php": "1.8.99" } } }
    ],
    "require": {
        "composer/installers": "^2.3",
        "qoliber/trident-cache-woocommerce": "^1.8"
    },
    "extra": {
        "installer-paths": { "web/app/plugins/{$name}/": ["type:wordpress-plugin"] }
    },
    "config": {
        "allow-plugins": { "composer/installers": true }
    }
}
JSON

docker run --rm -u "$U" -e COMPOSER_HOME=/tmp/composer -v "$SITE:/site" -w /site composer:2 \
  install --no-dev --no-interaction --no-progress
docker run --rm -u "$U" -v "$SITE:/site" -v "$PLUGIN/tests/composer-install/check.php:/check.php:ro" -w /site php:8.1-cli \
  php /check.php
