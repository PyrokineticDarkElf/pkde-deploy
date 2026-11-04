#!/usr/bin/env bash
#
# Test for bin/deploy/push-gh-secrets.sh (MGR-71) with a fake `op` and a fake `gh`: no real secrets, no network. The fake values are canary strings, so a
# leak into the script's output (or onto a command line) is caught. Checks the values reach `gh secret set` only through stdin, nothing is printed, a
# missing or empty field stops the run before anything is set, the key must be a reference to an SSH key item and is read in OpenSSH format, a dry run sets
# nothing, GH_TOKEN is required, and the argument checks.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
S="$REPO_ROOT/bin/deploy/push-gh-secrets.sh"
T="$(mktemp -d)" || { echo "mktemp failed" >&2; exit 2; }
trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin" "$T/vals"

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1 (expected '$3', got '$2')"; fi; }

# fake op: `op whoami`; `op read <ref>` prints the file named after the reference (slashes and spaces to _), or fails. The query part is logged.
cat > "$T/bin/op" <<'SH'
#!/usr/bin/env bash
echo "op $*" >> "$FAKE_DIR/op.log"
case "$1" in
  whoami) exit 1 ;; # the real one fails with the desktop-app integration: the script must not rely on it
  vault) [ -e "$FAKE_DIR/signed-out" ] && exit 1; echo "fake-vault"; exit 0 ;;
  read)
    ref="${2%%\?*}"; file="$FAKE_DIR/vals/$(printf '%s' "$ref" | tr '/ :?' '____')"
    [ -f "$file" ] || exit 1
    if [[ "$2" == *"?ssh-format=openssh"* ]]; then cat "$file.openssh" 2>/dev/null || exit 1; else cat "$file"; fi ;;
  *) exit 1 ;;
esac
SH
# fake gh: `gh secret set NAME -R repo` stores stdin under the name (stdin only: a value on the command line would be in gh.log); `gh secret list -R repo`.
cat > "$T/bin/gh" <<'SH'
#!/usr/bin/env bash
echo "gh $*" >> "$FAKE_DIR/gh.log"
[ -n "${GH_TOKEN:-}" ] || exit 4
if [ "$1 $2" = "secret set" ]; then
  [ -t 0 ] && exit 5
  mkdir -p "$FAKE_DIR/set"; cat > "$FAKE_DIR/set/$3"; exit 0
fi
if [ "$1 $2" = "secret list" ]; then for f in "$FAKE_DIR"/set/*; do [ -e "$f" ] && printf '%s\t2026-11-04T12:00:00Z\n' "$(basename "$f")"; done; exit 0; fi
exit 1
SH
chmod +x "$T/bin/op" "$T/bin/gh"
export FAKE_DIR="$T" PATH="$T/bin:$PATH" GH_TOKEN="fake-token-canary"

ITEM="op://Sites/staging.example.com deploy"
val() { printf '%s' "$2" > "$T/vals/$(printf '%s' "$ITEM/$1" | tr '/ :?' '____')"; }
reset() { rm -rf "$T/vals" "$T/set" "$T/op.log" "$T/gh.log" "$T/signed-out"; mkdir -p "$T/vals"; }
fill_staging() {
  val STAGING_DEPLOY_PATH "CANARY-PATH-/srv/site/deploy/staging"
  val STAGING_SSH_HOST "CANARY-HOST.example.test"
  val STAGING_SSH_PORT "CANARY-2222"
  val STAGING_SSH_USER "CANARY-user"
  val STAGING_SSH_KEY "op://Sites/staging deploy key/private key"
  printf '%s' "${PK_OPEN}CANARY-KEY${PK_CLOSE}" > "$T/vals/op___Sites_staging_deploy_key_private_key.openssh"
  printf '%s' "ignored" > "$T/vals/op___Sites_staging_deploy_key_private_key"
}
# The key marker is built at run time so no private-key block sits in the file (the repository's public-safety check looks for one).
PK_OPEN="-----BEGIN $(printf '%s %s' OPENSSH 'PRIVATE KEY')-----"; PK_CLOSE="-----END $(printf '%s %s' OPENSSH 'PRIVATE KEY')-----"
canaries() { grep -c 'CANARY' "$1" 2>/dev/null || true; }

# 1. A full staging run sets the five secrets, from stdin, prints no value, and lists the names.
reset; fill_staging
out="$(bash "$S" "$ITEM" owner/repo 2>&1)"; rc=$?
check "full staging run: exit 0" "$rc" 0
check "sets exactly the five required secrets" "$(ls "$T/set" | tr '\n' ' ')" "STAGING_DEPLOY_PATH STAGING_SSH_HOST STAGING_SSH_KEY STAGING_SSH_PORT STAGING_SSH_USER "
check "the host value reached gh through stdin" "$(cat "$T/set/STAGING_SSH_HOST")" "CANARY-HOST.example.test"
check "the key was read in OpenSSH format, from the referenced item" "$(cut -c1-30 "$T/set/STAGING_SSH_KEY")" "${PK_OPEN:0:30}"
check "the script printed no canary value" "$(printf '%s' "$out" | grep -c CANARY)" 0
check "no value or token on a command line (gh.log has no canary)" "$(canaries "$T/gh.log")" 0
check "the token was never printed" "$(printf '%s' "$out" | grep -c fake-token-canary)" 0
check "the names are listed at the end" "$(printf '%s' "$out" | grep -c '  STAGING_SSH_KEY')" 1
check "ssh-format=openssh was asked for" "$(grep -c 'ssh-format=openssh' "$T/op.log")" 2

# 2. Optional known_hosts: set when present, skipped otherwise.
val STAGING_SSH_KNOWN_HOSTS "CANARY-KNOWN-HOSTS"
rm -rf "$T/set"; out="$(bash "$S" "$ITEM" owner/repo 2>&1)"
check "known_hosts is set when the field exists" "$([ -f "$T/set/STAGING_SSH_KNOWN_HOSTS" ] && echo yes || echo no)" yes
reset; fill_staging; out="$(bash "$S" "$ITEM" owner/repo 2>&1)"
check "known_hosts is reported as skipped when absent" "$(printf '%s' "$out" | grep -c 'STAGING_SSH_KNOWN_HOSTS')" 1
check "...and not set" "$([ -f "$T/set/STAGING_SSH_KNOWN_HOSTS" ] && echo yes || echo no)" no

# 3. A missing required field stops everything before the first set.
reset; fill_staging; rm "$T/vals/$(printf '%s' "$ITEM/STAGING_SSH_USER" | tr '/ :?' '____')"
out="$(bash "$S" "$ITEM" owner/repo 2>&1)"; rc=$?
check "a missing field: non-zero exit" "$([ "$rc" -ne 0 ] && echo yes || echo no)" yes
check "...naming the field" "$(printf '%s' "$out" | grep -c STAGING_SSH_USER)" 1
check "...with nothing set" "$([ -d "$T/set" ] && echo some || echo none)" none

# 4. An empty field is refused too.
reset; fill_staging; val STAGING_SSH_PORT ""
out="$(bash "$S" "$ITEM" owner/repo 2>&1)"; rc=$?
check "an empty field: refused, nothing set" "$([ "$rc" -ne 0 ] && [ ! -d "$T/set" ] && echo yes || echo no)" yes

# 5. A private key pasted into the key field (not a reference) is refused without printing it.
reset; fill_staging; val STAGING_SSH_KEY "${PK_OPEN}CANARY-PASTED-KEY"
out="$(bash "$S" "$ITEM" owner/repo 2>&1)"; rc=$?
check "a pasted key: refused, nothing set" "$([ "$rc" -ne 0 ] && [ ! -d "$T/set" ] && echo yes || echo no)" yes
check "...and never echoed" "$(printf '%s' "$out" | grep -c CANARY)" 0

# 5b. One SSH Key item that holds the key AND the host details: with no STAGING_SSH_KEY field the item's own private key is used.
reset; fill_staging; rm "$T/vals/$(printf '%s' "$ITEM/STAGING_SSH_KEY" | tr '/ :?' '____')"
own="$T/vals/$(printf '%s' "$ITEM/private key" | tr '/ :?' '____')"
printf '%s' "ignored" > "$own"; printf '%s' "${PK_OPEN}CANARY-OWN-KEY${PK_CLOSE}" > "$own.openssh"
out="$(bash "$S" "$ITEM" owner/repo 2>&1)"; rc=$?
check "an SSH Key item with no key field: exit 0, five secrets set" "$([ "$rc" -eq 0 ] && ls "$T/set" | wc -l | tr -d ' ')" 5
check "...the key is the item's own private key" "$(grep -c 'CANARY-OWN-KEY' "$T/set/STAGING_SSH_KEY")" 1
check "...and nothing was printed" "$(printf '%s' "$out" | grep -c CANARY)" 0
reset; fill_staging; rm "$T/vals/$(printf '%s' "$ITEM/STAGING_SSH_KEY" | tr '/ :?' '____')"
out="$(bash "$S" "$ITEM" owner/repo 2>&1)"; rc=$?
check "no key field and no private key on the item: refused, nothing set" "$([ "$rc" -ne 0 ] && [ ! -d "$T/set" ] && echo yes || echo no)" yes

# 6. Dry run: names only, nothing set, no values.
reset; fill_staging
out="$(bash "$S" "$ITEM" owner/repo --dry-run 2>&1)"; rc=$?
check "dry run: exit 0, nothing set" "$([ "$rc" -eq 0 ] && [ ! -d "$T/set" ] && echo yes || echo no)" yes
check "dry run lists the names" "$(printf '%s' "$out" | grep -c STAGING_DEPLOY_PATH)" 1
check "dry run prints no value" "$(printf '%s' "$out" | grep -c CANARY)" 0
check "dry run never calls gh secret set" "$([ -f "$T/gh.log" ] && grep -c 'secret set' "$T/gh.log" || echo 0)" 0

# 7. --env both needs the prod fields too.
reset; fill_staging
out="$(bash "$S" "$ITEM" owner/repo --env both --dry-run 2>&1)"; rc=$?
check "--env both without prod fields: refused" "$([ "$rc" -ne 0 ] && echo yes || echo no)" yes
check "...naming a PROD field" "$(printf '%s' "$out" | grep -c 'PROD_')" 1

# 8. Guard rails.
reset; fill_staging
out="$(env -u GH_TOKEN bash "$S" "$ITEM" owner/repo 2>&1)"; rc=$?
check "no GH_TOKEN: refused" "$([ "$rc" -ne 0 ] && [ ! -d "$T/set" ] && echo yes || echo no)" yes
touch "$T/signed-out"; out="$(bash "$S" "$ITEM" owner/repo 2>&1)"; rc=$?
check "op signed out: refused" "$([ "$rc" -ne 0 ] && [ ! -d "$T/set" ] && echo yes || echo no)" yes
rm "$T/signed-out"
check "an item reference with a field: refused" "$(bash "$S" "op://Sites/item/field" owner/repo >/dev/null 2>&1; echo $?)" 1
check "a bad repository: refused" "$(bash "$S" "$ITEM" "owner;rm -rf" >/dev/null 2>&1; echo $?)" 1
check "a bad --env: refused" "$(bash "$S" "$ITEM" owner/repo --env nope >/dev/null 2>&1; echo $?)" 1
check "no arguments: usage, exit 2" "$(bash "$S" >/dev/null 2>&1; echo $?)" 2

# 9. The script itself never traces, prints a value, or holds one in a variable.
check "no set -x" "$(grep -c '^set -x\|set -o xtrace' "$S")" 0
check "the only command substitution of op read holds the key REFERENCE (never a value)" "$(grep -c 'held="\$(op read' "$S")" "$(grep -c '=\$(op read\|="\$(op read' "$S")"

echo "push-gh-secrets: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
