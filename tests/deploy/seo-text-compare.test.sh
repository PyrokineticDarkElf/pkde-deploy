#!/usr/bin/env bash
#
# Test for bin/deploy/seo/text_compare.py's word comparison (no network): identical text passes whatever the markup, a missing and an added
# word are found on the right side, header/footer/script/style/SVG/option text and case/punctuation/emoji do not count, and a page without
# <main> is read whole. Needs python3 and nothing else (requests is imported only by the command line).
set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
command -v python3 >/dev/null 2>&1 || { echo "SKIP: python3 is not installed"; exit 0; }
pass=0; fail=0
check() { if eval "$2"; then pass=$((pass + 1)); echo "  PASS  $1"; else fail=$((fail + 1)); echo "  FAIL  $1"; fi; }

py() { PYTHONPATH="$REPO_ROOT/bin/deploy/seo" python3 -c "from text_compare import *; $1"; }

check "the same text in different markup, case and punctuation is identical" \
  "py \"a, b = difference('<main><h1>Hello World!</h1><p>Call £20 now</p></main>', '<main><div><span>hello</span> <b>WORLD</b></div><p>call £20 now.</p></main>'); assert not a and not b, (a, b)\""
check "a word only the live page has is reported as live-only, and one only the rebuild has as new-only" \
  "py \"a, b = difference('<main><p>alpha beta gamma</p></main>', '<main><p>alpha beta delta</p></main>'); assert list(a) == ['gamma'] and list(b) == ['delta'], (a, b)\""
check "header, footer, scripts, styles, SVG titles and select options are ignored" \
  "py \"a, b = difference('<header>menu</header><main><p>same</p><script>var x=1</script><style>p{}</style><svg><title>icon</title></svg><select><option>Red</option></select></main><footer>foot</footer>', '<main><p>same</p></main>'); assert not a and not b, (a, b)\""
check "a repeated word counts each time" \
  "py \"a, b = difference('<main>a a a</main>', '<main>a</main>'); assert a['a'] == 2 and not b, (a, b)\""
check "a page without <main> is read whole" \
  "py \"w = words('<body><p>Plain page</p></body>'); assert w['plain'] == 1 and w['page'] == 1, w\""
check "the typographic apostrophe equals the plain one" \
  "py \"a, b = difference('<main>it’s here</main>', '<main>it\\'s here</main>'); assert not a and not b, (a, b)\""

echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
