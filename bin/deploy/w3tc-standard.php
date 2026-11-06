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

/**
 * Cookies that skip the page cache on a shop: by default a visitor with a basket is never served (or served to) a shared page. A site whose pages carry
 * nothing personal (kit 2026.11.06: the basket count is filled in by the browser, notices mark their page uncacheable; the audit in the kit's
 * docs/page-cache.md) opts in with the WordPress option `pkde_cache_basket_visitors` = 1, and then these cookies are NOT rejected (MGR-76).
 */
function pkde_w3tc_woocommerce_cookies(bool $cacheBasketVisitors = false): array
{
    return $cacheBasketVisitors ? [] : ['woocommerce_items_in_cart', 'wp_woocommerce_session_'];
}

/** The two cookies the opt-in lets through (the names the default list rejects). */
function pkde_w3tc_basket_cookies(): array
{
    return pkde_w3tc_woocommerce_cookies(false);
}

/** Whether this site opted in to serving cached pages to visitors with a basket (`wp option update pkde_cache_basket_visitors 1`). */
function pkde_w3tc_cache_basket_visitors(): bool
{
    return function_exists('get_option') && (string) get_option('pkde_cache_basket_visitors', '') === '1';
}

/**
 * The WP-Cron events W3TC needs for the standard to work: the hourly page-cache cleanup and the sitemap preloader. W3TC registers them itself ONLY
 * when its settings are saved in wp-admin (PgCache_Environment::fix_on_event); applying the standard by script writes the options and leaves the events
 * unscheduled (bb and tcs staging: cached pages 48 hours old against a 24 hour lifetime, a preloader that never ran). w3tc-settings.php schedules the
 * missing ones, the drift check reports them.
 *
 * @param  array<string,bool|int|string>  $standard  pkde_w3tc_standard()
 * @return list<string> the event (and schedule) names
 */
function pkde_w3tc_cron_events(array $standard): array
{
    $events = [];

    if (! empty($standard['pgcache.enabled']) && in_array($standard['pgcache.engine'] ?? '', ['file', 'file_generic'], true)) {
        $events[] = 'w3_pgcache_cleanup';
    }

    if (! empty($standard['pgcache.enabled']) && ! empty($standard['pgcache.prime.enabled'])) {
        $events[] = 'w3_pgcache_prime';
    }

    return $events;
}
