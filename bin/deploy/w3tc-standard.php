<?php

/**
 * The template's W3 Total Cache standard, in one place: bin/deploy/w3tc-settings.php writes it and bin/deploy/drift-check.php
 * checks it. Pure functions, no WordPress needed (the Pest tests load this file).
 */

/**
 * W3TC setting => standard value.
 *
 * - Page cache: Disk: Enhanced (cached pages are served by .htaccess rules, without PHP), pages last 24 hours (W3TC purges a page
 *   when it is edited), expired files cleared hourly.
 * - Preloader: every 15 minutes WP-Cron warms the next 10 pages of the sitemap, and an edited page is warmed again straight away.
 *   The deploy also warms the first pages right after a release goes live (bin/deploy/activate-release.sh, CACHE_WARM_LIMIT).
 * - OFF: browser cache (it rewrites .htaccess; the CDN and the theme's Asset Cache block cover static files), minify (Vite builds
 *   the assets, and W3TC's minify broke a page's scripts on bp: "wp is not defined"), database cache and object cache (no shared
 *   memory cache on the host; queries are not the bottleneck).
 *
 * @return array<string,bool|int|string>
 */
function pkde_w3tc_standard(string $sitemap): array
{
    return [
        'pgcache.enabled' => true,
        'pgcache.engine' => 'file_generic',
        'pgcache.lifetime' => 86400,
        'pgcache.file.gc' => 3600,
        'pgcache.prime.enabled' => true,
        'pgcache.prime.sitemap' => $sitemap,
        'pgcache.prime.interval' => 900,
        'pgcache.prime.limit' => 10,
        'pgcache.prime.post.enabled' => true,
        'browsercache.enabled' => false,
        'minify.enabled' => false,
        'dbcache.enabled' => false,
        'objectcache.enabled' => false,
    ];
}

/** Cookies that must skip the page cache on a shop: a visitor with a basket must never be served (or served to) a shared page. */
function pkde_w3tc_woocommerce_cookies(): array
{
    return ['woocommerce_items_in_cart', 'wp_woocommerce_session_'];
}
