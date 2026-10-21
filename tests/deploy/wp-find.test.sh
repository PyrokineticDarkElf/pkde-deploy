#!/usr/bin/env bash
#
# Test for bin/deploy/wp.sh: it finds WP-CLI in the documented order (WP_CLI, command -v wp, /usr/local/bin/wp, ~/bin/wp), prints
# which one it used, runs a phar through php with the memory limit and a wrapper script as it is, and fails clearly when there is none.
# A stub php records its arguments. Nothing leaves the machine.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
T="$(mktemp -d)" || exit 2
case "$(cd "$T" && pwd -P)/" in "$REPO_ROOT"/*) echo "REFUSING TO RUN: sandbox inside the repository" >&2; exit 2 ;; esac
trap 'rm -rf "$T"' EXIT

mkdir -p "$T/bin" "$T/home/bin" "$T/sys" "$T/empty"
cat > "$T/bin/php83" <<STUB
#!/bin/sh
echo "php83 \$*" >> "$T/php.log"
STUB
chmod +x "$T/bin/php83"
S="$REPO_ROOT/bin/deploy/wp.sh"
BASH_BIN="$(command -v bash)"

pass=0; fail=0
check() { if eval "$2"; then pass=$((pass + 1)); echo "  PASS  $1"; else fail=$((fail + 1)); echo "  FAIL  $1"; fi; }
# every run: a clean PATH with only the stub php83 (and the system tools), HOME in the sandbox, no /usr/local/bin/wp lookup unless set
run() { : > "$T/php.log"; env -i HOME="$T/home" PATH="$T/bin:/usr/bin:/bin" WP_CLI_SYSTEM_PATH="${SYS:-$T/sys/none}" "$@" "$BASH_BIN" "$S" plugin list > "$T/out" 2>&1; echo $?; }

echo "<?php // phar stub" > "$T/home/bin/wp"
rc=$(run)
check "only ~/bin/wp exists: found there, says so, run through php83 with the memory limit" "[ $rc -eq 0 ] && grep -q 'WP-CLI: $T/home/bin/wp (~/bin/wp)' '$T/out' && grep -q \"php83 -d memory_limit=512M $T/home/bin/wp plugin list\" '$T/php.log'"

echo "<?php // system phar" > "$T/sys/wp"
SYS="$T/sys/wp" rc=$(SYS="$T/sys/wp" run)
check "the system install wins over ~/bin/wp (cs has no ~/bin/wp)" "[ $rc -eq 0 ] && grep -q 'WP-CLI: $T/sys/wp (system install)' '$T/out'"

printf '#!/bin/sh\necho "wrapper $*" >> %s/php.log\n' "$T" > "$T/bin/wp"; chmod +x "$T/bin/wp"
SYS="$T/sys/wp" rc=$(SYS="$T/sys/wp" run)
check "a wp on the PATH wins over both, and an executable wrapper is run as it is" "[ $rc -eq 0 ] && grep -q 'WP-CLI: $T/bin/wp (command -v wp)' '$T/out' && grep -q 'wrapper plugin list' '$T/php.log' && ! grep -q php83 '$T/php.log'"
rm -f "$T/bin/wp"

echo "<?php // override" > "$T/empty/override.phar"
rc=$(SYS="$T/sys/wp" run WP_CLI="$T/empty/override.phar" WP_CLI_MEMORY=768M)
check "WP_CLI from the environment wins over everything, and WP_CLI_MEMORY is honoured" "[ $rc -eq 0 ] && grep -q '(WP_CLI from the environment)' '$T/out' && grep -q 'memory_limit=768M $T/empty/override.phar' '$T/php.log'"

rm -f "$T/home/bin/wp"
rc=$(SYS="$T/sys/none" run)
check "no WP-CLI anywhere: exit 127 and a message that lists where it looked" "[ $rc -eq 127 ] && grep -q 'WP-CLI not found' '$T/out' && grep -q '/usr/local/bin/wp' '$T/out'"

echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
