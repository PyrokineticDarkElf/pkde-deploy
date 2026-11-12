<?php

/**
 * Wordfence's own settings table read and written with plain SQL, for the deploy scripts that run `wp --skip-plugins --skip-themes eval-file ...` (WP-84, WP-87).
 * Why not wfConfig::get()/set(): they need Wordfence loaded, and loading every plugin for two option writes costs a full bootstrap (about 98 MB peak with the template's
 * plugins) on a host that kills heavy processes. wfConfig is a name/val/autoload table and nothing reads these keys from a cache (no persistent options cache), so the
 * write is what the plugin would do for these keys (howGetIPs, alertEmails and the dismiss option have no side effects in wfConfig::set). Not for apiKey, isPaid,
 * other_WFNet (those also write the firewall's own storage) or the cbl_* keys: use Wordfence for those.
 */

/** Is Wordfence an active plugin of this site (single site or network)? Reads the option only. */
function pkde_wordfence_active(): bool
{
    $active = (array) get_option('active_plugins', []);
    if (is_multisite()) {
        $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
    }

    return in_array('wordfence/wordfence.php', $active, true);
}

/** The installed Wordfence version, read from the plugin header ('' when the file is missing). */
function pkde_wordfence_version(): string
{
    $file = (defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins') . '/wordfence/wordfence.php';
    $head = is_readable($file) ? (string) file_get_contents($file, false, null, 0, 8192) : '';

    return preg_match('/^\s*\*?\s*Version:\s*([0-9][0-9A-Za-z.\-]*)/mi', $head, $m) === 1 ? $m[1] : '';
}

/** The wfConfig table's real name (its case depends on the database), or '' when Wordfence has not created it. */
function pkde_wfconfig_table(): string
{
    global $wpdb;

    $name = $wpdb->get_var($wpdb->prepare(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND LOWER(table_name) = %s LIMIT 1',
        strtolower($wpdb->base_prefix . 'wfconfig'),
    ));

    return is_string($name) ? $name : '';
}

/** One setting's value, or null when the row does not exist. */
function pkde_wfconfig_get(string $table, string $name): ?string
{
    global $wpdb;

    $value = $wpdb->get_var($wpdb->prepare("SELECT val FROM `$table` WHERE name = %s", $name));

    return is_string($value) ? $value : null;
}

/** Writes one setting, creating the row when needed ($autoload is Wordfence's 'yes' or 'no'). */
function pkde_wfconfig_set(string $table, string $name, string $value, string $autoload = 'yes'): void
{
    global $wpdb;

    $wpdb->query($wpdb->prepare(
        "INSERT INTO `$table` (name, val, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE val = %s, autoload = %s",
        $name,
        $value,
        $autoload,
        $value,
        $autoload,
    ));
}
