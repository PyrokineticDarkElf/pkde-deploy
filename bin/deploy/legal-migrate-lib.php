<?php

/**
 * The pure parts of legal-migrate.php (WP-104): which Pages look legal, and whether the approved list is usable. No WordPress needed.
 */
const PKDE_LEGAL_TYPES = ['privacy', 'cookies', 'terms', 'returns', 'delivery', 'accessibility', 'modern-slavery', 'other'];

/** A proposed document type from a page's slug and title, or null when it does not look like a legal page. */
function pkde_legal_guess_type(string $slug, string $title): ?string
{
    $text = strtolower($slug . ' ' . $title);

    return match (true) {
        (bool) preg_match('/privacy|data[-\s]protection|gdpr/', $text) => 'privacy',
        (bool) preg_match('/cookie/', $text) => 'cookies',
        (bool) preg_match('/terms|conditions|\bt\s*&?\s*cs?\b|t-and-c/', $text) => 'terms',
        (bool) preg_match('/return|refund/', $text) => 'returns',
        (bool) preg_match('/deliver|shipping|dispatch/', $text) => 'delivery',
        (bool) preg_match('/accessib/', $text) => 'accessibility',
        (bool) preg_match('/slavery/', $text) => 'modern-slavery',
        (bool) preg_match('/legal|disclaimer/', $text) => 'other',
        default => null,
    };
}

/**
 * The Pages to propose, with why: the two settings first, then the slug and title patterns.
 *
 * @param  list<array{id: int, title: string, slug: string, url: string, status: string, parent: int, modified: string}>  $pages
 * @return list<array{id: int, title: string, slug: string, url: string, status: string, type: string, why: string, problem: string}>
 */
function pkde_legal_candidates(array $pages, int $privacyId, int $termsId): array
{
    $rows = [];

    foreach ($pages as $page) {
        $why = [];
        $type = pkde_legal_guess_type($page['slug'], $page['title']);

        if ($page['id'] === $privacyId) {
            $why[] = 'the core Privacy Policy page';
            $type = 'privacy';
        }

        if ($page['id'] === $termsId) {
            $why[] = 'the WooCommerce Terms page';
            $type = 'terms';
        }

        if ($type === null && $why === []) {
            continue;
        }

        if ($why === []) {
            $why[] = 'the slug or title looks legal';
        }

        $problem = $page['parent'] !== 0 ? 'has a parent page, so its address is not at the root' : '';
        $rows[] = ['id' => $page['id'], 'title' => $page['title'], 'slug' => $page['slug'], 'url' => $page['url'], 'status' => $page['status'], 'type' => (string) $type, 'why' => implode(', ', $why), 'problem' => $problem];
    }

    return $rows;
}

/**
 * What is wrong with an approved list, in words; an empty list of problems means it can be applied.
 *
 * @param  callable(int): ?array{type: string, parent: int}  $find  the post with that id: its post type and parent, or null
 * @return list<string>
 */
function pkde_legal_list_problems(mixed $list, callable $find): array
{
    if (! is_array($list) || ! is_array($list['documents'] ?? null) || $list['documents'] === []) {
        return ['the file must return ["documents" => [["id" => 12, "type" => "privacy", "effective" => "2026-11-01"], ...]]'];
    }

    $problems = [];
    $seen = [];

    foreach ($list['documents'] as $n => $row) {
        $label = 'documents[' . $n . ']';
        $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        $type = is_array($row) ? (string) ($row['type'] ?? '') : '';
        $effective = is_array($row) ? (string) ($row['effective'] ?? '') : '';

        if ($id <= 0) {
            $problems[] = "$label has no id";

            continue;
        }

        if (isset($seen[$id])) {
            $problems[] = "$label: id $id is listed twice";
        }
        $seen[$id] = true;

        if (! in_array($type, PKDE_LEGAL_TYPES, true)) {
            $problems[] = "$label (id $id): type must be one of " . implode(', ', PKDE_LEGAL_TYPES);
        }

        if ($effective !== '' && ! pkde_legal_valid_date($effective)) {
            $problems[] = "$label (id $id): effective must be a date as YYYY-MM-DD";
        }

        $post = $find($id);

        if ($post === null) {
            $problems[] = "$label: id $id does not exist";
        } elseif (! in_array($post['type'], ['page', 'pkde_legal'], true)) {
            $problems[] = "$label: id $id is a {$post['type']}, not a page";
        } elseif ($post['parent'] !== 0) {
            $problems[] = "$label: id $id has a parent page, so its address would change";
        }
    }

    return $problems;
}

function pkde_legal_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}

/** The title of an HTML page, whitespace collapsed, or '' when there is none. */
function pkde_legal_html_title(string $html): string
{
    return preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) === 1 ? trim((string) preg_replace('/\s+/', ' ', html_entity_decode($m[1]))) : '';
}
