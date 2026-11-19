<?php

// TPL-201: rankmath-baseline.php only=titles|schema|share-image applies one part of the baseline. The script needs WordPress and Rank Math, so it runs in a
// separate PHP process against small stand-ins (a global stand-in for get_option would make drift-check.php, loaded by another test, believe WordPress is there).
require_once dirname(__DIR__, 2) . '/bin/deploy/rankmath-standard.php';

function pkdeRunBaseline(array $titles, array $args): array
{
    $prelude = tempnam(sys_get_temp_dir(), 'pkde-rb');
    $script = var_export(dirname(__DIR__, 2) . '/bin/deploy/rankmath-baseline.php', true);
    file_put_contents($prelude, '<?php
namespace RankMath { class Helper {} }
namespace {
    $GLOBALS["opts"] = ["rank-math-options-titles" => ' . var_export($titles, true) . ', "blogname" => "Clocks Ltd", "WPLANG" => "en_GB"];
    function get_option($k, $d = false) { return $GLOBALS["opts"][$k] ?? $d; }
    function update_option($k, $v) { $GLOBALS["opts"][$k] = $v; return true; }
    function get_theme_mod($k) { return 0; }
    function wp_get_attachment_image_url($id, $size) { return ""; }
    function post_type_exists($t) { return in_array($t, ["page", "post", "product"], true); }
    $args = ' . var_export($args, true) . ';
    include ' . $script . ';
    echo "\n", json_encode($GLOBALS["opts"]["rank-math-options-titles"]), "\n";
}
');
    $out = shell_exec('php ' . escapeshellarg($prelude) . ' 2>&1');
    unlink($prelude);

    return explode("\n", trim((string) $out));
}

it('parses only= into parts and names what is not a part', function () {
    expect(pkde_rankmath_only([]))->toBe(['only' => [], 'unknown' => []])
        ->and(pkde_rankmath_only(['only=titles']))->toBe(['only' => ['titles'], 'unknown' => []])
        ->and(pkde_rankmath_only(['other', 'only=titles, schema,titles']))->toBe(['only' => ['titles', 'schema'], 'unknown' => []])
        ->and(pkde_rankmath_only(['only=title,share-image']))->toBe(['only' => ['share-image'], 'unknown' => ['title']])
        ->and(pkde_rankmath_part_of('tax_category_title'))->toBe('titles')
        ->and(pkde_rankmath_part_of('pt_post_default_article_type'))->toBe('schema')
        ->and(pkde_rankmath_part_of('open_graph_image'))->toBe('share-image')
        ->and(pkde_rankmath_part_of('knowledgegraph_type'))->toBeNull();
});

it('applies only the title part: Archives off the taxonomy titles, nothing else touched', function () {
    $titles = ['tax_category_title' => '%term% Archives %sep% %sitename%', 'pt_post_title' => '%title% %sep% %sitename% %sep% %sitename%', 'knowledgegraph_type' => 'person', 'pt_post_default_rich_snippet' => 'off'];
    $plan = pkde_rankmath_baseline($titles, ['page', 'post', 'product'], ['name' => 'Clocks Ltd', 'logo_url' => '', 'logo_id' => 0], ['titles']);

    expect($plan)->toBe(['tax_category_title' => '%term% %sep% %sitename%', 'pt_post_title' => '%title% %sep% %sitename%']);

    // with no part: the whole baseline, as before (company, name, schema and the titles)
    $all = pkde_rankmath_baseline($titles, ['page', 'post', 'product'], ['name' => 'Clocks Ltd', 'logo_url' => '', 'logo_id' => 0]);
    expect($all)->toHaveKeys(['tax_category_title', 'pt_post_title', 'knowledgegraph_type', 'knowledgegraph_name', 'pt_post_default_rich_snippet']);
});

it('runs as a script with only=titles: one option written, key names printed, a second run changes nothing', function () {
    $titles = ['tax_category_title' => '%term% Archives %sep% %sitename%', 'knowledgegraph_type' => 'person'];
    $first = pkdeRunBaseline($titles, ['only=titles']);

    expect($first[0])->toBe('1 Rank Math setting(s) set: tax_category_title.')
        ->and($first[1])->toContain('Only titles was applied')
        ->and(end($first))->toBe('{"tax_category_title":"%term% %sep% %sitename%","knowledgegraph_type":"person"}');

    $again = pkdeRunBaseline(json_decode(end($first), true), ['only=titles']);
    expect($again[0])->toBe('0 Rank Math setting(s) set.');
});

it('refuses an unknown part and writes nothing', function () {
    $out = pkdeRunBaseline(['tax_category_title' => '%term% Archives'], ['only=titel']);

    expect($out[0])->toContain('Unknown part(s): titel')->and(end($out))->not->toContain('%sep%');
});
