#!/usr/bin/env bash
#
# Sandbox test for bin/deploy/safe-push-main.sh against a local bare "origin" (nothing leaves the machine):
# a docs/backlog-only range is allowed, a code path or a mix is refused with the paths listed, a non-fast-forward
# is refused, nothing is pushed without --push, and --push really fast-forwards origin/main.

set -uo pipefail
# SAFETY: this test creates and deletes fixtures. It must only ever do that inside the temp sandbox it creates.
# If a step fails (no git, no mktemp, a failed cd) it must stop, never carry on in the real repository.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
refuse() { echo "REFUSING TO RUN: $1 (nothing was changed)" >&2; exit 2; }
SCRIPT="$REPO_ROOT/bin/deploy/safe-push-main.sh"
command -v git >/dev/null 2>&1 && git --version >/dev/null 2>&1 || refuse "git is not available here (do not run this in a container without git)"
T="$(mktemp -d)" || refuse "mktemp failed"
[ -n "$T" ] && [ -d "$T" ] || refuse "mktemp did not create a sandbox"
case "$(cd "$T" && pwd -P)/" in "$REPO_ROOT"/*) refuse "the sandbox is inside the repository" ;; esac
trap 'rm -rf "$T"' EXIT
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@example.test GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@example.test

git init -q --bare "$T/origin.git" || refuse "git init failed"
git init -q "$T/work" || refuse "git init failed"
cd "$T/work" || refuse "could not enter the sandbox"
# Prove we are inside the sandbox, and not in the repository, before anything is written.
[ "$(pwd -P)" != "$REPO_ROOT" ] && [ "$(git rev-parse --show-toplevel 2>/dev/null)" = "$(pwd -P)" ] || refuse "not inside the sandbox repository"
git checkout -q -b main
mkdir -p backlog docs web && echo 1 > web/index.php && echo a > backlog/t.md && git add -A && git commit -qm base
git remote add origin "$T/origin.git" && git push -q origin main
git checkout -q -b develop

pass=0; fail=0
check() { if eval "$2"; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1"; fail=$((fail+1)); fi; }
origin_main() { git --git-dir="$T/origin.git" rev-parse main; }
before=$(origin_main)

echo "=== docs/backlog only ==="
echo b >> backlog/t.md; mkdir -p changelog.d && echo c > changelog.d/X-1.fix.md; echo d > docs/x.md; echo e > CHANGELOG.md
git add -A && git commit -qm "bookkeeping"
out=$(bash "$SCRIPT" 2>&1); rc=$?
check "allowed (exit 0, says OK)" "[ $rc -eq 0 ] && grep -q '^OK:' <<<\"\$out\""
check "check-only pushes nothing" "[ \"\$(origin_main)\" = \"$before\" ]"

echo "=== code in the range ==="
echo changed > web/index.php; git add -A && git commit -qm "code"
out=$(bash "$SCRIPT" 2>&1); rc=$?
check "refused (exit 1) and lists the path" "[ $rc -eq 1 ] && grep -q 'REFUSED' <<<\"\$out\" && grep -q 'web/index.php' <<<\"\$out\""
check "does not list allowed paths as offenders" "! grep -q 'backlog/t.md' <<<\"\$out\""
out=$(bash "$SCRIPT" --push 2>&1); rc=$?
check "--push is refused too, and origin/main is untouched" "[ $rc -eq 1 ] && [ \"\$(origin_main)\" = \"$before\" ]"

echo "=== non-fast-forward ==="
git checkout -q main && echo hotfix > web/hotfix.php && git add -A && git commit -qm hotfix && git push -q origin main
git checkout -q develop
out=$(bash "$SCRIPT" 2>&1); rc=$?
check "refused: origin/main is not an ancestor" "[ $rc -eq 1 ] && grep -q 'fast-forward' <<<\"\$out\""

echo "=== --push really fast-forwards (fresh docs-only branch) ==="
git checkout -q -b docs-only origin/main && mkdir -p docs && echo n > docs/new.md && git add -A && git commit -qm docs
before=$(origin_main)
out=$(bash "$SCRIPT" docs-only --push 2>&1); rc=$?
check "allowed and pushed (exit 0)" "[ $rc -eq 0 ]"
check "origin/main moved and now equals the branch tip" "[ \"\$(origin_main)\" != \"$before\" ] && [ \"\$(origin_main)\" = \"\$(git rev-parse docs-only)\" ]"
out=$(bash "$SCRIPT" docs-only 2>&1); rc=$?
check "nothing left to do afterwards" "[ $rc -eq 0 ] && grep -q 'Nothing to do' <<<\"\$out\""

echo; echo "RESULT: $pass passed, $fail failed"; exit $fail
