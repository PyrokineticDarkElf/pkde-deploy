#!/usr/bin/env bash
#
# Static guards for the workflows (MGR-67 / RP-85 / cs): every third-party action is pinned by a 40-character commit SHA (a tag can be moved),
# every checkout sets persist-credentials: false (the token must not sit in .git/config while composer and npm run third-party code), every
# workflow has a top-level permissions block, and the deploy step can pin the server's host key.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1 (expected '$3', got '$2')"; fi; }

for wf in "$REPO_ROOT"/.github/workflows/*.yml; do
  name=$(basename "$wf")
  # Third-party and GitHub actions (not this repository's own reusable workflow, which sites pin by tag).
  unpinned=$(grep -E '^\s*(- )?uses: ' "$wf" | grep -v 'PyrokineticDarkElf/pkde-deploy' | grep -vE 'uses: [^@ ]+@[0-9a-f]{40}( |$)' | wc -l | tr -d ' ')
  check "$name: every action is pinned by commit SHA" "$unpinned" 0
  checkouts=$(grep -cE 'uses: actions/checkout@' "$wf"); persisted=$(grep -c 'persist-credentials: false' "$wf")
  check "$name: every checkout sets persist-credentials: false" "$persisted" "$checkouts"
  has_perm=$(grep -cE '^permissions:' "$wf"); check "$name: has a top-level permissions block" "$has_perm" 1
done

check "the deploy step can pin the host key (PROD and STAGING)" "$(grep -c 'SSH_KNOWN_HOSTS' "$REPO_ROOT/.github/workflows/deploy.yml" | awk '{print ($1 >= 6) ? "ok" : "few"}')" ok
check "a pinned host key turns StrictHostKeyChecking on" "$(grep -c 'StrictHostKeyChecking=yes' "$REPO_ROOT/.github/workflows/deploy.yml")" 1

check "the deploy build installs npm packages without running their scripts" "$(grep -c 'npm ci --ignore-scripts' "$REPO_ROOT/.github/workflows/deploy.yml")" 1
check "the deploy refuses to load the SSH key when the pipeline tools were modified" "$(grep -n 'Check the pipeline tools were not modified' "$REPO_ROOT/.github/workflows/deploy.yml" | cut -d: -f1 | awk '{print ($1 > 0) ? "ok" : "no"}')" ok
key_line=$(grep -n 'name: Load deploy SSH key' "$REPO_ROOT/.github/workflows/deploy.yml" | cut -d: -f1); guard_line=$(grep -n 'name: Check the pipeline tools were not modified' "$REPO_ROOT/.github/workflows/deploy.yml" | cut -d: -f1)
check "...and the check comes before the key" "$([ "$guard_line" -lt "$key_line" ] && echo yes || echo no)" yes

echo "workflow-hardening: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
