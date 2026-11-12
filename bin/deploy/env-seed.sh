#!/usr/bin/env bash
#
# Creates a site's 1Password secure note from its server .env, once (WP-87). That is a 1Password WRITE: run it yourself in a terminal (the desktop app prompts you).
#
#   env-seed.sh <host> --env staging|prod --vault "<vault>" [--account <account>] [--example .env.example] [--dry-run]
#
# Reads shared/.env over ssh and creates the SECURE_NOTE "<host> .env" (override with --item) in the vault: one CONCEALED field per variable, label = the variable name,
# value = the literal right-hand side of the line (quotes and ${...} kept), sections named after the comment blocks of .env.example. It only creates: an item that
# already exists is refused (edit it in 1Password). --dry-run reads the server and prints the variable and section NAMES, creates nothing.
# Values are never printed or put on a command line: the item is sent to `op item create` as JSON on stdin. Afterwards: env-pull.sh ... --check must say MATCHES.
# Same ssh names as env-pull.sh (PROD_SSH_HOST, PROD_SSH_PORT, PROD_SSH_USER, PROD_DEPLOY_PATH; staging: STAGING_*). The tcs and bb notes already exist in the work account.
set -uo pipefail
set +x
command -v op >/dev/null 2>&1 || { echo "env-seed: the 1Password CLI (op) is not installed" >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { echo "env-seed: python3 is needed" >&2; exit 1; }
[ $# -ge 1 ] && [ "${1#-}" = "$1" ] || { sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }
exec python3 "$(dirname "$0")/env-sync.py" seed "$@"
