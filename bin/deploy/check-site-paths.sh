#!/usr/bin/env bash
#
# Guard for the rule "anything a site must have in git or CI lives where a site tracks it". Site repos ignore /bin/* (except /bin/deploy/),
# /CLAUDE.md, /web/app/themes/sage/docs and /users.csv*, so a workflow step that runs a file there passes in one checkout and breaks on
# every site (2026.10.19: bin/sync-template-adrs.sh). The reusable workflow runs this against the caller's checkout on every build; the
# pkde-deploy tests run it against fixtures.
#
#   bash check-site-paths.sh [repo-root]        (default: the current directory)
#
# It reads the paths the workflows in <root>/.github/workflows mention and fails when one is a site-ignored path or is not tracked in
# git. It asks git (git ls-files), on purpose: an untracked local file must not hide a problem, so locally it FAILS for a file you
# have created but not yet `git add`ed (the message says so). In CI everything is committed. A caller that names no repository path at
# all (the thin caller) passes.
set -uo pipefail
ROOT="$(cd "${1:-.}" && pwd -P)" || exit 2
cd "$ROOT" || exit 2
pass=0; fail=0
check() { if eval "$2"; then pass=$((pass + 1)); echo "  PASS  $1"; else fail=$((fail + 1)); echo "  FAIL  $1"; fi; }

ls .github/workflows/*.yml >/dev/null 2>&1 || { echo "no workflows in $ROOT/.github/workflows"; exit 0; }
paths="$(grep -hoE '(^|[^A-Za-z0-9_./-])(bin|tests|docs|web|config)/[A-Za-z0-9_./-]+|CLAUDE\.md|users\.csv[A-Za-z0-9_.]*' .github/workflows/*.yml | sed -E 's/^[^A-Za-z0-9]//' | sort -u)"
[ -n "$paths" ] || { echo "The workflows name no repository paths: nothing to check."; exit 0; }

echo "=== paths the workflows use must be tracked on a site ==="
while IFS= read -r p; do
    case "$p" in
        bin/deploy/*|bin/deploy) ;;
        bin/*|CLAUDE.md|users.csv*|web/app/themes/sage/docs/*) check "$p is not a path sites ignore" "false"; continue ;;
    esac
    # a workflow may name a directory or a glob stem; it must match at least one tracked file
    check "$p is tracked in git (a new file must be git add-ed first)" "[ -n \"\$(git ls-files -- '$p' | head -1)\" ] || [ -n \"\$(git ls-files -- '$p/*' | head -1)\" ]"
done <<< "$paths"

echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
