#!/usr/bin/env bash
#
# Pre-launch audit, run LOCALLY against a staging (or production) URL. A required step of the launch guide's go-live checklist.
# It makes HTTP GET requests only and drives a local headless Chrome; nothing runs on a server, nothing is written but the report.
#
#   bin/deploy/prelaunch-audit.sh https://staging.example.com [--expect staging|production] [--paths /,/about/,/contact/] [--out DIR]
#
# Checks (PASS / FAIL / INFO lines, exit 1 on any FAIL): Lighthouse mobile and desktop (accessibility and best practices >= 90,
# scores and LCP reported), axe-core on the key page types (no serious or critical violation; the pages come from --paths or the
# sitemaps), the keyboard focus check (tabbable stops, each with a visible focus indicator), and the Cloudflare/cache header checks
# (no X-Powered-By, compression, noindex on staging and absent on production, HTTP to HTTPS, hashed assets cached a year immutable).
#
# Needs: Node 20+, curl, and Chrome/Chromium (CHROME_PATH overrides the auto-detected path). The tools (lighthouse, puppeteer-core,
# axe-core, versions pinned below, at least two weeks old) install once into ~/.cache/pkde-prelaunch (PKDE_PRELAUNCH_TOOLS).
# Each run writes to an untracked folder, prelaunch-reports/<host>-<timestamp>/ (gitignored); no secrets are collected.

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
# Reports go under the directory you run it from (the site), never into the tools' own directory (vendor/pkde/deploy when installed).
ROOT="$PWD"

URL="${1:-}"; [ -n "$URL" ] || { sed -n '3,15p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }
shift
EXPECT=staging; PATHS=""; OUT=""
while [ $# -gt 0 ]; do
    case "$1" in
        --expect) EXPECT="${2:-}"; shift 2 ;;
        --paths) PATHS="${2:-}"; shift 2 ;;
        --out) OUT="${2:-}"; shift 2 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
done
case "$URL" in http://*|https://*) ;; *) echo "the URL must start with http:// or https://" >&2; exit 2 ;; esac
case "$EXPECT" in staging|production) ;; *) echo "--expect must be staging or production" >&2; exit 2 ;; esac

command -v node >/dev/null 2>&1 || { echo "Node is required (20+)." >&2; exit 2; }
command -v npm >/dev/null 2>&1 || { echo "npm is required." >&2; exit 2; }
CHROME="${CHROME_PATH:-}"
if [ -z "$CHROME" ]; then
    for c in "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" "/Applications/Chromium.app/Contents/MacOS/Chromium" "$(command -v google-chrome 2>/dev/null)" "$(command -v chromium 2>/dev/null)" "$(command -v chromium-browser 2>/dev/null)"; do
        [ -n "$c" ] && [ -x "$c" ] && { CHROME="$c"; break; }
    done
fi
[ -n "$CHROME" ] && [ -x "$CHROME" ] || { echo "Chrome or Chromium not found; set CHROME_PATH." >&2; exit 2; }

TOOLS="${PKDE_PRELAUNCH_TOOLS:-$HOME/.cache/pkde-prelaunch}"
mkdir -p "$TOOLS" || exit 2
cat > "$TOOLS/package.json" <<'JSON'
{
  "name": "pkde-prelaunch-tools",
  "private": true,
  "type": "module",
  "dependencies": { "lighthouse": "13.5.0", "puppeteer-core": "25.12.0", "axe-core": "4.13.0" }
}
JSON
if [ ! -d "$TOOLS/node_modules/lighthouse" ]; then
    echo "Installing the audit tools once into $TOOLS ..."
    (cd "$TOOLS" && npm install --no-audit --no-fund --loglevel=error) || { echo "tool install failed" >&2; exit 2; }
fi
cp "$HERE/prelaunch-audit.mjs" "$TOOLS/audit.mjs"

HOST="$(printf '%s' "$URL" | sed -E 's#^https?://##; s#[/:].*$##')"
[ -n "$OUT" ] || OUT="$ROOT/prelaunch-reports/${HOST}-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$OUT" || exit 2

echo "Auditing $URL (expect: $EXPECT), report folder: $OUT"
cd "$TOOLS" && node audit.mjs --url "$URL" --out "$OUT" --expect "$EXPECT" --chrome "$CHROME" ${PATHS:+--paths "$PATHS"}
RC=$?
# Exit 0 = all passed, 1 = a check failed; anything else (or no summary.txt) means the audit did not finish.
if [ "$RC" -gt 1 ] || [ ! -f "$OUT/summary.txt" ]; then
    echo "FAIL  The audit did not finish (exit $RC, no summary written): Lighthouse, axe and the focus check may not have run." >&2
    exit 1
fi
exit "$RC"
