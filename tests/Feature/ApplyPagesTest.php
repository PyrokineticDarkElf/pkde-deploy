<?php

/**
 * bin/deploy/apply-pages.php (from bb's script, BB-56): the pure parts in apply-pages-lib.php. The two steps against a real WordPress (plan, apply, a second plan, a missing page,
 * a missing picture, a form placeholder) were run on the template stack; the report says so.
 */
require_once dirname(__DIR__, 2) . '/bin/deploy/apply-pages-lib.php';

it('takes the file name of an uploads URL and ignores any other', function () {
    expect(pkde_pages_upload_name('http://localhost:8102/app/uploads/2023/04/photo-one.jpg'))->toBe('photo-one.jpg')
        ->and(pkde_pages_upload_name('/app/uploads/photo.png?x=1'))->toBe('photo.png')
        ->and(pkde_pages_upload_name('https://example.test/images/a.jpg'))->toBe('')
        ->and(pkde_pages_upload_name(null))->toBe('')
        ->and(pkde_pages_slug('/site/content/pages/about-us.html'))->toBe('about-us');
});

it('adds the attachment id to the blocks that carry a picture, nested ones included, and collects what has no attachment', function () {
    $known = ['hero.jpg' => 11, 'a.jpg' => 21, 'b.jpg' => 22];
    $lookup = fn(string $file) => $known[$file] ?? 0;
    $blocks = [
        ['blockName' => 'sage/hero', 'attrs' => ['imageUrl' => '/app/uploads/2023/hero.jpg', 'heading' => 'x'], 'innerBlocks' => [], 'innerHTML' => ''],
        ['blockName' => 'sage/section', 'attrs' => [], 'innerHTML' => '', 'innerBlocks' => [
            ['blockName' => 'sage/text-and-image', 'attrs' => ['imageUrl' => '/app/uploads/2023/gone.jpg', 'extraImages' => [['url' => '/app/uploads/a.jpg'], ['url' => '/app/uploads/b.jpg'], ['url' => '/app/uploads/nope.jpg']]], 'innerBlocks' => [], 'innerHTML' => ''],
            ['blockName' => 'core/image', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<figure><img src="http://x.test/app/uploads/2023/missing-core.jpg" alt=""/></figure>'],
            ['blockName' => 'core/image', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<figure><img src="http://x.test/app/uploads/hero.jpg" alt=""/></figure>'],
        ]],
    ];
    $missing = [];
    $mapped = pkde_pages_map_images($blocks, $lookup, $missing);

    expect($mapped[0]['attrs']['imageId'])->toBe(11)
        ->and($mapped[0]['attrs']['heading'])->toBe('x')
        ->and($mapped[1]['innerBlocks'][0]['attrs'])->not->toHaveKey('imageId') // no attachment for gone.jpg
        ->and($mapped[1]['innerBlocks'][0]['attrs']['extraImages'][0]['id'])->toBe(21)
        ->and($mapped[1]['innerBlocks'][0]['attrs']['extraImages'][1]['id'])->toBe(22)
        ->and($mapped[1]['innerBlocks'][0]['attrs']['extraImages'][2])->not->toHaveKey('id')
        ->and(array_keys($missing))->toBe(['gone.jpg', 'nope.jpg', 'missing-core.jpg']);
});

it('finds the form placeholders of a page file', function () {
    expect(pkde_pages_form_keys('<!-- wp:cf7 {"id":"{{form:contact}}"} /--> and {{form:park-home-enquiry}} and again {{form:contact}}'))->toBe(['contact', 'park-home-enquiry'])
        ->and(pkde_pages_form_keys('no placeholders'))->toBe([]);
});

it('says how it works: plan by default, by slug, a revision, never creating a page, content/ then config/content', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/bin/deploy/apply-pages.php');

    expect($source)->toContain("'mode' => 'plan'", 'wp_save_post_revision', "'name' => pkde_pages_slug(\$file)", "'content/pages', 'config/content/pages'", 'page missing', 'matches', "kses_remove_filters")
        ->and(substr_count($source, 'wp_insert_post('))->toBe(1)->and($source)->toContain("'post_type' => 'pkde_legal', 'post_status' => 'draft'"); // it never creates a page: the one insert is a DRAFT legal document (TPL-268)
});

it('finds the FAQ group placeholders in a page file, once each (TPL-258)', function () {
    expect(pkde_pages_faq_group_slugs('<!-- wp:sage/faq {"groups":[{{faq-group:general-questions}},{{faq-group:Delivery}}],"order":"manual"} /--> {{faq-group:general-questions}}'))->toBe(['general-questions', 'delivery'])
        ->and(pkde_pages_faq_group_slugs('no placeholders'))->toBe([]);

    $source = file_get_contents(dirname(__DIR__, 2) . '/bin/deploy/apply-pages.php');
    expect($source)->toContain('{{faq-group:', 'pkde_faq_group', 'no FAQ group with the slug');
});

it('names the blocks a legal document may not hold, from the nested tree too (TPL-261)', function () {
    $tree = [
        ['blockName' => 'core/heading', 'innerBlocks' => []],
        ['blockName' => null, 'innerBlocks' => []], // the filler between blocks
        ['blockName' => 'core/group', 'innerBlocks' => [['blockName' => 'core/paragraph', 'innerBlocks' => []], ['blockName' => 'core/cover', 'innerBlocks' => []]]],
        ['blockName' => 'sage/section', 'innerBlocks' => []],
        ['blockName' => 'core/cover', 'innerBlocks' => []],
    ];

    expect(pkde_pages_disallowed_blocks($tree, PKDE_PAGES_LEGAL_BLOCKS))->toBe(['core/group', 'core/cover', 'sage/section'])
        ->and(pkde_pages_disallowed_blocks([['blockName' => 'core/details', 'innerBlocks' => [['blockName' => 'core/list', 'innerBlocks' => [['blockName' => 'core/list-item', 'innerBlocks' => []]]]]]], PKDE_PAGES_LEGAL_BLOCKS))->toBe([])
        ->and(PKDE_PAGES_LEGAL_BLOCKS)->toContain('core/table', 'core/quote', 'core/separator');

    $source = file_get_contents(dirname(__DIR__, 2) . '/bin/deploy/apply-pages.php');
    expect($source)->toContain('content/legal', "'pkde_legal'", 'not allowed in a legal document', 'legal-migrate apply converts the Page first');
});


it('reads the front matter and the sidecar of a legal file, and says what is wrong with them (TPL-268)', function () {
    $file = "<!-- pkde-legal {\"type\": \"privacy\", \"title\": \"Privacy policy\", \"effective\": \"2026-11-01\"} -->\n<!-- wp:paragraph -->\n<p>Text.</p>\n<!-- /wp:paragraph -->\n";
    $r = pkde_pages_legal_front_matter($file);

    expect($r['meta'])->toBe(['type' => 'privacy', 'title' => 'Privacy policy', 'effective' => '2026-11-01'])
        ->and($r['content'])->toStartWith('<!-- wp:paragraph -->')->and($r['content'])->not->toContain('pkde-legal')
        ->and($r['problem'])->toBe('');

    // a sidecar gives the type; the front matter wins where both say something
    $both = pkde_pages_legal_front_matter("<!-- pkde-legal {\"title\": \"From the file\"} -->\n<p>x</p>", ['type' => 'cookies', 'title' => 'From the sidecar', 'effective' => '2026-02-03']);
    expect($both['meta'])->toBe(['type' => 'cookies', 'title' => 'From the file', 'effective' => '2026-02-03']);

    // none: nothing to create from
    expect(pkde_pages_legal_front_matter('<p>x</p>')['meta']['type'])->toBe('')
        ->and(pkde_pages_legal_front_matter("<!-- pkde-legal {\"type\": \"nonsense\"} -->\n<p>x</p>")['problem'])->toContain('is not one of')
        ->and(pkde_pages_legal_front_matter("<!-- pkde-legal {\"type\": \"terms\", \"effective\": \"20/11/2026\"} -->")['problem'])->toContain('YYYY-MM-DD')
        ->and(pkde_pages_legal_front_matter('<!-- pkde-legal {not json} -->')['problem'])->toContain('not valid JSON')
        ->and(pkde_pages_title_from_slug('privacy-policy'))->toBe('Privacy policy');

    $source = file_get_contents(dirname(__DIR__, 2) . '/bin/deploy/apply-pages.php');
    expect($source)->toContain("'post_status' => 'draft'", "'would create'", 'sanitize_title($d->post_title)', '_pkde_legal_type');
});
