#!/usr/bin/env bash
#
# Test for bin/deploy/wordfence-cloudflare.sh (WP-84) with a fake `ssh` (it runs the REAL remote command in a temp directory) and a fake `php83`: the PHP must reach the
# host as ssh STDIN, never on the remote command line (20i closed sessions at inline `wp eval '...'` commands); WP-CLI runs with --skip-plugins --skip-themes, a memory
# limit and eval-file; the temporary files are removed whatever happens; the output is read back; the exit code of the PHP is the script's exit code; the ssh names are
# required.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
S="$REPO_ROOT/bin/deploy/wordfence-cloudflare.sh"
T="$(mktemp -d)" || { echo "mktemp failed" >&2; exit 2; }
trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin" "$T/home" "$T/site/current"
touch "$T/wp"

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1 (expected '$3', got '$2')"; fi; }
has() { if grep -qF -- "$3" <<<"$2"; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1 (missing '$3')"; fi; }
lacks() { if grep -qF -- "$3" <<<"$2"; then fail=$((fail + 1)); echo "FAIL: $1 (found '$3')"; else pass=$((pass + 1)); fi; }

cat > "$T/bin/ssh" <<'SH'
#!/usr/bin/env bash
echo "ssh $*" >> "$FAKE_DIR/ssh.log"
exec bash -c "${@: -1}"
SH
# fake php83: logs its arguments, keeps a copy of the PHP file it was given (the last argument), prints a line and exits with FAKE_RC
cat > "$T/bin/php83" <<'SH'
#!/usr/bin/env bash
echo "php83 $*" >> "$FAKE_DIR/php83.log"
for a in "$@"; do f="$a"; done
cp "$f" "$FAKE_DIR/received.php"
echo "Updated: howGetIPs=REMOTE_ADDR"
exit "${FAKE_RC:-0}"
SH
chmod +x "$T/bin/ssh" "$T/bin/php83"
export FAKE_DIR="$T" HOME="$T/home" PATH="$T/bin:$PATH" STAGING_SSH_HOST=h STAGING_SSH_PORT=22 STAGING_SSH_USER=u STAGING_DEPLOY_PATH="$T/site" WP_CLI="$T/wp"

out="$(bash "$S" staging 2>&1)"; rc=$?
check "exit 0 when the PHP succeeds" "$rc" "0"
has "the output is read back" "$out" "Updated: howGetIPs=REMOTE_ADDR"
has "the PHP arrived through stdin" "$(cat "$T/received.php")" "howGetIPs"
check "the file starts with the PHP tag" "$(head -1 "$T/received.php")" "<?php"
sshlog="$(cat "$T/ssh.log")"
lacks "no PHP on the remote command line (wfconfig)" "$sshlog" "wfconfig"
lacks "no PHP on the remote command line (howGetIPs)" "$sshlog" "howGetIPs"
lacks "no PHP on the remote command line (INSERT)" "$sshlog" "INSERT"
has "WP-CLI skips plugins and themes" "$(cat "$T/php83.log")" "--skip-plugins --skip-themes"
has "WP-CLI runs the file" "$(cat "$T/php83.log")" "eval-file"
has "WP-CLI has a memory limit" "$(cat "$T/php83.log")" "-d memory_limit=512M"
check "the temporary files are gone" "$(ls -A "$T/home")" ""

FAKE_RC=1 bash "$S" staging >/dev/null 2>&1; check "the PHP's exit code is the script's" "$?" "1"
check "the temporary files are gone after a failure too" "$(ls -A "$T/home")" ""

(unset STAGING_SSH_HOST; bash "$S" staging >/dev/null 2>&1; check "a missing ssh name is a usage error" "$?" "2")
bash "$S" nonsense >/dev/null 2>&1; check "an unknown environment is a usage error" "$?" "2"

echo "wordfence-cloudflare: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
