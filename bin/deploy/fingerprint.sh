#!/usr/bin/env bash
#
# Layout fingerprint: proves that a change which must not alter the pages (moving code into a package) did not. Local, read-only (GET
# requests and a headless Chrome).
#
#   bin/deploy/fingerprint.sh capture http://localhost:8092 before.json [--paths /,/block-demo/,/about/] [--widths 1440,390]
#   ... make the change, rebuild ...
#   bin/deploy/fingerprint.sh capture http://localhost:8092 after.json  [same options]
#   bin/deploy/fingerprint.sh compare before.json after.json [--tolerance 0]
#
# Exit 0 when nothing differs, 1 with the differences listed otherwise. Needs Node 20+ and Chrome/Chromium (CHROME_PATH overrides the
# auto-detected path); puppeteer-core installs once into ~/.cache/pkde-prelaunch (the pre-launch audit's tool directory).
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
MODE="${1:-}"
case "$MODE" in
    capture) URL="${2:-}"; OUT="${3:-}"; [ -n "$URL" ] && [ -n "$OUT" ] || { echo "usage: fingerprint.sh capture <url> <out.json> [options]" >&2; exit 2; }; shift 3 ;;
    compare) A="${2:-}"; B="${3:-}"; [ -f "$A" ] && [ -f "$B" ] || { echo "usage: fingerprint.sh compare <a.json> <b.json>" >&2; exit 2; }; shift 3 ;;
    *) sed -n '3,12p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
command -v node >/dev/null 2>&1 || { echo "Node is required (20+)." >&2; exit 2; }

TOOLS="${PKDE_PRELAUNCH_TOOLS:-$HOME/.cache/pkde-prelaunch}"
if [ "$MODE" = compare ]; then
    exec node "$HERE/fingerprint.mjs" compare "$(cd "$(dirname "$A")" && pwd -P)/$(basename "$A")" "$(cd "$(dirname "$B")" && pwd -P)/$(basename "$B")" "$@"
fi

CHROME="${CHROME_PATH:-}"
if [ -z "$CHROME" ]; then
    for c in "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" "/Applications/Chromium.app/Contents/MacOS/Chromium" "$(command -v google-chrome 2>/dev/null)" "$(command -v chromium 2>/dev/null)" "$(command -v chromium-browser 2>/dev/null)"; do
        [ -n "$c" ] && [ -x "$c" ] && { CHROME="$c"; break; }
    done
fi
[ -n "$CHROME" ] && [ -x "$CHROME" ] || { echo "Chrome or Chromium not found; set CHROME_PATH." >&2; exit 2; }
if [ ! -d "$TOOLS/node_modules/puppeteer-core" ]; then
    mkdir -p "$TOOLS" && printf '{"name":"pkde-prelaunch-tools","private":true,"type":"module","dependencies":{"lighthouse":"13.5.0","puppeteer-core":"25.12.0","axe-core":"4.13.0"}}\n' > "$TOOLS/package.json"
    echo "Installing the tools once into $TOOLS ..."
    (cd "$TOOLS" && npm install --no-audit --no-fund --loglevel=error) || { echo "tool install failed" >&2; exit 2; }
fi
cp "$HERE/fingerprint.mjs" "$TOOLS/fingerprint.mjs"
case "$OUT" in /*) ;; *) OUT="$PWD/$OUT" ;; esac
cd "$TOOLS" && node fingerprint.mjs capture --url "$URL" --out "$OUT" --chrome "$CHROME" "$@"
