#!/usr/bin/env bash
#
# Pushes a site's GitHub deploy secrets from ONE 1Password item to the repository (MGR-71). The values go straight from `op read` into
# `gh secret set` through a pipe: they are never printed, logged, put on a command line, kept in a variable or written to disk.
#
#   GH_TOKEN=$(gh auth token --user ACCOUNT) bash push-gh-secrets.sh <op://vault/item> <owner/repo> [--env staging|prod|both] [--dry-run]
#
# The 1Password item has a field named exactly like each secret:
#   <PREFIX>_DEPLOY_PATH, <PREFIX>_SSH_HOST, <PREFIX>_SSH_PORT, <PREFIX>_SSH_USER      the value itself
#   <PREFIX>_SSH_KEY                                                                   a REFERENCE to a 1Password SSH key item's private key,
#                                                                                       e.g. op://Vault/deploy key/private key
#   <PREFIX>_SSH_KNOWN_HOSTS                                                           optional (the output of `ssh-keyscan -p PORT HOST`)
# with PREFIX STAGING and/or PROD (--env, default staging). The key is read in OpenSSH format (`?ssh-format=openssh`). A required field that is
# missing or empty stops the run BEFORE anything is set. --dry-run lists the secret names it would set (and checks the fields are filled) and sets
# nothing. At the end it lists the deploy secret NAMES that now exist (`gh secret list`: names and dates, no values).
#
# Needs: `op` signed in (the 1Password desktop app prompts you), `gh`, and GH_TOKEN in the environment for THIS command (so the account is explicit and
# never the one that happens to be logged in). Creating or editing the 1Password item is yours; setting repository secrets is an outward step.
set -uo pipefail
set +x

usage() { sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }
die() { echo "push-gh-secrets: $*" >&2; exit 1; }

ITEM="${1:-}"; REPO="${2:-}"
[ -n "$ITEM" ] && [ -n "$REPO" ] || usage
shift 2
ENVS="staging"; DRY=0
while [ $# -gt 0 ]; do
    case "$1" in
        --env) ENVS="${2:-}"; shift 2 ;;
        --dry-run) DRY=1; shift ;;
        *) usage ;;
    esac
done

[[ "$ITEM" =~ ^op://[^/]+/[^/]+$ ]] || die "the item reference must look like op://vault/item (no field)"
[[ "$REPO" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || die "the repository must look like owner/repo"
case "$ENVS" in staging) PREFIXES=(STAGING) ;; prod) PREFIXES=(PROD) ;; both) PREFIXES=(STAGING PROD) ;; *) die "--env must be staging, prod or both" ;; esac

command -v op >/dev/null 2>&1 || die "the 1Password CLI (op) is not installed"
command -v gh >/dev/null 2>&1 || die "the GitHub CLI (gh) is not installed"
[ -n "${GH_TOKEN:-}" ] || die "GH_TOKEN is not set: run it as GH_TOKEN=\$(gh auth token --user ACCOUNT) bash push-gh-secrets.sh ... so the account is explicit"
# `op whoami` fails with the desktop-app integration even when op works; listing the vaults is the check that matches what `op read` needs.
op vault list >/dev/null 2>&1 || die "op is not signed in or the 1Password app is locked (unlock it, then retry)"

REQUIRED=(DEPLOY_PATH SSH_HOST SSH_KEY SSH_PORT SSH_USER)
OPTIONAL=(SSH_KNOWN_HOSTS)

# The 1Password reference of a field: op://vault/item/FIELD.
ref() { printf '%s/%s' "$ITEM" "$1"; }
# 1 when the field exists and is not empty; prints a count, never the value.
filled() { op read "$(ref "$1")" 2>/dev/null | head -c 1 | wc -c | tr -d ' '; }

# 1) Check everything before setting anything.
TO_SET=(); SKIPPED=()
for prefix in "${PREFIXES[@]}"; do
    for name in "${REQUIRED[@]}"; do
        field="${prefix}_${name}"
        [ "$(filled "$field")" = 1 ] || die "the 1Password field $field is missing or empty in $ITEM; nothing was set"
        if [ "$name" = SSH_KEY ]; then
            # The field holds a reference to an SSH key item, not the key: a reference is not secret, and a key pasted into a text field is refused.
            keyref="$(op read "$(ref "$field")" 2>/dev/null)" || die "could not read the $field field; nothing was set"
            [[ "$keyref" =~ ^op://[^/]+/[^/]+/[^/]+$ ]] || die "$field must hold a reference to an SSH key item's private key (op://vault/item/private key), not the key itself; nothing was set"
            [ "$(op read "${keyref}?ssh-format=openssh" 2>/dev/null | head -c 1 | wc -c | tr -d ' ')" = 1 ] || die "$field points at $keyref, which could not be read as an OpenSSH private key; nothing was set"
        fi
        TO_SET+=("$field")
    done
    for name in "${OPTIONAL[@]}"; do
        field="${prefix}_${name}"
        if [ "$(filled "$field")" = 1 ]; then TO_SET+=("$field"); else SKIPPED+=("$field"); fi
    done
done
keyref=""

if [ "$DRY" = 1 ]; then
    echo "Dry run: would set these secrets on $REPO (the fields are all filled; no values were read out):"
    printf '  %s\n' "${TO_SET[@]}"
    [ "${#SKIPPED[@]}" -gt 0 ] && { echo "Optional, not in the item, would be skipped:"; printf '  %s\n' "${SKIPPED[@]}"; }
    exit 0
fi

# 2) Set them: op read | gh secret set, value only ever inside the pipe.
for field in "${TO_SET[@]}"; do
    case "$field" in
        *_SSH_KEY)
            keyref="$(op read "$(ref "$field")" 2>/dev/null)"
            op read "${keyref}?ssh-format=openssh" 2>/dev/null | gh secret set "$field" -R "$REPO" >/dev/null 2>&1 || die "setting $field failed (not printed: the value never leaves the pipe)"
            keyref=""
            ;;
        *)
            op read "$(ref "$field")" 2>/dev/null | gh secret set "$field" -R "$REPO" >/dev/null 2>&1 || die "setting $field failed (not printed: the value never leaves the pipe)"
            ;;
    esac
    echo "set $field"
done
[ "${#SKIPPED[@]}" -gt 0 ] && { echo "Skipped (optional, not in the item):"; printf '  %s\n' "${SKIPPED[@]}"; }

echo "Deploy secrets now on $REPO (names and dates only):"
gh secret list -R "$REPO" 2>/dev/null | awk '{print $1}' | grep -E '^(STAGING|PROD)_' | sort | sed 's/^/  /'
