#!/usr/bin/env bash
#
# Test for bin/deploy/perf-probe.sh in a temp sandbox with a stubbed curl: the probe file must be installed with the same token the
# requests carry, must be gone afterwards on EVERY exit path (success, failing requests, a signal, a stale copy from an earlier run),
# the runner refuses production, and basic-auth credentials never appear on curl's command line. Needs bash, php and sed.
# Skips (exit 0, says so) where php is missing. Nothing leaves the machine.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
command -v php >/dev/null 2>&1 || { echo "SKIP: php is not installed"; exit 0; }

T="$(mktemp -d)" || exit 2
case "$(cd "$T" && pwd -P)/" in "$REPO_ROOT"/*) echo "REFUSING TO RUN: sandbox inside the repository" >&2; exit 2 ;; esac
trap 'rm -rf "$T"' EXIT

ROOT="$T/site"
mkdir -p "$ROOT/bin/deploy" "$ROOT/web/app/mu-plugins" "$T/bin"
cp "$REPO_ROOT/bin/deploy/perf-probe.sh" "$REPO_ROOT/bin/deploy/perf-probe.php" "$ROOT/bin/deploy/"
echo "WP_ENV=staging" > "$ROOT/.env"
PROBE="$ROOT/web/app/mu-plugins/zz-perf-probe.php"

# The stub curl: logs its arguments and stdin, checks the probe is installed while a probed request runs and that the URL's token is
# the one in the file, and answers with a page (probed requests carry the report comment, the others are plain pages).
cat > "$T/bin/curl" <<STUB
#!/usr/bin/env bash
echo "\$*" >> "$T/curl.args"
out=""; url=""; hdr=""
while [ \$# -gt 0 ]; do
  case "\$1" in
    -o) out="\$2"; shift 2 ;;
    --config) cat >> "$T/curl.stdin"; shift 2 ;;
    -D) hdr="\$2"; shift 2 ;;
    -w|--max-time) shift 2 ;;
    -*) shift ;;
    *) url="\$1"; shift ;;
  esac
done
[ -f "$T/stub-sleep" ] && sleep 5
if [ -f "$T/stub-gated" ]; then printf 'HTTP/1.1 503 Service Unavailable\\r\\nRetry-After: 86400\\r\\n' > "\$hdr"; echo "<html>coming soon</html>" > "\$out"; printf '503|0.100|0.150'; exit 0; fi
[ -f "$T/stub-fail" ] && { echo "curl: (7) Failed to connect" >&2; exit 7; }
case "\$url" in
  *perfprobe=*)
    token="\${url#*perfprobe=}"; token="\${token%%&*}"
    grep -q "^const PKDE_PERFPROBE_TOKEN = '\$token'" "$PROBE" || { echo "token mismatch or probe not installed" >> "$T/stub.errors"; }
    printf '<html></html>\n<!-- perfprobe:{"stages_ms":{"muplugins_loaded":10,"plugins_loaded":50,"init":70,"template_redirect":80,"shutdown":150},"opcache":{"available":true,"misses":%s,"cache_full":false},"db":{"queries":20,"select1_avg_ms":0.1},"files":{"at_template_redirect":300,"at_end":310,"groups":{"core":100}},"init_top":[],"http":[],"memory":{"limit":"256M","peak_mb":20,"autoload_kb":50,"autoload_count":100},"php":"8.3","sapi":"cgi"} -->\n' "\$((RANDOM % 5 + 100))" > "\$out" ;;
  *) echo "<html>plain</html>" > "\$out" ;;
esac
printf '200|0.100|0.150'
STUB
chmod +x "$T/bin/curl"
export PATH="$T/bin:$PATH"
export PERF_PROBE_PHP=php

pass=0; fail=0
check() { if eval "$2"; then pass=$((pass + 1)); echo "  PASS  $1"; else fail=$((fail + 1)); echo "  FAIL  $1"; fi; }
run() { (cd "$ROOT" && bash bin/deploy/perf-probe.sh "$@"); }

echo "=== a normal run ==="
: > "$T/curl.args"; rm -f "$T/stub.errors"
run https://staging.example.test/ https://staging.example.test/shop/ > "$T/out1.log" 2>&1; rc=$?
check "exit 0" "[ $rc -eq 0 ]"
check "the probe file is gone afterwards" "[ ! -e '$PROBE' ] && grep -q 'Probe file removed: yes' '$T/out1.log'"
check "every probed request carried the installed token (stub saw the file)" "[ ! -e '$T/stub.errors' ]"
check "3 uncached + 1 cached request per URL are in the table (and the priming request is not)" "[ \$(grep -c 'uncached [123]' '$T/out1.log') -eq 6 ] && [ \$(grep -c ' cached ' '$T/out1.log') -eq 2 ] && ! grep -q 'prime' '$T/out1.log'"
check "the table has the stage columns" "grep -q 'plugins' '$T/out1.log' && grep -q 'oc misses+' '$T/out1.log'"
check "uncached requests use the query string, the cached one the plain URL" "grep -q 'shop/?perfprobe=' '$T/curl.args' && grep -c 'perfprobe=' '$T/curl.args' | grep -q '^6\$'"

echo "=== failing requests, a stale file, production, credentials ==="
touch "$T/stub-fail"
run https://staging.example.test/ > "$T/out2.log" 2>&1; rc=$?
check "failing requests: exit 0 with WARNs, and the probe file is gone" "[ $rc -eq 0 ] && grep -q 'WARN: uncached 1 request' '$T/out2.log' && [ ! -e '$PROBE' ]"
rm -f "$T/stub-fail"

echo "<?php // stale" > "$PROBE"
run https://staging.example.test/ > "$T/out3.log" 2>&1
check "a stale probe file from an earlier run is removed first (and the run ends without one)" "grep -q 'removing a stale probe file' '$T/out3.log' && [ ! -e '$PROBE' ]"

echo "WP_ENV=production" > "$ROOT/.env"
run https://example.test/ > "$T/out4.log" 2>&1; rc=$?
check "production is refused (exit 2) and nothing is installed" "[ $rc -eq 2 ] && [ ! -e '$PROBE' ] && grep -q 'PERF_PROBE_ALLOW_PRODUCTION' '$T/out4.log'"
PERF_PROBE_ALLOW_PRODUCTION=1 run https://example.test/ > "$T/out5.log" 2>&1; rc=$?
check "PERF_PROBE_ALLOW_PRODUCTION=1 allows it, and the file is still removed" "[ $rc -eq 0 ] && [ ! -e '$PROBE' ]"
echo "WP_ENV=staging" > "$ROOT/.env"

: > "$T/curl.args"
PERF_PROBE_BASIC_AUTH='gate:s3cret-pass' run https://staging.example.test/ > "$T/out6.log" 2>&1
check "basic auth goes to curl on stdin, never on its command line" "! grep -q 's3cret' '$T/curl.args' && grep -q 's3cret-pass' '$T/curl.stdin'"

echo "=== the coming-soon gate ==="
touch "$T/stub-gated"
run https://staging.example.test/ > "$T/out8.log" 2>&1; rc=$?
rm -f "$T/stub-gated"
check "a 503 with Retry-After is called out as the gate page, once per URL, and the file is gone" "[ $rc -eq 0 ] && [ \$(grep -c 'behind the coming-soon gate' '$T/out8.log') -eq 1 ] && grep -q 'figures are for the gate page' '$T/out8.log' && [ ! -e '$PROBE' ]"
run https://staging.example.test/ > "$T/out9.log" 2>&1
check "an ordinary run has no gate warning" "! grep -q 'coming-soon gate' '$T/out9.log'"

echo "=== a signal in the middle of a request ==="
touch "$T/stub-sleep"
(cd "$ROOT" && exec bash bin/deploy/perf-probe.sh https://staging.example.test/ > "$T/out7.log" 2>&1) &
pid=$!
for _ in $(seq 1 50); do [ -e "$PROBE" ] && break; sleep 0.1; done
check "the probe is installed while the run is in progress" "[ -e '$PROBE' ]"
kill -TERM "$pid" 2>/dev/null; wait "$pid" 2>/dev/null
rm -f "$T/stub-sleep"
check "SIGTERM mid-run: the probe file is gone" "[ ! -e '$PROBE' ]"

echo "=== the plugin itself ==="
check "an uninstalled copy (placeholder token) has no installed token and the plugin refuses to answer it" "grep -q \"^const PKDE_PERFPROBE_TOKEN = '__PERFPROBE_TOKEN__'\" '$REPO_ROOT/bin/deploy/perf-probe.php' && grep -q \"str_contains(PKDE_PERFPROBE_TOKEN, '__')\" '$REPO_ROOT/bin/deploy/perf-probe.php'"

echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
