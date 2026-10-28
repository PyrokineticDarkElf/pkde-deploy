#!/usr/bin/env bash
#
# Assembles the deploy artifact (default: ./dist) from an already-built working
# tree. Run from the repo root, after `composer install --no-dev` and the theme
# build. Used by .github/workflows/deploy.yml; safe to run locally to see exactly
# what a deploy would ship.
#
# The artifact is what gets rsynced into a new release directory, so everything
# here is "code that runs the site". The excludes are belt and braces: most of
# these paths are gitignored, so a clean CI checkout never has them, but a dirty
# local tree does, and a stray file shipped by accident is how runtime state or
# developer notes end up on a live server. check-artifact.sh then verifies the
# result rather than trusting these excludes.
#
# Patterns starting with / are anchored to web/ (the docroot).

set -euo pipefail

DEST="${1:-dist}"
# This script lives in the pkde-deploy tools (checked out next to the site, or in vendor/pkde/deploy); the files it ships to the
# server are its siblings, wherever the tools are.
TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"

rm -rf "$DEST"
mkdir -p "$DEST"

# web/app/uploads, w3tc-config, wflogs, ewww, .htaccess and .user.ini live in the
# server's shared/ dir (see activate-release.sh); the drop-ins and cache are
# generated at runtime; the rest is developer-only and would be publicly served.
rsync -a web/ "$DEST/web/" \
    --exclude '/app/uploads' \
    --exclude '/app/cache' \
    --exclude '/app/w3tc-config' \
    --exclude '/app/wflogs' \
    --exclude '/app/ewww' \
    --exclude '/app/advanced-cache.php' \
    --exclude '/app/object-cache.php' \
    --exclude '/app/db.php' \
    --exclude '/demo-content.html' \
    --exclude '/nginx.conf' \
    --exclude '/.user.ini' \
    --exclude '/.htaccess' \
    --exclude '/app/themes/sage/node_modules' \
    --exclude '/app/themes/sage/public/hot' \
    --exclude '/app/themes/sage/package.json' \
    --exclude '/app/themes/sage/package-lock.json' \
    --exclude '/app/themes/sage/vite.config.js' \
    --exclude '/app/themes/sage/bin' \
    --exclude '/app/themes/sage/docs' \
    --exclude '/app/themes/sage/.editorconfig' \
    --exclude '/app/themes/sage/.gitignore' \
    --exclude '/app/themes/sage/README.md' \
    --exclude '/app/mu-plugins/README.md' \
    --exclude '/app/mu-plugins/pkde-platform/tests' \
    --exclude '/app/mu-plugins/pkde-platform/docs' \
    --exclude '/app/mu-plugins/pkde-platform/README.md' \
    --exclude '/app/themes/sage/resources/blocks/README.md'

# WordPress core's readme.html and license.txt are publicly served and reveal the exact version.
rm -f "$DEST/web/wp/readme.html" "$DEST/web/wp/license.txt"

# '/.htaccess' above is anchored to web/: only the docroot's own file (kept in shared/ on the server) is left out. An unanchored
# '.htaccess' also dropped every plugin's own access denials (Wordfence lib/, W3 Total Cache pub/ and ini/), so releases shipped
# without them (TCS-88); check-artifact.sh now requires each one the build tree has. web/.htaccess.dist is deliberately NOT excluded:
# activate-release.sh seeds the server's shared .htaccess from it once.
rsync -a vendor/ "$DEST/vendor/"
rsync -a config/ "$DEST/config/"
cp composer.json composer.lock wp-cli.yml "$DEST/"

# The server-side checks the launch guide runs from the release (`wp eval-file bin/deploy/...`, `bash bin/deploy/perf-probe.sh`): no secrets, and outside the docroot
# (web/), so they are not web-reachable. Only these files, not the rest of bin/deploy (CI-side scripts).
mkdir -p "$DEST/bin/deploy"
cp "$TOOLS_DIR/drift-check.php" "$TOOLS_DIR/w3tc-standard.php" "$TOOLS_DIR/w3tc-settings.php" "$TOOLS_DIR/perf-probe.php" "$TOOLS_DIR/perf-probe.sh" "$TOOLS_DIR/wp.sh" "$DEST/bin/deploy/"

echo "Assembled deploy artifact in $DEST"
