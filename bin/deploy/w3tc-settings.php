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
 * bin/deploy/drift-check.php. `fix_environment` writes the .htaccess rules the page cache needs.
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
    $c->set('pgcache.reject.cookie', array_values(array_unique(array_merge((array) $c->get_array('pgcache.reject.cookie'), pkde_w3tc_woocommerce_cookies()))));
}
$c->save();
WP_CLI::success('W3 Total Cache settings saved; now run: wp w3-total-cache fix_environment');
