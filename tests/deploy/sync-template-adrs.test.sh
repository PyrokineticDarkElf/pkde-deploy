#!/usr/bin/env bash
#
# Test for bin/deploy/sync-template-adrs.sh in a temp sandbox: it copies the template's ADR folder, removes a stale copy, reports a
# site ADR that duplicates a template ADR (same title), never edits or deletes a site-numbered ADR, and is idempotent.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
refuse() { echo "REFUSING TO RUN: $1 (nothing was changed)" >&2; exit 2; }
SCRIPT="$REPO_ROOT/bin/deploy/sync-template-adrs.sh"
[ -f "$SCRIPT" ] || refuse "script missing"
T="$(mktemp -d)" || refuse "mktemp failed"
[ -n "$T" ] && [ -d "$T" ] || refuse "no sandbox"
case "$(cd "$T" && pwd -P)/" in "$REPO_ROOT"/*) refuse "sandbox inside the repository" ;; esac
trap 'rm -rf "$T"' EXIT

TPL="$T/template"; SITE="$T/site"
mkdir -p "$TPL/docs/decisions/template" "$SITE/docs/decisions/template"
printf '# MADR skeleton\n' > "$TPL/docs/decisions/template/0000-template.md"
printf '# Coming soon is a custom mu-plugin\nbody\n' > "$TPL/docs/decisions/template/0001-coming-soon.md"
printf '# Behind the proxy\nbody\n' > "$TPL/docs/decisions/template/0002-proxy.md"
printf '# Our own decision\nsite body\n' > "$SITE/docs/decisions/0001-own.md"
printf '# Coming soon is a custom mu-plugin\nsite copy\n' > "$SITE/docs/decisions/0031-coming-soon-copy.md"
printf '# Old\n' > "$SITE/docs/decisions/template/0009-gone.md"
own_before="$(cat "$SITE/docs/decisions/0001-own.md" "$SITE/docs/decisions/0031-coming-soon-copy.md")"

pass=0; fail=0
check() { if eval "$2"; then pass=$((pass + 1)); echo "  PASS  $1"; else fail=$((fail + 1)); echo "  FAIL  $1"; fi; }

out="$(bash "$SCRIPT" "$TPL" "$SITE" 2>&1)"; rc=$?
check "exits 0" "[ $rc -eq 0 ]"
check "copies the template ADRs" "[ -f '$SITE/docs/decisions/template/0001-coming-soon.md' ] && [ -f '$SITE/docs/decisions/template/0002-proxy.md' ] && [ -f '$SITE/docs/decisions/template/0000-template.md' ]"
check "removes a stale copy" "[ ! -e '$SITE/docs/decisions/template/0009-gone.md' ]"
check "reports the duplicate site ADR by name" "echo \"\$out\" | grep -q 'DUPLICATE  0031-coming-soon-copy.md repeats template/0001-coming-soon.md'"
check "does not flag the site's own ADR" "! echo \"\$out\" | grep -q 'DUPLICATE  0001-own.md'"
check "never edits or deletes site ADRs" "[ \"\$(cat '$SITE/docs/decisions/0001-own.md' '$SITE/docs/decisions/0031-coming-soon-copy.md')\" = \"\$own_before\" ]"
out2="$(bash "$SCRIPT" "$TPL" "$SITE" 2>&1)"
check "second run is idempotent (0 files updated)" "echo \"\$out2\" | grep -q '^0 template ADR file(s) updated'"
bash "$SCRIPT" "$T/nope" "$SITE" >/dev/null 2>&1; check "bad template path exits 2" "[ $? -eq 2 ]"

echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
