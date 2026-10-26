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

row https://live.example.com/ Home "" | cat - <(row https://live.example.com/about/ About "") | mk nodesc-live.csv
row https://new.example.test/ Home "" | cat - <(row https://new.example.test/about/ About "Short description fallback") | mk nodesc-new.csv
check "live has none and the rebuild has one: DESCRIPTION ADDED, a failure" "! cmp_ $T/nodesc-live.csv $T/nodesc-new.csv > $T/o6 && grep -q '/about/: DESCRIPTION ADDED' $T/o6"
check "live has one and the rebuild has none: DESCRIPTION REMOVED" "! cmp_ $T/same.csv $T/nodesc-live.csv > $T/o7 && grep -q 'DESCRIPTION REMOVED' $T/o7"

# robots: a noindex new site is not compared (every URL would differ) unless --cutover; --ignore robots leaves it out explicitly.
row https://live.example.com/ Home "Our home" https://live.example.com/ '"index, follow"' | mk robots-live.csv
row https://new.example.test/ Home "Our home" "" '"noindex, nofollow"' | mk robots-new.csv
check "a noindex new site does not produce a robots line, and says so" "cmp_ $T/robots-live.csv $T/robots-new.csv > $T/o8 && grep -q 'robots not compared on 1 URL' $T/o8 && ! grep -q '^/: robots' $T/o8"
check "--cutover compares robots strictly: a noindex on the new side fails" "! cmp_ $T/robots-live.csv $T/robots-new.csv --cutover > $T/o9 && grep -q '/: robots' $T/o9"
row https://new.example.test/ Home "Our home" "" '"noindex, follow"' | mk robots-new2.csv
row https://live.example.com/ Home "Our home" https://live.example.com/ "max-snippet:-1" | mk robots-live2.csv
row https://new.example.test/ Home "Our home" "" '"index, follow"' | mk robots-new3.csv
check "an indexable new site with a different robots value fails" "! cmp_ $T/robots-live2.csv $T/robots-new3.csv | grep -q '^0 unexplained'"
check "--ignore robots leaves it out even then" "cmp_ $T/robots-live2.csv $T/robots-new3.csv --ignore robots | grep -q '^0 unexplained'"

# query strings: /shop/?sage_view=new must not overwrite /shop/.
{ echo "$HEAD"; row https://live.example.com/shop/ Shop "Shop text" "" "" "Shop"; } > "$T/q-live.csv"
{ echo "$HEAD"; row https://new.example.test/shop/ Shop "Shop text" "" "" "Shop"; row "https://new.example.test/shop/?sage_view=new" Shop "Shop text" "" "" "New"; } > "$T/q-new.csv"
check "a query-string URL is skipped by default and does not overwrite its page" "cmp_ $T/q-live.csv $T/q-new.csv > $T/o10 && grep -q '^0 unexplained' $T/o10 && grep -q '1 new URL(s) with a query string were skipped' $T/o10"
check "--include-query keys by path and query: the extra URL is NEW in new, the page itself still compared" "cmp_ $T/q-live.csv $T/q-new.csv --include-query > $T/o11 && grep -q 'NEW in new: /shop/?sage_view=new' $T/o11 && grep -q '^0 unexplained' $T/o11"

echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
