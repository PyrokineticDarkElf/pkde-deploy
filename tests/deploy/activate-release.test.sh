#!/usr/bin/env bash
#
# Sandbox test for bin/deploy/activate-release.sh. Simulates a 20i account in a
# temp dir (stubbed php83 / WP-CLI, real rsync/ln/mv) and walks the script through
# the situations that matter: first deploy, migrating a live release's runtime
# state into shared/, an ordinary deploy, rollback, re-run, pruning, and a
# missing .env. Needs GNU userland (mv -T, readlink -f, touch -d): Linux, which
# is what CI runs on. On macOS run it through Docker:
#
#   docker run --rm -v "$PWD:/repo:ro" debian:bookworm-slim bash -c \
#     'apt-get update -qq && apt-get install -y -qq rsync python3 >/dev/null && bash /repo/tests/deploy/activate-release.test.sh'
#
# Exits non-zero if any check fails.

set -uo pipefail

# SAFETY: this test creates and deletes fixtures. It must only ever do that inside the temp sandbox it creates.
# If a step fails (no git, no mktemp, a failed cd) it must stop, never carry on in the real repository.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
refuse() { echo "REFUSING TO RUN: $1 (nothing was changed)" >&2; exit 2; }
SCRIPT="$REPO_ROOT/bin/deploy/activate-release.sh"
T="$(mktemp -d)" || refuse "mktemp failed"
[ -n "$T" ] && [ -d "$T" ] || refuse "mktemp did not create a sandbox"
case "$(cd "$T" && pwd -P)/" in "$REPO_ROOT"/*) refuse "the sandbox is inside the repository" ;; esac
trap 'rm -rf "$T"' EXIT
export HOME="$T/home"
mkdir -p "$HOME/bin" "$T/bin"
# The stub logs its arguments; while "$T/php83-hang" exists it then hangs (exec, so a kill reaches the sleep itself),
# standing in for a WP-CLI call that never returns (Scenario 11).
cat > "$T/bin/php83" <<STUB
#!/bin/sh
echo "[stub php83] \$*" >> "$T/php83.log"
[ -f "$T/php83-hang" ] && exec sleep 60
case "\$*" in
  *"language core install"*) [ -f "$T/php83-fail-lang" ] && { echo "Error: Could not create directory." >&2; exit 1; }; [ -f "$T/php83-hang-lang" ] && exec sleep 60 ;;
  *language*) [ -f "$T/php83-hang-lang" ] && exec sleep 60 ;;
  *"option get WPLANG"*) printf '%s' "\${PKDE_TEST_WPLANG:-}" ;;
  *"plugin is-active w3-total-cache"*) [ -f "$T/w3tc-active" ] || exit 1 ;;
  *"cli has-command acorn"*) [ -f "$T/acorn-yes" ] || exit 1 ;;
  *"option get pgcache.prime.sitemap"*) [ -f "$T/w3tc-sitemap" ] && printf '%s\n' "https://example.com/sitemap_index.xml" ;;
  *pgcache_prime*) [ -f "$T/w3tc-prime-hang" ] && exec sleep 60; [ -f "$T/w3tc-prime-slow" ] && sleep 3 ;;
esac
exit 0
STUB
chmod +x "$T/bin/php83"
export PATH="$T/bin:$PATH"
echo "<?php // wp-cli stub" > "$HOME/bin/wp"
BASE="$T/deploy/production"
S="$SCRIPT"
mkdir -p "$BASE"
pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; fail=$((fail+1)); }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }
mkrelease() { # <name> : fresh CI artifact
  mkdir -p $BASE/releases/$1/web/app/themes/sage $BASE/releases/$1/vendor $BASE/releases/$1/config
  echo "# dist" > $BASE/releases/$1/web/.htaccess.dist
}

echo "=== Scenario 1: FIRST EVER deploy (no current, empty shared) ==="
mkdir -p $BASE/shared; echo "WP_ENV=production" > $BASE/shared/.env
mkrelease R1
bash $S $BASE R1 5 >$T/s1.log 2>&1; rc=$?
check "script exits 0" "[ $rc -eq 0 ]"
check "current -> R1" "[ \"\$(readlink $BASE/current)\" = $BASE/releases/R1 ]"
check "shared dirs created: w3tc-config wflogs ewww uploads" "[ -d $BASE/shared/w3tc-config ] && [ -d $BASE/shared/wflogs ] && [ -d $BASE/shared/ewww ] && [ -d $BASE/shared/uploads ]"
check "R1/web/app/ewww is a symlink to shared/ewww" "[ \"\$(readlink $BASE/releases/R1/web/app/ewww)\" = $BASE/shared/ewww ]"
check ".htaccess seeded from .dist then both managed blocks appended" "grep -q 'BEGIN WordPress' $BASE/shared/.htaccess && grep -q 'BEGIN Site Hardening' $BASE/shared/.htaccess && head -1 $BASE/shared/.htaccess | grep -q '# dist'"
check "release .htaccess is a symlink to shared" "[ \"\$(readlink $BASE/releases/R1/web/.htaccess)\" = $BASE/shared/.htaccess ]"
check "per-release web/app/cache exists and is a real (non-shared) dir" "[ -d $BASE/releases/R1/web/app/cache ] && [ ! -L $BASE/releases/R1/web/app/cache ]"
check "wp-cli invoked via php83 (noexec-safe)" "grep -q 'stub php83.*wp rewrite flush\|stub php83.*/bin/wp rewrite flush' $T/php83.log"
check "never calls --hard" "! grep -q -- '--hard' $T/php83.log"
check "every wp-cli call gets -d memory_limit=512M (PHP's 128M CLI default is too small)" "[ \$(grep -c 'stub php83' $T/php83.log) -gt 0 ] && [ \$(grep 'stub php83' $T/php83.log | grep -vc -- '-d memory_limit=512M ') -eq 0 ]"

echo "=== Scenario 2: migration — live release R1 has REAL state dirs, deploy R2 ==="
# Make R1 look like today's production: real runtime dirs + drop-in + .user.ini
rm -f $BASE/releases/R1/web/app/ewww
mkdir -p $BASE/releases/R1/web/app/{w3tc-config,wflogs,ewww/lazy}
rm -rf $BASE/shared/w3tc-config $BASE/shared/wflogs $BASE/shared/ewww        # undo scenario-1 empties = "not yet shared"
echo "pgcache.enabled=CUSTOM" > $BASE/releases/R1/web/app/w3tc-config/master.php
echo "idx"                     > $BASE/releases/R1/web/app/w3tc-config/index.html
for f in attack-data.php ips.php rules.php template.php config.php config-synced.php config-livewaf.php config-transient.php; do echo $f > $BASE/releases/R1/web/app/wflogs/$f; done
echo hi > $BASE/releases/R1/web/app/wflogs/.htaccess
echo png > $BASE/releases/R1/web/app/ewww/lazy/placeholder.png; echo bin > $BASE/releases/R1/web/app/ewww/jpegtran
echo "<?php // W3TC page cache" > $BASE/releases/R1/web/app/advanced-cache.php
echo "auto_prepend_file = '$BASE/releases/R1/web/wp/wordfence-waf.php'" > $BASE/releases/R1/web/.user.ini
echo "USER-ADDED-BLOCK" >> $BASE/shared/.htaccess                           # a Wordfence/W3TC rule written at runtime
mkrelease R2
bash $S $BASE R2 5 >$T/s2.log 2>&1; rc=$?
check "script exits 0" "[ $rc -eq 0 ]"
check "W3TC settings carried over byte-for-byte" "[ \"\$(cat $BASE/shared/w3tc-config/master.php)\" = 'pgcache.enabled=CUSTOM' ] && [ -f $BASE/shared/w3tc-config/index.html ]"
check "wflogs seeded (attack-data, ips, rules, template, .htaccess)" "[ -f $BASE/shared/wflogs/attack-data.php ] && [ -f $BASE/shared/wflogs/ips.php ] && [ -f $BASE/shared/wflogs/rules.php ] && [ -f $BASE/shared/wflogs/template.php ] && [ -f $BASE/shared/wflogs/.htaccess ]"
check "wflogs config*.php NOT copied (Wordfence regenerates them)" "! ls $BASE/shared/wflogs/config*.php >/dev/null 2>&1"
check "ewww seeded (placeholder + binary)" "[ -f $BASE/shared/ewww/lazy/placeholder.png ] && [ -f $BASE/shared/ewww/jpegtran ]"
check "R2 ewww is a symlink to shared" "[ \"\$(readlink $BASE/releases/R2/web/app/ewww)\" = $BASE/shared/ewww ]"
check "advanced-cache.php drop-in carried into R2" "[ -f $BASE/releases/R2/web/app/advanced-cache.php ]"
check "R2 has NO .user.ini (so no dangling auto_prepend_file path)" "[ ! -e $BASE/releases/R2/web/.user.ini ]"
check "runtime-added .htaccess rule preserved" "grep -q USER-ADDED-BLOCK $BASE/shared/.htaccess"
check "no leftover *.seeding.* temp dirs" "! ls -d $BASE/shared/*.seeding.* >/dev/null 2>&1"
check "current -> R2" "[ \"\$(readlink $BASE/current)\" = $BASE/releases/R2 ]"

echo "=== Scenario 3: next deploy R3 — shared state must NOT be re-seeded or overwritten ==="
echo "pgcache.enabled=CHANGED-IN-WPADMIN" > $BASE/shared/w3tc-config/master.php        # admin saves settings in wp-admin on R2
echo "later-attack" >> $BASE/shared/wflogs/attack-data.php
mkdir -p $BASE/releases/R2/web/app/w3tc-config; echo "STALE-LOCAL-COPY" > $BASE/releases/R2/web/app/w3tc-config/master.php
mkrelease R3
bash $S $BASE R3 5 >$T/s3.log 2>&1; rc=$?
check "script exits 0" "[ $rc -eq 0 ]"
check "admin-saved W3TC setting survives (not overwritten by a stale release copy)" "[ \"\$(cat $BASE/shared/w3tc-config/master.php)\" = 'pgcache.enabled=CHANGED-IN-WPADMIN' ]"
check "wflogs data survives" "grep -q later-attack $BASE/shared/wflogs/attack-data.php"
check "no 'Seeded' message on a normal deploy" "! grep -q Seeded $T/s3.log"
check "WordPress block present exactly once" "[ \$(grep -c 'BEGIN WordPress' $BASE/shared/.htaccess) -eq 1 ]"
check "Hardening block present exactly once" "[ \$(grep -c 'BEGIN Site Hardening' $BASE/shared/.htaccess) -eq 1 ]"
check "Upload Hardening block present exactly once" "[ \$(grep -c 'BEGIN Upload Hardening' $BASE/shared/.htaccess) -eq 1 ]"
check "Upload Hardening denies PHP in uploads and in themes" "grep -qF 'RewriteRule ^app/uploads/.*' $BASE/shared/.htaccess && grep -qF 'RewriteRule ^app/themes/[^/]+/.*' $BASE/shared/.htaccess"
check "Upload Hardening sandboxes SVG" "grep -q 'X-Content-Type-Options' $BASE/shared/.htaccess && grep -q 'sandbox' $BASE/shared/.htaccess"
check "Upload Cache block present exactly once" "[ \$(grep -c 'BEGIN Upload Cache' $BASE/shared/.htaccess) -eq 1 ]"
check "XML-RPC Hardening block present exactly once" "[ \$(grep -c 'BEGIN XML-RPC Hardening' $BASE/shared/.htaccess) -eq 1 ] && grep -q 'Files \"xmlrpc.php\"' $BASE/shared/.htaccess"
check "Upload Cache: 30 days for uploads, one year immutable for ?ver= assets" "grep -q 'public, max-age=2592000\" env=PKDE_UPLOAD_ASSET' $BASE/shared/.htaccess && grep -q 'immutable\" env=PKDE_VER_ASSET' $BASE/shared/.htaccess"

echo "=== Scenario 4: ROLLBACK to R1 (an older, pre-change release with real dirs) ==="
bash $S $BASE R1 5 >$T/s4.log 2>&1; rc=$?
check "script exits 0" "[ $rc -eq 0 ]"
check "current -> R1" "[ \"\$(readlink $BASE/current)\" = $BASE/releases/R1 ]"
check "shared W3TC state untouched by rollback" "[ \"\$(cat $BASE/shared/w3tc-config/master.php)\" = 'pgcache.enabled=CHANGED-IN-WPADMIN' ]"

echo "=== Scenario 5: re-running the SAME release is harmless ==="
bash $S $BASE R1 5 >$T/s5.log 2>&1; check "exits 0" "[ $? -eq 0 ]"

echo "=== Scenario 6: prune keeps newest N ==="
for n in R4 R5 R6 R7; do sleep 1; mkrelease $n; touch -d "+$(( ${n#R} ))min" $BASE/releases/$n; bash $S $BASE $n 3 >/dev/null 2>&1; done
check "only 3 releases remain" "[ \$(ls $BASE/releases | wc -l) -eq 3 ]"
check "shared dir never pruned" "[ -d $BASE/shared/w3tc-config ] && [ -f $BASE/shared/.env ]"

echo "=== Scenario 7: missing .env aborts BEFORE touching anything ==="
mv $BASE/shared/.env $BASE/shared/.env.bak; before=$(readlink $BASE/current); mkrelease R8
bash $S $BASE R8 5 >$T/s7.log 2>&1; rc=$?
check "exits non-zero" "[ $rc -ne 0 ]"
check "current unchanged" "[ \"\$(readlink $BASE/current)\" = \"$before\" ]"
mv $BASE/shared/.env.bak $BASE/shared/.env

echo "=== Scenario 8: WP-CLI path — WP_CLI wins, else ~/bin/wp, else /usr/local/bin/wp ==="
mv "$HOME/bin/wp" "$HOME/bin/wp.off"; mkdir -p "$T/alt"; echo "<?php // alt stub" > "$T/alt/wp"
: > $T/php83.log; mkrelease R9
WP_CLI="$T/alt/wp" bash $S $BASE R9 5 >$T/s8a.log 2>&1
check "WP_CLI override is used" "grep -q 'stub php83\] -d memory_limit=[^ ]* $T/alt/wp' $T/php83.log"
: > $T/php83.log; mkrelease R10
bash $S $BASE R10 5 >$T/s8b.log 2>&1
if [ ! -f /usr/local/bin/wp ]; then
  check "no ~/bin/wp and no override falls back to /usr/local/bin/wp" "grep -q 'WP-CLI not found at /usr/local/bin/wp' $T/s8b.log"
fi
mv "$HOME/bin/wp.off" "$HOME/bin/wp"

echo "=== Scenario 9: web opcache reset after the symlink switch ==="
# A curl stub stands in for the web server: it records what the request found on disk, then answers per $T/curl-mode.
cat > "$T/bin/curl" <<STUB
#!/bin/bash
url="\${@: -1}"
echo "\$url" >> "$T/curl.log"
f=\$(ls $BASE/current/web/zz-opcache-*.php 2>/dev/null | head -1)
if [ -n "\$f" ]; then echo "EXISTS \$(grep -c 'opcache_reset' "\$f")" >> "$T/curl.log"; fi
case "\$(cat "$T/curl-mode")" in
  ok) echo "ok \$(basename "\$(readlink -f "$BASE/current")")" ;;
  wrongrel) echo "ok 19990101000000" ;;
  fail) echo "curl: (22) The requested URL returned error: 403" >&2; exit 22 ;;
  notfound) echo "curl: (22) The requested URL returned error: 404" >&2; exit 22 ;;
  notfound2)
    # The web node has not resolved the new current yet: 404 for the first two requests, then it answers.
    n=\$(grep -c '^https' "$T/curl.log")
    if [ "\$n" -le 2 ]; then echo "curl: (22) The requested URL returned error: 404" >&2; exit 22; fi
    echo "ok \$(basename "\$(readlink -f "$BASE/current")")" ;;
esac
STUB
chmod +x "$T/bin/curl"
echo "WP_ENV=production" > $BASE/shared/.env; echo "WP_HOME=https://example.test/" >> $BASE/shared/.env
: > $T/curl.log; echo ok > $T/curl-mode; mkrelease R20
bash $S $BASE R20 5 >$T/s9a.log 2>&1; rc=$?
check "exits 0" "[ $rc -eq 0 ]"
check "requests a zz-opcache-<32 hex>.php URL under WP_HOME" "grep -Eq '^https://example.test/zz-opcache-[0-9a-f]{32}\\.php\$' $T/curl.log"
check "the file existed at request time and calls opcache_reset" "grep -q '^EXISTS 1' $T/curl.log"
check "the file is gone afterwards" "[ -z \"\$(ls $BASE/releases/R20/web/zz-opcache-*.php 2>/dev/null)\" ]"
check "reports the web server serves the new release" "grep -q 'Web opcache reset; the web server serves release R20' $T/s9a.log"

: > $T/curl.log; echo fail > $T/curl-mode; mkrelease R21
bash $S $BASE R21 5 >$T/s9b.log 2>&1; rc=$?
check "request failure: deploy still exits 0 and current -> R21" "[ $rc -eq 0 ] && [ \"\$(readlink $BASE/current)\" = $BASE/releases/R21 ]"
check "request failure: loud warning" "grep -q 'could not request the opcache reset' $T/s9b.log && grep -q 'PREVIOUS release' $T/s9b.log"
check "request failure: the file is still removed" "[ -z \"\$(ls $BASE/releases/R21/web/zz-opcache-*.php 2>/dev/null)\" ]"

: > $T/curl.log; echo wrongrel > $T/curl-mode; mkrelease R22
bash $S $BASE R22 5 >$T/s9c.log 2>&1; rc=$?
check "wrong release reported: exit 0 and a warning naming it" "[ $rc -eq 0 ] && grep -q \"reports release '19990101000000', not R22\" $T/s9c.log"
check "wrong release: the file is removed" "[ -z \"\$(ls $BASE/releases/R22/web/zz-opcache-*.php 2>/dev/null)\" ]"

# bp BP-46: the 20i web node answers 404 for a few seconds after the switch. Retry on 404 only; keep the file meanwhile.
: > $T/curl.log; echo notfound2 > $T/curl-mode; mkrelease R24
OPCACHE_RETRY_INTERVAL=0 bash $S $BASE R24 5 >$T/s9e.log 2>&1; rc=$?
check "404 twice then ok: exit 0 and the success message, no warning" "[ $rc -eq 0 ] && grep -q 'Web opcache reset; the web server serves release R24' $T/s9e.log && ! grep -q 'WARN: could not request' $T/s9e.log"
check "404 twice then ok: three requests, the file existed at each" "[ \$(grep -c '^https.*zz-opcache' $T/curl.log) -eq 3 ] && [ \$(grep -c '^EXISTS 1' $T/curl.log) -eq 3 ]"
check "404 twice then ok: the file is removed afterwards" "[ -z \"\$(ls $BASE/releases/R24/web/zz-opcache-*.php 2>/dev/null)\" ]"
check "hardening probe: requests uploads PHP, theme PHP, mu-plugins and xmlrpc.php, and says so when they do not answer 403" "grep -q 'pkde-probe' $T/curl.log && grep -q 'xmlrpc.php' $T/curl.log && grep -q 'hardening probe: .* NOT enforced' $T/s9e.log"

: > $T/curl.log; echo notfound > $T/curl-mode; mkrelease R25
OPCACHE_RETRY_INTERVAL=0 OPCACHE_RETRY_ATTEMPTS=4 bash $S $BASE R25 5 >$T/s9f.log 2>&1; rc=$?
check "permanent 404: exit 0, current -> R25, one warning after the tries" "[ $rc -eq 0 ] && [ \"\$(readlink $BASE/current)\" = $BASE/releases/R25 ] && grep -q 'still answered 404 for the probe after 4 tries' $T/s9f.log && grep -q 'PREVIOUS release' $T/s9f.log"
check "permanent 404: exactly 4 requests" "[ \$(grep -c '^https.*zz-opcache' $T/curl.log) -eq 4 ]"
check "permanent 404: the file is removed" "[ -z \"\$(ls $BASE/releases/R25/web/zz-opcache-*.php 2>/dev/null)\" ]"

: > $T/curl.log; echo fail > $T/curl-mode; mkrelease R26
OPCACHE_RETRY_INTERVAL=0 bash $S $BASE R26 5 >$T/s9g.log 2>&1
check "403 is not a lag: one request, warned at once" "[ \$(grep -c '^https.*zz-opcache' $T/curl.log) -eq 1 ] && grep -q 'could not request the opcache reset' $T/s9g.log"

echo "WP_ENV=production" > $BASE/shared/.env; : > $T/curl.log; mkrelease R23
bash $S $BASE R23 5 >$T/s9d.log 2>&1; rc=$?
check "no WP_HOME: skipped with a warning, exit 0, curl never called" "[ $rc -eq 0 ] && grep -q 'cannot reset the web opcache' $T/s9d.log && [ ! -s $T/curl.log ]"
check "no WP_HOME: no probe file written" "[ -z \"\$(ls $BASE/releases/R23/web/zz-opcache-*.php 2>/dev/null)\" ]"

echo "=== Scenario 10: the Asset Cache block is added once and re-added when W3TC drops it ==="
check "block present after the earlier deploys, exactly once" "[ \$(grep -c '# BEGIN Asset Cache' $BASE/shared/.htaccess) -eq 1 ] && grep -q 'public, max-age=31536000, immutable' $BASE/shared/.htaccess"
check "no rule touches the unhashed manifests" "! grep -q 'manifest' $BASE/shared/.htaccess"
python3 - "$BASE/shared/.htaccess" <<'PY'
import re,sys
p=sys.argv[1]; s=open(p).read()
s=re.sub(r"\n# BEGIN Asset Cache.*?# END Asset Cache\n","\n",s,flags=re.S)   # what W3TC's regeneration does
open(p,"w").write(s)
PY
check "simulated W3TC rewrite removed it" "! grep -q '# BEGIN Asset Cache' $BASE/shared/.htaccess"
mkrelease R24; bash $S $BASE R24 5 >$T/s10.log 2>&1
check "next deploy re-adds it once" "[ \$(grep -c '# BEGIN Asset Cache' $BASE/shared/.htaccess) -eq 1 ] && grep -q 'Added the Asset Cache block' $T/s10.log"
mkrelease R25; bash $S $BASE R25 5 >$T/s10b.log 2>&1
check "a further deploy does not duplicate it" "[ \$(grep -c '# BEGIN Asset Cache' $BASE/shared/.htaccess) -eq 1 ]"

echo "=== Scenario 11: a hung WP-CLI flush is stopped, and the .htaccess blocks and the prune never wait on it ==="
# Seen in production: a flush hung for 5 minutes, the gateway dropped the connection, the job failed with 255 after the
# release was live. Now the flushes run last, each bounded by WP_CLI_TIMEOUT, and only warn.
for n in 40 41 42 43 44; do mkrelease R$n; touch -d "+$n min" $BASE/releases/R$n; done
python3 - "$BASE/shared/.htaccess" <<'PY'
import re,sys
p=sys.argv[1]; s=open(p).read()
s=re.sub(r"\n# BEGIN Asset Cache.*?# END Asset Cache\n","\n",s,flags=re.S)
open(p,"w").write(s)
PY
touch $T/php83-hang; : > $T/php83.log; start=$(date +%s)
WP_CLI_TIMEOUT=2 bash $S $BASE R44 3 >$T/s11.log 2>&1; rc=$?; took=$(( $(date +%s) - start ))
rm -f $T/php83-hang
check "exits 0 although both flushes hung" "[ $rc -eq 0 ]"
check "finished in well under the stub's 60s hang (took ${took}s)" "[ $took -lt 40 ]"
check "WARN names each timed-out flush" "grep -q 'WARN: wp cache flush timed out after 2s' $T/s11.log && grep -q 'WARN: wp rewrite flush timed out after 2s' $T/s11.log"
check "the release is live and the Asset Cache block was re-asserted" "[ \"\$(readlink $BASE/current)\" = $BASE/releases/R44 ] && grep -q '# BEGIN Asset Cache' $BASE/shared/.htaccess"
check "the prune ran (3 releases kept)" "[ \$(ls $BASE/releases | wc -l) -eq 3 ]"
check "step lines print in order: htaccess, prune, then the flushes" "[ \$(grep -n 'Checking the shared .htaccess blocks\|pruning old releases\|Running: wp cache flush\|Running: wp rewrite flush' $T/s11.log | cut -d: -f1 | tr '\n' ' ' | awk '{print (\$1<\$2 && \$2<\$3 && \$3<\$4) ? \"ordered\" : \"wrong\"}') = ordered ]"
: > $T/php83.log; mkrelease R45
bash $S $BASE R45 3 >$T/s11b.log 2>&1; rc=$?
check "a normal run (no hang): exit 0, both flushes ran, no timeout warning" "[ $rc -eq 0 ] && grep -q 'wp cache flush' $T/php83.log && grep -q 'wp rewrite flush' $T/php83.log && ! grep -q 'timed out' $T/s11b.log"

echo "=== Scenario 12: translation packs: shared languages/ and the language commands after the flushes ==="
check "languages is a shared dir and the release does NOT symlink it (a symlink breaks WordPress's language upgrader; WP_LANG_DIR points at shared/)" "[ -d $BASE/shared/languages ] && [ ! -e $BASE/releases/R45/web/app/languages ] && [ ! -L $BASE/releases/R45/web/app/languages ]"
# migration: a live release with REAL packs; the first deploy that shares languages/ seeds shared/ from it once
OLD_BASE=$BASE; BASE=$T/deploy/langs; mkdir -p $BASE/shared; cp $OLD_BASE/shared/.env $BASE/shared/.env
mkrelease L1; mkdir -p $BASE/releases/L1/web/app/languages/plugins; echo mo > $BASE/releases/L1/web/app/languages/de_DE.mo; echo plmo > $BASE/releases/L1/web/app/languages/plugins/x-de_DE.mo
ln -s $BASE/releases/L1 $BASE/current
mkrelease L2; : > $T/php83.log
PKDE_TEST_WPLANG=de_DE bash $S $BASE L2 5 >$T/s12a.log 2>&1; rc=$?
check "migration: exit 0, the live packs were seeded into shared/languages" "[ $rc -eq 0 ] && [ \"\$(cat $BASE/shared/languages/de_DE.mo)\" = mo ] && [ \"\$(cat $BASE/shared/languages/plugins/x-de_DE.mo)\" = plmo ] && grep -q 'Seeded shared/languages' $T/s12a.log"
check "the new release has no web/app/languages of its own; the packs stay in shared/ (WP_LANG_DIR)" "[ ! -e $BASE/releases/L2/web/app/languages ] && [ ! -L $BASE/releases/L2/web/app/languages ] && [ -f $BASE/shared/languages/de_DE.mo ]"
check "WPLANG=de_DE: core, plugin and theme language commands ran" "grep -q 'language core install de_DE' $T/php83.log && grep -q 'language core update' $T/php83.log && grep -q 'language plugin install --all de_DE' $T/php83.log && grep -q 'language plugin update --all' $T/php83.log && grep -q 'language theme install --all de_DE' $T/php83.log && grep -q 'language theme update --all' $T/php83.log"
check "the language commands come AFTER both flushes" "[ \$(grep -n 'rewrite flush\|language core install' $T/php83.log | cut -d: -f1 | tr '\n' ' ' | awk '{print (\$1<\$2) ? \"ordered\" : \"wrong\"}') = ordered ]"
for lang in "" en_US; do
  mkrelease L3$lang; : > $T/php83.log
  PKDE_TEST_WPLANG=$lang bash $S $BASE L3$lang 5 >$T/s12b.log 2>&1
  check "WPLANG='$lang': no language command runs" "! grep -q 'language ' $T/php83.log && grep -q 'option get WPLANG' $T/php83.log"
done
mkrelease L4; : > $T/php83.log
PKDE_TEST_WPLANG='de_DE;rm -rf /' bash $S $BASE L4 5 >$T/s12c.log 2>&1
check "an odd WPLANG value is ignored with a warning, nothing runs" "! grep -q 'language ' $T/php83.log && grep -q 'ignoring an odd WPLANG' $T/s12c.log"
# a language command that hangs is stopped by WP_CLI_TIMEOUT: only a WARN, the deploy still exits 0
touch $T/php83-hang-lang; mkrelease L5; : > $T/php83.log; start=$(date +%s)
PKDE_TEST_WPLANG=de_DE WP_CLI_TIMEOUT=2 bash $S $BASE L5 5 >$T/s12d.log 2>&1; rc=$?; took=$(( $(date +%s) - start )); rm -f $T/php83-hang-lang
check "a hung language command: exit 0, release live, WARN, finished in ${took}s (<60)" "[ $rc -eq 0 ] && [ \"\$(readlink $BASE/current)\" = $BASE/releases/L5 ] && grep -q 'WARN: wp language core install de_DE timed out after 2s' $T/s12d.log && [ $took -lt 60 ]"
# a failing language command: the WARN carries the first line of WP-CLI's error, the deploy still exits 0
touch $T/php83-fail-lang; mkrelease L6; : > $T/php83.log
PKDE_TEST_WPLANG=en_GB bash $S $BASE L6 5 >$T/s12e.log 2>&1; rc=$?; rm -f $T/php83-fail-lang
check "a failing language install: exit 0 and the WARN names the cause" "[ $rc -eq 0 ] && grep -q 'WARN: wp language core install en_GB failed (exit 1): Error: Could not create directory.' $T/s12e.log"
BASE=$OLD_BASE

echo "=== Scenario 13: WP_CLI_MEMORY override, and the page-cache warm-up after the flushes ==="
OLD_BASE=$BASE; BASE=$T/deploy/warm; mkdir -p $BASE/shared; cp $OLD_BASE/shared/.env $BASE/shared/.env
mkrelease W1; : > $T/php83.log
WP_CLI_MEMORY=768M bash $S $BASE W1 5 >$T/s13a.log 2>&1
check "WP_CLI_MEMORY=768M reaches every wp-cli call" "[ \$(grep 'stub php83' $T/php83.log | grep -vc -- '-d memory_limit=768M ') -eq 0 ] && grep -q 'memory_limit=768M ' $T/php83.log"
check "W3TC not active: no sitemap lookup and no priming" "! grep -q 'pgcache' $T/php83.log"
touch $T/w3tc-active; mkrelease W2; : > $T/php83.log
bash $S $BASE W2 5 >$T/s13b.log 2>&1
check "W3TC active but no preloader sitemap: nothing is primed, and it says so" "grep -q 'option get pgcache.prime.sitemap' $T/php83.log && ! grep -q 'pgcache_prime' $T/php83.log && grep -q 'nothing to warm' $T/s13b.log"
touch $T/w3tc-sitemap; mkrelease W3; : > $T/php83.log
bash $S $BASE W3 5 >$T/s13c.log 2>&1
check "W3TC active with a sitemap: pgcache_prime --start=1 --limit=30 runs after the flushes" "grep -q 'w3-total-cache pgcache_prime --start=1 --limit=30' $T/php83.log && [ \$(grep -n 'rewrite flush\|pgcache_prime' $T/php83.log | cut -d: -f1 | tr '\n' ' ' | awk '{print (\$1<\$2) ? \"ordered\" : \"wrong\"}') = ordered ]"
mkrelease W4; : > $T/php83.log
CACHE_WARM_LIMIT=7 bash $S $BASE W4 5 >$T/s13d.log 2>&1
check "CACHE_WARM_LIMIT=7 is used" "grep -q 'pgcache_prime --start=1 --limit=7' $T/php83.log"
mkrelease W5; : > $T/php83.log
CACHE_WARM_LIMIT='5;rm -rf /' bash $S $BASE W5 5 >$T/s13e.log 2>&1
check "an odd CACHE_WARM_LIMIT warns and primes nothing" "! grep -q 'pgcache_prime' $T/php83.log && grep -q 'CACHE_WARM_LIMIT must be a number' $T/s13e.log"
touch $T/w3tc-prime-hang; mkrelease W6; : > $T/php83.log; start=$(date +%s)
WP_CLI_TIMEOUT=100 CACHE_WARM_TIMEOUT=2 bash $S $BASE W6 5 >$T/s13f.log 2>&1; rc=$?; took=$(( $(date +%s) - start )); rm -f $T/w3tc-prime-hang
check "a priming that hangs is stopped by CACHE_WARM_TIMEOUT (not WP_CLI_TIMEOUT): exit 0, release live, WARN, ${took}s (<60)" "[ $rc -eq 0 ] && [ \"\$(readlink $BASE/current)\" = $BASE/releases/W6 ] && grep -q 'WARN: wp w3-total-cache pgcache_prime.*timed out after 2s' $T/s13f.log && [ $took -lt 60 ]"
# a slow priming (3 s) is NOT cut off by a short WP_CLI_TIMEOUT: it has its own, longer bound (uncached pages take ~4 s each on 20i)
touch $T/w3tc-prime-slow; mkrelease W7; : > $T/php83.log
WP_CLI_TIMEOUT=1 CACHE_WARM_TIMEOUT=30 bash $S $BASE W7 5 >$T/s13g.log 2>&1; rm -f $T/w3tc-prime-slow
check "a slow priming finishes under CACHE_WARM_TIMEOUT even though WP_CLI_TIMEOUT is 1s" "grep -q 'pgcache_prime' $T/php83.log && ! grep -q 'pgcache_prime.*timed out' $T/s13g.log"
mkrelease W8; : > $T/php83.log
bash $S $BASE W8 5 >$T/s13h.log 2>&1
check "the default priming bound is 240s" "grep -q 'Running: wp w3-total-cache pgcache_prime.*(timeout 240s)' $T/s13h.log"
mkrelease W9; : > $T/php83.log
CACHE_WARM_TIMEOUT='5;x' bash $S $BASE W9 5 >$T/s13i.log 2>&1
check "an odd CACHE_WARM_TIMEOUT warns and primes nothing" "! grep -q 'pgcache_prime' $T/php83.log && grep -q 'CACHE_WARM_TIMEOUT must be a number' $T/s13i.log"
BASE=$OLD_BASE

echo "=== Scenario 14: two execs (activate, then post), a prune that moves releases to .trash, acorn optimize, durations ==="
OLD_BASE=$BASE; BASE=$T/deploy/split; mkdir -p $BASE/shared; cp $OLD_BASE/shared/.env $BASE/shared/.env
for r in A1 A2 A3 A4 A5 A6; do mkrelease $r; touch -d "2020-01-0${r#A} 00:00" $BASE/releases/$r 2>/dev/null || true; done
: > $T/php83.log
bash $S $BASE A6 3 activate >$T/s14a.log 2>&1; rc=$?
check "activate: exit 0, the release is live, and no WP-CLI command ran" "[ $rc -eq 0 ] && [ \"\$(readlink $BASE/current)\" = $BASE/releases/A6 ] && [ ! -s $T/php83.log ] && ! grep -q 'Running: wp' $T/s14a.log"
check "activate: says the post part is the next exec and prints its own duration" "grep -q 'the post part' $T/s14a.log && grep -q \"Exec 'activate' finished after [0-9]*s\" $T/s14a.log"
check "activate: the old releases were moved to .trash, not deleted in line (3 kept)" "[ \$(ls $BASE/releases | wc -l) -eq 3 ] && grep -q 'moved to .trash' $T/s14a.log"
check "activate does not start the clean-up (that is the post part's last step)" "! grep -q 'Deleting the pruned releases' $T/s14a.log"
: > $T/php83.log
bash $S $BASE A6 3 post >$T/s14b.log 2>&1; rc=$?
check "post: exit 0; the flushes ran; the activation and .htaccess work did NOT run again" "[ $rc -eq 0 ] && grep -q 'wp cache flush' $T/php83.log && grep -q 'wp rewrite flush' $T/php83.log && ! grep -q 'Activated release' $T/s14b.log && ! grep -q 'Checking the shared .htaccess blocks' $T/s14b.log"
check "post: prints its own duration and starts the background clean-up" "grep -q \"Exec 'post' finished after [0-9]*s\" $T/s14b.log && grep -q 'Deleting the pruned releases in the background' $T/s14b.log"
for i in 1 2 3 4 5 6 7 8 9 10; do [ -z "$(ls -A $BASE/.trash 2>/dev/null)" ] && [ ! -d $BASE/.trash.lock ] && break; sleep 0.5; done
check "the clean-up finishes: .trash is empty and the lock is gone" "[ -z \"\$(ls -A $BASE/.trash)\" ] && [ ! -d $BASE/.trash.lock ]"
mkrelease A7; : > $T/php83.log
bash $S $BASE A7 3 post >$T/s14c.log 2>&1
check "post on a release that is not the live one is skipped with a WARN" "[ ! -s $T/php83.log ] && grep -q 'does not point at release A7' $T/s14c.log"
ln -sfn $BASE/releases/A6 $BASE/current
bash $S $BASE A6 3 bogus >$T/s14d.log 2>&1; rc=$?
check "an unknown mode exits 2" "[ $rc -eq 2 ] && grep -q 'must be all, activate or post' $T/s14d.log"
touch $T/acorn-yes; mkrelease A8; : > $T/php83.log
bash $S $BASE A8 3 >$T/s14e.log 2>&1
check "acorn present: has-command acorn is asked and acorn optimize runs AFTER the flushes" "grep -q 'cli has-command acorn' $T/php83.log && [ \$(grep -n 'rewrite flush\|acorn optimize' $T/php83.log | cut -d: -f1 | tr '\n' ' ' | awk '{print (\$1<\$2) ? \"ordered\" : \"wrong\"}') = ordered ]"
rm -f $T/acorn-yes; mkrelease A9; : > $T/php83.log
bash $S $BASE A9 3 >$T/s14f.log 2>&1
check "acorn absent: acorn optimize does not run" "grep -q 'cli has-command acorn' $T/php83.log && ! grep -q 'acorn optimize' $T/php83.log"
# a slow delete must not hold the exec open: a stub rm that sleeps on .trash paths
for r in B1 B2 B3 B4; do mkrelease $r; touch -d "2019-01-0${r#B} 00:00" $BASE/releases/$r 2>/dev/null || true; done
cat > $T/bin/rm <<RMSTUB
#!/bin/sh
case "\$*" in *.trash*) sleep 4 ;; esac
exec /bin/rm "\$@"
RMSTUB
chmod +x $T/bin/rm; mkrelease B5; : > $T/php83.log
start=$(date +%s); bash $S $BASE B5 2 >$T/s14g.log 2>&1; rc=$?; took=$(( $(date +%s) - start ))
check "a slow delete does not block: exit 0 in ${took}s (< 4s) while .trash still holds releases" "[ $rc -eq 0 ] && [ $took -lt 4 ] && [ -n \"\$(ls -A $BASE/.trash)\" ]"
for i in $(seq 1 30); do [ -z "$(ls -A $BASE/.trash 2>/dev/null)" ] && break; sleep 0.5; done
rm -f $T/bin/rm
check "...and the background delete finishes by itself" "[ -z \"\$(ls -A $BASE/.trash)\" ]"
check "'all' prints one duration line for the whole run" "grep -q \"Exec 'all' finished after [0-9]*s\" $T/s14g.log"
BASE=$OLD_BASE

echo; echo "RESULT: $pass passed, $fail failed"; exit $fail
