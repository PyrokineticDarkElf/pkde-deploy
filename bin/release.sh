#!/usr/bin/env bash
#
# Cuts a release of pkde-deploy: the ONLY way a tag is made (the account is on GitHub's free plan, so there is no branch protection;
# this script is the guard). A tag is a dated version, YYYY.MM.DD, with .1 .2 ... for a second release the same day.
#
#   bin/release.sh 2026.10.22
#
# 1. Refuses unless the tag is well formed and new, the tree is clean, you are on main level with origin/main, CHANGELOG.md has a
#    "## <tag>" section, and CI is green for HEAD.
# 2. Stamps the tag into the reusable workflow (env PKDE_DEPLOY_VERSION) in a "Release <tag>" commit and pushes main: the workflow
#    checks the tools out at that stamp, so a tag and its stamp can never differ.
# 3. Waits for CI to go green on the release commit, then tags exactly that commit and pushes the tag (CI checks the stamp again).
set -euo pipefail

TAG="${1:-}"
[[ "$TAG" =~ ^20[0-9]{2}\.(0[1-9]|1[0-2])\.(0[1-9]|[12][0-9]|3[01])(\.[0-9]+)?$ ]] || { echo "usage: bin/release.sh YYYY.MM.DD[.n]" >&2; exit 2; }
cd "$(dirname "$0")/.."

ci_state() { gh run list --branch main --commit "$1" --limit 1 --json status,conclusion -q '.[0] | "\(.status) \(.conclusion)"' 2>/dev/null || true; }

[ -z "$(git status --porcelain)" ] || { echo "the working tree is not clean" >&2; exit 1; }
[ "$(git rev-parse --abbrev-ref HEAD)" = main ] || { echo "not on main" >&2; exit 1; }
git fetch -q origin --tags
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || { echo "main is not level with origin/main (push or pull first)" >&2; exit 1; }
git rev-parse -q --verify "refs/tags/$TAG" >/dev/null && { echo "tag $TAG already exists" >&2; exit 1; }
grep -q "^## $TAG\$" CHANGELOG.md || { echo "CHANGELOG.md has no '## $TAG' section" >&2; exit 1; }
SHA="$(git rev-parse HEAD)"
[ "$(ci_state "$SHA")" = "completed success" ] || { echo "CI is not green for ${SHA:0:7} (got: $(ci_state "$SHA"))" >&2; exit 1; }

sed -i.bak "s/^  PKDE_DEPLOY_VERSION: .*/  PKDE_DEPLOY_VERSION: $TAG/" .github/workflows/deploy.yml && rm -f .github/workflows/deploy.yml.bak
git add .github/workflows/deploy.yml
if git diff --cached --quiet; then
    echo "The workflow already carries $TAG; tagging HEAD."   # e.g. a stamp fixed by hand
else
    git commit -q -m "Release $TAG"
    git push -q origin main
fi
REL="$(git rev-parse HEAD)"

echo "Waiting for CI on the release commit ${REL:0:7} ..."
for _ in $(seq 1 60); do
    state="$(ci_state "$REL")"
    case "$state" in
        "completed success") break ;;
        completed*) echo "CI failed on the release commit (${state}). Nothing was tagged; fix and release a new tag." >&2; exit 1 ;;
    esac
    sleep 15
done
[ "$(ci_state "$REL")" = "completed success" ] || { echo "CI did not finish in time; nothing was tagged" >&2; exit 1; }

git tag -a "$TAG" -m "$TAG" "$REL"
git push origin "$TAG"
echo "Released $TAG at ${REL:0:7}"
