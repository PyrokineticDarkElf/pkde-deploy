#!/usr/bin/env bash
#
# Verifies a deploy artifact (default: ./dist) before it is allowed to leave CI.
# Fails the build, with the offending paths listed, if the artifact:
#   - contains something that must never ship (secrets, per-server runtime state,
#     dev-only or publicly-embarrassing files, dev Composer packages), or
#   - is missing something the site cannot run without (a release without the
#     build manifest, or without a hand-written mu-plugin, is a broken site that
#     would otherwise only be noticed in production).
#
# Exact paths only: an earlier version searched for `.env` by name anywhere and
# tripped over a test fixture inside vendor/. Exact paths cannot do that.

set -uo pipefail

DIST="${1:-dist}"
fail=0

forbidden=(
    .env
    web/.env
    web/.htaccess
    web/.user.ini
    web/app/uploads
    web/app/cache
    web/app/w3tc-config
    web/app/wflogs
    web/app/ewww
    web/app/advanced-cache.php
    web/app/object-cache.php
    web/app/db.php
    web/demo-content.html
    web/nginx.conf
    web/wp/readme.html
    web/wp/license.txt
    web/app/themes/sage/node_modules
    web/app/themes/sage/package.json
    web/app/themes/sage/vite.config.js
    web/app/themes/sage/docs
    web/app/themes/sage/README.md
    web/app/mu-plugins/README.md
    web/app/mu-plugins/pkde-platform/README.md
    web/app/mu-plugins/pkde-platform/tests
    web/app/mu-plugins/pkde-platform/docs
    web/app/mu-plugins/pkde-platform/.github
    # the kit installs into the theme's vendor/, inside the docroot: nothing that is not code may ship
    web/app/themes/sage/vendor/pkde/sage-kit/tests
    web/app/themes/sage/vendor/pkde/sage-kit/docs
    web/app/themes/sage/vendor/pkde/sage-kit/README.md
    web/app/themes/sage/vendor/pkde/sage-kit/composer.json
    web/app/themes/sage/vendor/pkde/sage-kit/.github
    web/app/themes/sage/resources/blocks/README.md
    # Dev Composer packages: proves the --no-dev reinstall ran after lint/test.
    vendor/bin/pest
    vendor/bin/pint
    vendor/pestphp
    vendor/laravel/pint
)

required=(
    web/.htaccess.dist
    web/index.php
    web/wp-config.php
    web/wp/wp-load.php
    vendor/autoload.php
    config/application.php
    config/shared-paths.php
    composer.json
    composer.lock
    wp-cli.yml
    # run on the server with `wp eval-file <release>/bin/deploy/...` (launch guide): drift check and the W3TC standard
    bin/deploy/drift-check.php
    bin/deploy/w3tc-standard.php
    bin/deploy/w3tc-settings.php
    bin/deploy/perf-probe.php
    bin/deploy/perf-probe.sh
    bin/deploy/wp.sh
    web/app/themes/sage/public/build/manifest.json
    web/app/themes/sage/vendor/autoload.php
    web/app/mu-plugins/bedrock-autoloader.php
    web/app/mu-plugins/bedrock-disallow-indexing
    web/app/mu-plugins/pkde-platform/pkde-platform.php
    web/app/mu-plugins/pkde-platform/modules/staging-mail-guard.php
    web/app/themes/sage/vendor/pkde/sage-kit/src/KitServiceProvider.php
    web/app/mu-plugins/pkde-platform/modules/private-usernames/private-usernames.php
)

for p in "${forbidden[@]}"; do
    if [ -e "$DIST/$p" ] || [ -L "$DIST/$p" ]; then
        echo "::error::artifact must not contain $p"
        fail=1
    fi
done

for p in "${required[@]}"; do
    if [ ! -e "$DIST/$p" ]; then
        echo "::error::artifact is missing $p"
        fail=1
    fi
done

if [ "$fail" -ne 0 ]; then
    echo "Artifact check FAILED" >&2
    exit 1
fi

echo "Artifact check passed ($(find "$DIST" -type f | wc -l | tr -d ' ') files)"
