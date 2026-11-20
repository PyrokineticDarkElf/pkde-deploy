<?php

/**
 * bin/deploy/faq-migrate.php (WP-102), the pure parts in faq-migrate-lib.php. The two steps against a real WordPress (seeded old-style pages, plan, apply, a second apply,
 * the refusals) were run on the template stack; the report says so.
 */
require_once dirname(__DIR__, 2) . '/bin/deploy/faq-migrate-lib.php';

$item = fn(string $q, string $answer) => ['blockName' => 'sage/accordion-item', 'attrs' => ['heading' => $q], 'innerBlocks' => [['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "<p>$answer</p>"]], 'innerHTML' => '', 'innerContent' => []];
$render = fn(array $block): string => $block['innerHTML'] ?? '';
$serialize = fn(array $blocks): string => implode('', array_map(fn($b) => '[' . $b['innerHTML'] . ']', $blocks));

it('gives the same hash to the same question whatever its case, spacing, markup or question mark', function () {
    expect(pkde_faq_hash('Where is my order?'))->toBe(pkde_faq_hash('  where   IS my <strong>order</strong>'))
        ->and(pkde_faq_hash('Where is my order?'))->not->toBe(pkde_faq_hash('Where is my parcel?'))
        ->and(pkde_faq_hash('Q'))->toMatch('/^[0-9a-f]{12}$/');
});

it('recognises the kit FAQ block typed by hand, Rank Math\'s FAQ block and an accordion headed FAQ, but not a block already in group mode', function () {
    expect(pkde_faq_kind(['blockName' => 'sage/faq', 'attrs' => []]))->toBe('sage-faq')
        ->and(pkde_faq_kind(['blockName' => 'sage/faq', 'attrs' => ['groups' => [3]]]))->toBeNull()
        ->and(pkde_faq_kind(['blockName' => 'rank-math/faq-block', 'attrs' => []]))->toBe('rank-math')
        ->and(pkde_faq_kind(['blockName' => 'sage/accordion', 'attrs' => ['heading' => 'Delivery FAQs']]))->toBe('accordion')
        ->and(pkde_faq_kind(['blockName' => 'sage/accordion', 'attrs' => ['heading' => 'Frequently asked questions']]))->toBe('accordion')
        ->and(pkde_faq_kind(['blockName' => 'sage/accordion', 'attrs' => ['heading' => 'Our process']]))->toBeNull()
        ->and(pkde_faq_kind(['blockName' => 'core/paragraph', 'attrs' => []]))->toBeNull();
});

it('walks the blocks in order, numbers the FAQ blocks per page, finds nested ones and can replace one', function () use ($item) {
    $blocks = [
        ['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [['blockName' => 'sage/faq', 'attrs' => ['heading' => 'A'], 'innerBlocks' => [$item('Q1', 'a')]]]],
        ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => []],
        ['blockName' => 'rank-math/faq-block', 'attrs' => ['questions' => []], 'innerBlocks' => []],
    ];
    $seen = [];
    $new = pkde_faq_walk($blocks, function ($block, $kind, $i) use (&$seen) {
        $seen[] = "$i:$kind";

        return $i === 1 ? ['blockName' => 'sage/faq', 'attrs' => ['groups' => [9]], 'innerBlocks' => []] : null;
    });

    expect($seen)->toBe(['0:sage-faq', '1:rank-math'])
        ->and($new[2]['attrs']['groups'])->toBe([9])
        ->and($new[0]['innerBlocks'][0]['attrs']['heading'])->toBe('A'); // the first one was left
});

it('lists the questions of every kind, with the plain answer start and the hash, as inventory rows', function () use ($item, $render, $serialize) {
    $pages = [
        ['slug' => 'shipping', 'blocks' => [['blockName' => 'sage/faq', 'attrs' => ['heading' => 'Delivery'], 'innerBlocks' => [$item('Where is my order?', 'Check your email.'), $item('How long?', str_repeat('x', 200))]]]],
        ['slug' => 'care', 'blocks' => [['blockName' => 'rank-math/faq-block', 'attrs' => ['questions' => [['title' => 'Wash?', 'content' => '<p>Cold.</p><ul><li>No bleach</li></ul>', 'visible' => true], ['title' => 'Hidden?', 'content' => 'x', 'visible' => false]]], 'innerBlocks' => []]]],
    ];
    $rows = pkde_faq_inventory_rows($pages, $render, $serialize);

    expect($rows[0])->toBe(['page_slug', 'block_index', 'block_heading', 'question', 'answer_start', 'question_hash', 'kind'])
        ->and($rows)->toHaveCount(4)
        ->and($rows[1])->toBe(['shipping', '0', 'Delivery', 'Where is my order?', 'Check your email.', pkde_faq_hash('Where is my order?'), 'sage-faq'])
        ->and(mb_strlen($rows[2][4]))->toBe(120)
        ->and($rows[3])->toBe(['care', '0', '', 'Wash?', 'Cold. No bleach', pkde_faq_hash('Wash?'), 'rank-math']);
});

it('accepts a good mapping and says exactly what is wrong with a bad one', function () {
    $good = ['groups' => [['name' => 'Delivery', 'slug' => 'delivery', 'description' => '', 'questions' => ['46a2c5cc4d9a']]], 'blocks' => ['shipping#0' => 'delivery', 'care#1' => ['delivery']]];

    expect(pkde_faq_mapping_problems($good))->toBe([])
        ->and(pkde_faq_mapping_problems('nope'))->toHaveCount(1)
        ->and(pkde_faq_mapping_problems(['groups' => []]))->toHaveCount(1);

    $bad = pkde_faq_mapping_problems(['groups' => [
        ['name' => '', 'slug' => 'Bad Slug', 'questions' => ['xyz']],
        ['name' => 'Two', 'slug' => 'two', 'questions' => []],
        ['name' => 'Two again', 'slug' => 'two', 'questions' => []],
    ], 'blocks' => ['no-hash-sign' => 'two', 'a#0' => 'ghost']]);

    expect(implode("\n", $bad))->toContain('has no name', 'lower-case letters', 'not 12 hex characters', 'repeats the slug two', 'must be <page slug>#<block index>', 'names the group "ghost"');
});

it('turns an answer\'s HTML into paragraph, list and heading blocks', function () {
    $blocks = pkde_faq_html_to_blocks('<p>Cold wash.</p><ul><li>No bleach</li><li>Line dry</li></ul><ol><li>One</li></ol><h2>Note</h2>');

    expect($blocks)->toContain('<!-- wp:paragraph -->', '<p>Cold wash.</p>', '<!-- wp:list -->', '<!-- wp:list-item -->', '<li>No bleach</li>', '<!-- wp:list {"ordered":true} -->', '<ol class="wp-block-list">', '<!-- wp:heading -->')
        ->and(pkde_faq_html_to_blocks('Plain text answer'))->toContain('<p>Plain text answer</p>')
        ->and(pkde_faq_html_to_blocks('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'))->toBe('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->')
        ->and(pkde_faq_html_to_blocks(''))->toBe('');
});

it('finds theme files that look like a hand-coded FAQ', function () {
    $dir = sys_get_temp_dir() . '/pkde-faq-' . bin2hex(random_bytes(4));
    mkdir("$dir/resources/views", 0777, true);
    file_put_contents("$dir/resources/views/faq.blade.php", '<div class="faq-list">...</div>');
    file_put_contents("$dir/resources/views/schema.blade.php", '{"@type":"FAQPage"}');
    file_put_contents("$dir/resources/views/home.blade.php", '<h1>Home</h1>');

    expect(pkde_faq_blade_files($dir))->toBe(['resources/views/faq.blade.php', 'resources/views/schema.blade.php']);
});

it('ships the script with the two steps, the refusals and the revision kept', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/bin/deploy/faq-migrate.php');

    expect($source)->toContain('plan needs out=PATH', 'Refusing to run: apply uses only an approved mapping file', 'pkde_faq_mapping_problems', 'wp_save_post_revision', '_pkde_faq_hash', 'kses_remove_filters');
});
