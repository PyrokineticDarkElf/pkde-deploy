#!/usr/bin/env bash
#
# Layout fingerprint: proves that a change which must not alter the pages (moving code into a package) did not. Local, read-only (GET
# requests and a headless Chrome).
#
#   bin/deploy/fingerprint.sh capture http://localhost:8092 before.json [--paths /,/block-demo/,/about/] [--widths 1440,390] [--hide '.related,.upsells']
#   (--hide hides parts that change on every load by design, such as WooCommerce's random related products, so one build compares equal to itself)
#   ... make the change, rebuild ...
#   bin/deploy/fingerprint.sh capture http://localhost:8092 after.json  [same options]
#   bin/deploy/fingerprint.sh compare before.json after.json [--tolerance 0]
#   bin/deploy/fingerprint.sh edges http://localhost:8092 [--paths /,/about/,/no-such-page/] [--widths 1440,390] [--tolerance 1]
#   (edges: the header, page containers, a Section's content and the footer must start and end at the same x; exit 1 and a list otherwise)
#   bin/deploy/fingerprint.sh compare-sites https://staging.example.com https://www.example.com [--paths /,/about/] [--widths 1440,390] [--tolerance 0]
#
# compare-sites captures both sites and compares them in one command: the REQUIRED check before a production release (staging is
# what was tested, so every difference to production has to be explained). A capture that crashes in node is retried once. It
# normalises what only one environment has by design (consent banners and widgets with a CookieYes opt-out cookie, the Google Tag
# Manager frame, <picture> wrappers); --raw turns that off. A staging site behind Basic auth: FINGERPRINT_AUTH=user:pass in the
# environment, never as an argument.
#
# Exit 0 when nothing differs, 1 with the differences listed otherwise. Needs Node 20+ and Chrome/Chromium (CHROME_PATH overrides the
# auto-detected path); puppeteer-core installs once into ~/.cache/pkde-prelaunch (the pre-launch audit's tool directory).
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
MODE="${1:-}"
case "$MODE" in
    capture) URL="${2:-}"; OUT="${3:-}"; [ -n "$URL" ] && [ -n "$OUT" ] || { echo "usage: fingerprint.sh capture <url> <out.json> [options]" >&2; exit 2; }; shift 3 ;;
    compare-sites) SITE_A="${2:-}"; SITE_B="${3:-}"; [ -n "$SITE_A" ] && [ -n "$SITE_B" ] || { echo "usage: fingerprint.sh compare-sites <url-a> <url-b> [--paths ...] [--widths ...] [--tolerance N]" >&2; exit 2; }; shift 3 ;;
    edges) URL="${2:-}"; [ -n "$URL" ] || { echo "usage: fingerprint.sh edges <url> [--paths ...] [--widths ...] [--tolerance N]" >&2; exit 2; }; shift 2 ;;
    compare) A="${2:-}"; B="${3:-}"; [ -f "$A" ] && [ -f "$B" ] || { echo "usage: fingerprint.sh compare <a.json> <b.json>" >&2; exit 2; }; shift 3 ;;
    *) sed -n '3,23p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
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
cp "$HERE/edges.mjs" "$TOOLS/edges.mjs"
if [ "$MODE" = edges ]; then
    (cd "$TOOLS" && exec node edges.mjs --url "$URL" --chrome "$CHROME" "$@")
    exit $?
fi
case "${OUT:-}" in /*|"") ;; *) OUT="$PWD/$OUT" ;; esac
# The first capture after a build sometimes crashes in node; a second one works. Retry once, then report the failure.
capture() { # <url> <out> [options]
    local url="$1" out="$2"; shift 2
    (cd "$TOOLS" && node fingerprint.mjs capture --url "$url" --out "$out" --chrome "$CHROME" "$@") && return 0
    echo "fingerprint: the capture of $url failed; retrying once ..." >&2
    sleep "${FINGERPRINT_RETRY_PAUSE:-3}"
    (cd "$TOOLS" && node fingerprint.mjs capture --url "$url" --out "$out" --chrome "$CHROME" "$@")
}

if [ "$MODE" = compare-sites ]; then
    TOL=(); OPTS=(); NORM=(--normalise)
    while [ $# -gt 0 ]; do
        case "$1" in
            --tolerance) TOL=(--tolerance "${2:-0}"); shift 2 ;;
            --raw) NORM=(); shift ;;
            *) OPTS+=("$1"); shift ;;
        esac
    done
    W="$(mktemp -d)" || exit 2
    trap 'rm -rf "$W"' EXIT
    echo "Capturing $SITE_A ..."; capture "$SITE_A" "$W/a.json" "${NORM[@]+"${NORM[@]}"}" "${OPTS[@]+"${OPTS[@]}"}" || { echo "capture of $SITE_A failed" >&2; exit 2; }
    echo "Capturing $SITE_B ..."; capture "$SITE_B" "$W/b.json" "${NORM[@]+"${NORM[@]}"}" "${OPTS[@]+"${OPTS[@]}"}" || { echo "capture of $SITE_B failed" >&2; exit 2; }
    echo "Comparing $SITE_A (a) with $SITE_B (b):"
    node "$HERE/fingerprint.mjs" compare "$W/a.json" "$W/b.json" "${TOL[@]+"${TOL[@]}"}"
    exit $?
fi
capture "$URL" "$OUT" "$@"
