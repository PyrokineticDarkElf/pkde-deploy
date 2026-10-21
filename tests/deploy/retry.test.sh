#!/usr/bin/env bash
#
# Test for bin/deploy/retry.sh: success first time, success on the 2nd and 3rd attempt, failure after 3 attempts,
# and the label (never the command) in the output. No network, no files outside a temp dir.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
T="$(mktemp -d)" || { echo "mktemp failed" >&2; exit 2; }
trap 'rm -rf "$T"' EXIT
# shellcheck disable=SC1091
source "$REPO_ROOT/bin/deploy/retry.sh"
export RETRY_PAUSE=0

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1 (expected '$3', got '$2')"; fi; }

# A command that fails until its Nth call, counting calls in a file.
flaky() { local n; n=$(($(cat "$T/n" 2>/dev/null || echo 0) + 1)); echo "$n" > "$T/n"; [ "$n" -ge "$1" ]; }

for succeed_on in 1 2 3; do
  rm -f "$T/n"
  retry "step" flaky "$succeed_on" >/dev/null; check "succeeds on attempt $succeed_on: status" "$?" 0
  check "succeeds on attempt $succeed_on: calls" "$(cat "$T/n")" "$succeed_on"
done

rm -f "$T/n"
out=$(retry "the rsync" flaky 4 secret-host.example 2>&1); check "gives up: status" "$?" 1
check "gives up: calls" "$(cat "$T/n")" 3
case "$out" in *"the rsync failed after 3 attempts"*) pass=$((pass + 1)) ;; *) fail=$((fail + 1)); echo "FAIL: error line missing: $out" ;; esac
case "$out" in *secret-host*) fail=$((fail + 1)); echo "FAIL: command arguments leaked into the output" ;; *) pass=$((pass + 1)) ;; esac

echo "retry: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
