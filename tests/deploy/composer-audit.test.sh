#!/usr/bin/env bash
#
# Test for bin/deploy/composer-audit.sh with a fake `composer` that prints a prepared report: clean, low/medium only (pass),
# high, critical and unrated (fail), and output that is not a report (fail). No network.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
T="$(mktemp -d)" || { echo "mktemp failed" >&2; exit 2; }
trap 'rm -rf "$T"' EXIT
mkdir "$T/bin" "$T/proj"
printf '#!/bin/sh\ncat "$FAKE_REPORT"\n' > "$T/bin/composer"; chmod +x "$T/bin/composer"
export PATH="$T/bin:$PATH"

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1 (expected '$3', got '$2')"; fi; }
run() { printf '%s' "$1" > "$T/report.json"; FAKE_REPORT="$T/report.json" bash "$REPO_ROOT/bin/deploy/composer-audit.sh" "$T/proj" >"$T/out" 2>&1; echo $?; }
adv() { printf '{"advisories":{"vendor/pkg":[{"advisoryId":"X","cve":"CVE-1","title":"t","severity":%s,"link":"https://example.test"}]},"abandoned":[]}' "$1"; }

check "no advisories passes" "$(run '{"advisories":[],"abandoned":[]}')" 0
check "low passes" "$(run "$(adv '"low"')")" 0
check "medium passes" "$(run "$(adv '"medium"')")" 0
check "high fails" "$(run "$(adv '"high"')")" 1
check "critical fails" "$(run "$(adv '"critical"')")" 1
check "unrated (null) fails" "$(run "$(adv 'null')")" 1
check "unrated is named in the output" "$(grep -c unrated "$T/out")" 1
check "not a report fails" "$(run 'Could not reach packagist')" 1
check "missing directory fails" "$(FAKE_REPORT=/dev/null bash "$REPO_ROOT/bin/deploy/composer-audit.sh" "$T/nope" >/dev/null 2>&1; echo $?)" 2

echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
