#!/usr/bin/env bash
#
# Public-repository guard: nothing that looks like a real person, host, path or credential may be committed. It scans the TRACKED files
# (git grep), so it fails in CI for anything committed. Allowed: example/test domains, 127.0.0.1 and 0.0.0.0. If a finding is a deliberate
# fixture, change the fixture to an example.test address rather than weakening this test. gitleaks runs in CI as well.
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 2
pass=0; fail=0
check() { # <description> <command that prints offending lines>
    local out; out="$(eval "$2" 2>&1 | head -8)"
    if printf '%s' "$out" | grep -qE 'syntax error|command not found|fatal:'; then fail=$((fail + 1)); echo "  FAIL  $1 (the check itself is broken)"; echo "$out" | sed 's/^/          /'; return; fi
    if [ -z "$out" ]; then pass=$((pass + 1)); echo "  PASS  $1"; else fail=$((fail + 1)); echo "  FAIL  $1"; echo "$out" | sed 's/^/          /'; fi
}
EXCLUDE="':(exclude)LICENSE' ':(exclude)tests/public-safety.test.sh' ':(exclude)composer.lock' ':(exclude)package-lock.json'"

check "no email address outside example and test domains" \
  "git grep -nIEi '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[a-z]{2,}' -- . $EXCLUDE | grep -viE '@(example\\.(com|org|net|test|invalid)|[a-z0-9-]*\\.test|[a-z0-9-]*\\.invalid|localhost|x\\.test|b\\.com|acme-widgets\\.co\\.uk)([^A-Za-z0-9.-]|\$)' | grep -viE 'users\\.noreply\\.github\\.com|git@github\\.com|actions@github\\.com|\\bnpm@|@(types|wordpress|roots|tailwindcss|vitejs|laravel)/|@[0-9]+\\.[0-9]+'"
check "no IPv4 address other than loopback" \
  "git grep -nIE '\\b[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\\b' -- . $EXCLUDE | grep -vE '\\b(127\\.0\\.0\\.1|0\\.0\\.0\\.0)\\b' | grep -vE 'version|[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+ *[<=>~^]|[<=>~^] *[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+|@[0-9]|v[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+|20[0-9]{2}\\.[0-9]{2}\\.[0-9]{2}\\.[0-9]+'"
check "no home directory or developer machine path" \
  "git grep -nIE '/Users/[A-Za-z]|/home/[a-z][a-z0-9_-]+/' -- . $EXCLUDE | grep -vE '\\\$T/home|\\\$HOME'"
check "no private key block" \
  "git grep -nIE 'BEGIN ([A-Z]+ )?PRIVATE KEY' -- . $EXCLUDE"
check "no hard-coded token, password or API key assignment" \
  "git grep -nIEi '(password|passwd|secret|api[_-]?key|access[_-]?token)[\"'\\'' ]*[=:][ ]*[\"'\\''][A-Za-z0-9_/+=-]{12,}[\"'\\'']' -- . $EXCLUDE | grep -viE 'PERFPROBE|placeholder|example|\\\$\\{|\\\$\\(|%s|<token>'"
check "no Google tag, analytics or Turnstile site key" \
  "git grep -nIE 'GTM-[A-Z0-9]{6,}|UA-[0-9]{5,}|G-[A-Z0-9]{9,}|0x4[A-Za-z0-9_-]{20,}' -- . $EXCLUDE | grep -vE 'GTM-X+'"

echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
