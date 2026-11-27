#!/usr/bin/env bash
#
# Test for hit_all_workers in bin/deploy/activate-release.sh (tcs staging): when every parallel probe request reaches a worker of its own the pool may have more workers
# than requests, so the probe is requested again with twice as many until a worker answers twice (the pool is covered) or the cap. A fake gate_curl plays a pool of N workers;
# no network.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
T="$(mktemp -d)" || { echo "mktemp failed" >&2; exit 2; }
trap 'rm -rf "$T"' EXIT

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1 (expected '$3', got '$2')"; fi; }

sed -n '/^hit_all_workers() {/,/^}/p' "$REPO_ROOT/bin/deploy/activate-release.sh" > "$T/fn.sh"
check "hit_all_workers is defined in activate-release.sh" "$(grep -c '^hit_all_workers() {' "$T/fn.sh")" 1

# a pool of $POOL workers: request n is answered by worker ((n-1) mod POOL)+1, in one opcache instance
gate_curl() {
    local url="${*: -1}" n
    n="$(printf '%s' "$url" | sed -n 's/.*[?&]n=\([0-9]*\).*/\1/p')"
    printf 'ok 20261124\npool=1 pid=%s reset=true sapi=fpm\n' "$(( (n - 1) % POOL + 1 ))"
}
# shellcheck disable=SC1091
. "$T/fn.sh"

run() { POOL="$1" OPCACHE_EXTRA_HITS="${2:-16}" OPCACHE_MAX_HITS="${3:-64}" hit_all_workers "https://staging.example.test" "tok" 2>&1; }

# a pool of 11 (under 16): one round, nothing more to say
out="$(run 11)"
check "11 workers: one round" "$(printf '%s\n' "$out" | grep -c 'parallel probe requests reached')" 1
check "11 workers: no second request, no warning" "$(printf '%s\n' "$out" | grep -c 'requesting again\|WARN')" 0

# a pool of 40: 16 reach 16 distinct, 32 reach 32 distinct, 64 reach 40 (a worker answered twice): three rounds, no warning
out="$(run 40)"
check "40 workers: three rounds" "$(printf '%s\n' "$out" | grep -c 'parallel probe requests reached')" 3
check "40 workers: asked again with 32 and with 64" "$(printf '%s\n' "$out" | grep -o 'requesting again with [0-9]*' | tr '\n' ',')" "requesting again with 32,requesting again with 64,"
check "40 workers: the last round reached all 40" "$(printf '%s\n' "$out" | grep 'parallel probe requests reached' | tail -1 | grep -o 'reached [0-9]* distinct' )" "reached 40 distinct"
check "40 workers: no warning" "$(printf '%s\n' "$out" | grep -c 'WARN')" 0

# a pool of 100 with the cap at 64: stops at the cap with a warning
out="$(run 100)"
check "100 workers: stops at the cap and says so" "$(printf '%s\n' "$out" | grep -c 'even at 64')" 1

# the cap can be lowered: a pool of 40, cap 16, warns at once
out="$(run 40 16 16)"
check "cap 16: one round and a warning" "$(printf '%s\n' "$out" | grep -c 'parallel probe requests reached')|$(printf '%s\n' "$out" | grep -c 'even at 16')" "1|1"

# the 5xx recheck is in the fresh render check, and BROKEN SITE comes only after the retries
code="$REPO_ROOT/bin/deploy/activate-release.sh"
check "the fresh render check looks again before BROKEN SITE" "$(grep -c 'PKDE_FRESH_RENDER_5XX_RETRIES' "$code")" 2
check "a recovered 5xx is reported as fine" "$(grep -c 'answered a 5xx .* and then answered 200' "$code")" 1

echo "hit-workers: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
