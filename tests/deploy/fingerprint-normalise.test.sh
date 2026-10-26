#!/usr/bin/env bash
#
# Test for `fingerprint.sh compare-sites` with a real headless Chrome against two tiny local servers: "staging" (bare <img>, no consent
# banner, HTTP Basic auth) and "production" (a <picture> wrapper, a CookieYes banner and revisit widget, a Google Tag Manager iframe).
# Normalised (the default) they are identical; with --raw they differ; a real difference (one changed word) still fails; the Basic auth
# credentials come only from FINGERPRINT_AUTH. Needs node, python3, Chrome and the puppeteer tools in ~/.cache/pkde-prelaunch (installed
# by the first run of fingerprint.sh or prelaunch-audit.sh); skipped when any is missing.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
TOOLS="${PKDE_PRELAUNCH_TOOLS:-$HOME/.cache/pkde-prelaunch}"
command -v node >/dev/null 2>&1 && command -v python3 >/dev/null 2>&1 || { echo "SKIP: node or python3 is missing"; exit 0; }
[ -d "$TOOLS/node_modules/puppeteer-core" ] || { echo "SKIP: puppeteer-core is not installed in $TOOLS"; exit 0; }
CHROME="${CHROME_PATH:-}"
if [ -z "$CHROME" ]; then
    for c in "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" "$(command -v google-chrome 2>/dev/null)" "$(command -v chromium 2>/dev/null)" "$(command -v chromium-browser 2>/dev/null)"; do
        [ -n "$c" ] && [ -x "$c" ] && { CHROME="$c"; break; }
    done
fi
[ -n "$CHROME" ] || { echo "SKIP: no Chrome"; exit 0; }
export CHROME_PATH="$CHROME"

T="$(mktemp -d)" || exit 2
PIDS=()
trap 'kill "${PIDS[@]}" 2>/dev/null; rm -rf "$T"' EXIT
pass=0; fail=0
check() { if eval "$2"; then pass=$((pass + 1)); echo "  PASS  $1"; else fail=$((fail + 1)); echo "  FAIL  $1"; fi; }

cat > "$T/server.py" <<'PY'
import base64, sys
from http.server import BaseHTTPRequestHandler, HTTPServer

role, port = sys.argv[1], int(sys.argv[2])
hits = {'n': 0}
IMG = "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
word = "Welcome" if role != "changed" else "Goodbye"
BODY = f"<h1>{word} to the site</h1><p>Some text here.</p>"
if role == "staging":
    IMGS = f'<img src="{IMG}" width="300" height="200" alt="x">'
    EXTRA = ""
else:
    IMGS = f'<picture><source srcset="{IMG}" type="image/webp"><img src="{IMG}" width="300" height="200" alt="x"></picture>'
    EXTRA = ('<div class="cky-consent-container" style="position:fixed;bottom:0;left:0;width:100%;height:120px;background:#eee">We use cookies</div>'
             '<div class="cky-btn-revisit-wrapper" style="position:fixed;bottom:10px;left:10px;width:40px;height:40px">C</div>'
             '<iframe src="https://www.googletagmanager.com/ns.html?id=GTM-X" height="60" width="60" style="display:block"></iframe>')
def page():
    # The "random" role has a part that differs on every load (WooCommerce's random related products): a height that grows with each request.
    random_part = ''
    if role == 'random':
        hits['n'] += 1
        random_part = f'<div class="related" style="height:{100 + 10 * hits["n"]}px">related</div>'
    return f"<!doctype html><html><body style='margin:0'>{BODY}{IMGS}{EXTRA}{random_part}</body></html>".encode()


class H(BaseHTTPRequestHandler):
    def do_GET(self):
        if role == "staging" and self.headers.get("Authorization") != "Basic " + base64.b64encode(b"user:secret").decode():
            self.send_response(401)
            self.send_header("WWW-Authenticate", 'Basic realm="staging"')
            self.end_headers()
            return
        self.send_response(200)
        self.send_header("Content-Type", "text/html")
        self.end_headers()
        self.wfile.write(page())

    def log_message(self, *a):
        pass


HTTPServer(("127.0.0.1", port), H).serve_forever()
PY
free_port() { python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1])'; }
S=$(free_port); P=$(free_port); C=$(free_port); R=$(free_port)
python3 "$T/server.py" staging "$S" & PIDS+=($!); disown
python3 "$T/server.py" production "$P" & PIDS+=($!); disown
python3 "$T/server.py" changed "$C" & PIDS+=($!); disown
python3 "$T/server.py" random "$R" & PIDS+=($!); disown
sleep 1
sites() { FINGERPRINT_RETRY_PAUSE=0 bash "$REPO_ROOT/bin/deploy/fingerprint.sh" compare-sites "$@" --paths / --widths 1000; }

check "staging with Basic auth and production with banners and a picture wrapper are IDENTICAL once normalised" \
    "FINGERPRINT_AUTH=user:secret sites http://127.0.0.1:$S http://127.0.0.1:$P > $T/o1 2>&1 && grep -q IDENTICAL $T/o1"
check "--raw shows the differences (banner text, picture wrapper)" \
    "! FINGERPRINT_AUTH=user:secret sites http://127.0.0.1:$S http://127.0.0.1:$P --raw > $T/o2 2>&1 && grep -q DIFFERENT $T/o2"
check "without FINGERPRINT_AUTH the Basic-auth staging site is not readable (so the credentials come only from the environment)" \
    "! sites http://127.0.0.1:$S http://127.0.0.1:$P > $T/o3 2>&1"
check "a real text difference still fails when normalised" \
    "! sites http://127.0.0.1:$C http://127.0.0.1:$P > $T/o4 2>&1 && grep -q 'visible text differs' $T/o4"

check "a part that changes on every load makes a site differ from itself" \
    "! sites http://127.0.0.1:$R http://127.0.0.1:$R > $T/o5 2>&1 && grep -q DIFFERENT $T/o5"
check "--hide '.related' hides it, so the same build compares equal to itself" \
    "sites http://127.0.0.1:$R http://127.0.0.1:$R --hide '.related' > $T/o6 2>&1 && grep -q IDENTICAL $T/o6"

echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
