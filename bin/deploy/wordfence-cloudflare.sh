#!/usr/bin/env bash
#
# For a 20i site behind the Cloudflare proxy: makes Wordfence take the visitor IP from PHP's REMOTE_ADDR
# (Wordfence's "howGetIPs" setting), which is the right and the safe choice on 20i.
#
# Why REMOTE_ADDR and not Cloudflare's CF-Connecting-IP header. a staging pilot measured it:
#   * 20i itself rewrites REMOTE_ADDR to the real visitor address, taken from Cloudflare's header and only for
#     requests that come from Cloudflare. Behind the proxy, REMOTE_ADDR is the real visitor.
#   * REMOTE_ADDR cannot be forged: a direct POST to the origin with forged CF-Connecting-IP and X-Forwarded-For
#     headers was logged as the sender's true address.
#   * Reading the header instead (HTTP_CF_CONNECTING_IP) is forgeable by anyone who reaches the origin directly:
#     Wordfence consults its "trusted proxies" list only for a delimited chain of addresses, and that header holds
#     a single one, so the list never protects it. (An earlier version of this script chose the header and wrote
#     Cloudflare's ranges there; on a site that ran it the ranges are harmless and can stay or be cleared in
#     Wordfence > All Options > General > Trusted proxies.)
# A host that does NOT rewrite REMOTE_ADDR would show every visitor as a Cloudflare address; the two probes in the
# launch guide ("Behind Cloudflare") check which kind of host this is.
#
# It also dismisses the wp-admin notice "How does Wordfence get IPs is misconfigured", which recommends CF-Connecting-IP behind
# Cloudflare (owner 2026-11-12: Wordfence Diagnostics shows REMOTE_ADDR as the visitor, so REMOTE_ADDR stays). It sets the option
# Wordfence's own "No thanks" link sets, misconfiguredHowGetIPsChoice<version>. The name carries the plugin version, so after a
# Wordfence update the notice returns until this runs again; the template defines WORDFENCE_DISABLE_MISCONFIGURED_HOWGETIPS (Wordfence's
# own switch) in config/application.php to keep it away for good. The scan finding of the same name is not touched: Ignore it once
# in Wordfence > Scan.
#
# Usage:  bin/deploy/wordfence-cloudflare.sh <staging|production>
#
# Connects like the deploy workflow does, from the same names as its GitHub secrets exported in your shell
# (never stored in the repo): for production PROD_SSH_HOST, PROD_SSH_PORT, PROD_SSH_USER and PROD_DEPLOY_PATH;
# for staging the STAGING_* equivalents. WP-CLI is run as `php83 <wp>` on the host, with <wp> = WP_CLI from your
# shell if set, else ~/bin/wp if that exists, else /usr/local/bin/wp (a newer 20i package has no ~/bin).
# Idempotent: "Already up to date" on a re-run.

set -euo pipefail

case "${1:-}" in
    staging) prefix=STAGING ;;
    production) prefix=PROD ;;
    *) echo "Usage: $0 <staging|production>" >&2; exit 2 ;;
esac

# The PHP has no single quotes, so it can be wrapped in them for the remote shell. It runs with `--skip-plugins --skip-themes`: Wordfence is NOT loaded (a full
# plugin bootstrap for three option writes is what a 20i shell session may kill, WP-84), so wfConfig is written with plain SQL, the same rows wfConfig::set() writes
# for these keys (no side effects for howGetIPs or the dismiss option). bin/deploy/wfconfig-sql.php holds the same helpers for the release step.
php=$(cat <<'PHP'
global $wpdb;
if (! in_array("wordfence/wordfence.php", (array) get_option("active_plugins", []), true)) { fwrite(STDERR, "Wordfence is not active here.\n"); exit(1); }
$t = $wpdb->get_var($wpdb->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND LOWER(table_name) = %s LIMIT 1", strtolower($wpdb->base_prefix . "wfconfig")));
if (! is_string($t) || $t === "") { fwrite(STDERR, "Wordfence has no settings table here.\n"); exit(1); }
$get = function ($n) use ($wpdb, $t) { return $wpdb->get_var($wpdb->prepare("SELECT val FROM `$t` WHERE name = %s", $n)); };
$set = function ($n, $v, $a = "yes") use ($wpdb, $t) { $wpdb->query($wpdb->prepare("INSERT INTO `$t` (name, val, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE val = %s, autoload = %s", $n, $v, $a, $v, $a)); };
$changed = false;
if ($get("howGetIPs") !== "REMOTE_ADDR") {
    $set("howGetIPs", "REMOTE_ADDR");
    $set("detectProxyNextCheck", "", "no");
    $changed = true;
}
$head = (string) @file_get_contents((defined("WP_PLUGIN_DIR") ? WP_PLUGIN_DIR : WP_CONTENT_DIR . "/plugins") . "/wordfence/wordfence.php", false, null, 0, 8192);
$version = preg_match("/^\s*\*?\s*Version:\s*([0-9][0-9A-Za-z.\-]*)/mi", $head, $m) ? $m[1] : "";
if ($version !== "" && $get("misconfiguredHowGetIPsChoice" . $version) !== "1") {
    $set("misconfiguredHowGetIPsChoice" . $version, "1");
    $changed = true;
}
$now = $get("howGetIPs");
echo ($changed ? "Updated" : "Already up to date"), ": howGetIPs=", $now, ", notice dismissed for ", ($version !== "" ? $version : "(version unknown)"), "\n";
exit($now === "REMOTE_ADDR" ? 0 : 1);
PHP
)

need() {
    local name="${prefix}_$1"
    [ -n "${!name:-}" ] || { echo "Set $name in your shell first (same value as the GitHub secret)." >&2; exit 2; }
    printf '%s' "${!name}"
}
host=$(need SSH_HOST); port=$(need SSH_PORT); user=$(need SSH_USER); path=$(need DEPLOY_PATH)

# WP-CLI on the host: WP_CLI (set here, may use $HOME) wins; else ~/bin/wp; else /usr/local/bin/wp.
# Resolved on the host, hence the escaped $.
ssh -p "$port" "$user@$host" \
    "cd '$path/current' && WP=\"${WP_CLI:-}\" && { [ -n \"\$WP\" ] || { [ -f \"\$HOME/bin/wp\" ] && WP=\"\$HOME/bin/wp\" || WP=/usr/local/bin/wp; }; } && php83 -d memory_limit=512M \"\$WP\" --skip-plugins --skip-themes eval '$php'"
