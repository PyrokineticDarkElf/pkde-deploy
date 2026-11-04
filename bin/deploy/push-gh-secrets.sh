#!/usr/bin/env bash
#
# Pushes one environment's GitHub deploy secrets from ONE 1Password SSH Key item to the repository (MGR-71). The values go straight from `op read` into
# `gh secret set` through a pipe: never printed, logged, put on a command line, kept in a variable or written to disk.
#
#   GH_TOKEN=$(gh auth token --user ACCOUNT) bash push-gh-secrets.sh "<item title or op://vault/item>" <owner/repo> --env staging|prod [--dry-run] [--overwrite]
#
# The standard: one 1Password SSH Key item per environment, titled "Deploy - <domain>" (production) or "Deploy - staging.<domain>" (staging), holding
#   its private key                                                  -> <PREFIX>_SSH_KEY (read in OpenSSH format)
#   custom fields DEPLOY_PATH, SSH_HOST, SSH_PORT, SSH_USER         -> <PREFIX>_DEPLOY_PATH, _SSH_HOST, _SSH_PORT, _SSH_USER (the field's section does not matter)
#   optional custom field SSH_KNOWN_HOSTS (`ssh-keyscan -p PORT HOST`) -> <PREFIX>_SSH_KNOWN_HOSTS
# PREFIX is STAGING or PROD, from --env (required: it is never guessed). A staging item must have "staging" in its title and a production item must not:
# a mix-up stops the run. The item is found by its title (looked up with `op item list`, which shows no values) or given as op://vault/item.
#
# Before anything is set every required field is checked (missing or empty stops the run with nothing changed), and a secret that ALREADY exists on the
# repository is refused unless --overwrite is given (a live site's secrets are not replaced by accident). --dry-run does the checks, lists what it would
# create or overwrite, and sets nothing. It ends by listing the deploy secret NAMES on the repository (`gh secret list`: names and dates, no values).
#
# Needs: `op` signed in (the 1Password desktop app prompts you), `gh`, and GH_TOKEN in the environment for THIS command (the account is explicit, never the one
# that happens to be logged in). Creating or editing the 1Password item is yours; setting repository secrets is an outward step.
set -uo pipefail
set +x

usage() { sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }
die() { echo "push-gh-secrets: $*" >&2; exit 1; }

ITEM="${1:-}"; REPO="${2:-}"
[ -n "$ITEM" ] && [ -n "$REPO" ] || usage
shift 2
ENVNAME=""; DRY=0; OVERWRITE=0
while [ $# -gt 0 ]; do
    case "$1" in
        --env) ENVNAME="${2:-}"; shift 2 ;;
        --dry-run) DRY=1; shift ;;
        --overwrite) OVERWRITE=1; shift ;;
        *) usage ;;
    esac
done

[[ "$REPO" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || die "the repository must look like owner/repo"
case "$ENVNAME" in staging) PREFIX=STAGING ;; prod) PREFIX=PROD ;; "") die "--env staging or --env prod is required (it is never guessed)" ;; *) die "--env must be staging or prod" ;; esac

command -v op >/dev/null 2>&1 || die "the 1Password CLI (op) is not installed"
command -v gh >/dev/null 2>&1 || die "the GitHub CLI (gh) is not installed"
command -v python3 >/dev/null 2>&1 || die "python3 is needed to find the item by its title"
[ -n "${GH_TOKEN:-}" ] || die "GH_TOKEN is not set: run it as GH_TOKEN=\$(gh auth token --user ACCOUNT) bash push-gh-secrets.sh ... so the account is explicit"
# `op whoami` fails with the desktop-app integration even when op works; listing the vaults is the check that matches what `op read` needs.
op vault list >/dev/null 2>&1 || die "op is not signed in or the 1Password app is locked (unlock it, then retry)"

# The item: a title is looked up in `op item list` (no values in that output); op://vault/item is used as given.
TITLE=""
if [[ "$ITEM" =~ ^op://([^/]+)/([^/]+)$ ]]; then
    TITLE="${BASH_REMATCH[2]}"
    [[ "$TITLE" =~ ^[a-z0-9]{26}$ ]] && TITLE=""   # an item id carries no title to check
elif [[ "$ITEM" == op://* ]]; then
    die "the item reference must look like op://vault/item (no field)"
else
    TITLE="$ITEM"
    ITEM="$(op item list --format json 2>/dev/null | python3 -c '
import json, sys
title = sys.argv[1]
hits = [i for i in json.load(sys.stdin) if i.get("title") == title]
if len(hits) != 1:
    sys.exit(3 if not hits else 4)
print("op://%s/%s" % (hits[0]["vault"]["id"], hits[0]["id"]))
' "$TITLE")" || die "no single 1Password item is titled \"$TITLE\" (check the exact title, and that the vault is available to op)"
fi

if [ -n "$TITLE" ]; then
    case "$PREFIX:$TITLE" in
        STAGING:*staging*) ;;
        STAGING:*) die "--env staging, but the item \"$TITLE\" has no \"staging\" in its title: nothing was set" ;;
        PROD:*staging*) die "--env prod, but the item \"$TITLE\" has \"staging\" in its title: nothing was set" ;;
    esac
fi

ref() { printf '%s/%s' "$ITEM" "$1"; }
# 1 when the field exists and is not empty; prints a count, never the value.
filled() { op read "$(ref "$1")" 2>/dev/null | head -c 1 | wc -c | tr -d ' '; }
key_filled() { op read "$(ref 'private key')?ssh-format=openssh" 2>/dev/null | head -c 1 | wc -c | tr -d ' '; }

# 1) Check everything before setting anything.
FIELDS=(DEPLOY_PATH SSH_HOST SSH_PORT SSH_USER)
SECRETS=(); SKIPPED=()
for name in "${FIELDS[@]}"; do
    [ "$(filled "$name")" = 1 ] || die "the field $name is missing or empty in the 1Password item; nothing was set"
    SECRETS+=("${PREFIX}_${name}")
done
[ "$(key_filled)" = 1 ] || die "the item has no private key readable in OpenSSH format (is it an SSH Key item?); nothing was set"
SECRETS+=("${PREFIX}_SSH_KEY")
if [ "$(filled SSH_KNOWN_HOSTS)" = 1 ]; then SECRETS+=("${PREFIX}_SSH_KNOWN_HOSTS"); else SKIPPED+=("${PREFIX}_SSH_KNOWN_HOSTS"); fi

# 2) What exists already: refused without --overwrite.
EXISTING="$(gh secret list -R "$REPO" 2>/dev/null | awk '{print $1}')" || die "could not list the secrets on $REPO (does GH_TOKEN belong to an account with access?); nothing was set"
CLASH=()
for s in "${SECRETS[@]}"; do
    if printf '%s\n' "$EXISTING" | grep -qx "$s"; then CLASH+=("$s"); fi
done

if [ "$DRY" = 1 ]; then
    echo "Dry run for $REPO, --env $ENVNAME (all required fields are filled; no values were read out). Would set:"
    for s in "${SECRETS[@]}"; do
        if printf '%s\n' "${CLASH[@]+"${CLASH[@]}"}" | grep -qx "$s"; then echo "  $s  (OVERWRITES an existing secret)"; else echo "  $s  (new)"; fi
    done
    [ "${#SKIPPED[@]}" -gt 0 ] && { echo "Optional, not in the item, would be skipped:"; printf '  %s\n' "${SKIPPED[@]}"; }
    [ "${#CLASH[@]}" -gt 0 ] && echo "A real run needs --overwrite because ${#CLASH[@]} of them already exist."
    exit 0
fi
if [ "${#CLASH[@]}" -gt 0 ] && [ "$OVERWRITE" != 1 ]; then
    printf '  %s\n' "${CLASH[@]}" >&2
    die "these secrets already exist on $REPO (listed above); nothing was set. Re-run with --overwrite to replace them"
fi

# 3) Set them: op read | gh secret set, the value only ever inside the pipe.
for s in "${SECRETS[@]}"; do
    case "$s" in
        *_SSH_KEY) op read "$(ref 'private key')?ssh-format=openssh" 2>/dev/null | gh secret set "$s" -R "$REPO" >/dev/null 2>&1 || die "setting $s failed (not printed: the value never leaves the pipe)" ;;
        *) op read "$(ref "${s#"${PREFIX}_"}")" 2>/dev/null | gh secret set "$s" -R "$REPO" >/dev/null 2>&1 || die "setting $s failed (not printed: the value never leaves the pipe)" ;;
    esac
    echo "set $s"
done
[ "${#SKIPPED[@]}" -gt 0 ] && { echo "Skipped (optional, not in the item):"; printf '  %s\n' "${SKIPPED[@]}"; }

echo "Deploy secrets now on $REPO (names and dates only):"
gh secret list -R "$REPO" 2>/dev/null | awk '{print $1}' | grep -E '^(STAGING|PROD)_' | sort | sed 's/^/  /'
