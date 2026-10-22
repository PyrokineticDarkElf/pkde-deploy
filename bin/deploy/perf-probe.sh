#!/usr/bin/env bash
#
# Performance probe runner. Run it ON the server, in ONE SSH session, from the release (or `current`):
#
#   ssh <host> 'cd <DEPLOY_PATH>/current && bash bin/deploy/perf-probe.sh https://staging.example.com/ https://staging.example.com/shop/'
#
# It installs bin/deploy/perf-probe.php as the temporary mu-plugin web/app/mu-plugins/zz-perf-probe.php with a fresh random token,
# makes 3 uncached requests (the token in the query string, so no page cache answers them) and 1 cached request per URL (after one
# unreported priming request), prints one table (stage timings, queries, files, OPcache misses between requests, peak memory) and
# the details of the last uncached request per URL, and REMOVES the mu-plugin again. The file is removed on every exit path
# (success, a failed request, Ctrl-C, a dropped SSH connection) and the script checks that it is gone; if it cannot remove it, it
# says so loudly and exits 1. A stale copy from an earlier crashed run is removed first.
#
# Environment (all optional):
#   PERF_PROBE_PHP                 php binary (default: php83, else php)
#   PERF_PROBE_UNCACHED            uncached requests per URL (default 3)
#   PERF_PROBE_BASIC_AUTH          user:password for a staging gate; handed to curl on stdin, never on its command line
#   PERF_PROBE_ALLOW_PRODUCTION=1  required when WP_ENV is production (the probe is meant for staging)
#
# A deleted PHP file can still answer for a while: on 20i OPcache revalidates only every ~10 s (validate_timestamps) and the NFS attribute
# cache adds to that, so the probe file may keep answering a request that carries its token for about 10 s after it is removed (tcs saw a
# deleted file still callable a second later). That is why the plugin answers only a request with the random token, prints nothing
# sensitive, and why ANY temporary PHP file put in a docroot must do the same.
#
# The OPcache "misses+" column is the misses added since the previous probed request: the first request after installing also
# compiles the probe itself, so read the later rows.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
SRC="$ROOT/bin/deploy/perf-probe.php"
TARGET="$ROOT/web/app/mu-plugins/zz-perf-probe.php"
UNCACHED="${PERF_PROBE_UNCACHED:-3}"

if [ "$#" -eq 0 ]; then
    echo "usage: bash bin/deploy/perf-probe.sh <url> [<url> ...]" >&2
    exit 2
fi
case "$UNCACHED" in "" | *[!0-9]* | 0) echo "PERF_PROBE_UNCACHED must be a positive number" >&2; exit 2 ;; esac
[ -f "$SRC" ] || { echo "missing $SRC" >&2; exit 2; }
[ -d "$ROOT/web/app/mu-plugins" ] || { echo "no web/app/mu-plugins in $ROOT" >&2; exit 2; }
command -v curl >/dev/null 2>&1 || { echo "curl is required" >&2; exit 2; }

PHP="${PERF_PROBE_PHP:-}"
if [ -z "$PHP" ]; then
    if command -v php83 >/dev/null 2>&1; then PHP=php83; else PHP=php; fi
fi
command -v "$PHP" >/dev/null 2>&1 || { echo "no PHP binary ($PHP) to build the report with" >&2; exit 2; }

WP_ENV_VALUE="${WP_ENV:-$(sed -n 's/^WP_ENV=//p' "$ROOT/.env" 2>/dev/null | tail -1 | tr -d "\"' \r")}"
if [ "$WP_ENV_VALUE" = production ] && [ "${PERF_PROBE_ALLOW_PRODUCTION:-}" != 1 ]; then
    echo "WP_ENV is production: the probe is meant for staging. Set PERF_PROBE_ALLOW_PRODUCTION=1 to run it anyway." >&2
    exit 2
fi

WORK="$(mktemp -d)" || { echo "mktemp failed" >&2; exit 2; }

cleanup() { # runs on every exit path; the exit status becomes 1 if the probe file could not be removed
    local rc=$?
    trap - EXIT
    rm -f "$TARGET" 2>/dev/null
    rm -rf "$WORK"
    if [ -e "$TARGET" ] || [ -L "$TARGET" ]; then
        echo "ERROR: could not remove $TARGET. DELETE IT NOW: it answers requests that carry its token." >&2
        exit 1
    fi
    exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT TERM HUP

if [ -e "$TARGET" ]; then
    echo "NOTE: removing a stale probe file left by an earlier run"
    rm -f "$TARGET" || { echo "ERROR: cannot remove the stale $TARGET" >&2; exit 1; }
fi

TOKEN="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
[ "${#TOKEN}" -eq 32 ] || { echo "could not generate a token" >&2; exit 1; }
# Only the constant's line carries the token; the plugin refuses to answer while the placeholder is still in it.
sed "s/^\(const PKDE_PERFPROBE_TOKEN = '\)__PERFPROBE_TOKEN__'/\1$TOKEN'/" "$SRC" > "$TARGET" || { echo "could not install the probe" >&2; exit 1; }
chmod 644 "$TARGET"
grep -q "^const PKDE_PERFPROBE_TOKEN = '$TOKEN'" "$TARGET" || { echo "the token was not written into the probe" >&2; exit 1; }
echo "Probe installed (removed again when this finishes). PHP: $PHP, WP_ENV: ${WP_ENV_VALUE:-unknown}"

n=0
GATED=""
request() { # <kind> <url as requested> <url to fetch>
    n=$((n + 1))
    local id kind="$1" shown="$2" fetch="$3" out
    id="$(printf '%03d' "$n")"
    local args=(-sS -o "$WORK/$id.body" -D "$WORK/$id.hdr" --compressed --max-time 90 -w '%{http_code}|%{time_starttransfer}|%{time_total}')
    if [ -n "${PERF_PROBE_BASIC_AUTH:-}" ]; then
        out="$(printf 'user = "%s"\n' "$PERF_PROBE_BASIC_AUTH" | curl --config - "${args[@]}" "$fetch" 2>"$WORK/$id.err")" || out="0|0|0"
    else
        out="$(curl "${args[@]}" "$fetch" 2>"$WORK/$id.err")" || out="0|0|0"
    fi
    printf '%s|%s|%s\n' "$shown" "$kind" "$out" > "$WORK/$id.meta"
    [ "${out%%|*}" != 0 ] || echo "WARN: $kind request to $shown failed: $(head -1 "$WORK/$id.err")" >&2
    # The coming-soon mu-plugin answers anonymous visitors with a 503 and a Retry-After header, before any of the site runs.
    if [ "${out%%|*}" = 503 ] && grep -qi '^retry-after:' "$WORK/$id.hdr" 2>/dev/null; then
        case " $GATED " in *" $shown "*) ;; *) GATED="$GATED $shown" ;; esac
    fi
}

for url in "$@"; do
    case "$url" in *\?*) sep='&' ;; *) sep='?' ;; esac
    i=0
    while [ "$i" -lt "$UNCACHED" ]; do
        i=$((i + 1))
        request "uncached $i" "$url" "${url}${sep}perfprobe=${TOKEN}&pp=${i}"
    done
    request "prime" "$url" "$url"   # not reported: fills the page cache so the next request can be a hit
    rm -f "$WORK/$(printf '%03d' "$n").meta"
    request "cached" "$url" "$url"
done

echo
"$PHP" "$SRC" report "$WORK"
echo
for gated in $GATED; do
    echo "WARN: $gated is behind the coming-soon gate (503 with Retry-After); the figures are for the gate page, not the site. Take COMING_SOON off on staging (or test as a logged-in visitor) and run the probe again." >&2
done
if rm -f "$TARGET" && [ ! -e "$TARGET" ]; then
    echo "Probe file removed: yes"
fi
