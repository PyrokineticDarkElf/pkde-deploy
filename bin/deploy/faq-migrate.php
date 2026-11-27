<?php

/**
 * Moves a site's FAQs from the pages that hold them into the FAQs post type and its groups. Run it with WP-CLI's eval-file, in two steps:
 *
 *   plan   (read-only)  lists every page with a kit FAQ block (typed by hand), a Rank Math FAQ block, an accordion headed "FAQ", or a run of core Details blocks under a FAQ heading (directly before them, in their Group or Section, or anywhere earlier in the nearest enclosing Section or top-level Group), with its question count (a synced pattern, a `wp_block` post, is scanned like a page and where it is used is listed); names the theme files that
 *                       look like a hand-coded FAQ (a person moves those); writes the inventory CSV (page slug, block index, block heading, question, the first 120 characters of the answer,
 *                       a stable question hash, the block kind: no personal data) to the path given as out=. The site's agent proposes the groups from that file, the owner approves them.
 *
 *     bash bin/deploy/wp.sh eval-file bin/deploy/faq-migrate.php plan out=/tmp/faq-inventory.csv
 *     docker compose run --rm wpcli wp --allow-root eval-file vendor/pkde/deploy/bin/deploy/faq-migrate.php plan out=faq-inventory.csv
 *
 *   apply mapping=config/pkde-faq-groups.php
 *                       uses ONLY the approved mapping file (see config/pkde-faq-groups.php.example in the template): creates or reuses its groups, creates one FAQ per unique question hash
 *                       (the same question on three pages is one FAQ), puts each FAQ in its groups in the mapping's order, and switches each FAQ block the mapping names ("<page slug>#<block
 *                       index>") to the kit's FAQ block in group mode. The page's old content is kept as a revision. It refuses to run without a mapping file, changes nothing when the file
 *                       has a problem, and is idempotent (running it again changes nothing). It prints counts and slugs only.
 *
 * Needs the platform `faq` module (the post type and the groups).
 */
require_once __DIR__ . '/faq-migrate-lib.php';

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
    echo "Unknown step \"{$opts['mode']}\". Use plan out=PATH or apply mapping=PATH.\n";
    exit(1);
}

if (! post_type_exists('pkde_faq') || ! taxonomy_exists('pkde_faq_group')) {
    echo "The FAQs post type is not registered: bump pkde/platform to the release with the faq module (and check PKDE_PLATFORM_DISABLE).\n";
    exit(1);
}

global $wpdb;

/** Every post that may hold a FAQ block, oldest first: id, slug, type, status, and its parsed blocks. @return list<array{id:int, slug:string, type:string, blocks:array, post:WP_Post}> */
$pkdePages = function () use ($wpdb): array {
    $GLOBALS['pkde_faq_loose'] = [];
    $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','pending','private','future') AND post_type NOT IN ('revision','pkde_faq','nav_menu_item','attachment')
        AND (post_content LIKE '%wp:sage/faq%' OR post_content LIKE '%wp:rank-math/faq-block%' OR post_content LIKE '%wp:sage/accordion%' OR post_content LIKE '%wp:details%') ORDER BY ID ASC");
    $pages = [];

    foreach ($ids as $id) {
        $post = get_post((int) $id);
        $blocks = parse_blocks($post->post_content);
        $found = false;
        pkde_faq_walk($blocks, function () use (&$found) {
            $found = true;

            return null;
        });

        if ($found) {
            $pages[] = ['id' => (int) $post->ID, 'slug' => (string) $post->post_name, 'type' => $post->post_type, 'blocks' => $blocks, 'post' => $post];
        }

        // core Details blocks that are not under a FAQ heading: listed by plan, never migrated
        $loose = pkde_faq_loose_details($blocks);

        if ($loose > 0) {
            $GLOBALS['pkde_faq_loose'][(string) ($post->post_name ?: $post->ID) . ' (' . $post->post_type . ')'] = $loose;
        }
    }

    return $pages;
};

$render = fn(array $block): string => render_block($block);
$serialize = fn(array $blocks): string => serialize_blocks($blocks);

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
if ($opts['mode'] === 'plan') {
    $out = (string) ($opts['out'] ?? '');

    if ($out === '') {
        echo "plan needs out=PATH for the inventory CSV (out=- prints it to the screen).\n";
        exit(1);
    }

    $pages = $pkdePages();
    $rows = pkde_faq_inventory_rows($pages, $render, $serialize);
    $stream = $out === '-' ? fopen('php://output', 'w') : fopen($out, 'w');

    if (! $stream) {
        echo "Could not write $out.\n";
        exit(1);
    }

    foreach ($rows as $row) {
        fputcsv($stream, $row);
    }

    if ($out !== '-') {
        fclose($stream);
    }

    $questions = count($rows) - 1;
    $unique = count(array_unique(array_column(array_slice($rows, 1), 5)));
    $slugs = array_count_values(array_column($pages, 'slug'));
    echo "PLAN (nothing was changed except the CSV).\n";

    foreach ($pages as $page) {
        $index = 0;
        pkde_faq_walk($page['blocks'], function (array $block, string $kind, int $i) use ($page, $render, $serialize) {
            printf("  %s#%d  %s  %d question(s)%s%s\n", $page['slug'], $i, $kind, count(pkde_faq_items($block, $kind, $render, $serialize)), pkde_faq_block_heading($block, $kind) !== '' ? '  "' . pkde_faq_block_heading($block, $kind) . '"' : '', $page['type'] === 'wp_block' ? '  [synced pattern]' : '');

            return null;
        }, $index);

        if ($page['type'] === 'wp_block') {
            $users = [];

            foreach ($wpdb->get_results($wpdb->prepare("SELECT ID, post_name, post_type, post_content FROM {$wpdb->posts} WHERE post_status <> 'trash' AND post_type NOT IN ('revision', 'wp_block') AND post_content LIKE %s", '%wp:block%')) as $user) {
                if (pkde_faq_content_uses_block((string) $user->post_content, $page['id'])) {
                    $users[] = ($user->post_name ?: $user->ID) . ' (' . $user->post_type . ')';
                }
            }

            foreach ($wpdb->get_results($wpdb->prepare("SELECT t.slug, tt.taxonomy, tt.description FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.description LIKE %s", '%wp:block%')) as $term) {
                if (pkde_faq_content_uses_block((string) $term->description, $page['id'])) {
                    $users[] = $term->slug . ' (' . $term->taxonomy . ' term description)';
                }
            }

            $files = pkde_faq_files_printing_block(get_template_directory(), $page['id'], $page['slug']);
            echo '    used by: ' . ($users ? implode(', ', $users) : 'no post or term description holds it') . ".\n";
            echo $files ? '    CHECK BY HAND (theme files that may print it): ' . implode(', ', $files) . ".\n" : '';
            echo "    (apply rewrites this pattern's content, so every place that uses it follows)\n";
        }
    }

    $ambiguous = array_keys(array_filter($slugs, fn($n) => $n > 1));
    echo count($pages) . " page(s) with a FAQ block; $questions question(s), $unique unique (a repeated question becomes one FAQ).\n";

    if ($ambiguous) {
        echo 'WARN: more than one post has the slug ' . implode(', ', $ambiguous) . ": a mapping key <slug>#<index> would be ambiguous, give those posts distinct slugs first.\n";
    }

    $looseList = (array) ($GLOBALS['pkde_faq_loose'] ?? []);
    echo $looseList ? 'CHECK BY HAND (core Details blocks that are not under a FAQ heading, not migrated): ' . implode(', ', array_map(fn($slug, $n) => "$slug ($n run" . ($n > 1 ? 's' : '') . ')', array_keys($looseList), $looseList)) . ".\n" : '';

    $blade = pkde_faq_blade_files(get_template_directory());
    echo $blade ? 'Hand-coded FAQs for a person to move (theme files): ' . implode(', ', $blade) . ".\n" : "No theme file looks like a hand-coded FAQ.\n";
    echo $out === '-' ? '' : "Inventory written to $out.\n";

    return;
}

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
$file = (string) ($opts['mapping'] ?? '');

if ($file === '') {
    echo "Refusing to run: apply uses only an approved mapping file. Give mapping=config/pkde-faq-groups.php (see config/pkde-faq-groups.php.example).\n";
    exit(1);
}

$roots = array_filter([function_exists('App\\site_root') ? App\site_root() : '', defined('ABSPATH') ? dirname(rtrim(ABSPATH, '/'), 2) : '', (string) getcwd()]);
$found = null;

foreach (str_starts_with($file, '/') ? [$file] : array_map(fn($root) => rtrim($root, '/') . '/' . $file, $roots) as $candidate) {
    if (is_file($candidate)) {
        $found = $candidate;
        break;
    }
}

if ($found === null) {
    echo "Refusing to run: the mapping file $file was not found.\n";
    exit(1);
}

$mapping = (static function (string $__file) {
    return include $__file;
})($found);
$problems = pkde_faq_mapping_problems($mapping);

if ($problems) {
    echo "Refusing to run: the mapping file has " . count($problems) . " problem(s), nothing was changed:\n";

    foreach ($problems as $problem) {
        echo "  - $problem\n";
    }

    exit(1);
}

$pages = $pkdePages();
$slugCounts = array_count_values(array_column($pages, 'slug'));
$ambiguous = array_keys(array_filter($slugCounts, fn($n) => $n > 1));

if ($ambiguous) {
    echo 'Refusing to run: more than one post has the slug ' . implode(', ', $ambiguous) . ", so a block key would be ambiguous. Nothing was changed.\n";
    exit(1);
}

kses_remove_filters(); // wp-cli has no logged-in user: do not let kses rewrite the block markup

// the questions as the content holds them: hash => [question html, answer block markup], the first occurrence wins
$known = [];
foreach ($pages as $page) {
    $index = 0;
    pkde_faq_walk($page['blocks'], function (array $block, string $kind) use (&$known, $render, $serialize) {
        foreach (pkde_faq_items($block, $kind, $render, $serialize) as [$question, $markup]) {
            $known[pkde_faq_hash($question)] ??= [$question, $markup];
        }

        return null;
    }, $index);
}

// the groups: created or reused by slug; name and description follow the approved file
$termIds = [];
$report = ['groups_created' => [], 'groups_reused' => []];

foreach ($mapping['groups'] as $group) {
    $existing = get_term_by('slug', $group['slug'], 'pkde_faq_group');

    if ($existing) {
        wp_update_term($existing->term_id, 'pkde_faq_group', ['name' => (string) $group['name'], 'description' => (string) ($group['description'] ?? '')]);
        $termIds[$group['slug']] = (int) $existing->term_id;
        $report['groups_reused'][] = $group['slug'];
    } else {
        $made = wp_insert_term((string) $group['name'], 'pkde_faq_group', ['slug' => $group['slug'], 'description' => (string) ($group['description'] ?? '')]);

        if (is_wp_error($made)) {
            echo 'Could not create the group ' . $group['slug'] . ": nothing more was changed.\n";
            exit(1);
        }

        $termIds[$group['slug']] = (int) $made['term_id'];
        $report['groups_created'][] = $group['slug'];
    }
}

// one FAQ per unique question hash that a group lists; found again by its hash on a re-run
$faqIds = [];
$created = 0;
$reused = 0;
$missing = [];

foreach ($mapping['groups'] as $group) {
    foreach ($group['questions'] as $hash) {
        if (isset($faqIds[$hash])) {
            continue;
        }

        $existing = get_posts(['post_type' => 'pkde_faq', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_pkde_faq_hash', 'meta_value' => $hash, 'suppress_filters' => true]);

        if ($existing) {
            $faqIds[$hash] = (int) $existing[0];
            $reused++;
            continue;
        }

        if (! isset($known[$hash])) {
            $missing[] = $hash;
            continue;
        }

        $id = wp_insert_post(['post_type' => 'pkde_faq', 'post_status' => 'publish', 'post_title' => pkde_faq_text($known[$hash][0]), 'post_content' => $known[$hash][1]], true);

        if (is_wp_error($id)) {
            echo 'Could not create a FAQ for the hash ' . $hash . ": nothing more was changed.\n";
            exit(1);
        }

        update_post_meta($id, '_pkde_faq_hash', $hash);
        $faqIds[$hash] = (int) $id;
        $created++;
    }
}

// each FAQ in its groups, and each group's order as the file lists it
foreach ($mapping['groups'] as $group) {
    $order = [];

    foreach ($group['questions'] as $hash) {
        if (isset($faqIds[$hash])) {
            wp_set_object_terms($faqIds[$hash], [$termIds[$group['slug']]], 'pkde_faq_group', true);
            $order[] = $faqIds[$hash];
        }
    }

    update_term_meta($termIds[$group['slug']], '_pkde_faq_order', $order);
}

// the blocks: each one the mapping names becomes the FAQ block in group mode; the page's old content stays as a revision
$switched = [];
$grouped = 0;
$unmapped = [];
$blockMap = (array) ($mapping['blocks'] ?? []);

foreach ($pages as $page) {
    $index = 0;
    $changed = false;
    $blocks = pkde_faq_walk($page['blocks'], function (array $block, string $kind, int $i) use ($page, $blockMap, $termIds, &$changed, &$switched, &$unmapped) {
        $key = $page['slug'] . '#' . $i;

        if (! isset($blockMap[$key])) {
            $unmapped[] = $key;

            return null;
        }

        $changed = true;
        $switched[] = $key;
        $attrs = ['groups' => array_values(array_map(fn($slug) => $termIds[(string) $slug], (array) $blockMap[$key])), 'order' => 'manual'];

        foreach (['heading', 'description', 'columnThreshold', 'theme', 'cardStyle'] as $keep) {
            if (isset($block['attrs'][$keep]) && $block['attrs'][$keep] !== '') {
                $attrs[$keep] = $block['attrs'][$keep];
            }
        }

        if ($kind === PKDE_FAQ_KIND_DETAILS) {
            // the heading in front of the Details became this block's heading; a heading further up (in the Group or Section, or in a sibling Group of the same Section) stays where it is, and the block prints none; a wrapper Group that held only that heading is removed and the block takes the heading
            $attrs['heading'] = ! empty($block['attrs']['consumesHeading']) ? (string) $block['attrs']['heading'] : '';
        }

        return ['blockName' => 'sage/faq', 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
    }, $index);

    if ($changed) {
        wp_save_post_revision($page['id']); // the old content, kept
        wp_update_post(['ID' => $page['id'], 'post_content' => serialize_blocks($blocks)]);
    }
}

// a block the mapping names on a page that no longer holds a FAQ block to migrate was switched by an earlier run (a block in group mode is not one to migrate)
$pageSlugs = array_column($pages, 'slug');
$grouped = count(array_filter(array_keys($blockMap), fn($key) => ! in_array(explode('#', (string) $key, 2)[0], $pageSlugs, true)));

echo 'Groups: ' . count($report['groups_created']) . ' created' . ($report['groups_created'] ? ' (' . implode(', ', $report['groups_created']) . ')' : '') . ', ' . count($report['groups_reused']) . ' reused' . ($report['groups_reused'] ? ' (' . implode(', ', $report['groups_reused']) . ')' : '') . ".\n";
echo "FAQs: $created created, $reused already there.\n";
echo 'Blocks: ' . count($switched) . ' switched to group mode' . ($switched ? ' (' . implode(', ', $switched) . ')' : '') . ", $grouped already in group mode or gone.\n";
echo $unmapped ? 'Not in the mapping, left as they are: ' . implode(', ', $unmapped) . ".\n" : '';
echo $missing ? count($missing) . " question hash(es) in the mapping were not found in the content: " . implode(', ', $missing) . ".\n" : '';
