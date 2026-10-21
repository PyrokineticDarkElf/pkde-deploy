#!/usr/bin/env bash
#
# Test for bin/deploy/check-site-paths.sh against throwaway git repositories: a workflow naming a site-ignored path (bin/foo.sh) or a
# path that is not tracked fails; tracked paths pass; a thin caller naming no paths passes.
set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
SCRIPT="$REPO_ROOT/bin/deploy/check-site-paths.sh"
T="$(mktemp -d)" || exit 2
case "$(cd "$T" && pwd -P)/" in "$REPO_ROOT"/*) echo "REFUSING TO RUN: sandbox inside the repository" >&2; exit 2 ;; esac
trap 'rm -rf "$T"' EXIT
pass=0; fail=0
check() { if eval "$2"; then pass=$((pass + 1)); echo "  PASS  $1"; else fail=$((fail + 1)); echo "  FAIL  $1"; fi; }

mk() { # <name> <workflow body>
    local d="$T/$1"; mkdir -p "$d/.github/workflows" "$d/tests" "$d/bin"
    printf '%s\n' "$2" > "$d/.github/workflows/ci.yml"
    echo ok > "$d/tests/a.test.sh"; echo ok > "$d/bin/foo.sh"
    (cd "$d" && git init -q && git add .github tests && git -c user.name=t -c user.email=t@t commit -qm init) >/dev/null 2>&1
    echo "$d"
}

d="$(mk good 'run: bash tests/a.test.sh')"
check "a tracked path passes" "bash '$SCRIPT' '$d' >/dev/null 2>&1"
d="$(mk ignored 'run: bash bin/foo.sh')"
bash "$SCRIPT" "$d" > "$T/o1" 2>&1; rc=$?
check "a path under bin/ (ignored on sites) fails and is named" "[ $rc -eq 1 ] && grep -q 'FAIL  bin/foo.sh is not a path sites ignore' '$T/o1'"
d="$(mk deploydir 'run: bash bin/deploy/x.sh')"
check "bin/deploy/ is allowed but must be tracked: an untracked one fails, with the git add hint" "! bash '$SCRIPT' '$d' > '$T/o2' 2>&1 && grep -q 'git add-ed first' '$T/o2'"
d="$(mk thin 'uses: PyrokineticDarkElf/pkde-deploy/.github/workflows/deploy.yml@2026.10.21')"
check "a thin caller that names no repository paths passes" "bash '$SCRIPT' '$d' > '$T/o3' 2>&1 && grep -q 'nothing to check' '$T/o3'"
d="$(mk claude 'run: cat CLAUDE.md')"
check "CLAUDE.md fails (ignored on sites)" "! bash '$SCRIPT' '$d' >/dev/null 2>&1"

echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
