#!/usr/bin/env bash
#
# Test for bin/deploy/rcpt-probe.py against a tiny fake SMTP server on localhost (nothing is sent anywhere
# else): a known mailbox must answer 250 (exit 0), an unknown one 550, a dead port must report it, and a
# missing MX must report that. Needs python3 (stdlib only).

set -uo pipefail
# SAFETY: this test creates and deletes fixtures. It must only ever do that inside the temp sandbox it creates.
# If a step fails (no git, no mktemp, a failed cd) it must stop, never carry on in the real repository.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
refuse() { echo "REFUSING TO RUN: $1 (nothing was changed)" >&2; exit 2; }
PROBE="$REPO_ROOT/bin/deploy/rcpt-probe.py"
T="$(mktemp -d)" || refuse "mktemp failed"
[ -n "$T" ] && [ -d "$T" ] || refuse "mktemp did not create a sandbox"
case "$(cd "$T" && pwd -P)/" in "$REPO_ROOT"/*) refuse "the sandbox is inside the repository" ;; esac
PORT=$((20000 + RANDOM % 20000))
trap 'kill "${SERVER:-}" 2>/dev/null; rm -rf "$T"' EXIT

cat > "$T/fake.py" <<PY
import socketserver, sys
class H(socketserver.StreamRequestHandler):
    def handle(self):
        w = lambda s: self.wfile.write((s + "\r\n").encode())
        w("220 fake ESMTP")
        for raw in self.rfile:
            c = raw.decode().strip(); u = c.upper()
            if u.startswith(("EHLO", "HELO", "MAIL", "RSET")): w("250 ok")
            elif u.startswith("RCPT"): w("250 ok" if c.split("<")[1].split(">")[0] == "info@example.test" else "550 5.1.1 User unknown")
            elif u.startswith("QUIT"): w("221 bye"); return
            else: w("500 what")
socketserver.ThreadingTCPServer.allow_reuse_address = True
socketserver.ThreadingTCPServer(("127.0.0.1", $PORT), H).serve_forever()
PY
python3 "$T/fake.py" & SERVER=$!; disown
for _ in 1 2 3 4 5 6 7 8 9 10; do (echo > "/dev/tcp/127.0.0.1/$PORT") 2>/dev/null && break; sleep 0.3; done

pass=0; fail=0
check() { if eval "$2"; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1"; fail=$((fail+1)); fi; }

out=$(python3 "$PROBE" --mx "127.0.0.1:$PORT" info@example.test); rc=$?
check "known mailbox: 250 and exit 0" "[ $rc -eq 0 ] && grep -q 'info@example.test: 250' <<<\"\$out\""
out=$(python3 "$PROBE" --mx "127.0.0.1:$PORT" info@example.test nobody@example.test); rc=$?
check "unknown mailbox: 550 reported as a problem, exit 1" "[ $rc -eq 1 ] && grep -q 'nobody@example.test: 550.*PROBLEM' <<<\"\$out\""
out=$(python3 "$PROBE" --mx "127.0.0.1:1" info@example.test); rc=$?
check "unreachable MX: reported, exit 1" "[ $rc -eq 1 ] && grep -q 'could not probe' <<<\"\$out\""
out=$(python3 "$PROBE" info@example.invalid 2>&1); rc=$?
check "no MX records (or no dig): reported, non-zero exit" "[ $rc -ne 0 ] && grep -q 'NO MX\\|dig not found' <<<\"\$out\""

echo; echo "RESULT: $pass passed, $fail failed"; exit $fail
