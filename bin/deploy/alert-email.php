<?php

/**
 * The address Wordfence alerts go to (WP-87). Two optional .env variables define it: WORDFENCE_ALERT_EMAIL, else WP_ADMIN_EMAIL. They are usually the same
 * address but may differ. A value that is not an email address counts as unset. Shared by wordfence-standard.php (writes it on every release) and
 * drift-check.php (checks it); pure, no WordPress calls.
 */

/**
 * A setting by name: the constant when config/application.php defined it, else the .env value (Bedrock's env(), then getenv()), '' when it is set nowhere.
 * So a site that has the variable in its .env but not the template's Config::define lines is still checked, not silently skipped.
 */
function pkde_env_setting(string $name): string
{
    if (defined($name)) {
        return (string) constant($name);
    }
    $value = function_exists('env') ? env($name) : getenv($name);

    return is_string($value) ? $value : '';
}

/** The effective alert address, or '' when neither variable holds a valid one. */
function pkde_alert_address(string $alert, string $admin): string
{
    foreach ([$alert, $admin] as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
            return $candidate;
        }
    }

    return '';
}

/** Is Wordfence's alertEmails (comma separated) exactly this one address? Case does not matter. */
function pkde_alert_emails_exact(string $alertEmails, string $address): bool
{
    $list = array_values(array_filter(array_map('trim', explode(',', strtolower($alertEmails))), fn($e) => $e !== ''));

    return $address !== '' && $list === [strtolower($address)];
}
