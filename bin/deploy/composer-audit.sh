#!/usr/bin/env bash
#
# Composer security audit of one project's composer.lock, for the deploy workflow (root and theme). Fails (exit 1) on any advisory
# rated high or critical, and on an advisory with NO rating (Symfony's and many others are published unrated, and "unknown" must
# not read as "fine"). Low and medium advisories are listed and let through. If the audit itself cannot run (no network, bad
# output) it fails as well: a green audit has to mean it ran. Dev packages are not audited: they are never deployed.
#
# Usage: composer-audit.sh [DIR]   (DIR defaults to the current directory)
# To accept an advisory deliberately, use Composer's own `config.audit.ignore` in that composer.json, with the reason.

set -uo pipefail
cd "${1:-.}" || { echo "::error::composer-audit: no such directory: ${1:-.}"; exit 2; }

out="$(composer audit --locked --no-dev --format=json --no-interaction 2>/dev/null)"
if ! printf '%s' "$out" | php -r 'exit(is_array(json_decode(stream_get_contents(STDIN), true)) ? 0 : 1);'; then
    echo "::error::composer audit did not produce a report in $PWD, so the dependencies were not audited"
    exit 1
fi

printf '%s' "$out" | php -r '
$report = json_decode(stream_get_contents(STDIN), true);
$blocking = 0;
foreach (($report["advisories"] ?? []) as $package => $list) {
    foreach ($list as $a) {
        $sev = strtolower((string) ($a["severity"] ?? ""));
        $stop = in_array($sev, ["high", "critical", ""], true);
        $blocking += $stop ? 1 : 0;
        printf("%s%s: %s %s (%s) %s\n", $stop ? "::error::" : "", $package, $a["cve"] ?? $a["advisoryId"] ?? "?", $a["title"] ?? "", $sev !== "" ? $sev : "unrated", $a["link"] ?? "");
    }
}
if ($blocking) { fwrite(STDERR, "$blocking blocking advisory(ies): update the package, or ignore it in composer.json config.audit.ignore with the reason.\n"); exit(1); }
echo "composer audit: no high, critical or unrated advisories in ", getcwd(), "\n";
'
