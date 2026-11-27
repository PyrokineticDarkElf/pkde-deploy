<?php

/**
 * Relevanssi settings for the shop search, in any environment (from tcs). Lived in pkde-sage-kit/bin until 2026.11.19.10; operator scripts belong here. Idempotent; rebuilds the index at the end.
 *
 * - Products only (the search is a product search), with the short description, the SKU and every product taxonomy that holds words a
 *   customer types: categories, tags, brands and all attribute taxonomies (colour, material ...).
 * - AND between words ("grey wall clock" must match all three), falling back to OR when nothing matches; partial words always match ("cuck"
 *   finds cuckoo). Titles weigh five times the body.
 * - No excerpts or highlighting: results are the shop's own product cards. Searches are logged without IP addresses, so
 *   Dashboard > User searches shows what customers look for and which searches find nothing.
 * - Hidden products and products marked noindex stay out (Relevanssi's WooCommerce and SEO support).
 *
 * Run once per environment, after a deploy:  bash bin/deploy/wp.sh eval-file bin/deploy/relevanssi-settings.php   (locally: wp eval-file vendor/pkde/deploy/bin/deploy/relevanssi-settings.php)
 * The free editions of Relevanssi and Relevanssi Live Ajax Search are enough (tcs and bb both use them: relevanssi ^4.28 and
 * relevanssi-live-ajax-search ^2.6); Relevanssi Premium works the same.
 */
if (! function_exists('relevanssi_build_index')) {
    WP_CLI::error('Relevanssi is not active.');
}

$taxonomies = array_values(array_filter(
    array_merge(['product_cat', 'product_tag', 'product_brand'], function_exists('wc_get_attribute_taxonomy_names') ? wc_get_attribute_taxonomy_names() : []),
    'taxonomy_exists',
));

$settings = [
    'relevanssi_index_post_types' => ['product'],
    'relevanssi_index_taxonomies_list' => $taxonomies,
    'relevanssi_index_fields' => '_sku',
    'relevanssi_index_excerpt' => 'on',
    'relevanssi_index_author' => '',
    'relevanssi_index_comments' => 'none',
    'relevanssi_implicit_operator' => 'AND',
    'relevanssi_disable_or_fallback' => 'off',
    'relevanssi_fuzzy' => 'always',
    'relevanssi_title_boost' => 5,
    'relevanssi_excerpts' => 'off',
    'relevanssi_highlight' => 'no',
    'relevanssi_log_queries' => 'on',
    'relevanssi_log_queries_with_ip' => 'off',
    'relevanssi_respect_exclude' => 'on',
    'relevanssi_seo_noindex' => 'on',
];
foreach ($settings as $name => $value) {
    update_option($name, $value);
}

relevanssi_build_index(false, false);
WP_CLI::success(sprintf('Relevanssi settings saved and the index rebuilt (taxonomies: %s).', implode(', ', $taxonomies)));
