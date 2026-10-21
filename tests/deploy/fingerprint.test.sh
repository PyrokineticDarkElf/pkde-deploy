#!/usr/bin/env bash
#
# Test for bin/deploy/fingerprint.mjs `compare` (no browser needed): identical fingerprints pass; a moved box, a different tag, a
# different text, a missing element and a missing page each fail; a difference within the tolerance passes. Needs node.
set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
command -v node >/dev/null 2>&1 || { echo "SKIP: node is not installed"; exit 0; }
T="$(mktemp -d)" || exit 2
trap 'rm -rf "$T"' EXIT
pass=0; fail=0
check() { if eval "$2"; then pass=$((pass + 1)); echo "  PASS  $1"; else fail=$((fail + 1)); echo "  FAIL  $1"; fi; }
cmp_() { node "$REPO_ROOT/bin/deploy/fingerprint.mjs" compare "$1" "$2" "${@:3}"; }

mk() { # <file> <text> <elements json>
    printf '{"url":"x","widths":[1440],"pages":{"/ @1440":{"text":"%s","elements":%s}}}' "$2" "$3" > "$T/$1"
}
BASE='[["b.0","header",0,0,1440,80],["b.1","main",0,80,1440,600]]'
mk a.json "Hello world" "$BASE"
mk same.json "Hello world" "$BASE"
check "identical fingerprints pass" "cmp_ $T/a.json $T/same.json | grep -q IDENTICAL"

mk moved.json "Hello world" '[["b.0","header",0,0,1440,80],["b.1","main",0,84,1440,600]]'
check "a box that moved 4px fails and is reported" "! cmp_ $T/a.json $T/moved.json > $T/o1 && grep -q 'moved or resized' $T/o1"
check "...but passes with a 5px tolerance" "cmp_ $T/a.json $T/moved.json --tolerance 5 | grep -q IDENTICAL"
mk tag.json "Hello world" '[["b.0","div",0,0,1440,80],["b.1","main",0,80,1440,600]]'
check "a changed tag fails" "! cmp_ $T/a.json $T/tag.json > $T/o2 && grep -q 'changed tag or position' $T/o2"
mk text.json "Hello there" "$BASE"
check "different visible text fails and shows where" "! cmp_ $T/a.json $T/text.json > $T/o3 && grep -q 'visible text differs' $T/o3"
mk fewer.json "Hello world" '[["b.0","header",0,0,1440,80]]'
check "a missing element fails" "! cmp_ $T/a.json $T/fewer.json > $T/o4 && grep -q '2 elements before, 1 after' $T/o4"
printf '{"url":"x","widths":[1440],"pages":{}}' > "$T/nopage.json"
check "a missing page fails" "! cmp_ $T/a.json $T/nopage.json > $T/o5 && grep -q 'only in' $T/o5"

echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
