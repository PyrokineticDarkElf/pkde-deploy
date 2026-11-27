<?php

/**
 * Sets Rank Math to the template baseline (bin/deploy/rankmath-standard.php) and the site language to en_GB (or SITE_LOCALE). Idempotent; writes only the keys that
 * differ and prints them (names, never values). bin/setup runs it for a new site; an existing site that wants the baseline runs it once, after reading the drift rows:
 *
 *   bash bin/deploy/wp.sh eval-file bin/deploy/rankmath-baseline.php
 *
 * or locally: docker compose run --rm wpcli wp --allow-root eval-file vendor/pkde/deploy/bin/deploy/rankmath-baseline.php
 *
 * Part by part: `eval-file ... rankmath-baseline.php only=titles` applies one part of the baseline on its own, `only=titles,schema` several;
 * the parts are titles, schema and share-image (pkde_rankmath_only()). The owner's ruling is "Archives off" for every site, so every site runs `only=titles`
 * on staging after its next deploy and on production in the window. No argument: the whole baseline, as before. Idempotent; prints key names only.
 *
 * It overwrites a title template or a schema choice a site made on purpose, which is why an existing site reads the drift rows first. A new site's own values come
 * afterwards, in wp-admin.
 */
require_once __DIR__ . '/rankmath-standard.php';

if (! class_exists('\\RankMath\\Helper')) {
    echo "Rank Math is not active: nothing to set.\n";

    return;
}

$titles = (array) get_option('rank-math-options-titles', []);
$logoId = (int) get_theme_mod('custom_logo');
$logoUrl = $logoId ? (string) wp_get_attachment_image_url($logoId, 'full') : '';
$brand = ['name' => trim((string) get_option('blogname')), 'logo_url' => $logoUrl, 'logo_id' => $logoId];
$postTypes = array_values(array_filter(['page', 'post', 'product'], 'post_type_exists'));

$parsed = pkde_rankmath_only($args ?? []);
if ($parsed['unknown'] !== []) {
    echo 'Unknown part(s): ' . implode(', ', $parsed['unknown']) . '. only= takes ' . implode(', ', PKDE_RANKMATH_PARTS) . ". Nothing was changed.\n";
    exit(1);
}
$only = $parsed['only'];

$changes = pkde_rankmath_baseline($titles, $postTypes, $brand, $only);
if ($changes !== []) {
    update_option('rank-math-options-titles', array_merge($titles, $changes));
}
echo count($changes) . ' Rank Math setting(s) set' . ($changes ? ': ' . implode(', ', array_keys($changes)) : '') . ".\n";

if ($only !== []) {
    echo 'Only ' . implode(', ', $only) . " was applied (no language, logo or organization change).\n";

    return;
}

// The site language. WordPress only keeps a WPLANG value whose language pack is installed (update_option silently stores '' otherwise), so the pack
// is downloaded first. en_US is stored as an empty option.
$locale = pkde_rankmath_wanted_locale();
if ((string) get_option('WPLANG') !== $locale) {
    require_once ABSPATH . 'wp-admin/includes/translation-install.php';
    if ($locale !== 'en_US' && ! in_array($locale, get_available_languages(), true) && ! wp_download_language_pack($locale)) {
        echo "WARN: the $locale language pack could not be downloaded: the site language is unchanged.\n";
    } else {
        update_option('WPLANG', $locale === 'en_US' ? '' : $locale);
        echo "Site language set to $locale.\n";
    }
}

if ($logoUrl === '') {
    echo "No logo is set (Appearance > Customize > Site Identity): the organization logo and the default share image stay empty until it is.\n";
}
