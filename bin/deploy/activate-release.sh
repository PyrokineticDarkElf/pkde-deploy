#!/usr/bin/env bash
#
# Runs on the remote 20i host, after a release has already been rsynced into
# <BASE>/releases/<RELEASE>/ by .github/workflows/deploy.yml. Links the
# persistent shared state into the new release, flips <BASE>/current to it
# atomically, best-effort flushes WordPress's cache and rewrite rules, and
# prunes old releases.
#
# Never runs composer/npm — the release was already fully built in CI; this
# script only ever does symlink/copy/flush/prune work.
#
# Usage: activate-release.sh <base_path> <release_timestamp> [keep_count]
#
# Rollback: re-run this same script against an OLDER release timestamp that's
# still on disk under <BASE>/releases/ — it doesn't care whether RELEASE is
# newer or older than whatever <BASE>/current currently points at.
#
# --- What is persistent, and how (the rule of thumb) -----------------------
# A release is disposable; anything a plugin or an admin writes at runtime
# would be lost on the next deploy unless it lives in <BASE>/shared/. There are
# exactly two ways to keep a runtime file out of the release:
#
#   * Served over HTTP (the browser fetches it by URL)  ->  symlink it under
#     web/ into shared/.     uploads/, ewww/, .htaccess
#   * Only PHP reads it                                  ->  point the plugin at
#     shared/ with a path constant set from .env (config/application.php), so
#     there is no symlink to dangle.   w3tc-config/ (W3TC_CONFIG_DIR),
#     wflogs/ (WFWAF_LOG_PATH), languages/ (WP_LANG_DIR; a symlinked
#     languages dir breaks WordPress's language upgrader)
#
# Both kinds are created in shared/ here. On the very first run after a path
# starts being shared, shared/<name> is seeded ONCE from the release that is
# currently live, so no existing setting or data is lost in the switchover.
#
# Disposable on purpose (rebuilt on demand, a cold cache after a code change is
# desirable): web/app/cache. Carried forward as copies, because they are tiny
# generated code stubs with no release-specific paths: W3TC's advanced-cache.php
# and object-cache.php drop-ins (see "drop-ins" below).
#
# Wordfence "Optimize the Firewall" is deliberately NOT supported: it writes an
# absolute path to ONE release directory into .user.ini / .htaccess, which then
# dangles when that release is pruned. Wordfence's default WAF mode needs none
# of it. A new release simply has no .user.ini, so a previously optimised
# firewall quietly reverts to the default mode on the next deploy.

set -euo pipefail

BASE="${1:?usage: activate-release.sh <base_path> <release_timestamp> [keep_count] [all|activate|post]}"
RELEASE="${2:?usage: activate-release.sh <base_path> <release_timestamp> [keep_count] [all|activate|post]}"
KEEP="${3:-5}"
# One run does everything ("all", the default, also what a manual rollback uses). The deploy workflow runs it as TWO separate
# remote execs on the same SSH connection: "activate" (switch, opcache reset, .htaccess, the fast prune) and then "post" (the
# flushes, acorn optimize, cache warm-up, languages, and starting the background clean-up of the pruned releases). 20i's gateway
# seems to close ONE exec channel after about 5 to 6 minutes (tcs run 39599779954 dropped at 5 min 36 s, in the silences of an
# rm -rf on NFS and of wp cache flush); splitting keeps each exec short, and each prints how long it ran.
MODE="${4:-all}"
case "$MODE" in all|activate|post) ;; *) echo "ERROR: the fourth argument must be all, activate or post (got '$MODE')" >&2; exit 2 ;; esac
stage() { echo "[+${SECONDS}s] $*"; }

RELEASE_DIR="$BASE/releases/$RELEASE"
SHARED_DIR="$BASE/shared"
CURRENT_LINK="$BASE/current"
# WP-CLI: WP_CLI from the environment wins; otherwise ~/bin/wp (older 20i packages), else the system
# install at /usr/local/bin/wp (a newer package has no ~/bin).
if [ -z "${WP_CLI:-}" ]; then
    if [ -f "$HOME/bin/wp" ]; then WP_CLI="$HOME/bin/wp"; else WP_CLI=/usr/local/bin/wp; fi
fi
echo "WP-CLI: $WP_CLI"
# $HOME is an NFS mount with noexec on this host (confirmed via `mount`), so
# the phar itself can never be executed directly regardless of its +x bit —
# it must always be run as `php83 "$WP_CLI"`, not `"$WP_CLI"`. The generic
# `php` on PATH is a much older system default (8.0 here); `php83` is 20i's
# per-version binary and matches this project's own PHP >=8.3 requirement.
PHP_BIN="$(command -v php83 || command -v php)"

OPCACHE_PROBE_FILE=""
finish() { rm -f "${OPCACHE_PROBE_FILE:-}"; echo "Exec '$MODE' finished after ${SECONDS}s"; }
trap finish EXIT

if [ ! -d "$RELEASE_DIR" ]; then
    echo "ERROR: release dir $RELEASE_DIR not found" >&2
    exit 1
fi

if [ ! -f "$SHARED_DIR/.env" ]; then
    echo "ERROR: $SHARED_DIR/.env does not exist." >&2
    echo "Create it by hand before the first deploy (see the deployment plan's" >&2
    echo "one-time setup checklist) — this script never creates or writes it." >&2
    exit 1
fi

# The release that is live right now (empty on the very first deploy, or when
# re-activating the release that is already current).
PREV_DIR=""
if [ -L "$CURRENT_LINK" ]; then
    PREV_DIR="$(readlink -f "$CURRENT_LINK" || true)"
    if [ "$PREV_DIR" = "$(readlink -f "$RELEASE_DIR")" ]; then
        PREV_DIR=""
    fi
fi

if [ "$MODE" != post ]; then   # ---- the activation part (modes all and activate) ----

# seed_shared_dir <name> [rsync-args...]
# Creates shared/<name>. If it did not exist yet and the live release has a real
# (not symlinked) web/app/<name>, copies that in first so the switch to shared
# state starts from what is live. Built in a temp dir and renamed into place, so
# an interrupted copy can never leave a half-seeded shared dir that the next run
# would then treat as complete. Never touches an existing shared dir.
seed_shared_dir() {
    local name="$1"
    shift
    local dest="$SHARED_DIR/$name"
    local src="$PREV_DIR/web/app/$name"

    if [ -d "$dest" ]; then
        return 0
    fi

    local tmp="$dest.seeding.$$"
    rm -rf "$tmp"
    mkdir -p "$tmp"
    if [ -n "$PREV_DIR" ] && [ -d "$src" ] && [ ! -L "$src" ]; then
        rsync -a "$@" "$src/" "$tmp/"
        echo "Seeded shared/$name from the live release"
    fi
    mv "$tmp" "$dest"
}

mkdir -p "$SHARED_DIR/uploads"
# W3TC's saved settings. Pointed at via W3TC_CONFIG_DIR in .env.
seed_shared_dir w3tc-config
# Wordfence's data (attack data, IP lists, GeoIP). Pointed at via WFWAF_LOG_PATH
# in .env. config*.php are excluded: Wordfence regenerates them from its own
# database settings, and they embed the old release's path.
seed_shared_dir wflogs --exclude 'config*.php'
# EWWW's lazy-load placeholders (URL-served) and downloaded tool binaries.
seed_shared_dir ewww
# Translation packs (core, plugins, themes). web/app/languages is gitignored, so without this every release starts with none and
# a non-English site shows English strings until packs are reinstalled; they are installed after the flushes below.
seed_shared_dir languages

# --- link shared resources into this release ---
rm -f "$RELEASE_DIR/.env"
ln -s "$SHARED_DIR/.env" "$RELEASE_DIR/.env"

rm -rf "$RELEASE_DIR/web/app/uploads"
ln -s "$SHARED_DIR/uploads" "$RELEASE_DIR/web/app/uploads"

# EWWW builds placeholder URLs from the content URL (/app/ewww/lazy/...), so the
# folder has to be reachable over HTTP: a symlink under web/, not a path
# constant to somewhere outside the docroot.
rm -rf "$RELEASE_DIR/web/app/ewww"
ln -s "$SHARED_DIR/ewww" "$RELEASE_DIR/web/app/ewww"

# Translation packs are NOT symlinked into the release: a symlinked web/app/languages made WordPress's language upgrader fail
# ("The destination directory already exists and could not be removed"). The kit's config/application.php sets WP_LANG_DIR to
# shared/languages instead (config/shared-paths.php: from .env, or found next to the symlinked .env). A stray directory or
# link from an older design is removed so nothing shadows it.
rm -rf "$RELEASE_DIR/web/app/languages"

# web/app/cache is per-release and disposable (see the header), but must exist and
# be writable for W3TC's page cache and minify output to land in it: its
# .htaccess rules point at /app/cache/... relative to the docroot. Created empty
# rather than left for the first request to create.
mkdir -p "$RELEASE_DIR/web/app/cache"

# .htaccess: shared and persistent across releases, not shipped fresh by CI.
# Wordfence, W3TC and the hardening block below all write their own rules into
# .htaccess at runtime — treating it as release-local (copied in from
# web/.htaccess.dist on every deploy, as earlier versions of this script did)
# would silently wipe those on the very next deploy, the same way it once
# wiped the plain WordPress rewrite block below. Seed the shared copy once
# from this release's own .htaccess.dist if it doesn't exist yet, then every
# release -- this one and every future one -- just symlinks to it.
if [ ! -f "$SHARED_DIR/.htaccess" ]; then
    cp "$RELEASE_DIR/web/.htaccess.dist" "$SHARED_DIR/.htaccess"
fi
rm -f "$RELEASE_DIR/web/.htaccess"
ln -s "$SHARED_DIR/.htaccess" "$RELEASE_DIR/web/.htaccess"

# drop-ins: W3TC writes advanced-cache.php / object-cache.php into web/app/ when
# the page / object cache is switched on. They are generated and gitignored, so
# a fresh release has neither, and page caching would silently stop until
# someone re-saved W3TC's settings. Their content only references the plugin
# directory relative to WP_PLUGIN_DIR (no release path), so a plain copy from the
# live release is safe. If an admin has switched the feature off, W3TC has
# already removed the file from the live release and nothing is carried over.
if [ -n "$PREV_DIR" ]; then
    for dropin in advanced-cache.php object-cache.php; do
        if [ -f "$PREV_DIR/web/app/$dropin" ] && [ ! -e "$RELEASE_DIR/web/app/$dropin" ]; then
            cp -p "$PREV_DIR/web/app/$dropin" "$RELEASE_DIR/web/app/$dropin"
            echo "Carried $dropin forward from the live release"
        fi
    done
fi

# --- atomic symlink swap ---
# Build the new symlink under a temp name, then rename it over the old one.
# Rename is atomic on the same filesystem, so there is no window where
# `current` points at nothing or at a half-written target.
ln -sfn "$RELEASE_DIR" "$CURRENT_LINK.tmp"
mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"
echo "Activated release $RELEASE -> $CURRENT_LINK"

# --- reset the WEB opcache: the symlink switch alone does not make PHP run the new code ---
# 20i's PHP-FPM runs with opcache on and revalidate_path=0, so scripts cached under the UNRESOLVED path
# (current/...) stay cached across the atomic switch above and the web keeps executing the previous release's
# code (bp saw generator 7.1.2 while core on disk was 7.1.3). A My20i PHP restart did not clear it; one
# opcache_reset() run BY THE WEB POOL did. WP-CLI cannot do it: the CLI has its own separate opcache.
# So: drop a one-time, unguessably named PHP file into the new release, request it over HTTPS via WP_HOME,
# and delete it straight away (an EXIT trap covers every way out; a deleted PHP file can still answer for about 10 s on 20i, OPcache
# revalidates every 10 s on top of the NFS attribute cache, which is harmless here: the name is a random 128-bit token and it prints one line). The file also reports which release it runs
# from, which doubles as a check that the live web server really serves the release just activated.
# Failure only warns: a deploy must never fail over this.
reset_web_opcache() {
    local url token answer
    url="$(sed -n 's/^WP_HOME=//p' "$SHARED_DIR/.env" 2>/dev/null | tr -d "\"'\r" | head -1)" || url=""
    url="${url%/}"
    if [ -z "$url" ] || ! command -v curl >/dev/null 2>&1; then
        echo "WARN: cannot reset the web opcache (needs WP_HOME in $SHARED_DIR/.env and curl)." >&2
        echo "WARN: the web server may keep running the PREVIOUS release's code. Reset it by hand (one web-side opcache_reset())." >&2
        return 0
    fi

    token="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
    OPCACHE_PROBE_FILE="$RELEASE_DIR/web/zz-opcache-$token.php"
    printf '%s\n' '<?php if (function_exists("opcache_reset")) { opcache_reset(); } echo "ok ", basename(dirname(realpath(__DIR__)));' > "$OPCACHE_PROBE_FILE"

    # The 20i web node can lag a few seconds in resolving the new `current`, and answers 404 for the probe until it does
    # (bp BP-46). Retry on 404 only (default: every 5s, 19 tries, about 90s); the probe file stays in place meanwhile.
    # Any other failure (403, no connection, timeout) is not a lag and warns at once.
    local tries="${OPCACHE_RETRY_ATTEMPTS:-19}" pause="${OPCACHE_RETRY_INTERVAL:-5}" attempt=1 got=1
    while :; do
        if answer="$(curl -fsSL --max-time 30 "$url/zz-opcache-$token.php" 2>&1)"; then got=0; break; fi
        case "$answer" in *"error: 404"*) ;; *) break ;; esac
        [ "$attempt" -ge "$tries" ] && { answer="the web server still answered 404 for the probe after $attempt tries (about $(( (attempt - 1) * pause ))s): it has not resolved the new current yet. ${answer}"; break; }
        sleep "$pause"; attempt=$((attempt + 1))
    done

    if [ "$got" -eq 0 ]; then
        rm -f "$OPCACHE_PROBE_FILE"; OPCACHE_PROBE_FILE=""
        case "$answer" in
            "ok $RELEASE") echo "Web opcache reset; the web server serves release $RELEASE" ;;
            ok\ *) echo "WARN: the web opcache was reset but the web server reports release '${answer#ok }', not $RELEASE: it may still serve the old release." >&2 ;;
            *) echo "WARN: the opcache reset request returned an unexpected answer: ${answer:0:200}" >&2 ;;
        esac
    else
        rm -f "$OPCACHE_PROBE_FILE"; OPCACHE_PROBE_FILE=""
        echo "WARN: ================================================================" >&2
        echo "WARN: could not request the opcache reset at $url (${answer:0:200})." >&2
        echo "WARN: the web server may keep running the PREVIOUS release's code until its opcache is cleared." >&2
        echo "WARN: ================================================================" >&2
    fi
    return 0
}
reset_web_opcache

# --- self-heal the shared .htaccess ---
# Operates on the shared file directly (the same file the symlink above points
# at) so a fix here is never lost to the next release. Each block is appended
# only when its BEGIN marker is missing, so this is idempotent and never
# rewrites rules another tool (Wordfence, W3TC) put in the file.
HTACCESS="$SHARED_DIR/.htaccess"
echo "Checking the shared .htaccess blocks"

# Belt-and-braces on top of dropping `--hard` above and making .htaccess
# shared: confirmed directly that W3 Total Cache's Browser Cache module
# regenerates .htaccess from its own known rule sections and drops anything it
# doesn't recognise — including this block — if that module is ever
# (re-)enabled from wp-admin. Handles both "exists but missing the block" and
# "missing entirely" (>> creates the file in the latter case).
if [ ! -f "$HTACCESS" ] || ! grep -q '# BEGIN WordPress' "$HTACCESS"; then
    cat >>"$HTACCESS" <<'EOF'

# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF
    echo "Re-asserted the WordPress rewrite block in $HTACCESS"
fi

# Source and manifests that ride along in a release but are never meant to be
# fetched over HTTP (everything under web/ is the docroot). resources/css and
# friends are deliberately NOT listed: wp-admin enqueues resources/css/admin.css
# by URL. Wrapped in <IfModule> so an unexpected server config degrades to
# "no protection" rather than a 500 on every request.
if ! grep -q '# BEGIN Site Hardening' "$HTACCESS"; then
    cat >>"$HTACCESS" <<'EOF'

# BEGIN Site Hardening
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^app/themes/[^/]+/(?:app|bin|config|docs|node_modules|vendor|resources/views)/ - [F,L]
RewriteRule ^app/themes/[^/]+/(?:composer\.(?:json|lock)|package(?:-lock)?\.json|vite\.config\.[cm]?js|README\.md|LICENSE\.md|\.editorconfig|\.gitignore)$ - [F,L]
RewriteRule ^app/mu-plugins/ - [F,L]
</IfModule>
# END Site Hardening
EOF
    echo "Added the Site Hardening block to $HTACCESS"
fi

# Long-lived caching for the content-hashed Vite output: public/build/assets/ and the self-hosted fonts under
# public/build/__laravel_vite_plugin__/fonts/ carry a content hash in their names, so a one-year immutable
# Cache-Control is safe (a changed file gets a new name). manifest.json and fonts-manifest.json are NOT hashed and
# are deliberately outside these two paths. LocationMatch is not allowed in .htaccess, so mod_rewrite marks the request
# with an environment variable and mod_headers keys on it. Appended last, so it overrides any Cache-Control from
# W3TC's Browser Cache rules; and, like Site Hardening above, re-added when its marker is missing because W3TC
# regenerates .htaccess and drops blocks it does not know. Each module is wrapped in <IfModule>: no module = no header.
if ! grep -q '# BEGIN Asset Cache' "$HTACCESS"; then
    cat >>"$HTACCESS" <<'EOF'

# BEGIN Asset Cache
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^app/themes/[^/]+/public/build/(?:assets|__laravel_vite_plugin__/fonts)/ - [E=PKDE_HASHED_ASSET:1]
</IfModule>
<IfModule mod_headers.c>
Header set Cache-Control "public, max-age=31536000, immutable" env=PKDE_HASHED_ASSET
</IfModule>
# END Asset Cache
EOF
    echo "Added the Asset Cache block to $HTACCESS"
fi

# --- prune old releases, keep the newest $KEEP ---
# Fast: each old release is MOVED into $BASE/.trash (a rename, instant even on NFS) and deleted later in the background by
# empty_trash, so a slow recursive delete never holds an exec open (one measured rm -rf of a single release was silent for 266 s).
stage "pruning old releases, keeping the newest $KEEP"
TRASH="$BASE/.trash"
mkdir -p "$TRASH"
cd "$BASE/releases"
# The release just activated is never pruned, whatever its mtime says (a rollback to an old release, or a smaller KEEP,
# must not delete the live one, and the flushes below still run from it): keep it plus the newest KEEP-1 others.
trash_stamp="$(date +%s)"
{ ls -1t | grep -vxF -- "$RELEASE" || true; } | tail -n "+$KEEP" | xargs -r -I{} mv -- {} "$TRASH/{}.$trash_stamp"   # grep exits 1 when nothing is left (first deploy)
stage "pruned old releases (moved to .trash), kept newest $KEEP"

fi   # ---- end of the activation part ----

# Deleting what the prune moved into $BASE/.trash: detached (nohup, no inherited file descriptors, so the SSH channel can close), one
# at a time (a lock directory, stale after two hours), and never waited for. Called as the last thing the post part does.
TRASH="$BASE/.trash"
TRASH_LOCK="$BASE/.trash.lock"
empty_trash() {
    [ -d "$TRASH" ] || return 0
    [ -n "$(ls -A "$TRASH" 2>/dev/null)" ] || return 0
    if ! mkdir "$TRASH_LOCK" 2>/dev/null; then
        if [ -n "$(find "$TRASH_LOCK" -maxdepth 0 -mmin +120 2>/dev/null)" ]; then
            rmdir "$TRASH_LOCK" 2>/dev/null && mkdir "$TRASH_LOCK" 2>/dev/null || { echo "Clean-up of .trash is already running"; return 0; }
        else
            echo "Clean-up of .trash is already running"
            return 0
        fi
    fi
    nohup bash -c 'find "$1" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +; rmdir "$2"' _ "$TRASH" "$TRASH_LOCK" </dev/null >/dev/null 2>&1 &
    echo "Deleting the pruned releases in the background (.trash)"
}

if [ "$MODE" = activate ]; then
    stage "activation part done; the post part (flushes, warm-up, languages) is the next exec"
    exit 0
fi

if [ "$MODE" = post ] && [ "$(readlink -f "$CURRENT_LINK" 2>/dev/null)" != "$(readlink -f "$RELEASE_DIR")" ]; then
    echo "WARN: $CURRENT_LINK does not point at release $RELEASE: the post steps are skipped (they must run from the live release)." >&2
    exit 0
fi

# --- cache/rewrite flush: best-effort, bounded, runs LAST, never fails the deploy over this ---
# Deliberately `rewrite flush` (soft, DB-only: updates the `rewrite_rules`
# option) and NOT `--hard`: confirmed directly that `--hard` makes WordPress
# attempt to rewrite web/.htaccess itself via save_mod_rewrite_rules(), and
# in this SSH/CLI context (no real HTTP request, so WordPress's own apache/
# mod_rewrite detection misfires) that call doesn't just fail to write:
# it deletes the file outright ("Warning: Regenerating a .htaccess file
# requires special configuration" is WP-CLI's own signal that this happened,
# not a harmless no-op). We manage .htaccess ourselves (shared + self-healed,
# both above), so WordPress never needs to touch the file at all.
#
# Last, and bounded, because a WP-CLI call can hang (seen in production: another WP-CLI session on the same site
# held it for 5 minutes until the gateway dropped the connection and the job failed with 255, after the release was
# already live). The .htaccess re-assert and the prune above therefore never wait on it, and each call is killed
# after WP_CLI_TIMEOUT seconds (default 120) with a WARN; the deploy still succeeds.
WP_CLI_TIMEOUT="${WP_CLI_TIMEOUT:-120}"
# PHP's command-line memory default (128M on 20i) is not enough once WooCommerce, the caching and security plugins and the site
# language's translations load: wp cache flush died with "Allowed memory size" at 128M (and the deploy still showed
# success, because run_wp only warns). Every WP-CLI call gets this much.
WP_CLI_MEMORY="${WP_CLI_MEMORY:-512M}"
wp_run() { # <wp args...>: one WP-CLI call in the release, with the memory limit, killed after WP_CLI_TIMEOUT when `timeout` exists
    if command -v timeout >/dev/null 2>&1; then
        (cd "$RELEASE_DIR" && timeout -k 10 "$WP_CLI_TIMEOUT" "$PHP_BIN" -d memory_limit="$WP_CLI_MEMORY" "$WP_CLI" "$@")
    else
        (cd "$RELEASE_DIR" && "$PHP_BIN" -d memory_limit="$WP_CLI_MEMORY" "$WP_CLI" "$@")
    fi
}
run_wp() { # <wp args...>
    local rc=0 out detail
    echo "Running: wp $* (timeout ${WP_CLI_TIMEOUT}s)"
    command -v timeout >/dev/null 2>&1 || echo "WARN: 'timeout' is not installed, wp $* runs without a time limit" >&2
    # The output is kept, so a failure's WARN can name its cause ("wp language core install en_GB failed
    # (exit 1)" said nothing about why). It is printed when the call ends.
    out="$(mktemp 2>/dev/null)" || out=/dev/null
    wp_run "$@" >"$out" 2>&1 || rc=$?
    [ "$out" = /dev/null ] || cat "$out"
    case "$rc" in
        0) ;;
        124|137) echo "WARN: wp $* timed out after ${WP_CLI_TIMEOUT}s and was stopped; the release is live, carrying on" >&2 ;;
        *)
            detail=""
            if [ "$out" != /dev/null ]; then
                detail="$(grep -v '^[[:space:]]*$' "$out" | { grep -m1 -iE 'error|fatal|warning|failed|denied|memory' || true; } | cut -c1-300)"
                [ -n "$detail" ] || detail="$(grep -v '^[[:space:]]*$' "$out" | head -1 | cut -c1-300 || true)"
            fi
            echo "WARN: wp $* failed (exit $rc)${detail:+: $detail}" >&2
            ;;
    esac
    [ "$out" = /dev/null ] || rm -f "$out"
    return 0
}
if [ -f "$WP_CLI" ]; then
    stage "flushes"
    run_wp cache flush
    run_wp rewrite flush

    # Acorn's own caches (config, events, views) for the new release, when the theme has Acorn: its WP-CLI command exists only then.
    if wp_run cli has-command acorn >/dev/null 2>&1; then
        run_wp acorn optimize
    fi

    # Warm the page cache: a release starts with an empty one (web/app/cache is per release), so W3 Total Cache's preloader fetches
    # the first pages of its sitemap now instead of leaving them to the first visitors. Only when W3TC is active AND a preloader
    # sitemap is set in its settings (bin/deploy/w3tc-settings.php sets one); otherwise nothing runs. Bounded and warn-only.
    if wp_run plugin is-active w3-total-cache >/dev/null 2>&1; then
        PRIME_SITEMAP="$(wp_run w3-total-cache option get pgcache.prime.sitemap 2>/dev/null | tr -d '[:space:]')" || PRIME_SITEMAP=""
        # Uncached pages take about 4 s each on 20i, so the priming has its own, longer bound than the other WP-CLI calls
        # (30 pages would overrun the 120 s WP_CLI_TIMEOUT; 26 pages took about 80 s in one measured run).
        case "${CACHE_WARM_LIMIT:-30}:${CACHE_WARM_TIMEOUT:-240}" in
            *[!0-9:]*|:*|*:) echo "WARN: CACHE_WARM_LIMIT must be a number and CACHE_WARM_TIMEOUT must be a number of seconds, the page cache is not warmed" >&2 ;;
            *)
                if [ -n "$PRIME_SITEMAP" ]; then
                    WP_CLI_TIMEOUT="${CACHE_WARM_TIMEOUT:-240}" run_wp w3-total-cache pgcache_prime --start=1 --limit="${CACHE_WARM_LIMIT:-30}"
                else
                    echo "W3 Total Cache has no preloader sitemap set: nothing to warm"
                fi
                ;;
        esac
    fi

    # Translations for the site language (the WPLANG option), after the flushes, bounded and warn-only like them: install what is
    # missing and update what is outdated for core, plugins and themes into the shared languages/ directory. Nothing runs for an
    # English (en_US) or unset site. The option read is bounded too: a hung WP-CLI must not stall the deploy here either.
    SITE_LANG="$(wp_run option get WPLANG 2>/dev/null | tr -d '[:space:]')" || SITE_LANG=""
    case "$SITE_LANG" in
        ""|en_US) ;;
        *[!A-Za-z0-9_-]*) echo "WARN: ignoring an odd WPLANG value, no language packs installed" >&2 ;;
        *)
            run_wp language core install "$SITE_LANG"
            run_wp language core update
            run_wp language plugin install --all "$SITE_LANG"
            run_wp language plugin update --all
            run_wp language theme install --all "$SITE_LANG"
            run_wp language theme update --all
            ;;
    esac
else
    echo "WARN: WP-CLI not found at $WP_CLI -- skipping cache/rewrite flush" >&2
fi

stage "post steps done"
empty_trash
