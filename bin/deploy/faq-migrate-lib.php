<?php

/**
 * The pure parts of bin/deploy/faq-migrate.php (WP-102): finding the FAQ blocks of a page, their questions and a stable hash for each, the inventory rows, the mapping
 * file's checks, and the conversion of an answer's HTML to block markup. No WordPress call is made here except through the callables the caller passes, so Pest tests it.
 */

/** The block kinds the migration knows: the kit's FAQ block (hand-typed), Rank Math's FAQ block, and an accordion headed "FAQ". */
const PKDE_FAQ_KIND_KIT = 'sage-faq';
const PKDE_FAQ_KIND_RANK_MATH = 'rank-math';
const PKDE_FAQ_KIND_ACCORDION = 'accordion';

/** A question or answer as plain text: tags and entities removed, spaces collapsed. */
function pkde_faq_text(string $html): string
{
    $html = (string) preg_replace('~</(?:p|li|ul|ol|div|h[1-6]|tr|blockquote)>|<br\s*/?>~i', ' ', $html); // block ends and line breaks separate words

    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
}

/** A stable id for a question: the same question gets the same hash whatever the page, the case, the spacing, the markup or a trailing question mark. */
function pkde_faq_hash(string $question): string
{
    $norm = mb_strtolower(rtrim(pkde_faq_text($question), " ?\u{00A0}"));

    return substr(sha1($norm), 0, 12);
}

/** The kind of FAQ block a parsed block is, or null. A kit FAQ block already in group mode (`groups` set) is not one to migrate. */
function pkde_faq_kind(array $block): ?string
{
    $name = $block['blockName'] ?? '';
    $attrs = (array) ($block['attrs'] ?? []);

    if ($name === 'sage/faq') {
        return empty($attrs['groups']) ? PKDE_FAQ_KIND_KIT : null;
    }

    if ($name === 'rank-math/faq-block') {
        return PKDE_FAQ_KIND_RANK_MATH;
    }

    if ($name === 'sage/accordion' && preg_match('/\bFAQs?\b|frequently asked/i', pkde_faq_text((string) ($attrs['heading'] ?? ''))) === 1) {
        return PKDE_FAQ_KIND_ACCORDION;
    }

    return null;
}

/**
 * Walk parsed blocks in document order. `$visit($block, $kind, $index)` is called for each FAQ block (the index counts them per page, from 0) and returns null to keep it or a
 * replacement block. The inside of a FAQ block is not walked (its items are its questions).
 *
 * @return array the (possibly changed) blocks
 */
function pkde_faq_walk(array $blocks, callable $visit, int &$index = 0): array
{
    foreach ($blocks as $i => $block) {
        $kind = pkde_faq_kind($block);

        if ($kind !== null) {
            $replacement = $visit($block, $kind, $index++);

            if ($replacement !== null) {
                $blocks[$i] = $replacement;
            }

            continue;
        }

        if (! empty($block['innerBlocks'])) {
            $blocks[$i]['innerBlocks'] = pkde_faq_walk($block['innerBlocks'], $visit, $index);
        }
    }

    return $blocks;
}

/** The heading of a FAQ block (plain text). */
function pkde_faq_block_heading(array $block, string $kind): string
{
    return $kind === PKDE_FAQ_KIND_RANK_MATH ? '' : pkde_faq_text((string) ($block['attrs']['heading'] ?? ''));
}

/**
 * The questions of a FAQ block: [question html, answer markup, answer html]. The answer markup is what a new FAQ post is written with (block markup); the answer html
 * is what is shown (for the plain text of the inventory).
 *
 * @param  callable(array): string  $render  render_block(), for an answer's inner blocks
 * @param  callable(array): string  $serialize  serialize_blocks(), for an answer's inner blocks
 * @return list<array{0: string, 1: string, 2: string}>
 */
function pkde_faq_items(array $block, string $kind, callable $render, callable $serialize): array
{
    $items = [];

    if ($kind === PKDE_FAQ_KIND_RANK_MATH) {
        foreach ((array) ($block['attrs']['questions'] ?? []) as $q) {
            if (is_array($q) && pkde_faq_text((string) ($q['title'] ?? '')) !== '' && ($q['visible'] ?? true) !== false) {
                $content = (string) ($q['content'] ?? '');
                $items[] = [(string) $q['title'], pkde_faq_html_to_blocks($content), $content];
            }
        }

        return $items;
    }

    foreach ((array) ($block['innerBlocks'] ?? []) as $item) {
        if (($item['blockName'] ?? '') !== 'sage/accordion-item' || pkde_faq_text((string) ($item['attrs']['heading'] ?? '')) === '') {
            continue;
        }

        $inner = (array) ($item['innerBlocks'] ?? []);
        $items[] = [(string) $item['attrs']['heading'], $serialize($inner), implode('', array_map($render, $inner))];
    }

    return $items;
}

/**
 * The inventory CSV rows of one page's blocks, with the header first.
 *
 * @param  list<array{slug: string, blocks: array}>  $pages
 * @return list<list<string>>
 */
function pkde_faq_inventory_rows(array $pages, callable $render, callable $serialize): array
{
    $rows = [['page_slug', 'block_index', 'block_heading', 'question', 'answer_start', 'question_hash', 'kind']];

    foreach ($pages as $page) {
        $index = 0;
        pkde_faq_walk($page['blocks'], function (array $block, string $kind, int $i) use (&$rows, $page, $render, $serialize) {
            foreach (pkde_faq_items($block, $kind, $render, $serialize) as [$question, , $answerHtml]) {
                $rows[] = [$page['slug'], (string) $i, pkde_faq_block_heading($block, $kind), pkde_faq_text($question), mb_substr(pkde_faq_text($answerHtml), 0, 120), pkde_faq_hash($question), $kind];
            }

            return null;
        }, $index);
    }

    return $rows;
}

/** Check an approved mapping file's content. @return list<string> the problems (empty: it can be applied) */
function pkde_faq_mapping_problems(mixed $mapping): array
{
    $problems = [];

    if (! is_array($mapping) || ! isset($mapping['groups']) || ! is_array($mapping['groups']) || $mapping['groups'] === []) {
        return ['the file must return an array with a non-empty "groups" list (see config/pkde-faq-groups.php.example)'];
    }

    $slugs = [];

    foreach ($mapping['groups'] as $i => $group) {
        $slug = is_array($group) ? (string) ($group['slug'] ?? '') : '';
        $where = 'groups[' . $i . ']' . ($slug !== '' ? " ($slug)" : '');

        if (! is_array($group) || trim((string) ($group['name'] ?? '')) === '') {
            $problems[] = "$where has no name";
        }

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $problems[] = "$where needs a slug of lower-case letters, digits and hyphens";
        } elseif (isset($slugs[$slug])) {
            $problems[] = "$where repeats the slug $slug";
        }

        $slugs[$slug] = true;

        if (! isset($group['questions']) || ! is_array($group['questions'])) {
            $problems[] = "$where needs a \"questions\" list of question hashes";
        } else {
            foreach ($group['questions'] as $hash) {
                if (! is_string($hash) || preg_match('/^[0-9a-f]{12}$/', $hash) !== 1) {
                    $problems[] = "$where lists a question hash that is not 12 hex characters";
                    break;
                }
            }
        }
    }

    foreach ((array) ($mapping['blocks'] ?? []) as $key => $to) {
        if (preg_match('/^[^#\s]+#\d+$/', (string) $key) !== 1) {
            $problems[] = "blocks key \"$key\" must be <page slug>#<block index>";
        }

        foreach ((array) $to as $slug) {
            if (! isset($slugs[(string) $slug])) {
                $problems[] = "blocks \"$key\" names the group \"$slug\", which is not in groups";
            }
        }
    }

    return $problems;
}

/** An answer's HTML as block markup: paragraphs, lists and headings; anything else becomes paragraph text. */
function pkde_faq_html_to_blocks(string $html): string
{
    $html = trim($html);

    if ($html === '') {
        return '';
    }

    if (str_contains($html, '<!-- wp:')) {
        return $html; // already block markup
    }

    $out = [];
    $found = preg_match_all('~<(p|ul|ol|h[1-6])\b[^>]*>(.*?)</\1>~is', $html, $parts, PREG_SET_ORDER);
    $rest = trim((string) preg_replace('~<(p|ul|ol|h[1-6])\b[^>]*>.*?</\1>~is', '', $html));

    if (! $found) {
        $parts = [[$html, 'p', $html]];
    }

    foreach ($parts as [, $tag, $inner]) {
        $tag = strtolower($tag);

        if ($tag === 'p') {
            $out[] = "<!-- wp:paragraph -->\n<p>" . trim($inner) . "</p>\n<!-- /wp:paragraph -->";
        } elseif ($tag === 'ul' || $tag === 'ol') {
            preg_match_all('~<li\b[^>]*>(.*?)</li>~is', $inner, $li);
            $items = array_map(fn($text) => "<!-- wp:list-item -->\n<li>" . trim($text) . "</li>\n<!-- /wp:list-item -->", $li[1]);
            $ordered = $tag === 'ol' ? ' {"ordered":true}' : '';
            $out[] = "<!-- wp:list$ordered -->\n<$tag class=\"wp-block-list\">" . implode('', $items) . "</$tag>\n<!-- /wp:list -->";
        } else {
            $level = (int) substr($tag, 1);
            $level = in_array($level, [3, 4], true) ? $level : 3;
            $attrs = $level === 3 ? '' : ' {"level":4}';
            $out[] = "<!-- wp:heading$attrs -->\n<h$level class=\"wp-block-heading\">" . trim($inner) . "</h$level>\n<!-- /wp:heading -->";
        }
    }

    if ($found && $rest !== '' && pkde_faq_text($rest) !== '') {
        $out[] = "<!-- wp:paragraph -->\n<p>" . trim($rest) . "</p>\n<!-- /wp:paragraph -->";
    }

    return implode("\n\n", $out);
}

/**
 * The files in a theme's views (and its app code) that look like a hand-coded FAQ: a Blade view or PHP file that prints FAQPage structured data or marks a list as FAQ.
 *
 * @return list<string> paths relative to $themeDir
 */
function pkde_faq_blade_files(string $themeDir): array
{
    $found = [];

    foreach (['resources/views', 'app'] as $sub) {
        $dir = rtrim($themeDir, '/') . '/' . $sub;

        if (! is_dir($dir)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! preg_match('/\.(blade\.php|php)$/', $file->getFilename())) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/FAQPage|class="[^"]*\bfaq\b|id="faq|accordion[^\n]{0,60}faq/i', $source) === 1) {
                $found[] = ltrim(substr($file->getPathname(), strlen(rtrim($themeDir, '/'))), '/');
            }
        }
    }

    sort($found);

    return $found;
}
