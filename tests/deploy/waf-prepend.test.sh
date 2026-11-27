#!/usr/bin/env bash
#
# Test for bin/deploy/waf-prepend.sh: Wordfence Extended Protection written at paths that survive releases. Checks: nothing happens without the opt-in; with it the
# shim lands in shared/waf and web/.user.ini gets Wordfence's own marked block; the shim is valid PHP, includes the bootstrap through the stable `current` path, defines
# WFWAF_LOG_PATH from the .env value (else shared/wflogs/), and does NOTHING (no error) when the plugin is gone; a second run changes nothing; another auto_prepend_file
# in an existing .user.ini is commented out and other lines are kept; turning the flag off removes only our block; a release without Wordfence is left alone; a path with a
# quote is refused. No value from the .env is ever printed.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
S="$REPO_ROOT/bin/deploy/waf-prepend.sh"
T="$(mktemp -d)" || { echo "mktemp failed" >&2; exit 2; }
trap 'rm -rf "$T"' EXIT

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1 (expected '$3', got '$2')"; fi; }
has() { if grep -qF -- "$3" <<<"$2"; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1 (missing '$3')"; fi; }
lacks() { if grep -qF -- "$3" <<<"$2"; then fail=$((fail + 1)); echo "FAIL: $1 (found '$3')"; else pass=$((pass + 1)); fi; }

SHARED="$T/deploy/shared"; REL="$T/deploy/releases/r1"; CUR="$T/deploy/current"
mkdir -p "$SHARED" "$REL/web/app/plugins/wordfence/waf" "$REL/web"
cat > "$REL/web/app/plugins/wordfence/waf/bootstrap.php" <<'PHP'
<?php
echo "BOOTSTRAP-LOADED log=", defined('WFWAF_LOG_PATH') ? WFWAF_LOG_PATH : 'unset', "\n";
PHP
ln -s "$REL" "$CUR"
printf 'WP_ENV=staging\nDB_PASSWORD=canary-never-printed-value\n' > "$SHARED/.env"

# 1. no opt-in: nothing written
out="$(bash "$S" "$REL" "$SHARED" "$CUR" 2>&1)"; rc=$?
check "no opt-in: exit 0" "$rc" "0"
has "no opt-in: says so" "$out" "not enabled"
check "no opt-in: no shim" "$([ -e "$SHARED/waf" ] && echo yes || echo no)" "no"
check "no opt-in: no .user.ini" "$([ -e "$REL/web/.user.ini" ] && echo yes || echo no)" "no"
lacks "no value printed" "$out" "canary-never-printed-value"

# 2. opt-in
printf 'WORDFENCE_EXTENDED_PROTECTION=1\n' >> "$SHARED/.env"
out="$(bash "$S" "$REL" "$SHARED" "$CUR" 2>&1)"; rc=$?
check "opt-in: exit 0" "$rc" "0"
has "opt-in: reports" "$out" "Wordfence extended protection: shim at"
lacks "opt-in: no value printed" "$out" "canary-never-printed-value"
INI="$(cat "$REL/web/.user.ini")"
has ".user.ini has Wordfence's begin marker" "$INI" "; Wordfence WAF"
has ".user.ini has the end marker" "$INI" "; END Wordfence WAF"
has ".user.ini points at the shared shim" "$INI" "auto_prepend_file = '$SHARED/waf/wordfence-waf.php'"
lacks ".user.ini does not name the release" "$INI" "releases/r1"
php -l "$SHARED/waf/wordfence-waf.php" >/dev/null 2>&1; check "the shim is valid PHP" "$?" "0"
has "the shim uses the current link" "$(cat "$SHARED/waf/wordfence-waf.php")" "$CUR/web/app/plugins/wordfence/waf/bootstrap.php"
lacks "the shim does not name the release" "$(cat "$SHARED/waf/wordfence-waf.php")" "releases/r1"
run="$(php "$SHARED/waf/wordfence-waf.php" 2>&1)"
has "the shim loads the bootstrap" "$run" "BOOTSTRAP-LOADED"
has "the log path defaults to shared/wflogs/" "$run" "log=$SHARED/wflogs/"

# 3. a second run changes nothing
before="$(cat "$REL/web/.user.ini")$(cat "$SHARED/waf/wordfence-waf.php")"
bash "$S" "$REL" "$SHARED" "$CUR" >/dev/null 2>&1
check "idempotent" "$(cat "$REL/web/.user.ini")$(cat "$SHARED/waf/wordfence-waf.php")" "$before"
check "one begin marker only" "$(grep -c '^; Wordfence WAF$' "$REL/web/.user.ini")" "1"

# 4. the log path comes from the .env
printf 'WFWAF_LOG_PATH=%s/custom/wflogs\n' "$T" >> "$SHARED/.env"
bash "$S" "$REL" "$SHARED" "$CUR" >/dev/null 2>&1
has "WFWAF_LOG_PATH from .env" "$(php "$SHARED/waf/wordfence-waf.php" 2>&1)" "log=$T/custom/wflogs/"

# 5. the plugin is gone (a release switched, or Wordfence removed): the shim does nothing and does not error
mv "$REL/web/app/plugins/wordfence" "$T/wf-away"
out="$(php "$SHARED/waf/wordfence-waf.php" 2>&1)"; rc=$?
check "plugin gone: shim exits 0" "$rc" "0"
check "plugin gone: shim prints nothing" "$out" ""
mv "$T/wf-away" "$REL/web/app/plugins/wordfence"

# 6. an existing .user.ini: other settings kept, another auto_prepend_file commented out, our block replaced not duplicated
printf 'memory_limit = 256M\nauto_prepend_file = /somewhere/else.php\n' > "$REL/web/.user.ini"
bash "$S" "$REL" "$SHARED" "$CUR" >/dev/null 2>&1
INI="$(cat "$REL/web/.user.ini")"
has "other settings kept" "$INI" "memory_limit = 256M"
has "another prepend commented out" "$INI" ";auto_prepend_file = /somewhere/else.php"
check "exactly one active auto_prepend_file" "$(grep -c "^auto_prepend_file" "$REL/web/.user.ini")" "1"

# 7. turning the flag off removes our block and keeps the rest
sed -i.bak 's/^WORDFENCE_EXTENDED_PROTECTION=1$/WORDFENCE_EXTENDED_PROTECTION=0/' "$SHARED/.env" && rm -f "$SHARED/.env.bak"
out="$(bash "$S" "$REL" "$SHARED" "$CUR" 2>&1)"
has "flag off: says it removed the block" "$out" "removed the block"
INI="$(cat "$REL/web/.user.ini")"
lacks "flag off: our block is gone" "$INI" "Wordfence WAF"
has "flag off: other settings kept" "$INI" "memory_limit = 256M"

# 8. a release without Wordfence is left alone, with a warning
sed -i.bak 's/^WORDFENCE_EXTENDED_PROTECTION=0$/WORDFENCE_EXTENDED_PROTECTION=1/' "$SHARED/.env" && rm -f "$SHARED/.env.bak"
REL2="$T/deploy/releases/r2"; mkdir -p "$REL2/web"
out="$(bash "$S" "$REL2" "$SHARED" "$CUR" 2>&1)"; rc=$?
check "no plugin: exit 0" "$rc" "0"
has "no plugin: warns" "$out" "no Wordfence plugin"
check "no plugin: no .user.ini written" "$([ -e "$REL2/web/.user.ini" ] && echo yes || echo no)" "no"

# 10. the one-command rollback (--rollback <shared> <current>): flag off in .env, ONLY Wordfence's block leaves the live release's .user.ini (the opcache block stays)
sed -i.bak 's/^WORDFENCE_EXTENDED_PROTECTION=0$/WORDFENCE_EXTENDED_PROTECTION=1/' "$SHARED/.env" && rm -f "$SHARED/.env.bak"
printf '; pkde opcache\nopcache.revalidate_path = 1\n; END pkde opcache\n' > "$REL/web/.user.ini"
bash "$S" "$REL" "$SHARED" "$CUR" >/dev/null 2>&1
has "rollback setup: the block is in place" "$(cat "$REL/web/.user.ini")" "auto_prepend_file = '$SHARED/waf/wordfence-waf.php'"
out="$(bash "$S" --rollback "$SHARED" "$CUR" 2>&1)"; rc=$?
check "rollback: exit 0" "$rc" "0"
has "rollback: says it removed the block" "$out" "removed the block"
INI="$(cat "$REL/web/.user.ini")"
lacks "rollback: Wordfence's block is gone" "$INI" "auto_prepend_file"
has "rollback: the opcache block stays" "$INI" "opcache.revalidate_path = 1"
check "rollback: the flag is 0 in .env" "$(grep -c '^WORDFENCE_EXTENDED_PROTECTION=0$' "$SHARED/.env")" "1"
check "rollback: the rest of .env is untouched" "$(grep -c '^DB_PASSWORD=canary-never-printed-value$' "$SHARED/.env")" "1"
lacks "rollback: prints no .env value" "$out" "canary-never-printed-value"
out="$(bash "$S" "$REL" "$SHARED" "$CUR" 2>&1)"
check "after rollback a release does not bring the block back" "$(grep -c '^auto_prepend_file' "$REL/web/.user.ini")" "0"
# no flag line at all, and an .env without a final newline: the line is appended on its own line
printf 'WP_ENV=staging' > "$SHARED/.env"
bash "$S" --rollback "$SHARED" "$CUR" >/dev/null 2>&1
check "rollback without a flag line: appended on its own line" "$(tail -2 "$SHARED/.env" | tr '\n' '|')" "WP_ENV=staging|WORDFENCE_EXTENDED_PROTECTION=0|"
bash "$S" --rollback "$SHARED" "$T/nowhere" >/dev/null 2>&1; check "rollback with a missing current link: usage error" "$?" "2"

# 9. argument checks and a quote in a path
bash "$S" >/dev/null 2>&1; check "no arguments: usage error" "$?" "2"
bash "$S" "$REL" "$SHARED" "$T/it's" >/dev/null 2>&1; check "a quote in a path is refused" "$?" "2"

echo "waf-prepend: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
