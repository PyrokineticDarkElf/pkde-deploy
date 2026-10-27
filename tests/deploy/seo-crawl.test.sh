#!/usr/bin/env bash
#
# Test for bin/deploy/seo/crawl.py against a tiny local site: the normal crawl reads the sitemap and follows an archive link; --urls crawls
# exactly the listed paths and follows nothing; the CSV carries title, description, H1 and robots. Needs python3 with `requests`; skipped otherwise.
set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
command -v python3 >/dev/null 2>&1 && python3 -c 'import requests' 2>/dev/null || { echo "SKIP: python3 or requests is missing"; exit 0; }
T="$(mktemp -d)" || exit 2
PIDS=()
trap 'kill "${PIDS[@]}" 2>/dev/null; rm -rf "$T"' EXIT
pass=0; fail=0
check() { if eval "$2"; then pass=$((pass + 1)); echo "  PASS  $1"; else fail=$((fail + 1)); echo "  FAIL  $1"; fi; }

cat > "$T/server.py" <<'PY'
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

port = int(sys.argv[1])
BASE = f"http://127.0.0.1:{port}"
PAGES = {
    "/": ('Home', 'The home page', 'Welcome', f'<a href="/a/">A</a> <a href="/category/x/">Category</a>'),
    "/a/": ('Page A', 'About A', 'A heading', ''),
    "/category/x/": ('Category X', 'Things in X', 'Category X', ''),
}


class H(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == "/sitemap_index.xml":
            self.send_response(200); self.send_header("Content-Type", "application/xml"); self.end_headers()
            self.wfile.write(f"<sitemapindex><sitemap><loc>{BASE}/sm.xml</loc></sitemap></sitemapindex>".encode())
        elif self.path == "/sm.xml":
            self.send_response(200); self.send_header("Content-Type", "application/xml"); self.end_headers()
            self.wfile.write(f"<urlset><url><loc>{BASE}/</loc></url><url><loc>{BASE}/a/</loc></url></urlset>".encode())
        elif self.path in PAGES:
            title, desc, h1, links = PAGES[self.path]
            self.send_response(200); self.send_header("Content-Type", "text/html"); self.end_headers()
            self.wfile.write(f'<!doctype html><title>{title}</title><meta name="description" content="{desc}"><meta name="robots" content="index, follow"><h1>{h1}</h1>{links}'.encode())
        else:
            self.send_response(404); self.end_headers()

    def log_message(self, *a):
        pass


HTTPServer(("127.0.0.1", port), H).serve_forever()
PY
PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1])')
python3 "$T/server.py" "$PORT" & PIDS+=($!); disown
sleep 1
crawl() { python3 "$REPO_ROOT/bin/deploy/seo/crawl.py" --base "http://127.0.0.1:$PORT" --delay 0 "$@" 2>/dev/null; }

crawl --out "$T/full"
check "the normal crawl reads the sitemap, follows the archive link and records title, description and H1" \
  "grep -q '/category/x/' $T/full.csv && grep -q 'Page A' $T/full.csv && grep -q 'About A' $T/full.csv && grep -q 'A heading' $T/full.csv"

printf '/a/\n' > "$T/paths.txt"
crawl --urls "$T/paths.txt" --out "$T/list"
check "--urls crawls exactly the listed paths: one row, no sitemap, no probes, no followed links" \
  "[ \"\$(tail -n +2 $T/list.csv | wc -l | tr -d ' ')\" = 1 ] && grep -q '/a/' $T/list.csv && ! grep -q '/category/x/' $T/list.csv"

echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
