<?php

/**
 * Sets W3 Total Cache to the template standard (bin/deploy/w3tc-standard.php) in any environment. Idempotent; only the keys
 * listed there are written. Run it on the server in ONE SSH session (it must start with <?php), from the site's release directory:
 *
 *   bash bin/deploy/wp.sh eval-file bin/deploy/w3tc-settings.php
 *   bash bin/deploy/wp.sh w3-total-cache fix_environment
 *
 * or locally: docker compose run --rm wpcli wp --allow-root eval-file bin/deploy/w3tc-settings.php
 *
 * Page cache Disk: Enhanced with a 24 hour lifetime and hourly garbage collection; the preloader on the sitemap (10 pages every
 * 15 minutes, and a page is warmed again when it is edited); browser cache, minify, database cache and object cache OFF. The
 * WooCommerce cookies are added to the page-cache rejects only when WooCommerce is active. Then check it with
 * bin/deploy/drift-check.php. `fix_environment` writes the .htaccess rules the page cache needs. The cleanup and preload cron events W3TC
 * normally schedules on a wp-admin save are scheduled here too. WP-Cron needs visits to run: a staging site behind a gate gets none, so also run a
 * server-side cron (launch guide, "W3TC: WP_CACHE and the cron").
 */
require_once __DIR__ . '/w3tc-standard.php';

if (! class_exists('\W3TC\Dispatcher')) {
    WP_CLI::error('W3 Total Cache is not active.');
}

$c = \W3TC\Dispatcher::config();
foreach (pkde_w3tc_standard(home_url('/sitemap_index.xml')) as $key => $value) {
    $c->set($key, $value);
}
if (class_exists('WooCommerce')) {
    // By default the basket cookies are rejected. A site that opted in (pkde_cache_basket_visitors = 1, MGR-76) has them taken out of the list.
    $existing = (array) $c->get_array('pgcache.reject.cookie');
    $optIn = pkde_w3tc_cache_basket_visitors();
    $c->set('pgcache.reject.cookie', array_values($optIn ? array_diff($existing, pkde_w3tc_basket_cookies()) : array_unique(array_merge($existing, pkde_w3tc_woocommerce_cookies()))));
    WP_CLI::log($optIn ? 'Basket cookies are NOT rejected: this site serves cached pages to visitors with a basket (pkde_cache_basket_visitors).' : 'Basket cookies are rejected: visitors with a basket skip the page cache.');
}
$c->save();

// W3TC schedules its cleanup and preload events only when settings are saved in wp-admin, not through this script: schedule the missing ones here
// (idempotent). Their schedules ("w3_pgcache_cleanup", "w3_pgcache_prime") are registered by W3TC from the config just saved.
foreach (pkde_w3tc_cron_events(pkde_w3tc_standard(home_url('/sitemap_index.xml'))) as $event) {
    if (wp_next_scheduled($event)) {
        WP_CLI::log("Cron event $event already scheduled.");
    } elseif (wp_schedule_event(time(), $event, $event) === false) {
        WP_CLI::warning("Could not schedule the cron event $event (is its schedule registered? is W3TC's page cache enabled?).");
    } else {
        WP_CLI::log("Scheduled the cron event $event.");
    }
}
WP_CLI::success('W3 Total Cache settings saved and its cron events scheduled; now run: wp w3-total-cache fix_environment');
