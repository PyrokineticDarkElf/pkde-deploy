#!/usr/bin/env bash
#
# Wordfence "Extended Protection" for a Bedrock release layout (WP-85): the firewall runs BEFORE WordPress through PHP's auto_prepend_file, set in the docroot's
# .user.ini (PHP-FPM / CGI on 20i). Wordfence's own installer writes the prepend file and .user.ini into WordPress' directory, inside the current RELEASE, with
# the release's real path: the next release prunes that directory, the path dangles, and PHP fails on every request ("Failed opening required"). So this
# script, run by activate-release.sh on every release, keeps the two pieces at paths that never change:
#
#   <shared>/waf/wordfence-waf.php   a small guarded shim: if <current>/web/app/plugins/wordfence/waf/bootstrap.php exists it defines WFWAF_LOG_PATH
#                                    (the .env value, else <shared>/wflogs/) and includes the bootstrap; if the plugin is gone it does nothing (never fatal)
#   <release>/web/.user.ini          "; Wordfence WAF / auto_prepend_file = '<shared>/waf/wordfence-waf.php' / ; END Wordfence WAF", written into the new
#                                    release (the markers are Wordfence's own, so the plugin recognises, updates and removes the block)
#
# Opt-in per site: WORDFENCE_EXTENDED_PROTECTION=1 in <shared>/.env (anything else leaves things alone; it removes a block this script wrote when the flag is
# turned off). It touches nothing outside <shared>/waf and <release>/web/.user.ini, prints no value from .env and exits 0 unless it cannot write.
#
# Usage: waf-prepend.sh <release_dir> <shared_dir> <current_link>
set -uo pipefail

RELEASE_DIR="${1:-}"; SHARED_DIR="${2:-}"; CURRENT_LINK="${3:-}"
[ -d "$RELEASE_DIR" ] && [ -d "$SHARED_DIR" ] && [ -n "$CURRENT_LINK" ] || { echo "usage: waf-prepend.sh <release_dir> <shared_dir> <current_link>" >&2; exit 2; }
for p in "$RELEASE_DIR" "$SHARED_DIR" "$CURRENT_LINK"; do
    case "$p" in *"'"*|*$'\n'*) echo "waf-prepend: refusing a path with a quote or a newline" >&2; exit 2 ;; esac
done

# one value from <shared>/.env (the last assignment, quotes and carriage returns removed; nothing is echoed by this function)
env_value() { sed -n "s/^$1=//p" "$SHARED_DIR/.env" 2>/dev/null | tail -1 | tr -d "\"'\r"; }

FLAG="$(env_value WORDFENCE_EXTENDED_PROTECTION)"
INI="$RELEASE_DIR/web/.user.ini"
BEGIN='; Wordfence WAF'
END='; END Wordfence WAF'

remove_block() { # <file>: drops the marked block (and a lone blank line it leaves), keeps everything else
    awk -v b="$BEGIN" -v e="$END" '$0==b{skip=1;next} skip&&$0==e{skip=0;next} !skip{print}' "$1"
}

case "$FLAG" in
    1|true|TRUE|yes|on) ;;
    *)
        if [ -f "$INI" ] && grep -qxF "$BEGIN" "$INI" && grep -q "$SHARED_DIR/waf/wordfence-waf.php" "$INI"; then
            tmp="$(mktemp)" && remove_block "$INI" > "$tmp" && cat "$tmp" > "$INI" && rm -f "$tmp"
            [ -s "$INI" ] || rm -f "$INI"
            echo "Wordfence extended protection: off in .env; removed the block this script wrote from web/.user.ini"
        else
            echo "Wordfence extended protection: not enabled (set WORDFENCE_EXTENDED_PROTECTION=1 in .env to opt in)"
        fi
        exit 0
        ;;
esac

BOOTSTRAP_REL="web/app/plugins/wordfence/waf/bootstrap.php"
if [ ! -f "$RELEASE_DIR/$BOOTSTRAP_REL" ]; then
    echo "WARN: Wordfence extended protection is enabled but this release has no Wordfence plugin; nothing written" >&2
    exit 0
fi

LOG_PATH="$(env_value WFWAF_LOG_PATH)"
LOG_PATH="${LOG_PATH:-$SHARED_DIR/wflogs}"
LOG_PATH="${LOG_PATH%/}/"
case "$LOG_PATH" in *"'"*) echo "waf-prepend: refusing a WFWAF_LOG_PATH with a quote" >&2; exit 2 ;; esac

mkdir -p "$SHARED_DIR/waf" || { echo "waf-prepend: cannot create $SHARED_DIR/waf" >&2; exit 1; }
SHIM="$SHARED_DIR/waf/wordfence-waf.php"
cat > "$SHIM.new" <<PHP
<?php
// Written by pkde-deploy (bin/deploy/waf-prepend.sh) on every release; this path never changes. Wordfence Extended Protection: the firewall runs before WordPress.
// Guarded: if the plugin is not there (removed, or a release is being switched) nothing happens and the request goes on.
\$pkdeWafBootstrap = '$CURRENT_LINK/$BOOTSTRAP_REL';
if (file_exists(\$pkdeWafBootstrap)) {
	if (! defined('WFWAF_LOG_PATH')) {
		define('WFWAF_LOG_PATH', '$LOG_PATH');
	}
	include_once \$pkdeWafBootstrap;
}
PHP
chmod 644 "$SHIM.new" && mv "$SHIM.new" "$SHIM" || { echo "waf-prepend: cannot write $SHIM" >&2; exit 1; }

mkdir -p "$RELEASE_DIR/web"
block="$BEGIN
auto_prepend_file = '$SHIM'
$END"
tmp="$(mktemp)" || exit 1
if [ -f "$INI" ]; then
    # comment out any other auto_prepend_file (Wordfence does the same), drop an older block of ours, add the current one
    remove_block "$INI" | sed 's/^auto_prepend_file/;auto_prepend_file/' > "$tmp"
else
    : > "$tmp"
fi
[ -s "$tmp" ] && printf '\n' >> "$tmp"
printf '%s\n' "$block" >> "$tmp"
cat "$tmp" > "$INI" && chmod 644 "$INI" && rm -f "$tmp" || { echo "waf-prepend: cannot write $INI" >&2; exit 1; }

echo "Wordfence extended protection: shim at shared/waf/wordfence-waf.php, web/.user.ini points at it (takes effect within user_ini.cache_ttl, 300 s by default)"
exit 0
