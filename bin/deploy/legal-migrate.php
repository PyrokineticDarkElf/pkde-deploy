<?php

/**
 * Moves a site's legal Pages (privacy, cookies, terms, returns ...) into the legal documents post type, `pkde_legal` (WP-104). Run it with WP-CLI's eval-file, in two steps:
 *
 *   plan   (read-only)  lists the Pages that look legal (by slug or title, plus the ones chosen as the core Privacy Policy page and the WooCommerce Terms page) with ID, title, slug,
 *                       address, status and a proposed type. The site's agent confirms the list in its report and the owner approves it as a file.
 *
 *     docker compose run --rm wpcli wp --allow-root eval-file vendor/pkde/deploy/bin/deploy/legal-migrate.php plan
 *
 *   apply list=config/pkde-legal-documents.php
 *                       converts ONLY the listed Pages: post_type becomes pkde_legal, keeping the ID, the content, the slug, the status, the revisions and the menu items (a menu item
 *                       of a converted Page now says it is a document); sets the document type and the effective date (the file's, else the day the Page was last changed);
 *                       flushes the rewrite rules (the normal flush, never a hard one). It refuses to run without the file, changes nothing when the file has a problem, and a second
 *                       run changes nothing. It then checks that each address still answers 200 with the same title (when the site can be reached from here), and that the core
 *                       privacy address and the WooCommerce terms address are unchanged. It prints counts, slugs and addresses only.
 *
 * The file (see config/pkde-legal-documents.php.example in the template):
 *   return ['documents' => [['id' => 12, 'type' => 'privacy', 'effective' => '2026-11-01'], ['id' => 15, 'type' => 'terms']]];
 *
 * Needs the platform `legal` module (the post type).
 */
require_once __DIR__ . '/legal-migrate-lib.php';

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
    echo "Unknown step \"{$opts['mode']}\". Use plan, or apply list=PATH.\n";
    exit(1);
}

if (! post_type_exists('pkde_legal')) {
    echo "The legal documents post type is not registered: bump pkde/platform to the release with the legal module (and check PKDE_PLATFORM_DISABLE).\n";
    exit(1);
}

global $wpdb;

$pageRows = function () use ($wpdb): array {
    return array_map(fn($p) => [
        'id' => (int) $p->ID, 'title' => (string) $p->post_title, 'slug' => (string) $p->post_name, 'url' => (string) get_permalink($p), 'status' => (string) $p->post_status,
        'parent' => (int) $p->post_parent, 'modified' => substr((string) $p->post_modified, 0, 10),
    ], get_posts(['post_type' => 'page', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true]));
};
$privacyId = (int) get_option('wp_page_for_privacy_policy');
$termsId = (int) get_option('woocommerce_terms_page_id');

if ($opts['mode'] === 'plan') {
    $rows = pkde_legal_candidates($pageRows(), $privacyId, $termsId);
    echo "PLAN (nothing was changed).\n";

    foreach ($rows as $row) {
        echo "  #{$row['id']}  {$row['slug']}  [{$row['status']}]  {$row['url']}\n      \"{$row['title']}\"  proposed type: {$row['type']}  ({$row['why']})" . ($row['problem'] !== '' ? "  PROBLEM: {$row['problem']}" : '') . "\n";
    }

    $done = get_posts(['post_type' => 'pkde_legal', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true]);
    echo count($rows) . ' page(s) look legal; ' . count($done) . " already a legal document.\n";
    echo "Core Privacy Policy page: " . ($privacyId ?: 'not set') . '; WooCommerce Terms page: ' . ($termsId ?: 'not set') . ".\n";
    echo "Next: the site's agent confirms this list, the owner approves it, then write config/pkde-legal-documents.php and run apply list=...\n";
    exit(0);
}

// ------------------------------------------------------------------ apply
$listPath = (string) ($opts['list'] ?? '');

if ($listPath === '') {
    echo "apply needs the approved list: apply list=config/pkde-legal-documents.php. Nothing was changed.\n";
    exit(1);
}

$roots = array_values(array_filter([function_exists('App\\site_root') ? App\site_root() : '', defined('ABSPATH') ? dirname(rtrim(ABSPATH, '/'), 2) : '', (string) getcwd()]));
$file = null;

foreach (str_starts_with($listPath, '/') ? [$listPath] : array_map(fn($root) => rtrim($root, '/') . '/' . $listPath, $roots) as $candidate) {
    if (is_file($candidate)) {
        $file = $candidate;
        break;
    }
}

if ($file === null) {
    echo "The list file was not found ($listPath). Nothing was changed.\n";
    exit(1);
}

$list = require $file;
$find = function (int $id): ?array {
    $post = get_post($id);

    return $post ? ['type' => (string) $post->post_type, 'parent' => (int) $post->post_parent] : null;
};
$problems = pkde_legal_list_problems($list, $find);

if ($problems !== []) {
    echo "The list has problems; nothing was changed:\n";
    foreach ($problems as $problem) {
        echo "  - $problem\n";
    }
    exit(1);
}

$fetchTitle = function (string $url): ?string {
    $response = wp_remote_get(add_query_arg('pkde-legal-check', (string) time(), $url), ['timeout' => 20, 'redirection' => 0, 'sslverify' => false]);

    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }

    return pkde_legal_html_title((string) wp_remote_retrieve_body($response));
};

$before = [];
foreach ($list['documents'] as $row) {
    $before[(int) $row['id']] = ['url' => (string) get_permalink((int) $row['id']), 'title' => null];
    $before[(int) $row['id']]['title'] = $fetchTitle($before[(int) $row['id']]['url']);
}
$privacyBefore = (string) get_privacy_policy_url();
$termsBefore = $termsId ? (string) get_permalink($termsId) : '';

$converted = [];
$already = [];

foreach ($list['documents'] as $row) {
    $id = (int) $row['id'];
    $post = get_post($id);
    $type = (string) $row['type'];
    $effective = (string) ($row['effective'] ?? '') ?: substr((string) $post->post_modified, 0, 10);

    if ($post->post_type === 'pkde_legal') {
        $already[] = $post->post_name;

        continue;
    }

    // the same row, a new post type: the ID, content, slug, status and revisions stay (revisions point at the ID, not the type)
    $wpdb->update($wpdb->posts, ['post_type' => 'pkde_legal', 'post_parent' => 0], ['ID' => $id]);
    clean_post_cache($id);
    update_post_meta($id, '_pkde_legal_type', $type);
    update_post_meta($id, '_pkde_legal_effective', $effective);
    delete_post_meta($id, '_wp_page_template');

    // menu items of the Page now point at a document
    $items = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_menu_item_object_id' AND meta_value = %s", (string) $id));
    foreach ($items as $itemId) {
        if (get_post_meta((int) $itemId, '_menu_item_type', true) === 'post_type' && get_post_meta((int) $itemId, '_menu_item_object', true) === 'page') {
            update_post_meta((int) $itemId, '_menu_item_object', 'pkde_legal');
        }
    }

    $converted[] = $post->post_name;
}

flush_rewrite_rules(false); // the normal flush, never --hard

echo 'Converted ' . count($converted) . ' page(s) to legal documents' . ($converted ? ': ' . implode(', ', $converted) : '') . ".\n";
echo count($already) . ' already a document' . ($already ? ' (' . implode(', ', $already) . ')' : '') . ".\n";

// the checks
$bad = 0;
$unreachable = 0;

foreach ($before as $id => $info) {
    $url = (string) get_permalink($id);
    $title = $fetchTitle($url);
    $slug = (string) get_post_field('post_name', $id);

    if ($info['title'] === null || $title === null) {
        $unreachable++;
        echo "  check $slug: could not be fetched from here ($url); check it in a browser.\n";
    } elseif ($url !== $info['url'] || $title !== $info['title']) {
        $bad++;
        echo "  CHECK FAILED $slug: address or title changed ($url).\n";
    } else {
        echo "  check $slug: 200, same address and title.\n";
    }
}

$privacyAfter = (string) get_privacy_policy_url();
$termsAfter = $termsId ? (string) get_permalink($termsId) : '';
echo '  core privacy address: ' . ($privacyBefore === $privacyAfter ? 'unchanged' : "CHANGED ($privacyBefore to $privacyAfter)") . ($privacyBefore === '' ? ' (not set)' : '') . ".\n";
echo '  WooCommerce terms address: ' . ($termsBefore === $termsAfter ? 'unchanged' : "CHANGED ($termsBefore to $termsAfter)") . ($termsBefore === '' ? ' (not set)' : '') . ".\n";
$bad += ($privacyBefore !== $privacyAfter ? 1 : 0) + ($termsBefore !== $termsAfter ? 1 : 0);
echo $bad === 0 ? "All checks passed" . ($unreachable ? " ($unreachable could not be fetched from here)" : '') . ".\n" : "$bad check(s) FAILED: look before going further.\n";
exit($bad === 0 ? 0 : 1);
