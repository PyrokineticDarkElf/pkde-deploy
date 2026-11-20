<?php

/**
 * Applies a site's page content, kept as files, to the pages it belongs to (from bb's config/content/apply-pages.php, BB-56). The files are repeatable site content: a staging
 * run and, at the cutover, a production run after the database import give the same pages. Run it with WP-CLI's eval-file; it prints slugs, statuses and counts only.
 *
 *   bash bin/deploy/wp.sh eval-file bin/deploy/apply-pages.php            plan (the default; read-only)
 *   bash bin/deploy/wp.sh eval-file bin/deploy/apply-pages.php plan
 *   bash bin/deploy/wp.sh eval-file bin/deploy/apply-pages.php apply
 *   ... dir=content/pages                                                  where the files are (the default); a path from the site's root, or absolute
 *
 * One file per page, content/pages/<slug>.html (block markup); and one per legal document, content/legal/<slug>.html, applied to the pkde_legal post with that slug (run legal-migrate
 * apply first: it converts the Page; a file with a block outside the legal list is refused and the block named). For each file it:
 *   - finds the page by slug (any status); it never creates a page: a missing slug is reported;
 *   - resolves {{faq-group:<slug>}} to the id of that FAQ group, for the kit FAQ block in group mode ("groups":[{{faq-group:general-questions}}]); the group must exist, none is created;
 *   - resolves {{form:<key>}} to the Contact Form 7 form tagged with that key (App\form_id; for `contact` also the form titled "Contact Us" when none is tagged);
 *   - maps every /uploads/... picture to an attachment of this site by FILE NAME and adds its id to the blocks that take one (imageUrl, extraImages); a picture with no
 *     attachment here is reported as missing, the page is still written;
 *   - saves the current content as a revision, replaces post_content only (title, slug, template, parent and status are kept);
 *   - skips a page whose content already matches (so it can run again, and the second run changes nothing).
 * The deploy ships a top-level content/ folder with the release, next to config/. Without `dir=`, a site that still keeps the files in config/content/pages
 * (bb's first place) is read from there.
 */
require_once __DIR__ . '/apply-pages-lib.php';

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
    echo "Unknown step \"{$opts['mode']}\". Use plan or apply (optionally dir=content/pages).\n";
    exit(1);
}

$mode = $opts['mode'];
$roots = array_values(array_filter([function_exists('App\\site_root') ? App\site_root() : '', defined('ABSPATH') ? dirname(rtrim(ABSPATH, '/'), 2) : '', (string) getcwd()]));
$wanted = isset($opts['dir']) ? [$opts['dir']] : ['content/pages', 'config/content/pages'];
$dir = null;

foreach ($wanted as $candidate) {
    foreach (str_starts_with($candidate, '/') ? [$candidate] : array_map(fn($root) => rtrim($root, '/') . '/' . $candidate, $roots) as $path) {
        if (is_dir($path)) {
            $dir = $path;
            break 2;
        }
    }
}

// Legal documents (WP-104): content/legal/<slug>.html is applied to the pkde_legal post with that slug (legal-migrate apply converts the Page first).
$legalDir = null;

foreach (isset($opts['legal']) ? [$opts['legal']] : ['content/legal'] as $candidate) {
    foreach (str_starts_with($candidate, '/') ? [$candidate] : array_map(fn($root) => rtrim($root, '/') . '/' . $candidate, $roots) as $path) {
        if (is_dir($path)) {
            $legalDir = $path;
            break 2;
        }
    }
}

if ($dir === null && $legalDir === null) {
    echo 'No page files found (looked for ' . implode(' or ', $wanted) . " and content/legal). Nothing was changed.\n";
    exit(1);
}

global $wpdb;

$attachmentId = function (string $file) use ($wpdb): int {
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND (meta_value = %s OR meta_value LIKE %s) ORDER BY post_id DESC LIMIT 1",
        $file,
        '%/' . $wpdb->esc_like($file),
    ));
};

$formId = function (string $key) use ($wpdb): int {
    $id = function_exists('App\\form_id') ? App\form_id($key) : 0;

    if ($id) {
        return $id;
    }

    if ($key === 'contact') {
        $found = get_posts(['post_type' => 'wpcf7_contact_form', 'post_status' => 'any', 'title' => 'Contact Us', 'numberposts' => 1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC']);

        return (int) ($found[0] ?? 0);
    }

    return 0;
};

$files = $dir !== null ? (glob($dir . '/*.html') ?: []) : [];
sort($files);
$legalFiles = $legalDir !== null ? (glob($legalDir . '/*.html') ?: []) : [];
sort($legalFiles);
$jobs = array_merge(array_map(fn($f) => [$f, 'page'], $files), array_map(fn($f) => [$f, 'pkde_legal'], $legalFiles));
$counts = ['match' => 0, 'would update' => 0, 'updated' => 0, 'page missing' => 0, 'problem' => 0];
echo "apply-pages ($mode): " . count($files) . ' file(s)' . ($legalFiles ? ' and ' . count($legalFiles) . ' legal file(s)' : '') . "\n";
kses_remove_filters(); // wp-cli has no logged-in user: do not let kses rewrite the block markup

foreach ($jobs as [$file, $postType]) {
    $isLegal = $postType === 'pkde_legal';
    $slug = ($isLegal ? 'legal/' : '') . pkde_pages_slug($file);
    $page = $isLegal && ! post_type_exists('pkde_legal') ? [] : get_posts(['post_type' => $postType, 'name' => pkde_pages_slug($file), 'post_status' => 'any', 'numberposts' => 1]);

    if (! $page) {
        printf($isLegal ? "%-24s document missing (legal-migrate apply converts the Page first; nothing is created here)\n" : "%-24s page missing\n", $slug);
        $counts['page missing']++;
        continue;
    }

    $page = $page[0];
    $content = (string) file_get_contents($file);
    $failed = false;

    foreach (pkde_pages_form_keys($content) as $key) {
        $form = $formId($key);

        if (! $form) {
            printf("%-24s PROBLEM: no Contact Form 7 form with the key %s\n", $slug, $key);
            $counts['problem']++;
            $failed = true;
            break;
        }

        $content = str_ireplace('{{form:' . $key . '}}', (string) $form, $content);
    }

    // {{faq-group:<slug>}} becomes the id of that FAQ group (the kit's FAQ block stores group ids), so a page file can carry the FAQ block in group mode and the order
    // apply-pages then faq-migrate is not needed. A group that does not exist is a problem: nothing is created here.
    if (! $failed) {
        foreach (pkde_pages_faq_group_slugs($content) as $groupSlug) {
            $term = taxonomy_exists('pkde_faq_group') ? get_term_by('slug', $groupSlug, 'pkde_faq_group') : false;

            if (! $term) {
                printf("%-24s PROBLEM: no FAQ group with the slug %s (create it first: the FAQs screen, or faq-migrate apply)\n", $slug, $groupSlug);
                $counts['problem']++;
                $failed = true;
                break;
            }

            $content = str_ireplace('{{faq-group:' . $groupSlug . '}}', (string) $term->term_id, $content);
        }
    }

    if ($failed) {
        continue;
    }

    // a legal document is written with the legal block list only: a file with another block is refused, and the block is named
    if ($isLegal) {
        $notAllowed = pkde_pages_disallowed_blocks(parse_blocks($content), PKDE_PAGES_LEGAL_BLOCKS);

        if ($notAllowed) {
            printf("%-24s PROBLEM: block(s) not allowed in a legal document: %s\n", $slug, implode(', ', $notAllowed));
            $counts['problem']++;
            continue;
        }
    }

    $missing = [];
    $blocks = pkde_pages_map_images(parse_blocks($content), $attachmentId, $missing);
    $new = serialize_blocks($blocks);
    $same = serialize_blocks(parse_blocks($page->post_content)) === $new;
    $note = sprintf('%d block(s) at the top, %d picture(s) missing', count($blocks), count($missing));

    if ($missing) {
        printf("%-24s missing pictures: %s\n", $slug, implode(', ', array_keys($missing)));
    }

    if ($same) {
        printf("%-24s matches (%s)\n", $slug, $note);
        $counts['match']++;
        continue;
    }

    if ($mode === 'plan') {
        printf("%-24s would update (%s)\n", $slug, $note);
        $counts['would update']++;
        continue;
    }

    wp_save_post_revision($page->ID);
    $result = wp_update_post(['ID' => $page->ID, 'post_content' => wp_slash($new)], true);

    if (is_wp_error($result)) {
        printf("%-24s PROBLEM: the update failed\n", $slug);
        $counts['problem']++;
        continue;
    }

    printf("%-24s updated (%s)\n", $slug, $note);
    $counts['updated']++;
}

echo 'apply-pages done: ' . implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($counts), $counts)) . ".\n";
