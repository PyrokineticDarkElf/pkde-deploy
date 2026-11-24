<?php

/**
 * The pure parts of bin/deploy/apply-pages.php (from bb's config/content/apply-pages.php, BB-56): mapping the pictures of a page's blocks to attachments by file name, and
 * the placeholders a page file may hold. No WordPress call is made here except through the callables the caller passes, so Pest tests it.
 */

/** The file name of an uploads URL ('/app/uploads/2023/04/photo.jpg' gives 'photo.jpg'), or '' when the URL is not in the uploads folder. */
function pkde_pages_upload_name(mixed $url): string
{
    return is_string($url) && str_contains($url, '/uploads/') ? basename(parse_url($url, PHP_URL_PATH) ?: $url) : '';
}

/**
 * Adds the attachment id to every block that carries a picture URL (`imageUrl` gets `imageId`, `extraImages[].url` gets `.id`) and collects the file names that have no
 * attachment on this site (also for a core Image block, whose id WordPress derives from its class). Nested blocks are walked.
 *
 * @param  callable(string): int  $attachmentId  the attachment id of a file name, 0 when there is none
 * @param  array<string, true>  $missing  filled with the file names that have no attachment
 */
function pkde_pages_map_images(array $blocks, callable $attachmentId, array &$missing): array
{
    foreach ($blocks as $i => $block) {
        $attrs = (array) ($block['attrs'] ?? []);
        $name = pkde_pages_upload_name($attrs['imageUrl'] ?? '');

        if ($name !== '') {
            $id = $attachmentId($name);
            $id ? $attrs['imageId'] = $id : $missing[$name] = true;
        }

        foreach ((array) ($attrs['extraImages'] ?? []) as $k => $extra) {
            $extraName = pkde_pages_upload_name($extra['url'] ?? '');

            if ($extraName !== '') {
                $id = $attachmentId($extraName);
                $id ? $attrs['extraImages'][$k]['id'] = $id : $missing[$extraName] = true;
            }
        }

        if (($block['blockName'] ?? '') === 'core/image' && preg_match('#src="([^"]*/uploads/[^"]+)"#', (string) ($block['innerHTML'] ?? ''), $m) === 1) {
            $imageName = pkde_pages_upload_name($m[1]);

            if ($imageName !== '' && ! $attachmentId($imageName)) {
                $missing[$imageName] = true;
            }
        }

        $block['attrs'] = $attrs;

        if (! empty($block['innerBlocks'])) {
            $block['innerBlocks'] = pkde_pages_map_images($block['innerBlocks'], $attachmentId, $missing);
        }

        $blocks[$i] = $block;
    }

    return $blocks;
}

/** The slug a page file is for: its file name without `.html`. */
function pkde_pages_slug(string $file): string
{
    return basename($file, '.html');
}

/** The form placeholders a page file holds: `{{form:<key>}}` (a Contact Form 7 form looked up by its key). @return list<string> the keys */
function pkde_pages_form_keys(string $content): array
{
    preg_match_all('/\{\{form:([a-z0-9_-]+)\}\}/i', $content, $m);

    return array_values(array_unique($m[1]));
}

/** The FAQ group placeholders a page file holds: `{{faq-group:<slug>}}` (the id of the FAQ group with that slug, for the kit FAQ block's `groups`). @return list<string> the slugs */
function pkde_pages_faq_group_slugs(string $content): array
{
    preg_match_all('/\{\{faq-group:([a-z0-9_-]+)\}\}/i', $content, $m);

    return array_values(array_unique(array_map('strtolower', $m[1])));
}

/** The blocks a legal document may hold (the platform legal module's list, WP-104); a file in content/legal/ with any other block is refused. */
const PKDE_PAGES_LEGAL_BLOCKS = ['core/heading', 'core/paragraph', 'core/list', 'core/list-item', 'core/table', 'core/quote', 'core/details', 'core/separator', 'core/missing'];

/**
 * The names of the blocks in a parsed tree that are not in the allowed list, each once, in the order met (the empty-name freeform filler between blocks is not a block).
 *
 * @param  list<array<string, mixed>>  $blocks
 * @param  list<string>  $allowed
 * @return list<string>
 */
function pkde_pages_disallowed_blocks(array $blocks, array $allowed): array
{
    $found = [];

    foreach ($blocks as $block) {
        $name = $block['blockName'] ?? null;

        if (is_string($name) && $name !== '' && ! in_array($name, $allowed, true) && ! in_array($name, $found, true)) {
            $found[] = $name;
        }

        foreach (pkde_pages_disallowed_blocks((array) ($block['innerBlocks'] ?? []), $allowed) as $inner) {
            if (! in_array($inner, $found, true)) {
                $found[] = $inner;
            }
        }
    }

    return $found;
}

/** The document types a legal file may name (the platform legal module's list). */
const PKDE_PAGES_LEGAL_TYPES = ['privacy', 'cookies', 'terms', 'returns', 'delivery', 'accessibility', 'modern-slavery', 'other'];

/**
 * The front matter of a legal file and the file's content without it. The front matter is a first HTML comment holding JSON:
 * `<!-- pkde-legal {"type": "privacy", "title": "Privacy policy", "effective": "2026-11-01"} -->`. A sidecar `<slug>.json` with the same keys is merged under it (the front matter wins).
 *
 * @param  array<string, mixed>  $sidecar  the decoded sidecar, [] when there is none
 * @return array{meta: array<string, string>, content: string, problem: string} `problem` names what is wrong with the front matter, '' when nothing is
 */
function pkde_pages_legal_front_matter(string $content, array $sidecar = []): array
{
    $meta = $sidecar;
    $problem = '';

    if (preg_match('/^\s*<!--\s*pkde-legal\s+(\{.*?\})\s*-->\s*/s', $content, $m) === 1) {
        $decoded = json_decode($m[1], true);

        if (is_array($decoded)) {
            $meta = array_replace($meta, $decoded);
        } else {
            $problem = 'the pkde-legal front matter is not valid JSON';
        }

        $content = substr($content, strlen($m[0]));
    }

    $clean = [];
    foreach (['type', 'title', 'effective'] as $key) {
        $clean[$key] = is_string($meta[$key] ?? null) ? trim($meta[$key]) : '';
    }

    if ($clean['type'] !== '' && ! in_array($clean['type'], PKDE_PAGES_LEGAL_TYPES, true)) {
        $problem = 'the type "' . $clean['type'] . '" is not one of ' . implode(', ', PKDE_PAGES_LEGAL_TYPES);
    }

    if ($clean['effective'] !== '' && (DateTimeImmutable::createFromFormat('!Y-m-d', $clean['effective']) === false || DateTimeImmutable::createFromFormat('!Y-m-d', $clean['effective'])->format('Y-m-d') !== $clean['effective'])) {
        $problem = 'effective must be a date as YYYY-MM-DD';
    }

    return ['meta' => $clean, 'content' => ltrim($content, "\n"), 'problem' => $problem];
}

/** A title for a document made from its slug when the file names none: "privacy-policy" becomes "Privacy policy". */
function pkde_pages_title_from_slug(string $slug): string
{
    $words = str_replace(['-', '_'], ' ', $slug);

    return ucfirst(strtolower(trim($words)));
}
