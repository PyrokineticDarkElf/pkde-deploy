<?php

/**
 * Takes the WooCommerce cart, checkout and my-account pages out of Rank Math's sitemap (B-02). Those pages are noindex because Rank Math's
 * WooCommerce module says so in the page, by a filter, not in each page's own robots setting, so the sitemap would still list them. Idempotent: the IDs
 * are added to the pages already excluded, and Rank Math's cached sitemap files are dropped (Rank Math does not do that when this setting changes).
 * bin/setup runs it on a new site; an existing site runs it once, on staging and again on production. It writes one option and reads no secret.
 *
 *   bash bin/deploy/wp.sh eval-file bin/deploy/seo-sitemap-exclusions.php
 *
 * or locally: docker compose run --rm wpcli wp --allow-root eval-file vendor/pkde/deploy/bin/deploy/seo-sitemap-exclusions.php
 */
if (! function_exists('wc_get_page_id') || ! class_exists('\\RankMath\\Sitemap\\Cache')) {
    echo "WooCommerce or Rank Math is not active: nothing to exclude.\n";

    return;
}

$ids = array_values(array_filter(array_map('wc_get_page_id', ['cart', 'checkout', 'myaccount']), fn($id) => $id > 0));
$options = (array) get_option('rank-math-options-sitemap', []);
$before = wp_parse_id_list($options['exclude_posts'] ?? '');
$after = array_values(array_unique(array_merge($before, $ids)));

if ($after !== $before) {
    $options['exclude_posts'] = implode(', ', $after);
    update_option('rank-math-options-sitemap', $options);
}

\RankMath\Sitemap\Cache::invalidate_storage();
echo count($ids) . ' WooCommerce page(s) excluded from the sitemap (' . (count($after) - count($before)) . " added), sitemap cache dropped.\n";
