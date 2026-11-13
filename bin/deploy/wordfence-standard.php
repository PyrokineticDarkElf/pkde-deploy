<?php

/**
 * Release step (WP-87, WP-88): two Wordfence settings every release re-asserts: loginSec_disableAuthorScan on (no username discovery through ?author=N or the REST
 * users list), and the alert recipients are EXACTLY the effective alert address from .env, re-asserted on every release by activate-release.sh
 * (`wp --skip-plugins --skip-themes eval-file bin/deploy/wordfence-standard.php`). The address is WORDFENCE_ALERT_EMAIL, else WP_ADMIN_EMAIL (alert-email.php). Wordfence
 * keeps alertEmails in its own table, so a constant cannot supply it; it is written with plain SQL (wfconfig-sql.php) so no plugin is loaded for it. A site that sets
 * neither variable is left alone, and so is one where Wordfence is not active. The address is never printed. Idempotent.
 */

require_once __DIR__ . '/alert-email.php';
require_once __DIR__ . '/wfconfig-sql.php';

$table = pkde_wordfence_active() ? pkde_wfconfig_table() : '';

if ($table === '') {
    echo "Wordfence settings: Wordfence is not active here; skipped.\n";

    return;
}

// "Prevent discovery of usernames" (?author=N and the REST users list for anonymous visitors) is part of the standard (WP-88).
if (pkde_wfconfig_get($table, 'loginSec_disableAuthorScan') === '1') {
    echo "Wordfence loginSec_disableAuthorScan: already on.\n";
} else {
    pkde_wfconfig_set($table, 'loginSec_disableAuthorScan', '1');
    echo "Wordfence loginSec_disableAuthorScan: set to on.\n";
}

$address = pkde_alert_address(
    pkde_env_setting('WORDFENCE_ALERT_EMAIL'),
    pkde_env_setting('WP_ADMIN_EMAIL'),
);

if ($address === '') {
    echo "Wordfence alertEmails: neither WORDFENCE_ALERT_EMAIL nor WP_ADMIN_EMAIL is set to an address in .env; left alone.\n";

    return;
}

if (pkde_alert_emails_exact((string) pkde_wfconfig_get($table, 'alertEmails'), $address)) {
    echo "Wordfence alertEmails: already exactly the alert address from .env.\n";

    return;
}

pkde_wfconfig_set($table, 'alertEmails', $address);
echo "Wordfence alertEmails: set to exactly the alert address from .env.\n";
