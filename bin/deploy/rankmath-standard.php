<?php

/**
 * The template's Rank Math baseline (T-2/T-3, B-05, B-08, B-11), in one place: bin/deploy/rankmath-baseline.php writes it (bin/setup runs it for a new
 * site) and bin/deploy/drift-check.php checks it. Pure functions, no WordPress needed (the Pest tests load this file). Every value stays editable in
 * wp-admin (Rank Math > Titles & Meta): the baseline is where a site STARTS, and the drift check tells an existing site what differs.
 */

/** The site language the baseline expects (WordPress's own setting; a site outside the UK sets SITE_LOCALE in its .env). */
const PKDE_RANKMATH_LOCALE = 'en_GB';

/** The language the site should have: SITE_LOCALE from the .env when it is a locale code, else en_GB. */
function pkde_rankmath_wanted_locale(): string
{
    $value = function_exists('Pkde\\Platform\\setting') ? trim(\Pkde\Platform\setting('SITE_LOCALE')) : '';

    return preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $value) === 1 ? $value : PKDE_RANKMATH_LOCALE;
}

/**
 * A title template without the word "Archives" and with the site name once. Rank Math's defaults print "Category Archives - Site" for a term and some
 * templates repeat %sitename%.
 */
function pkde_rankmath_clean_title(string $template): string
{
    $clean = preg_replace('/\s*\bArchives\b/', '', $template) ?? $template;
    $first = strpos($clean, '%sitename%');

    if ($first !== false) {
        $head = substr($clean, 0, $first + strlen('%sitename%'));
        $tail = preg_replace('/(?:\s*%sep%)?\s*%sitename%/', '', substr($clean, strlen($head))) ?? '';
        $clean = $head . $tail;
    }

    return trim(preg_replace('/\s{2,}/', ' ', $clean) ?? $clean);
}

/**
 * The titles options the baseline sets, from what the site has.
 *
 * @param  array<string, mixed>  $titles  the current rank-math-options-titles
 * @param  list<string>  $postTypes  public post types that have a pt_<type>_* group
 * @param  array{name: string, logo_url: string, logo_id: int}  $brand
 * @param  list<string>  $only  parts (PKDE_RANKMATH_PARTS) to apply on their own; empty = the whole baseline
 * @return array<string, mixed> only the keys whose value differs from the current one
 */
/**
 * The parts of the baseline a site may apply on its own: titles (the title templates, without "Archives" and with the site name once),
 * schema (the default rich snippet of pages, posts and products) and share-image (the default share image). Everything else (company, name, logo, archive
 * descriptions) is applied only with no part named.
 */
const PKDE_RANKMATH_PARTS = ['titles', 'schema', 'share-image'];

/**
 * Which part of the baseline a Rank Math title-options key belongs to, or null when it belongs to none. Pure.
 */
function pkde_rankmath_part_of(string $key): ?string
{
    if (preg_match('/_title$/', $key) === 1) {
        return 'titles';
    }
    if (preg_match('/_default_rich_snippet$|_default_article_type$/', $key) === 1) {
        return 'schema';
    }
    if (in_array($key, ['open_graph_image', 'open_graph_image_id'], true)) {
        return 'share-image';
    }

    return null;
}

/**
 * The `only=a,b` argument of rankmath-baseline.php (WP-CLI passes eval-file arguments as a list). Returns the parts asked for and the names that are not parts.
 * No argument: no parts, which means the whole baseline.
 *
 * @param  list<string>  $args
 * @return array{only: list<string>, unknown: list<string>}
 */
function pkde_rankmath_only(array $args): array
{
    $only = [];
    $unknown = [];

    foreach ($args as $arg) {
        if (str_starts_with((string) $arg, 'only=')) {
            foreach (array_filter(array_map('trim', explode(',', substr((string) $arg, 5)))) as $part) {
                in_array($part, PKDE_RANKMATH_PARTS, true) ? $only[] = $part : $unknown[] = $part;
            }
        }
    }

    return ['only' => array_values(array_unique($only)), 'unknown' => array_values(array_unique($unknown))];
}

function pkde_rankmath_baseline(array $titles, array $postTypes, array $brand, array $only = []): array
{
    $want = ['knowledgegraph_type' => 'company'];

    if ($brand['name'] !== '') {
        $want['knowledgegraph_name'] = $brand['name'];
        $want['website_name'] = $brand['name'];
    }

    if ($brand['logo_url'] !== '' && empty($titles['knowledgegraph_logo'])) {
        $want['knowledgegraph_logo'] = $brand['logo_url'];
        $want['knowledgegraph_logo_id'] = $brand['logo_id'];
    }

    // The default share image is the brand's logo until a site chooses a real 1200 x 630 picture.
    if ($brand['logo_url'] !== '' && empty($titles['open_graph_image'])) {
        $want['open_graph_image'] = $brand['logo_url'];
        $want['open_graph_image_id'] = $brand['logo_id'];
    }

    // Pages are a WebPage (no extra Article), posts a BlogPosting, products a Product.
    if (in_array('page', $postTypes, true)) {
        $want['pt_page_default_rich_snippet'] = 'off';
    }
    if (in_array('post', $postTypes, true)) {
        $want['pt_post_default_rich_snippet'] = 'article';
        $want['pt_post_default_article_type'] = 'BlogPosting';
    }
    if (in_array('product', $postTypes, true)) {
        $want['pt_product_default_rich_snippet'] = 'product';
    }

    foreach ($titles as $key => $value) {
        if (is_string($value) && preg_match('/^(?:pt_|tax_)[a-z0-9_]+_title$|^(?:homepage|author_archive|date_archive|search|404)_title$/', (string) $key)) {
            $want[$key] = pkde_rankmath_clean_title($value);
        } elseif (preg_match('/^tax_[a-z0-9_]+_description$/', (string) $key)) {
            $want[$key] = '%term_description%';
        }
    }

    return array_filter($want, fn($value, $key) => ($titles[$key] ?? null) !== $value && ($only === [] || in_array(pkde_rankmath_part_of((string) $key), $only, true)), ARRAY_FILTER_USE_BOTH);
}

/**
 * Checks of the baseline: [check name, ok, what is wrong]. The same function that decides what bin/setup writes decides what is drift.
 *
 * @param  array<string, mixed>  $titles  rank-math-options-titles
 * @param  list<string>  $postTypes
 * @return list<array{0: string, 1: bool, 2: string}>
 */
function pkde_rankmath_findings(array $titles, array $postTypes, string $locale, string $wantLocale = PKDE_RANKMATH_LOCALE): array
{
    $plan = pkde_rankmath_baseline($titles, $postTypes, ['name' => '', 'logo_url' => '', 'logo_id' => 0]);
    $named = fn(string $pattern) => array_keys(array_filter($plan, fn($v, $k) => preg_match($pattern, (string) $k) === 1, ARRAY_FILTER_USE_BOTH));
    $schema = array_intersect_key($plan, array_flip(['pt_page_default_rich_snippet', 'pt_post_default_rich_snippet', 'pt_post_default_article_type', 'pt_product_default_rich_snippet']));
    $badTitles = $named('/_title$/');
    $badDescriptions = $named('/^tax_.*_description$/');

    return [
        ['Rank Math: the organization is a company, not a person', ($titles['knowledgegraph_type'] ?? '') === 'company', 'Titles & Meta > Local SEO says "' . ($titles['knowledgegraph_type'] ?? '') . '"'],
        ['Rank Math: the organization name and logo are set', trim((string) ($titles['knowledgegraph_name'] ?? '')) !== '' && trim((string) ($titles['knowledgegraph_logo'] ?? '')) !== '', 'name or logo is empty (Titles & Meta > Local SEO)'],
        ['Rank Math: pages are a WebPage, posts a BlogPosting, products a Product', $schema === [], 'differs: ' . implode(', ', array_keys($schema))],
        ['Rank Math: title templates have no "Archives" and the site name once', $badTitles === [], 'fix: ' . implode(', ', $badTitles)],
        ['Rank Math: archive descriptions come from the term description', $badDescriptions === [], 'not %term_description%: ' . implode(', ', $badDescriptions)],
        ['Rank Math: a default share image (og:image) is set', trim((string) ($titles['open_graph_image'] ?? '')) !== '', 'Titles & Meta > Global Meta > OpenGraph Thumbnail is empty'],
        ['Site language is ' . $wantLocale, $locale === $wantLocale, 'the locale is ' . $locale . ' (Settings > General > Site Language; the deploy installs the language pack)'],
    ];
}
