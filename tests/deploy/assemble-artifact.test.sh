#!/usr/bin/env bash
#
# Test for bin/deploy/assemble-artifact.sh and check-artifact.sh: the artifact keeps every plugin's own .htaccess and leaves out only the
# docroot's. A temp tree with fake plugins; no network, nothing outside the temp dir.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
T="$(mktemp -d)" || { echo "mktemp failed" >&2; exit 2; }
trap 'rm -rf "$T"' EXIT

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1 (expected '$3', got '$2')"; fi; }

# The smallest tree both scripts accept.
site="$T/site"
mkdir -p "$site/web/app/plugins/wordfence/lib" "$site/web/app/plugins/w3-total-cache/pub" "$site/web/app/plugins/w3-total-cache/ini" \
         "$site/web/app/mu-plugins/pkde-platform/modules/private-usernames" "$site/web/app/mu-plugins/bedrock-disallow-indexing" \
         "$site/web/app/themes/sage/public/build" "$site/web/app/themes/sage/vendor/pkde/sage-kit/src" "$site/web/wp" "$site/vendor" "$site/config" "$site/bin"
for f in web/.htaccess.dist web/index.php web/wp-config.php web/wp/wp-load.php vendor/autoload.php config/application.php config/shared-paths.php \
         composer.json composer.lock wp-cli.yml web/app/themes/sage/public/build/manifest.json web/app/themes/sage/vendor/autoload.php \
         web/app/mu-plugins/bedrock-autoloader.php web/app/mu-plugins/pkde-platform/pkde-platform.php \
         web/app/mu-plugins/pkde-platform/modules/staging-mail-guard.php web/app/themes/sage/vendor/pkde/sage-kit/src/KitServiceProvider.php \
         web/app/mu-plugins/pkde-platform/modules/private-usernames/private-usernames.php \
         web/app/mu-plugins/pkde-platform/modules/safe-svg.php web/app/mu-plugins/pkde-platform/modules/login-slug.php; do
  echo x > "$site/$f"
done
echo 'Require all denied' > "$site/web/app/plugins/wordfence/lib/.htaccess"
echo 'Require all denied' > "$site/web/app/plugins/w3-total-cache/pub/.htaccess"
echo 'Require all denied' > "$site/web/app/plugins/w3-total-cache/ini/.htaccess"
echo 'ROOT RULES' > "$site/web/.htaccess"
# The theme's folder READMEs are for developers: they must not ship (docs folders).
mkdir -p "$site/web/app/themes/sage/resources/views/vendor/pkde-kit" "$site/web/app/themes/sage/app"
echo '# readme' > "$site/web/app/themes/sage/resources/views/README.md"
echo '# readme' > "$site/web/app/themes/sage/resources/views/vendor/pkde-kit/README.md"
echo '# readme' > "$site/web/app/themes/sage/app/README.md"

# A site's repeatable content ships next to config/ (apply-pages.php), and so do the migration and page scripts.
mkdir -p "$site/content/pages"
echo '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' > "$site/content/pages/home.html"

(cd "$site" && bash "$REPO_ROOT/bin/deploy/assemble-artifact.sh" dist >/dev/null 2>&1)
check "content/pages/home.html is in the artifact" "$([ -f "$site/dist/content/pages/home.html" ] && echo yes || echo no)" yes
check "site-details-migrate.php ships with its library" "$(ls "$site/dist/bin/deploy/site-details-migrate.php" "$site/dist/bin/deploy/site-details-migrate-lib.php" 2>/dev/null | wc -l | tr -d ' ')" 2
check "legal-migrate.php ships with its library" "$(ls "$site/dist/bin/deploy/legal-migrate.php" "$site/dist/bin/deploy/legal-migrate-lib.php" 2>/dev/null | wc -l | tr -d ' ')" 2
check "apply-pages.php and faq-migrate.php ship with their libraries" "$(ls "$site/dist/bin/deploy/apply-pages.php" "$site/dist/bin/deploy/apply-pages-lib.php" "$site/dist/bin/deploy/faq-migrate.php" "$site/dist/bin/deploy/faq-migrate-lib.php" 2>/dev/null | wc -l | tr -d ' ')" 4
check "wordfence lib/.htaccess is in the artifact" "$([ -f "$site/dist/web/app/plugins/wordfence/lib/.htaccess" ] && echo yes || echo no)" yes
check "w3tc pub/.htaccess is in the artifact" "$([ -f "$site/dist/web/app/plugins/w3-total-cache/pub/.htaccess" ] && echo yes || echo no)" yes
check "w3tc ini/.htaccess is in the artifact" "$([ -f "$site/dist/web/app/plugins/w3-total-cache/ini/.htaccess" ] && echo yes || echo no)" yes
check "the docroot's own .htaccess is not in the artifact" "$([ -e "$site/dist/web/.htaccess" ] && echo yes || echo no)" no
check ".htaccess.dist is in the artifact" "$([ -f "$site/dist/web/.htaccess.dist" ] && echo yes || echo no)" yes
check "the theme folder READMEs are not in the artifact" "$(ls "$site/dist/web/app/themes/sage/resources/views/README.md" "$site/dist/web/app/themes/sage/resources/views/vendor/pkde-kit/README.md" "$site/dist/web/app/themes/sage/app/README.md" 2>/dev/null | wc -l | tr -d ' ')" 0

(cd "$site" && bash "$REPO_ROOT/bin/deploy/check-artifact.sh" dist >/dev/null 2>&1); check "check-artifact passes on a complete artifact" "$?" 0

rm "$site/dist/web/app/plugins/wordfence/lib/.htaccess"
out=$(cd "$site" && bash "$REPO_ROOT/bin/deploy/check-artifact.sh" dist 2>&1); status=$?
check "check-artifact fails when a plugin .htaccess was dropped" "$status" 1
case "$out" in *"web/app/plugins/wordfence/lib/.htaccess"*) pass=$((pass + 1)) ;; *) fail=$((fail + 1)); echo "FAIL: the dropped file is not named: $out" ;; esac

echo "assemble-artifact: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
