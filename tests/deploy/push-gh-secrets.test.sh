#!/usr/bin/env bash
#
# Test for bin/deploy/push-gh-secrets.sh (MGR-71) with a fake `op` and a fake `gh`: no real secrets, no network. The fake values are canary strings, so a
# leak into the script's output (or onto a command line) is caught. Checks: the values reach `gh secret set` only through stdin and nothing is printed; the
# item is found by title or op:// reference; the standard layout (an SSH Key item with DEPLOY_PATH, SSH_HOST, SSH_PORT, SSH_USER and the private key);
# --env is required and must match the item's title; a missing or empty field stops the run before anything is set; an existing secret is refused without
# --overwrite; a dry run sets nothing; GH_TOKEN is required; the argument checks.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
S="$REPO_ROOT/bin/deploy/push-gh-secrets.sh"
T="$(mktemp -d)" || { echo "mktemp failed" >&2; exit 2; }
trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin"

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1 (expected '$3', got '$2')"; fi; }

# fake op: `op vault list`; `op item list --format json` (titles and ids, no values); `op read op://vault/item/field[?ssh-format=openssh]` prints
# vals/<item>/<field> (the .openssh variant for the key), or fails. `op whoami` fails on purpose: the real one does with the desktop-app integration.
cat > "$T/bin/op" <<'SH'
#!/usr/bin/env bash
echo "op $*" >> "$FAKE_DIR/op.log"
case "$1" in
  whoami) exit 1 ;;
  vault) [ -e "$FAKE_DIR/signed-out" ] && exit 1; echo "fake-vault"; exit 0 ;;
  item) cat "$FAKE_DIR/items.json"; exit 0 ;;
  read)
    ref="${2%%\?*}"; rest="${ref#op://}"; vault="${rest%%/*}"; rest="${rest#*/}"; itemseg="${rest%%/*}"; field="${rest#*/}"
    # an item id is mapped back to its title (what the values are filed under)
    title="$(python3 -c 'import json,sys
for i in json.load(open(sys.argv[1])):
    if i["id"] == sys.argv[2]: print(i["title"])' "$FAKE_DIR/items.json" "$itemseg")"
    [ -n "$title" ] && itemseg="$title"
    file="$FAKE_DIR/vals/${itemseg// /_}/${field// /_}"
    [ -f "$file" ] || exit 1
    if [[ "$2" == *"?ssh-format=openssh"* ]]; then cat "$file.openssh" 2>/dev/null || exit 1; else cat "$file"; fi ;;
  *) exit 1 ;;
esac
SH
# fake gh: `gh secret set NAME -R repo` stores stdin under the name (never an argument); `gh secret list -R repo` lists what is stored plus "existing".
cat > "$T/bin/gh" <<'SH'
#!/usr/bin/env bash
echo "gh $*" >> "$FAKE_DIR/gh.log"
[ -n "${GH_TOKEN:-}" ] || exit 4
if [ "$1 $2" = "secret set" ]; then [ -t 0 ] && exit 5; mkdir -p "$FAKE_DIR/set"; cat > "$FAKE_DIR/set/$3"; exit 0; fi
if [ "$1 $2" = "secret list" ]; then
  [ -f "$FAKE_DIR/existing" ] && while read -r n; do printf '%s\t2026-01-01T00:00:00Z\n' "$n"; done < "$FAKE_DIR/existing"
  for f in "$FAKE_DIR"/set/*; do [ -e "$f" ] && printf '%s\t2026-11-04T12:00:00Z\n' "$(basename "$f")"; done; exit 0
fi
exit 1
SH
chmod +x "$T/bin/op" "$T/bin/gh"
export FAKE_DIR="$T" PATH="$T/bin:$PATH" GH_TOKEN="fake-token-canary"

# The key marker is built at run time so no private-key block sits in the file (the repository's public-safety check looks for one).
PK_OPEN="-----BEGIN $(printf '%s %s' OPENSSH 'PRIVATE KEY')-----"; PK_CLOSE="-----END $(printf '%s %s' OPENSSH 'PRIVATE KEY')-----"
STAGING_TITLE="Deploy - staging.example.com"; PROD_TITLE="Deploy - example.com"
items() { printf '[{"id":"stagingitemid000000000000aa","title":"%s","vault":{"id":"vaultid","name":"Sites"}},{"id":"proditemid00000000000000bb","title":"%s","vault":{"id":"vaultid","name":"Sites"}}]' "$STAGING_TITLE" "$PROD_TITLE" > "$T/items.json"; }
val() { mkdir -p "$T/vals/${1// /_}"; printf '%s' "$3" > "$T/vals/${1// /_}/${2// /_}"; }
reset() { rm -rf "$T/vals" "$T/set" "$T/op.log" "$T/gh.log" "$T/signed-out" "$T/existing"; items; }
fill() { # <item title> <label>
  val "$1" DEPLOY_PATH "CANARY-PATH-/srv/site/deploy/$2"; val "$1" SSH_HOST "CANARY-HOST.example.test"; val "$1" SSH_PORT "CANARY-2222"; val "$1" SSH_USER "CANARY-user"
  val "$1" "private key" "ignored"; printf '%s' "${PK_OPEN}CANARY-KEY-$2${PK_CLOSE}" > "$T/vals/${1// /_}/private_key.openssh"
}

# 1. A staging run by title: sets the five secrets from stdin, prints no value, lists the names.
reset; fill "$STAGING_TITLE" staging
out="$(bash "$S" "$STAGING_TITLE" owner/repo --env staging 2>&1)"; rc=$?
check "full staging run by title: exit 0" "$rc" 0
check "sets the five required secrets with the STAGING_ prefix" "$(ls "$T/set" | tr '\n' ' ')" "STAGING_DEPLOY_PATH STAGING_SSH_HOST STAGING_SSH_KEY STAGING_SSH_PORT STAGING_SSH_USER "
check "the host value reached gh through stdin" "$(cat "$T/set/STAGING_SSH_HOST")" "CANARY-HOST.example.test"
check "the key is the item's own private key, in OpenSSH format" "$(cat "$T/set/STAGING_SSH_KEY" | grep -c CANARY-KEY-staging)" 1
check "the script printed no canary value" "$(printf '%s' "$out" | grep -c CANARY)" 0
check "no value or token on a command line (gh.log has no canary)" "$([ -f "$T/gh.log" ] && { grep -c CANARY "$T/gh.log" || true; } || echo 0)" 0
check "the token was never printed" "$(printf '%s' "$out" | grep -c fake-token-canary)" 0
check "the names are listed at the end" "$(printf '%s' "$out" | grep -c '  STAGING_SSH_KEY')" 1
check "the title was resolved without reading any value (item list)" "$(grep -c 'item list' "$T/op.log")" 1

# 2. The same by op://vault/item, and a prod item with PROD_.
reset; fill "$PROD_TITLE" prod
out="$(bash "$S" "op://Sites/$PROD_TITLE" owner/repo --env prod 2>&1)"; rc=$?
check "prod run by op:// reference: exit 0 with PROD_ secrets" "$([ "$rc" -eq 0 ] && [ -f "$T/set/PROD_SSH_HOST" ] && [ ! -f "$T/set/STAGING_SSH_HOST" ] && echo yes || echo no)" yes

# 3. Optional known_hosts.
reset; fill "$STAGING_TITLE" staging; val "$STAGING_TITLE" SSH_KNOWN_HOSTS "CANARY-KNOWN-HOSTS"
bash "$S" "$STAGING_TITLE" owner/repo --env staging >/dev/null 2>&1
check "known_hosts is set when the field exists" "$([ -f "$T/set/STAGING_SSH_KNOWN_HOSTS" ] && echo yes || echo no)" yes
reset; fill "$STAGING_TITLE" staging; out="$(bash "$S" "$STAGING_TITLE" owner/repo --env staging 2>&1)"
check "known_hosts is reported as skipped when absent" "$(printf '%s' "$out" | grep -c 'STAGING_SSH_KNOWN_HOSTS')" 1

# 4. --env must match the title, and is required.
reset; fill "$STAGING_TITLE" staging
out="$(bash "$S" "$STAGING_TITLE" owner/repo --env prod 2>&1)"; rc=$?
check "a staging item with --env prod: refused, nothing set" "$([ "$rc" -ne 0 ] && [ ! -d "$T/set" ] && echo yes || echo no)" yes
reset; fill "$PROD_TITLE" prod
out="$(bash "$S" "$PROD_TITLE" owner/repo --env staging 2>&1)"; rc=$?
check "a prod item with --env staging: refused, nothing set" "$([ "$rc" -ne 0 ] && [ ! -d "$T/set" ] && echo yes || echo no)" yes
check "--env is required (never guessed)" "$(bash "$S" "$STAGING_TITLE" owner/repo >/dev/null 2>&1; echo $?)" 1
check "an unknown title: refused" "$(bash "$S" "Deploy - nope" owner/repo --env staging >/dev/null 2>&1; echo $?)" 1

# 5. A missing or empty required field stops everything before the first set.
reset; fill "$STAGING_TITLE" staging; rm "$T/vals/${STAGING_TITLE// /_}/SSH_USER"
out="$(bash "$S" "$STAGING_TITLE" owner/repo --env staging 2>&1)"; rc=$?
check "a missing field: non-zero exit, naming it, nothing set" "$([ "$rc" -ne 0 ] && printf '%s' "$out" | grep -q SSH_USER && [ ! -d "$T/set" ] && echo yes || echo no)" yes
reset; fill "$STAGING_TITLE" staging; val "$STAGING_TITLE" SSH_PORT ""
out="$(bash "$S" "$STAGING_TITLE" owner/repo --env staging 2>&1)"; rc=$?
check "an empty field: refused, nothing set" "$([ "$rc" -ne 0 ] && [ ! -d "$T/set" ] && echo yes || echo no)" yes
reset; fill "$STAGING_TITLE" staging; rm "$T/vals/${STAGING_TITLE// /_}/private_key.openssh"
out="$(bash "$S" "$STAGING_TITLE" owner/repo --env staging 2>&1)"; rc=$?
check "no OpenSSH private key: refused, nothing set" "$([ "$rc" -ne 0 ] && [ ! -d "$T/set" ] && echo yes || echo no)" yes

# 6. Existing secrets are refused without --overwrite, replaced with it.
reset; fill "$STAGING_TITLE" staging; printf 'STAGING_SSH_HOST\nSTAGING_SSH_KEY\n' > "$T/existing"
out="$(bash "$S" "$STAGING_TITLE" owner/repo --env staging 2>&1)"; rc=$?
check "existing secrets without --overwrite: refused, nothing set, the names listed" "$([ "$rc" -ne 0 ] && [ ! -d "$T/set" ] && printf '%s' "$out" | grep -q 'STAGING_SSH_HOST' && echo yes || echo no)" yes
out="$(bash "$S" "$STAGING_TITLE" owner/repo --env staging --overwrite 2>&1)"; rc=$?
check "with --overwrite they are replaced" "$([ "$rc" -eq 0 ] && [ -f "$T/set/STAGING_SSH_HOST" ] && echo yes || echo no)" yes

# 7. Dry run: names only, says new or overwrites, sets nothing, no values.
reset; fill "$STAGING_TITLE" staging; printf 'STAGING_SSH_HOST\n' > "$T/existing"
out="$(bash "$S" "$STAGING_TITLE" owner/repo --env staging --dry-run 2>&1)"; rc=$?
check "dry run: exit 0, nothing set" "$([ "$rc" -eq 0 ] && [ ! -d "$T/set" ] && echo yes || echo no)" yes
check "dry run marks the existing one as an overwrite and the rest new" "$(printf '%s' "$out" | grep -c 'OVERWRITES\|(new)')" 5
check "dry run prints no value" "$(printf '%s' "$out" | grep -c CANARY)" 0
check "dry run never calls gh secret set" "$([ -f "$T/gh.log" ] && { grep -c 'secret set' "$T/gh.log" || true; } || echo 0)" 0

# 8. Guard rails.
reset; fill "$STAGING_TITLE" staging
out="$(env -u GH_TOKEN bash "$S" "$STAGING_TITLE" owner/repo --env staging 2>&1)"; rc=$?
check "no GH_TOKEN: refused" "$([ "$rc" -ne 0 ] && [ ! -d "$T/set" ] && echo yes || echo no)" yes
touch "$T/signed-out"; out="$(bash "$S" "$STAGING_TITLE" owner/repo --env staging 2>&1)"; rc=$?
check "op locked or signed out: refused" "$([ "$rc" -ne 0 ] && [ ! -d "$T/set" ] && echo yes || echo no)" yes
rm "$T/signed-out"
check "an item reference with a field: refused" "$(bash "$S" "op://Sites/item/field" owner/repo --env staging >/dev/null 2>&1; echo $?)" 1
check "a bad repository: refused" "$(bash "$S" "$STAGING_TITLE" "owner;rm -rf" --env staging >/dev/null 2>&1; echo $?)" 1
check "a bad --env: refused" "$(bash "$S" "$STAGING_TITLE" owner/repo --env nope >/dev/null 2>&1; echo $?)" 1
check "no arguments: usage, exit 2" "$(bash "$S" >/dev/null 2>&1; echo $?)" 2

# 9. The script itself never traces and never holds a value in a variable.
check "no set -x" "$(grep -c '^set -x\|set -o xtrace' "$S")" 0
check "no variable is assigned from op read" "$(grep -c '=\$(op read\|="\$(op read' "$S")" 0

echo "push-gh-secrets: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
