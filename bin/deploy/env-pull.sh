#!/usr/bin/env bash
#
# Brings a site's server .env in line with its 1Password secure note (WP-87). A DRY RUN unless --apply: it lists variable NAMES only (never a value) and writes nothing.
#
#   env-pull.sh <host> --env staging|prod --vault "<vault>" [--account <account>] [--example .env.example]            dry run
#   env-pull.sh <host> --env staging|prod --vault "<vault>" --check                                                  changes nothing; exit 1 if the server and the note differ (names only in .env.example are INFO)
#   env-pull.sh <host> --env staging|prod --vault "<vault>" --apply [--prune] [--production-go]                       writes the server's shared/.env
#
# The note is "<host> .env" (override with --item): a SECURE_NOTE, one field per variable, label = the variable name, value = the literal right-hand side of the
# .env line. Create it with env-seed.sh. Run it from the site's repository so .env.example is found (it lists variables the note lacks as INFO).
#
# Connects like the other deploy scripts, from the same names as the GitHub secrets exported in your shell: PROD_SSH_HOST, PROD_SSH_PORT, PROD_SSH_USER and
# PROD_DEPLOY_PATH (staging: STAGING_*). One ssh ControlMaster is reused; PKDE_SSH_OPTS adds options (for example -i <pub> -o IdentitiesOnly=yes).
# Needs `op` signed in (the 1Password desktop app prompts you) and python3. The values go from `op` into this process and over ssh stdin: never printed, never on
# a command line, never in a file here. It counts as a gateway session (reports/README), so take the lock; a production write needs the owner's go.
#
# Refuses (nothing written) when a required variable (WP_ENV, WP_HOME, WP_SITEURL, DB_*, the eight salts) is empty or missing in the note, or a value spans lines.
# Adds and changes variables; removes a server-only variable only with --prune. --apply keeps .env.bak-<time> next to the file and then requests WP_HOME: a 5xx or no
# answer is reported with the rollback command.
set -uo pipefail
set +x
command -v op >/dev/null 2>&1 || { echo "env-pull: the 1Password CLI (op) is not installed" >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { echo "env-pull: python3 is needed" >&2; exit 1; }
[ $# -ge 1 ] && [ "${1#-}" = "$1" ] || { sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }
exec python3 "$(dirname "$0")/env-sync.py" pull "$@"
