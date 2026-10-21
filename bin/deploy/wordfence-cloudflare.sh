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

# The PHP has no single quotes, so it can be wrapped in them for the remote shell.
php=$(cat <<'PHP'
if (! class_exists("wfConfig")) { fwrite(STDERR, "Wordfence is not active here.\n"); exit(1); }
$changed = false;
if (wfConfig::get("howGetIPs") !== "REMOTE_ADDR") {
    wfConfig::set("howGetIPs", "REMOTE_ADDR");
    wfConfig::set("detectProxyNextCheck", false, wfConfig::DONT_AUTOLOAD);
    $changed = true;
}
$now = wfConfig::get("howGetIPs");
echo ($changed ? "Updated" : "Already up to date"), ": howGetIPs=", $now, "\n";
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
    "cd '$path/current' && WP=\"${WP_CLI:-}\" && { [ -n \"\$WP\" ] || { [ -f \"\$HOME/bin/wp\" ] && WP=\"\$HOME/bin/wp\" || WP=/usr/local/bin/wp; }; } && php83 \"\$WP\" eval '$php'"
