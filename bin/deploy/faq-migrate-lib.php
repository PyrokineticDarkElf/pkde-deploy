<?php

/**
 * The pure parts of bin/deploy/faq-migrate.php: finding the FAQ blocks of a page, their questions and a stable hash for each, the inventory rows, the mapping
 * file's checks, and the conversion of an answer's HTML to block markup. No WordPress call is made here except through the callables the caller passes, so Pest tests it.
 */

/** The block kinds the migration knows: the kit's FAQ block (hand-typed), Rank Math's FAQ block, and an accordion headed "FAQ". */
const PKDE_FAQ_KIND_KIT = 'sage-faq';
const PKDE_FAQ_KIND_RANK_MATH = 'rank-math';
const PKDE_FAQ_KIND_ACCORDION = 'accordion';
const PKDE_FAQ_KIND_DETAILS = 'details';

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

/** True for a heading that introduces FAQs: "FAQ", "FAQs", "F.A.Q.", "Questions", "Frequently asked questions". */
function pkde_faq_heading_matches(string $text): bool
{
    return preg_match('/faq|f\.a\.q|questions/i', $text) === 1;
}

/** A whitespace-only block between two blocks (the freeform filler parse_blocks leaves). */
function pkde_faq_is_blank(array $block): bool
{
    return ($block['blockName'] ?? null) === null && trim((string) ($block['innerHTML'] ?? '')) === '';
}

/** The question of a core Details block: its summary. */
function pkde_faq_details_summary(array $block): string
{
    if (isset($block['attrs']['summary']) && is_string($block['attrs']['summary'])) {
        return $block['attrs']['summary'];
    }

    return preg_match('~<summary\b[^>]*>(.*?)</summary>~is', (string) ($block['innerHTML'] ?? ''), $m) === 1 ? $m[1] : '';
}

/** The text of a core heading block, or null when the block is not a heading. */
function pkde_faq_heading_text(array $block): ?string
{
    return ($block['blockName'] ?? '') === 'core/heading' ? pkde_faq_text((string) ($block['attrs']['content'] ?? $block['innerHTML'] ?? '')) : null;
}

/** Non-blank leaf blocks under (and including) a block. */
function pkde_faq_leaf_count(array $block): int
{
    if (empty($block['innerBlocks'])) {
        return pkde_faq_is_blank($block) ? 0 : 1;
    }

    return array_sum(array_map('pkde_faq_leaf_count', $block['innerBlocks']));
}

/**
 * The search for a FAQ heading in a Section (or top-level Group): the path (child positions from the scope's inner blocks) of the FIRST heading that matches, in document
 * order, at any depth, not inside a Details block or a FAQ block; and the path of the topmost wrapper Group of it that holds nothing else (null when the heading is a direct
 * child of the scope or its wrappers hold other blocks). Null when the scope has no such heading.
 *
 * @return ?\ArrayObject {text, heading: ?list<int>, wrapper: ?list<int>, seen: bool, used: bool}
 */
function pkde_faq_scope(array $blocks): ?\ArrayObject
{
    $find = function (array $list, array $path) use (&$find): ?array {
        foreach ($list as $i => $block) {
            $here = [...$path, $i];

            if (($block['blockName'] ?? '') === 'core/details' || pkde_faq_kind($block) !== null) {
                continue;
            }

            $text = pkde_faq_heading_text($block);

            if ($text !== null && pkde_faq_heading_matches($text)) {
                return [$here, $text];
            }

            if (! empty($block['innerBlocks']) && ($found = $find($block['innerBlocks'], $here)) !== null) {
                return $found;
            }
        }

        return null;
    };

    $found = $find($blocks, []);

    if ($found === null) {
        return null;
    }

    [$heading, $text] = $found;
    $wrapper = null;

    for ($length = 1; $length < count($heading); $length++) {
        $node = ['innerBlocks' => $blocks];

        foreach (array_slice($heading, 0, $length) as $step) {
            $node = $node['innerBlocks'][$step];
        }

        if (pkde_faq_leaf_count($node) === 1) {
            $wrapper = array_slice($heading, 0, $length);
            break;
        }
    }

    return new \ArrayObject(['text' => $text, 'heading' => $heading, 'wrapper' => $wrapper, 'seen' => false, 'used' => false]);
}

/**
 * Makes a container block's `innerContent` agree with its inner blocks after blocks were replaced or removed in it. serialize_blocks() places one inner block at every `null`
 * of innerContent, so a Group that lost or gained a block must have as many `null` chunks as inner blocks (the opening markup, one `null` per block with a newline between,
 * the closing markup). A block whose count already agrees is returned as it is.
 */
function pkde_faq_sync_inner_content(array $block): array
{
    $content = (array) ($block['innerContent'] ?? []);
    $slots = count(array_filter($content, fn($chunk) => $chunk === null));
    $count = count((array) ($block['innerBlocks'] ?? []));

    if ($slots === $count || $content === []) {
        return $block;
    }

    $first = array_search(null, $content, true);
    $last = array_key_last(array_filter($content, fn($chunk) => $chunk === null));
    $opening = implode('', array_slice($content, 0, $first === false ? count($content) : $first));
    $closing = $last === null ? '' : implode('', array_slice($content, $last + 1));
    $rebuilt = [$opening];

    for ($n = 0; $n < $count; $n++) {
        $rebuilt[] = null;

        if ($n < $count - 1) {
            $rebuilt[] = "\n";
        }
    }

    $rebuilt[] = $closing;
    $block['innerContent'] = $rebuilt;

    return $block;
}

/** Ends a scope: the wrapper Group that held only the heading is dropped when a FAQ block took the heading, and the marker is removed either way. */
function pkde_faq_finish_scope(array $blocks, bool $used): array
{
    $out = [];

    foreach ($blocks as $block) {
        if (! empty($block['attrs']['pkdeScopeWrapper'])) {
            if ($used) {
                continue;
            }

            unset($block['attrs']['pkdeScopeWrapper']);
        }

        if (! empty($block['innerBlocks'])) {
            $block['innerBlocks'] = pkde_faq_finish_scope($block['innerBlocks'], $used);
            $block = pkde_faq_sync_inner_content($block);
        }

        $out[] = $block;
    }

    return $out;
}

/**
 * Walk parsed blocks in document order. `$visit($block, $kind, $index)` is called for each FAQ block (the index counts them per page, from 0) and returns null to keep it or a
 * replacement block. The inside of a FAQ block is not walked (its items are its questions).
 *
 * A run of one or more consecutive core Details blocks is one FAQ block (kind `details`, a virtual block whose inner blocks are the Details blocks) when a heading that matches
 * pkde_faq_heading_matches() comes directly before it (the heading is then the FAQ block's heading and the replacement takes its place), or when the Group or Section it
 * sits in has such a heading (the heading stays where it is), or, failing both, when a matching heading appears ANYWHERE inside the nearest enclosing Section (or the
 * top-level Group when there is no Section), at any depth and before the run in document order, not inside a Details block (tcs and el: the heading is in a sibling Group
 * of the one that holds the Details). The first such heading is used. It stays where it is and the FAQ block prints none; only when the wrapper Group it sits in holds
 * nothing else is that wrapper removed and the heading becomes the FAQ block's heading. Details blocks anywhere else are not touched; `$loose` counts those runs.
 *
 * @param  string  $containerHeading  the matching heading of the Group or Section the blocks are in, '' if none
 * @param  ?\ArrayObject  $scope  the nearest Section's (or top-level Group's) heading search, see pkde_faq_scope()
 * @param  list<int>  $path  the position of $blocks inside the scope, to find the scope's heading again
 * @return array the (possibly changed) blocks
 */
function pkde_faq_walk(array $blocks, callable $visit, int &$index = 0, string $containerHeading = '', ?\ArrayObject $loose = null, ?\ArrayObject $scope = null, array $path = [], int $depth = 0): array
{
    $out = [];
    $count = count($blocks);

    for ($i = 0; $i < $count; $i++) {
        $block = $blocks[$i];
        $kind = pkde_faq_kind($block);

        if ($kind !== null) {
            $replacement = $visit($block, $kind, $index++);
            $out[] = $replacement ?? $block;

            continue;
        }

        if (($block['blockName'] ?? '') === 'core/details') {
            $end = $i;
            $details = [];

            for ($j = $i; $j < $count; $j++) {
                if (($blocks[$j]['blockName'] ?? '') === 'core/details') {
                    $details[] = $blocks[$j];
                    $end = $j;
                } elseif (! pkde_faq_is_blank($blocks[$j])) {
                    break;
                }
            }

            // the heading directly before the run (blank blocks skipped)
            $previous = null;
            for ($k = count($out) - 1; $k >= 0; $k--) {
                if (! pkde_faq_is_blank($out[$k])) {
                    $previous = $k;
                    break;
                }
            }

            $text = $previous !== null ? pkde_faq_heading_text($out[$previous]) : null;
            $immediate = $text !== null && pkde_faq_heading_matches($text);
            $heading = $immediate ? $text : $containerHeading;
            $viaScope = false;

            if ($heading === '' && $scope !== null && $scope['seen'] && $scope['text'] !== '') {
                $heading = $scope['text'];
                $viaScope = true;
            }

            $consumes = $immediate || ($viaScope && ! $scope['used'] && $scope['wrapper'] !== null);

            if ($heading === '') {
                $loose?->offsetSet('loose', $loose['loose'] + 1);
                for ($m = $i; $m <= $end; $m++) {
                    $out[] = $blocks[$m];
                }
                $i = $end;

                continue;
            }

            $virtual = ['blockName' => 'pkde/details-run', 'attrs' => ['heading' => $heading, 'consumesHeading' => $consumes], 'innerBlocks' => $details, 'innerHTML' => '', 'innerContent' => []];
            $replacement = $visit($virtual, PKDE_FAQ_KIND_DETAILS, $index++);

            if ($replacement !== null) {
                if ($immediate) {
                    array_splice($out, $previous, 1); // the heading becomes the FAQ block's heading
                }
                if ($viaScope && $consumes) {
                    $scope['used'] = true; // the wrapper Group that held only the heading is removed when the scope is finished
                }
                $out[] = $replacement;
            } else {
                for ($m = $i; $m <= $end; $m++) {
                    $out[] = $blocks[$m];
                }
            }
            $i = $end;

            continue;
        }

        if ($scope !== null && $scope['heading'] !== null && [...$path, $i] === $scope['heading']) {
            $scope['seen'] = true;
        }

        if (! empty($block['innerBlocks'])) {
            $inside = '';
            $childScope = $scope;
            $childPath = [...$path, $i];
            $ownScope = ($block['blockName'] ?? '') === 'sage/section' || (($block['blockName'] ?? '') === 'core/group' && $depth === 0 && $scope === null);

            if ($scope !== null && $scope['wrapper'] !== null && $childPath === $scope['wrapper']) {
                $block['attrs']['pkdeScopeWrapper'] = true;
            }

            if ($ownScope) {
                $childScope = pkde_faq_scope($block['innerBlocks']);
                $childPath = [];
            }

            if (in_array($block['blockName'] ?? '', ['core/group', 'sage/section'], true)) {
                foreach ($block['innerBlocks'] as $child) {
                    $text = pkde_faq_heading_text($child);

                    if ($text !== null && pkde_faq_heading_matches($text)) {
                        $inside = $text;
                        break;
                    }
                }
            }

            $block['innerBlocks'] = pkde_faq_walk($block['innerBlocks'], $visit, $index, $inside, $loose, $childScope, $childPath, $depth + 1);

            if ($ownScope && $childScope !== null) {
                $block['innerBlocks'] = pkde_faq_finish_scope($block['innerBlocks'], (bool) $childScope['used']);
            }

            $block = pkde_faq_sync_inner_content($block);
        }

        $out[] = $block;
    }

    return $out;
}

/** How many runs of core Details blocks on a page are NOT under a FAQ heading (for a person to check by hand). */
function pkde_faq_loose_details(array $blocks): int
{
    $loose = new \ArrayObject(['loose' => 0]);
    $index = 0;
    pkde_faq_walk($blocks, fn() => null, $index, '', $loose);

    return (int) $loose['loose'];
}

/** True when post content places the synced pattern (reusable block) with that id: `<!-- wp:block {"ref":ID} /-->`. */
function pkde_faq_content_uses_block(string $content, int $id): bool
{
    return preg_match('/<!--\s*wp:block\s*\{[^}]*"ref"\s*:\s*' . $id . '\b/', $content) === 1;
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

    if ($kind === PKDE_FAQ_KIND_DETAILS) {
        foreach ((array) ($block['innerBlocks'] ?? []) as $item) {
            if (pkde_faq_text(pkde_faq_details_summary($item)) === '') {
                continue;
            }

            $inner = (array) ($item['innerBlocks'] ?? []);
            $items[] = [pkde_faq_details_summary($item), $serialize($inner), implode('', array_map($render, $inner))];
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

/**
 * The theme files (views and app code) that look as if they print a synced pattern: they name its slug, or print blocks (`wp_block`, `do_blocks`, `render_block`) and name its id.
 *
 * @return list<string> paths relative to $themeDir
 */
function pkde_faq_files_printing_block(string $themeDir, int $id, string $slug): array
{
    $found = [];

    foreach (['resources/views', 'app'] as $sub) {
        $dir = rtrim($themeDir, '/') . '/' . $sub;

        if (! is_dir($dir)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || ! preg_match('/\.(blade\.php|php)$/', $file->getFilename())) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $named = $slug !== '' && str_contains($source, $slug);
            $printed = preg_match('/wp_block|do_blocks|render_block/', $source) === 1 && preg_match('/\b' . $id . '\b/', $source) === 1;

            if ($named || $printed) {
                $found[] = ltrim(substr($file->getPathname(), strlen(rtrim($themeDir, '/'))), '/');
            }
        }
    }

    sort($found);

    return $found;
}
