#!/usr/bin/env bash
#
# Runs WP-CLI on a 20i server wherever this package keeps it, and says which one it used. The guide's commands use it so
# they work on every package: some have the phar at ~/bin/wp, others the system install at /usr/local/bin/wp (cs has no ~/bin/wp).
#
#   cd <DEPLOY_PATH>/current && bash bin/deploy/wp.sh eval-file bin/deploy/drift-check.php
#   bash bin/deploy/wp.sh plugin list
#
# Search order: $WP_CLI (an explicit override), `command -v wp`, /usr/local/bin/wp, ~/bin/wp. The first line it prints (to stderr) is
# "WP-CLI: <path> (<how it was found>)". A phar or PHP script is run as `php83 -d memory_limit=512M <wp>` (the home directory is
# noexec, and the CLI default of 128M runs out with WooCommerce); an executable wrapper script is run as it is. Optional: WP_PHP (the
# PHP binary; default php83, else php) and WP_CLI_MEMORY (default 512M).

set -uo pipefail

find_wp() { # prints "<path>|<how>"
    if [ -n "${WP_CLI:-}" ]; then echo "$WP_CLI|WP_CLI from the environment"; return 0; fi
    local found
    if found="$(command -v wp 2>/dev/null)" && [ -n "$found" ] && [ -e "$found" ]; then echo "$found|command -v wp"; return 0; fi
    if [ -e "${WP_CLI_SYSTEM_PATH:-/usr/local/bin/wp}" ]; then echo "${WP_CLI_SYSTEM_PATH:-/usr/local/bin/wp}|system install"; return 0; fi
    if [ -e "$HOME/bin/wp" ]; then echo "$HOME/bin/wp|~/bin/wp"; return 0; fi
    return 1
}

if ! hit="$(find_wp)"; then
    echo "WP-CLI not found: looked for \$WP_CLI, 'wp' on the PATH, /usr/local/bin/wp and ~/bin/wp. See the launch guide (install WP-CLI)." >&2
    exit 127
fi
WP="${hit%%|*}"
echo "WP-CLI: $WP (${hit#*|})" >&2

# An executable wrapper (a shell script that starts PHP itself) is run directly; a phar or PHP file goes through php.
first="$(head -c 64 "$WP" 2>/dev/null | head -1)"
case "$first" in
    '#!'*php*) ;;
    '#!'*) exec "$WP" "$@" ;;
esac

PHP="${WP_PHP:-}"
if [ -z "$PHP" ]; then
    if command -v php83 >/dev/null 2>&1; then PHP=php83; else PHP=php; fi
fi
exec "$PHP" -d memory_limit="${WP_CLI_MEMORY:-512M}" "$WP" "$@"
