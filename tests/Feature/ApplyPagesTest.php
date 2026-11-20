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

    expect($source)->toContain("'mode' => 'plan'", 'wp_save_post_revision', "'name' => \$slug", "'content/pages', 'config/content/pages'", 'page missing', 'matches', "kses_remove_filters")
        ->and($source)->not->toContain('wp_insert_post'); // it never creates a page
});
