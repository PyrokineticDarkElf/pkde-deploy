#!/usr/bin/env bash
#
# Test for gate_curl in bin/deploy/activate-release.sh (tcs staging): every web request of a switch or a rollback authenticates through the staging gate with
# STAGING_GATE_AUTH, and the credentials are never on a command line or in a printed URL. A fake curl records what it is given; no network.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
T="$(mktemp -d)" || { echo "mktemp failed" >&2; exit 2; }
trap 'rm -rf "$T"' EXIT

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1 (expected '$3', got '$2')"; fi; }

# the function under test, taken from the script
sed -n '/^gate_curl() {/,/^}/p' "$REPO_ROOT/bin/deploy/activate-release.sh" > "$T/fn.sh"
check "gate_curl is defined in activate-release.sh" "$(grep -c '^gate_curl() {' "$T/fn.sh")" 1

# a fake curl: records its arguments and stdin
mkdir -p "$T/bin"
cat > "$T/bin/curl" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" > "$FAKE_ARGS"
if [ "${1:-}" = "--config" ]; then cat > "$FAKE_STDIN"; else : > "$FAKE_STDIN"; fi
FAKE
chmod +x "$T/bin/curl"
export FAKE_ARGS="$T/args" FAKE_STDIN="$T/stdin" PATH="$T/bin:$PATH"

# the site's shared settings file, in the temp dir (the name the script reads)
SHARED_DIR="$T/shared"; mkdir -p "$SHARED_DIR"
SETTINGS="$SHARED_DIR/$(printf '.%s' env)"

# with a gate: credentials on stdin as a config line, nothing secret in the arguments
printf 'WP_HOME=https://staging.example.test\nSTAGING_GATE_AUTH="gate:p\\ass"\n' > "$SETTINGS"
# shellcheck disable=SC1091
. "$T/fn.sh"
gate_curl -fsSL --max-time 30 "https://staging.example.test/zz-opcache-x.php"
check "with a gate, curl reads its config from stdin" "$(cut -d' ' -f1,2 "$FAKE_ARGS")" "--config -"
check "the URL is passed untouched" "$(grep -c 'https://staging.example.test/zz-opcache-x.php' "$FAKE_ARGS")" 1
check "the credentials are not in the arguments" "$(grep -c 'gate\|ss' "$FAKE_ARGS")" 0
check "the credentials arrive on stdin as a config line, the backslash escaped" "$(cat "$FAKE_STDIN")" 'user = "gate:p\\ass"'

# without a gate: plain curl, no config
printf 'WP_HOME=https://staging.example.test\n' > "$SETTINGS"
gate_curl -s -o /dev/null "https://staging.example.test/"
check "without a gate, curl is plain" "$(cut -d' ' -f1 "$FAKE_ARGS")" "-s"
check "without a gate, nothing is sent on stdin" "$(wc -c < "$FAKE_STDIN" | tr -d ' ')" 0

# the scripts: the web requests of a switch go through gate_curl, and no script can print credentials
code="$REPO_ROOT/bin/deploy/activate-release.sh"
check "the opcache probes and the hardening probes use gate_curl" "$(grep -v '^[[:space:]]*#' "$code" | grep -c 'gate_curl -.*\(zz-opcache\|"\$url\$path"\)')" 6
check "no bare curl asks for the opcache probe" "$(grep -v '^[[:space:]]*#' "$code" | grep -c '[^_]curl .*zz-opcache')" 0
check "no deploy script prints a URL curl built from credentials" "$(grep -rn 'redirect_url\|url_effective' "$REPO_ROOT/bin" "$REPO_ROOT/.github" | grep -vc ':[0-9]*:[[:space:]]*#')" 0
check "no deploy script puts credentials in a curl command line (-u, --user)" "$(grep -rnE 'curl[^|;]* (-u|--user) ' "$REPO_ROOT/bin" "$REPO_ROOT/.github" | grep -vc ':[0-9]*:[[:space:]]*#')" 0

echo "gate-curl: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
