#!/usr/bin/env bash
#
# Test for bin/deploy/seo/compare.py on small crawl CSVs: identical sites pass; a changed description is reported on its own line and fails;
# a live page-builder token (Divi's @ET-DC@, either at sign) is a LIVE BUG that does not fail; the same token on the new side fails;
# --accept and --accept-file turn a reviewed difference into ACCEPTED; a missing page, a changed title and a changed canonical path fail.
# Needs python3; no network.
set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
command -v python3 >/dev/null 2>&1 || { echo "SKIP: python3 is not installed"; exit 0; }
T="$(mktemp -d)" || exit 2
trap 'rm -rf "$T"' EXIT
pass=0; fail=0
check() { if eval "$2"; then pass=$((pass + 1)); echo "  PASS  $1"; else fail=$((fail + 1)); echo "  FAIL  $1"; fi; }
cmp_() { python3 "$REPO_ROOT/bin/deploy/seo/compare.py" "$@"; }

HEAD='url,status,final_status,title,description,canonical,robots,h1'
row() { printf '%s,200,200,%s,%s,%s,%s,%s\n' "$1" "$2" "$3" "${4:-}" "${5:-}" "${6:-Heading}"; }
mk() { { echo "$HEAD"; cat; } > "$T/$1"; }

row https://live.example.com/ Home "Our home" https://live.example.com/ | mk live.csv
row https://live.example.com/about/ About "About us text" | cat - <(row https://live.example.com/ Home "Our home" https://live.example.com/) | mk live.csv
row https://new.example.test/ Home "Our home" https://new.example.test/ | cat - <(row https://new.example.test/about/ About "About us text") | mk same.csv
check "identical sites pass" "cmp_ $T/live.csv $T/same.csv | grep -q '^0 unexplained'"

row https://new.example.test/ Home "Our home" | cat - <(row https://new.example.test/about/ About "Different words now") | mk desc.csv
check "a changed description is reported as DESCRIPTION CHANGED and fails" "! cmp_ $T/live.csv $T/desc.csv > $T/o1 && grep -q '/about/: DESCRIPTION CHANGED' $T/o1"
check "--accept turns it into ACCEPTED and passes" "cmp_ $T/live.csv $T/desc.csv --accept /about/ > $T/o2 && grep -q 'ACCEPTED /about/' $T/o2"
printf '| URL | note |\n|---|---|\n| /about/ | reviewed |\n' > "$T/exceptions.md"
check "--accept-file reads the first column of the tables" "cmp_ $T/live.csv $T/desc.csv --accept-file $T/exceptions.md > /dev/null"

row https://live.example.com/ Home "Our home" | cat - <(row https://live.example.com/about/ About '＠ET-DC＠eyJkeW5hbWljIjp0cnVl') | mk livebug.csv
check "a live Divi token (full-width at sign) is a LIVE BUG and does not fail" "cmp_ $T/livebug.csv $T/same.csv > $T/o3 && grep -q 'LIVE BUG /about/' $T/o3 && grep -q ' 1 live bug' $T/o3"
row https://live.example.com/ Home "Our home" | cat - <(row https://live.example.com/about/ About '@ET-DC@eyJ') | mk livebug2.csv
check "the ASCII at sign form is recognised too" "cmp_ $T/livebug2.csv $T/same.csv | grep -q 'LIVE BUG /about/'"
row https://new.example.test/ Home "Our home" | cat - <(row https://new.example.test/about/ About '＠ET-DC＠eyJ') | mk newbug.csv
check "the token on the NEW side fails" "! cmp_ $T/live.csv $T/newbug.csv > $T/o4 && grep -q 'NEW BUG' $T/o4"

row https://new.example.test/ Home "Our home" | mk missing.csv
check "a missing page fails" "! cmp_ $T/live.csv $T/missing.csv | grep -q '^0 unexplained'"
row https://new.example.test/ "Home page" "Our home" | cat - <(row https://new.example.test/about/ About "About us text") | mk title.csv
check "a changed title fails" "! cmp_ $T/live.csv $T/title.csv > $T/o5 && grep -q 'title:' $T/o5"
row https://new.example.test/ Home "Our home" https://new.example.test/elsewhere/ | cat - <(row https://new.example.test/about/ About "About us text") | mk canon.csv
check "a changed canonical path fails" "! cmp_ $T/live.csv $T/canon.csv | grep -q '^0 unexplained'"

echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
