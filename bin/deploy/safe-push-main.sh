#!/usr/bin/env bash
#
# Guard for moving main. main deploys to PRODUCTION, so it moves only at an owner-approved release. The one
# exception this script allows: bringing bookkeeping commits that are already on develop across, when NOTHING else
# is waiting on develop. It looks at every commit in origin/main..<branch> and refuses unless each changed path is
# under backlog/, changelog.d/, CHANGELOG.md or docs/ (the paths the deploy workflow ignores on push anyway).
# Any other path means develop holds unreleased code: that is a release decision, not a "sync".
#
# Usage:  bin/deploy/safe-push-main.sh [<branch>=develop]            check only: prints the verdict, pushes nothing
#         bin/deploy/safe-push-main.sh [<branch>=develop] --push     check, then fast-forward origin/main to <branch>
#
# Exit codes: 0 allowed (and pushed with --push), 1 refused, 2 usage/git error. Only ever fast-forwards, never forces.
# This is a seat belt, not enforcement: someone can still `git push` by hand. Make main a protected branch in the
# repository settings (require a pull request / restrict who can push) for the real thing.

set -euo pipefail

branch=develop; push=0
for arg in "$@"; do
    case "$arg" in
        --push) push=1 ;;
        -*) echo "Unknown option: $arg" >&2; exit 2 ;;
        *) branch=$arg ;;
    esac
done

git fetch --quiet origin || { echo "Could not fetch origin." >&2; exit 2; }
git rev-parse --verify --quiet "origin/main" >/dev/null || { echo "No origin/main." >&2; exit 2; }
git rev-parse --verify --quiet "$branch" >/dev/null || { echo "No branch $branch." >&2; exit 2; }

if ! git merge-base --is-ancestor origin/main "$branch"; then
    echo "REFUSED: origin/main is not an ancestor of $branch, so this would not be a fast-forward." >&2
    exit 1
fi

commits=$(git rev-list --count "origin/main..$branch")
if [ "$commits" -eq 0 ]; then
    echo "Nothing to do: $branch has no commits that origin/main lacks."
    exit 0
fi

# Every path touched by any commit in the range (--name-only lists renames and deletions too).
outside=$(git log "origin/main..$branch" --name-only --pretty=format: \
    | sed '/^$/d' | sort -u \
    | grep -Ev '^(backlog/|changelog\.d/|docs/|CHANGELOG\.md$)' || true)

if [ -n "$outside" ]; then
    echo "REFUSED: $branch holds $commits commit(s) ahead of origin/main that touch more than backlog/, changelog.d/, CHANGELOG.md and docs/:" >&2
    echo "$outside" | sed 's/^/  /' >&2
    echo "That is unreleased code. main (production) moves only at an owner-approved release; put bookkeeping on develop only." >&2
    exit 1
fi

echo "OK: the $commits commit(s) in origin/main..$branch only touch backlog/, changelog.d/, CHANGELOG.md and docs/."
if [ "$push" = 1 ]; then
    git push origin "$branch:main"
else
    echo "Check only. Re-run with --push to fast-forward origin/main."
fi
