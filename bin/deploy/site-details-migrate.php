<?php

/**
 * Moves a site's business details into Site details (WP-106, the platform's `site-details` module). Run it with WP-CLI's eval-file, in two steps:
 *
 *   plan   (read-only)  lists the old `client_*` options the site has (phone, email, address, the five social profiles; read straight from the database, not through the read shim) and
 *                       the Site details field each goes to, then what is already stored. A site that keeps its details somewhere else (cs: the App\Business constants) names a small
 *                       adapter file with adapter=PATH: a PHP file returning the details in Site details' own shape, which fills what the options do not.
 *
 *     docker compose run --rm wpcli wp --allow-root eval-file vendor/pkde/deploy/bin/deploy/site-details-migrate.php plan
 *
 *   apply  [adapter=PATH]  writes the values into Site details (the stored option `pkde_site_details`). A field that already has a stored value is KEPT, so it is idempotent and never overwrites
 *                       what was edited in wp-admin. Phones, emails and addresses go through the same checks as the wp-admin screen (a number it cannot read is named and left out).
 *                       The old `client_*` options are NOT deleted: the module's read shim makes them answer with the new values for one release, then they can be removed.
 *                       Fields the declaration (config/pkde-site-details.php) locks are not stored: the declaration owns them.
 *
 * Needs the platform `site-details` module. Prints field names and counts, and the values it moves (public business details), nothing else.
 */
require_once __DIR__ . '/site-details-migrate-lib.php';

$opts = ['mode' => 'plan'];
foreach ((array) ($args ?? []) as $arg) {
    if (str_contains((string) $arg, '=')) {
        [$k, $v] = explode('=', (string) $arg, 2);
        $opts[$k] = $v;
    } else {
        $opts['mode'] = (string) $arg;
    }
}

if (! in_array($opts['mode'], ['plan', 'apply'], true)) {
    echo "Unknown step \"{$opts['mode']}\". Use plan, or apply [adapter=PATH].\n";
    exit(1);
}

if (! function_exists('App\\MuPlugins\\site_details_sanitize')) {
    echo "The Site details module is not loaded: bump pkde/platform to the release with the site-details module (and check PKDE_PLATFORM_DISABLE). Nothing was changed.\n";
    exit(1);
}

global $wpdb;

// the real stored values, not what the read shim answers
$options = [];
foreach (array_keys(PKDE_SD_MAP) as $name) {
    $options[$name] = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
}

$adapter = [];
if (isset($opts['adapter'])) {
    $roots = array_values(array_filter([function_exists('App\\site_root') ? App\site_root() : '', defined('ABSPATH') ? dirname(rtrim(ABSPATH, '/'), 2) : '', (string) getcwd()]));
    $file = null;
    foreach (str_starts_with($opts['adapter'], '/') ? [$opts['adapter']] : array_map(fn($root) => rtrim($root, '/') . '/' . $opts['adapter'], $roots) as $candidate) {
        if (is_file($candidate)) {
            $file = $candidate;
            break;
        }
    }

    if ($file === null) {
        echo "The adapter file was not found ({$opts['adapter']}). Nothing was changed.\n";
        exit(1);
    }

    $adapter = (array) require $file;
}

$errors = [];
$in = pkde_sd_from_options($options, (string) get_option('blogname'), $adapter);
$migrated = App\MuPlugins\site_details_sanitize($in, App\MuPlugins\site_details_e164(), App\MuPlugins\site_details_region(), $errors);
$stored = (array) get_option(App\MuPlugins\SITE_DETAILS_OPTION, []);
$declaration = App\MuPlugins\site_details_declaration();
$filled = App\MuPlugins\site_details_strip_locked(pkde_sd_fill($stored, $migrated), $declaration['locked']);
$changed = $filled !== $stored;

echo ($opts['mode'] === 'plan' ? 'PLAN (nothing was changed)' : 'APPLY') . ".\n";
foreach (pkde_sd_plan_lines($options) as $line) {
    echo "  $line\n";
}
echo '  business name: ' . ((string) get_option('blogname') !== '' ? 'the site title "' . get_option('blogname') . '" becomes the trading name when none is stored' : 'no site title') . "\n";

if ($adapter !== []) {
    echo '  adapter: ' . implode(', ', array_keys($adapter)) . " from {$opts['adapter']}\n";
}

foreach ($errors as $error) {
    echo "  PROBLEM: $error\n";
}

echo '  declaration: ' . (is_file(dirname(rtrim(ABSPATH, '/'), 2) . '/config/pkde-site-details.php') ? 'config/pkde-site-details.php (' . count($declaration['locked']) . ' locked field(s), type ' . $declaration['type'] . ')' : 'none') . "\n";
echo '  stored already: ' . ($stored === [] ? 'nothing' : count(array_filter(['business' => implode('', (array) ($stored['business'] ?? [])), 'phones' => $stored['phones'] ?? [], 'emails' => $stored['emails'] ?? [], 'locations' => $stored['locations'] ?? [], 'socials' => $stored['socials'] ?? []])) . ' group(s) with values (they are kept)') . "\n";

if ($opts['mode'] === 'plan') {
    echo $changed ? "Would write Site details from the old options. Run apply to do it.\n" : "Nothing to change: Site details already holds everything the old options gave.\n";
    exit(0);
}

if (! $changed) {
    echo "Nothing to change: Site details already holds everything the old options gave.\n";
    exit(0);
}

update_option(App\MuPlugins\SITE_DETAILS_OPTION, $filled, false);
$d = App\MuPlugins\site_details();
echo sprintf("Site details written: %d phone(s), %d email(s), %d address(es), %d social profile(s); the old client_* options still answer, with these values (one release).\n", count($d['phones']), count($d['emails']), count($d['locations']), count($d['socials']));
