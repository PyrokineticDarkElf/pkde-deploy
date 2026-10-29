#!/usr/bin/env bash
#
# Test for bin/deploy/edges.mjs (MGR-62): the comparison that decides whether a content edge matches the header's. The browser part runs in
# `fingerprint.sh edges` against a real page; here the pure function gets fixtures. Needs node only.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
command -v node >/dev/null 2>&1 || { echo "node not found; skipping"; exit 0; }

out=$(node --input-type=module -e "
import { edgeProblems } from '$REPO_ROOT/bin/deploy/edges.mjs';
const ref = { left: 104, right: 1336 };
const ok = edgeProblems([{ label: 'section child p', left: 104, right: 1336 }, { label: 'footer div', left: 104.4, right: 1335.6 }], ref, 1);
const narrow = edgeProblems([{ label: 'section child p', left: 152, right: 1288 }], ref, 1);
const shifted = edgeProblems([{ label: 'main div', left: 104, right: 1300 }], ref, 1);
const loose = edgeProblems([{ label: 'main div', left: 106, right: 1334 }], ref, 2);
console.log(JSON.stringify({ ok: ok.length, narrow, shifted: shifted.length, loose: loose.length }));
")
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1 (expected '$3', got '$2')"; fi; }
check "edges within the tolerance pass" "$(echo "$out" | node -e 'console.log(JSON.parse(require("fs").readFileSync(0,"utf8")).ok)')" 0
check "a Section narrower than the header is reported (the bug MGR-62 is about)" "$(echo "$out" | node -e 'console.log(JSON.parse(require("fs").readFileSync(0,"utf8")).narrow[0])')" "section child p: content 152..1288 (header 104..1336)"
check "a right edge that differs is reported" "$(echo "$out" | node -e 'console.log(JSON.parse(require("fs").readFileSync(0,"utf8")).shifted)')" 1
check "a wider tolerance accepts 2px" "$(echo "$out" | node -e 'console.log(JSON.parse(require("fs").readFileSync(0,"utf8")).loose)')" 0
check "fingerprint.sh lists the edges mode" "$(grep -c 'fingerprint.sh edges' "$REPO_ROOT/bin/deploy/fingerprint.sh" | awk '{print ($1 >= 2) ? "yes" : "no"}')" yes

echo "edges: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
