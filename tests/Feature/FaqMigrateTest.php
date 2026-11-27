<?php

/**
 * bin/deploy/faq-migrate.php, the pure parts in faq-migrate-lib.php. The two steps against a real WordPress (seeded old-style pages, plan, apply, a second apply,
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

/** A core Details block as parse_blocks returns it: the summary lives in the HTML, the answer in the inner blocks. */
function pkdeDetails(string $question, string $answer): array
{
    return ['blockName' => 'core/details', 'attrs' => [], 'innerBlocks' => [['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "<p>$answer</p>", 'innerContent' => ["<p>$answer</p>"]]],
        'innerHTML' => "\n<details class=\"wp-block-details\"><summary>$question</summary>\n\n</details>\n", 'innerContent' => ["\n<details class=\"wp-block-details\"><summary>$question</summary>\n", null, "\n</details>\n"]];
}

function pkdeHeading(string $text, int $level = 2): array
{
    return ['blockName' => 'core/heading', 'attrs' => ['level' => $level], 'innerBlocks' => [], 'innerHTML' => "<h$level class=\"wp-block-heading\">$text</h$level>", 'innerContent' => ["<h$level class=\"wp-block-heading\">$text</h$level>"]];
}

function pkdeFiller(): array
{
    return ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "\n\n", 'innerContent' => ["\n\n"]];
}

it('recognises a heading that introduces FAQs', function () {
    foreach (['F.A.Q.', 'FAQ', 'FAQs', 'Frequently asked questions', 'Questions', 'Delivery questions'] as $text) {
        expect(pkde_faq_heading_matches($text))->toBeTrue($text);
    }

    expect(pkde_faq_heading_matches('Our story'))->toBeFalse()->and(pkde_faq_heading_matches('Contact us'))->toBeFalse();
});

it('finds core Details blocks under an F.A.Q. heading as one FAQ block (tcs: five on the contact page), the heading becoming the block heading', function () use ($render, $serialize) {
    $blocks = [
        ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<p>Call us.</p>'],
        pkdeFiller(),
        pkdeHeading('F.A.Q.'),
        pkdeFiller(),
        pkdeDetails('How long is delivery?', 'Two to three days.'),
        pkdeFiller(),
        pkdeDetails('Do you ship abroad?', 'Yes, to Europe.'),
        pkdeDetails('Can I pay by card?', 'Yes.'),
        pkdeDetails('Is it guaranteed?', 'For two years.'),
        pkdeDetails('Can I return it?', 'Within 30 days.'),
        pkdeFiller(),
        ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<p>Thanks.</p>'],
    ];

    $rows = pkde_faq_inventory_rows([['slug' => 'contact-us', 'blocks' => $blocks]], $render, $serialize);

    expect($rows)->toHaveCount(6)
        ->and($rows[1])->toBe(['contact-us', '0', 'F.A.Q.', 'How long is delivery?', 'Two to three days.', pkde_faq_hash('How long is delivery?'), 'details'])
        ->and(array_column($rows, 3))->toBe(['question', 'How long is delivery?', 'Do you ship abroad?', 'Can I pay by card?', 'Is it guaranteed?', 'Can I return it?'])
        ->and(pkde_faq_loose_details($blocks))->toBe(0);

    $new = pkde_faq_walk($blocks, function ($block, $kind, $i) {
        expect($kind)->toBe('details')->and($i)->toBe(0)->and($block['attrs'])->toBe(['heading' => 'F.A.Q.', 'consumesHeading' => true])->and($block['innerBlocks'])->toHaveCount(5);

        return ['blockName' => 'sage/faq', 'attrs' => ['groups' => [7]], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
    });
    $names = array_map(fn($b) => $b['blockName'] ?? '(filler)', $new);

    // the heading and the five Details became one FAQ block; the paragraphs around them stay
    expect($names)->toBe(['core/paragraph', '(filler)', '(filler)', 'sage/faq', '(filler)', 'core/paragraph'])->and(array_filter($new, fn($b) => ($b['blockName'] ?? '') === 'core/details'))->toBe([]);
});

it('also finds Details blocks inside a Group or Section whose heading matches, leaving that heading where it is', function () use ($render, $serialize) {
    $blocks = [['blockName' => 'sage/section', 'attrs' => [], 'innerHTML' => '', 'innerBlocks' => [
        pkdeHeading('Frequently asked questions'),
        ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<p>Some words first.</p>'],
        pkdeDetails('One?', 'a'),
        pkdeDetails('Two?', 'b'),
    ]]];

    $new = pkde_faq_walk($blocks, function ($block, $kind) {
        expect($block['attrs']['consumesHeading'])->toBeFalse()->and($block['attrs']['heading'])->toBe('Frequently asked questions');

        return ['blockName' => 'sage/faq', 'attrs' => ['groups' => [1]], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
    });

    expect(array_column($new[0]['innerBlocks'], 'blockName'))->toBe(['core/heading', 'core/paragraph', 'sage/faq'])
        ->and(pkde_faq_inventory_rows([['slug' => 'faq-page', 'blocks' => $blocks]], $render, $serialize))->toHaveCount(3);
});

it('leaves Details blocks elsewhere alone and counts them as loose, for a person to check', function () use ($render, $serialize) {
    $blocks = [
        pkdeHeading('Our materials'),
        pkdeDetails('Is it organic?', 'Yes.'),
        pkdeDetails('Is it soft?', 'Very.'),
        ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<p>x</p>'],
        pkdeDetails('Another one?', 'Yes.'),
    ];

    $visited = false;
    $new = pkde_faq_walk($blocks, function () use (&$visited) {
        $visited = true;

        return null;
    });

    expect($visited)->toBeFalse()->and($new)->toBe($blocks)->and(pkde_faq_loose_details($blocks))->toBe(2) // two runs
        ->and(pkde_faq_inventory_rows([['slug' => 'materials', 'blocks' => $blocks]], $render, $serialize))->toHaveCount(1); // the header only
});

it('reads a synced pattern like a page and finds where it is placed (el: a wp_block of ten Details blocks)', function () use ($render, $serialize) {
    $details = [];
    foreach (range(1, 10) as $n) {
        $details[] = pkdeDetails("Solar question $n?", "Answer $n.");
    }

    $rows = pkde_faq_inventory_rows([['slug' => 'el-faq-solar-systems', 'blocks' => array_merge([pkdeHeading('Solar systems FAQ')], $details)]], $render, $serialize);

    expect($rows)->toHaveCount(11)->and($rows[1][0])->toBe('el-faq-solar-systems')->and($rows[1][2])->toBe('Solar systems FAQ')->and($rows[10][3])->toBe('Solar question 10?');

    expect(pkde_faq_content_uses_block('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- wp:block {"ref":4242} /-->', 4242))->toBeTrue()
        ->and(pkde_faq_content_uses_block('<!-- wp:block {"ref":42420} /-->', 4242))->toBeFalse()
        ->and(pkde_faq_content_uses_block('<!-- wp:paragraph --><p>{"ref":4242}</p>', 4242))->toBeFalse();

    $dir = sys_get_temp_dir() . '/pkde-faq-blk-' . bin2hex(random_bytes(4));
    mkdir("$dir/resources/views", 0777, true);
    file_put_contents("$dir/resources/views/category.blade.php", "{!! do_blocks(get_post(4242)->post_content) !!}");
    file_put_contents("$dir/resources/views/slug.blade.php", "{{-- el-faq-solar-systems --}}");
    file_put_contents("$dir/resources/views/other.blade.php", "{!! do_blocks(\$x) !!} 99");

    expect(pkde_faq_files_printing_block($dir, 4242, 'el-faq-solar-systems'))->toBe(['resources/views/category.blade.php', 'resources/views/slug.blade.php']);
});

it('says in the script how details, synced patterns and loose Details are handled', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/bin/deploy/faq-migrate.php');

    expect($source)->toContain('PKDE_FAQ_KIND_DETAILS', "'wp_block'", 'used by:', 'CHECK BY HAND', 'pkde_faq_loose_details', "post_content LIKE '%wp:details%'", 'consumesHeading');
});

/** a FAQ heading anywhere in the nearest Section (or top-level Group) heads a Details run that has no heading of its own. */
$pkdeGroup = fn(array $inner, array $attrs = []) => ['blockName' => 'core/group', 'attrs' => $attrs, 'innerBlocks' => $inner, 'innerHTML' => '', 'innerContent' => []];
$pkdePara = fn(string $t) => ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "<p>$t</p>"];
$pkdeFaq = ['blockName' => 'sage/faq', 'attrs' => ['groups' => [1]], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];

it('el: a heading in a SIBLING Group of the same Section heads the Details run; the heading group keeps its intro and stays', function () use ($pkdeGroup, $pkdePara, $pkdeFaq) {
    $details = array_map(fn($n) => pkdeDetails("Question $n?", "Answer $n."), range(1, 10));
    $blocks = [['blockName' => 'sage/section', 'attrs' => [], 'innerHTML' => '', 'innerContent' => [], 'innerBlocks' => [
        $pkdeGroup([pkdeHeading('Frequently Asked Questions'), $pkdePara('Straight answers.')]),
        $pkdeGroup($details),
    ]]];

    $seen = null;
    $new = pkde_faq_walk($blocks, function ($block) use (&$seen, $pkdeFaq) {
        $seen = $block['attrs'];

        return $pkdeFaq;
    });

    // one run, the heading does not move (the wrapper holds an intro paragraph too), so the block prints none
    expect($seen)->toBe(['heading' => 'Frequently Asked Questions', 'consumesHeading' => false])
        ->and($new[0]['innerBlocks'][0]['innerBlocks'])->toHaveCount(2)
        ->and(array_column($new[0]['innerBlocks'][1]['innerBlocks'], 'blockName'))->toBe(['sage/faq'])
        ->and(pkde_faq_loose_details($blocks))->toBe(0);
});

it('tcs: a heading two levels down in an intro Group beside the questions Group heads the run; the intro stays because it holds more than the heading', function () use ($pkdeGroup, $pkdePara, $pkdeFaq) {
    $blocks = [['blockName' => 'sage/section', 'attrs' => [], 'innerHTML' => '', 'innerContent' => [], 'innerBlocks' => [
        $pkdeGroup([
            $pkdeGroup([$pkdeGroup([pkdeHeading('F.A.Q.'), pkdeHeading('General Questions')], ['layout' => ['type' => 'flex']]), $pkdePara('Ask away.')]),
            $pkdeGroup(array_map(fn($n) => pkdeDetails("Question $n?", "Answer $n."), range(1, 5))),
        ], ['layout' => ['type' => 'grid']]),
    ]]];

    $seen = [];
    $new = pkde_faq_walk($blocks, function ($block) use (&$seen, $pkdeFaq) {
        $seen[] = $block['attrs'];

        return $pkdeFaq;
    });
    $grid = $new[0]['innerBlocks'][0]['innerBlocks'];

    expect($seen)->toBe([['heading' => 'F.A.Q.', 'consumesHeading' => false]]) // the FIRST matching heading; "General Questions" is not used
        ->and($grid[0]['innerBlocks'][0]['innerBlocks'])->toHaveCount(2) // both headings still there
        ->and(array_column($grid[1]['innerBlocks'], 'blockName'))->toBe(['sage/faq'])
        ->and(json_encode($new))->not->toContain('pkdeScopeWrapper');
});

it('removes the wrapper Group of the heading only when it held nothing else, and the FAQ block takes the heading', function () use ($pkdeGroup, $pkdeFaq) {
    $blocks = [['blockName' => 'sage/section', 'attrs' => [], 'innerHTML' => '', 'innerContent' => [], 'innerBlocks' => [
        $pkdeGroup([$pkdeGroup([pkdeHeading('Questions')])]),
        $pkdeGroup([pkdeDetails('One?', 'a'), pkdeDetails('Two?', 'b')]),
    ]]];

    $seen = null;
    $new = pkde_faq_walk($blocks, function ($block) use (&$seen, $pkdeFaq) {
        $seen = $block['attrs'];

        return $pkdeFaq;
    });

    expect($seen)->toBe(['heading' => 'Questions', 'consumesHeading' => true])
        ->and($new[0]['innerBlocks'])->toHaveCount(1) // the wrapper with only the heading is gone
        ->and($new[0]['innerBlocks'][0]['innerBlocks'][0]['blockName'])->toBe('sage/faq');

    // a plan (nothing replaced) leaves every block, markers included, untouched
    expect(pkde_faq_walk($blocks, fn() => null))->toBe($blocks);
});

it('does not use a heading that comes AFTER the run, one inside a Details block, or one outside the Section; a nearer heading wins', function () use ($pkdeGroup, $pkdePara, $pkdeFaq) {
    $after = [['blockName' => 'sage/section', 'attrs' => [], 'innerHTML' => '', 'innerContent' => [], 'innerBlocks' => [
        $pkdeGroup([pkdeDetails('One?', 'a')]),
        $pkdeGroup([pkdeHeading('FAQ')]),
    ]]];
    $outside = [pkdeHeading('FAQ'), ['blockName' => 'sage/section', 'attrs' => [], 'innerHTML' => '', 'innerContent' => [], 'innerBlocks' => [$pkdeGroup([pkdeDetails('One?', 'a')])]]];
    $insideDetails = pkdeDetails('One?', 'a');
    $insideDetails['innerBlocks'][] = pkdeHeading('More questions');
    $inDetails = [['blockName' => 'sage/section', 'attrs' => [], 'innerHTML' => '', 'innerContent' => [], 'innerBlocks' => [$pkdeGroup([$insideDetails])]]];

    foreach ([$after, $outside, $inDetails] as $blocks) {
        $visited = false;
        pkde_faq_walk($blocks, function () use (&$visited) {
            $visited = true;

            return null;
        });
        expect($visited)->toBeFalse()->and(pkde_faq_loose_details($blocks))->toBe(1);
    }

    // a heading directly in front of the run beats the Section's first one
    $near = [['blockName' => 'sage/section', 'attrs' => [], 'innerHTML' => '', 'innerContent' => [], 'innerBlocks' => [
        $pkdeGroup([pkdeHeading('Frequently asked questions')]),
        $pkdeGroup([pkdeHeading('Delivery questions'), pkdeDetails('One?', 'a')]),
    ]]];
    $seen = null;
    pkde_faq_walk($near, function ($block) use (&$seen, $pkdeFaq) {
        $seen = $block['attrs'];

        return $pkdeFaq;
    });
    expect($seen)->toBe(['heading' => 'Delivery questions', 'consumesHeading' => true]);
});

it('uses the top-level Group as the scope when there is no Section', function () use ($pkdeGroup, $pkdeFaq) {
    $blocks = [$pkdeGroup([$pkdeGroup([pkdeHeading('F.A.Q.'), pkdeHeading('x')]), $pkdeGroup([pkdeDetails('One?', 'a')])])];
    $seen = null;
    pkde_faq_walk($blocks, function ($block) use (&$seen, $pkdeFaq) {
        $seen = $block['attrs'];

        return $pkdeFaq;
    });

    expect($seen['heading'])->toBe('F.A.Q.');
});

it('keeps a Group serialisable when a run of Details inside it becomes one block (innerContent gets one slot per inner block)', function () use ($pkdeFaq) {
    $group = ['blockName' => 'core/group', 'attrs' => [], 'innerHTML' => '', 'innerBlocks' => [pkdeHeading('FAQ'), pkdeDetails('One?', 'a'), pkdeDetails('Two?', 'b')],
        'innerContent' => ["\n<div class=\"wp-block-group\">", null, "\n", null, "\n", null, "</div>\n"]];

    $new = pkde_faq_walk([$group], fn() => $pkdeFaq);

    expect($new[0]['innerBlocks'])->toHaveCount(1) // the heading and both Details became the one block
        ->and($new[0]['innerContent'])->toBe(["\n<div class=\"wp-block-group\">", null, "</div>\n"])
        ->and(count(array_filter($new[0]['innerContent'], fn($c) => $c === null)))->toBe(count($new[0]['innerBlocks']));

    // the section whose wrapper Group is removed keeps one slot per remaining block
    $section = ['blockName' => 'sage/section', 'attrs' => [], 'innerHTML' => '', 'innerBlocks' => [
        ['blockName' => 'core/group', 'attrs' => [], 'innerHTML' => '', 'innerContent' => ['<div>', null, '</div>'], 'innerBlocks' => [pkdeHeading('Questions')]],
        ['blockName' => 'core/group', 'attrs' => [], 'innerHTML' => '', 'innerContent' => ['<div>', null, "\n", null, '</div>'], 'innerBlocks' => [pkdeDetails('One?', 'a'), pkdeDetails('Two?', 'b')]],
    ], 'innerContent' => ['<section>', null, "\n", null, '</section>']];
    $new = pkde_faq_walk([$section], fn() => $pkdeFaq);

    expect($new[0]['innerBlocks'])->toHaveCount(1)->and($new[0]['innerContent'])->toBe(['<section>', null, '</section>'])
        ->and($new[0]['innerBlocks'][0]['innerContent'])->toBe(['<div>', null, '</div>']);
});
