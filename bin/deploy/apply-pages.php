<?php

/**
 * Applies a site's page content, kept as files, to the pages it belongs to (from bb's config/content/apply-pages.php). The files are repeatable site content: a staging
 * run and, at the cutover, a production run after the database import give the same pages. Run it with WP-CLI's eval-file; it prints slugs, statuses and counts only.
 *
 *   bash bin/deploy/wp.sh eval-file bin/deploy/apply-pages.php            plan (the default; read-only)
 *   bash bin/deploy/wp.sh eval-file bin/deploy/apply-pages.php plan
 *   bash bin/deploy/wp.sh eval-file bin/deploy/apply-pages.php apply
 *   ... dir=content/pages                                                  where the files are (the default); a path from the site's root, or absolute
 *
 * One file per page, content/pages/<slug>.html (block markup); and one per legal document, content/legal/<slug>.html, applied to the pkde_legal post with that slug (run legal-migrate
 * apply first: it converts the Page; a file with a block outside the legal list is refused and the block named). A DRAFT document is found too (by its slug, or by its title when it
 * has no slug yet), and a MISSING one is created as a draft when the file names its type: a first line `<!-- pkde-legal {"type": "privacy", "title": "Privacy policy", "effective": "2026-11-01"} -->`
 * or a sidecar `<slug>.json` with the same keys (the front matter is not part of the content). For each file it:
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

// Legal documents: content/legal/<slug>.html is applied to the pkde_legal post with that slug (legal-migrate apply converts the Page first).
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
$counts = ['match' => 0, 'would update' => 0, 'updated' => 0, 'would create' => 0, 'created' => 0, 'page missing' => 0, 'problem' => 0];
echo "apply-pages ($mode): " . count($files) . ' file(s)' . ($legalFiles ? ' and ' . count($legalFiles) . ' legal file(s)' : '') . "\n";
kses_remove_filters(); // wp-cli has no logged-in user: do not let kses rewrite the block markup

foreach ($jobs as [$file, $postType]) {
    $isLegal = $postType === 'pkde_legal';
    $slug = ($isLegal ? 'legal/' : '') . pkde_pages_slug($file);
    $page = $isLegal && ! post_type_exists('pkde_legal') ? [] : get_posts(['post_type' => $postType, 'name' => pkde_pages_slug($file), 'post_status' => $isLegal ? ['publish', 'draft', 'pending', 'private', 'future'] : 'any', 'numberposts' => 1]); // 'any' hides a document's drafts from a request with no user: name the statuses
    $legalMeta = ['meta' => ['type' => '', 'title' => '', 'effective' => ''], 'content' => '', 'problem' => ''];

    if ($isLegal) {
        // the front matter (a first <!-- pkde-legal {...} --> comment) and a sidecar <slug>.json say what the document is; neither is part of the content
        $sidecarFile = preg_replace('/\.html$/', '.json', $file);
        $sidecar = is_file($sidecarFile) ? (array) json_decode((string) file_get_contents($sidecarFile), true) : [];
        $legalMeta = pkde_pages_legal_front_matter((string) file_get_contents($file), $sidecar);

        // a draft made without a slug has an empty post_name (WordPress names it when it is published): find it by its title
        if (! $page && post_type_exists('pkde_legal')) {
            $byTitle = array_filter(get_posts(['post_type' => 'pkde_legal', 'post_status' => ['draft', 'pending', 'auto-draft'], 'numberposts' => 100]), fn($d) => $d->post_name === '' && sanitize_title($d->post_title) === pkde_pages_slug($file));
            $page = array_slice(array_values($byTitle), 0, 1);
        }
    }

    // a missing legal document is created as a DRAFT when the file says what it is (a type): a site needs no script of its own
    if (! $page && $isLegal && post_type_exists('pkde_legal') && $legalMeta['meta']['type'] !== '' && $legalMeta['problem'] === '') {
        $created = true;
        $page = [(object) ['ID' => 0, 'post_content' => '', 'post_type' => 'pkde_legal']];
    } else {
        $created = false;
    }

    if ($isLegal && $legalMeta['problem'] !== '') {
        printf("%-24s PROBLEM: %s\n", $slug, $legalMeta['problem']);
        $counts['problem']++;
        continue;
    }

    if (! $page) {
        printf($isLegal ? "%-24s document missing (legal-migrate apply converts the Page first, or give the file a type to create it as a draft: <!-- pkde-legal {\"type\": \"privacy\"} --> or a %s.json; nothing is created here)\n" : "%-24s page missing\n", $slug, pkde_pages_slug($file));
        $counts['page missing']++;
        continue;
    }

    $page = $page[0];
    $content = $isLegal ? $legalMeta['content'] : (string) file_get_contents($file);
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

    if ($same && ! $created) {
        printf("%-24s matches (%s)\n", $slug, $note);
        $counts['match']++;
        continue;
    }

    if ($mode === 'plan') {
        printf($created ? "%-24s would create as a draft, type %s (%s)\n" : "%-24s would update (%s)\n", $slug, ...($created ? [$legalMeta['meta']['type'], $note] : [$note]));
        $counts[$created ? 'would create' : 'would update']++;
        continue;
    }

    if ($created) {
        $new_id = wp_insert_post([
            'post_type' => 'pkde_legal', 'post_status' => 'draft', 'post_name' => pkde_pages_slug($file), 'comment_status' => 'closed', 'ping_status' => 'closed',
            'post_title' => $legalMeta['meta']['title'] !== '' ? $legalMeta['meta']['title'] : pkde_pages_title_from_slug(pkde_pages_slug($file)), 'post_content' => wp_slash($new),
        ], true);

        if (is_wp_error($new_id) || ! $new_id) {
            printf("%-24s PROBLEM: the document could not be created\n", $slug);
            $counts['problem']++;
            continue;
        }

        update_post_meta($new_id, '_pkde_legal_type', $legalMeta['meta']['type']);
        $legalMeta['meta']['effective'] !== '' && update_post_meta($new_id, '_pkde_legal_effective', $legalMeta['meta']['effective']);
        printf("%-24s created as a draft, type %s (%s)\n", $slug, $legalMeta['meta']['type'], $note);
        $counts['created']++;
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
