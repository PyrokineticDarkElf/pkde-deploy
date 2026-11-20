<?php

/**
 * bin/deploy/legal-migrate.php (WP-104), the pure parts in legal-migrate-lib.php. The two steps against a real WordPress (seeded legal Pages, the settings, a menu item, plan, apply, a
 * second apply, the refusals) were run on the template stack; the report says so.
 */
require_once dirname(__DIR__, 2) . '/bin/deploy/legal-migrate-lib.php';

$page = fn(int $id, string $slug, string $title, int $parent = 0) => ['id' => $id, 'title' => $title, 'slug' => $slug, 'url' => "https://example.test/$slug/", 'status' => 'publish', 'parent' => $parent, 'modified' => '2026-10-01'];

it('proposes a type from the slug or title', function () {
    foreach ([['privacy-policy', 'Privacy Policy', 'privacy'], ['data-protection', 'Data protection', 'privacy'], ['cookie-policy', 'Cookie Policy', 'cookies'], ['terms-and-conditions', 'Terms', 'terms'], ['t-and-cs', 'T&Cs', 'terms'],
        ['refund_returns', 'Refund and Returns Policy', 'returns'], ['delivery', 'Delivery', 'delivery'], ['shipping-information', 'Shipping', 'delivery'], ['accessibility-statement', 'Accessibility', 'accessibility'],
        ['modern-slavery-statement', 'Modern slavery', 'modern-slavery'], ['legal-notice', 'Legal notice', 'other']] as [$slug, $title, $type]) {
        expect(pkde_legal_guess_type($slug, $title))->toBe($type, $slug);
    }

    foreach ([['about-us', 'About us'], ['contact', 'Contact'], ['shop', 'Shop'], ['home', 'Home'], ['our-story', 'Our story']] as [$slug, $title]) {
        expect(pkde_legal_guess_type($slug, $title))->toBeNull($slug);
    }
});

it('lists the pages that look legal and the two settings, saying why, and flags a page with a parent', function () use ($page) {
    $rows = pkde_legal_candidates([$page(1, 'about', 'About'), $page(2, 'privacy-policy', 'Privacy Policy'), $page(3, 'our-rules', 'Our rules'), $page(4, 'refund_returns', 'Refund and Returns Policy'), $page(5, 'terms', 'Terms', 9)], 2, 3);

    expect(array_column($rows, 'id'))->toBe([2, 3, 4, 5])
        ->and($rows[0]['type'])->toBe('privacy')->and($rows[0]['why'])->toBe('the core Privacy Policy page')
        ->and($rows[1]['type'])->toBe('terms')->and($rows[1]['why'])->toBe('the WooCommerce Terms page') // chosen in the settings though the title says nothing
        ->and($rows[2]['why'])->toBe('the slug or title looks legal')
        ->and($rows[3]['problem'])->toContain('parent page');
});

it('accepts a good list and says exactly what is wrong with a bad one', function () {
    $find = fn(int $id) => match ($id) {
        10 => ['type' => 'page', 'parent' => 0],
        11 => ['type' => 'pkde_legal', 'parent' => 0],
        12 => ['type' => 'post', 'parent' => 0],
        13 => ['type' => 'page', 'parent' => 5],
        default => null,
    };

    expect(pkde_legal_list_problems(['documents' => [['id' => 10, 'type' => 'privacy', 'effective' => '2026-11-01'], ['id' => 11, 'type' => 'terms']]], $find))->toBe([])
        ->and(pkde_legal_list_problems(null, $find))->toHaveCount(1)
        ->and(pkde_legal_list_problems(['documents' => []], $find))->toHaveCount(1);

    $problems = pkde_legal_list_problems(['documents' => [['type' => 'privacy'], ['id' => 10, 'type' => 'nonsense'], ['id' => 12, 'type' => 'privacy'], ['id' => 99, 'type' => 'privacy'], ['id' => 13, 'type' => 'privacy'], ['id' => 10, 'type' => 'privacy', 'effective' => '20/11/2026']]], $find);
    $text = implode('|', $problems);

    expect($text)->toContain('no id')->toContain('type must be one of')->toContain('is a post, not a page')->toContain('does not exist')->toContain('has a parent page')->toContain('listed twice')->toContain('YYYY-MM-DD');
});

it('reads a page title from html', function () {
    expect(pkde_legal_html_title("<html><head><title>\n  Privacy &amp; you \n- Site</title></head>"))->toBe('Privacy & you - Site')
        ->and(pkde_legal_html_title('<p>no title</p>'))->toBe('');
});

it('ships the script with the two steps, the refusals and the normal flush', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/bin/deploy/legal-migrate.php');

    expect($source)->toContain('apply needs the approved list', 'Nothing was changed', 'flush_rewrite_rules(false)', "'post_type' => 'pkde_legal'", '_menu_item_object', 'get_privacy_policy_url', 'woocommerce_terms_page_id')
        ->and($source)->not->toContain('flush_rewrite_rules(true)');
});
