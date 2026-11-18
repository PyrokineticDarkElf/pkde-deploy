<?php

// bin/deploy/seo-sitemap-exclusions.php needs WordPress, WooCommerce and Rank Math, so it runs here in a separate PHP process against small stand-ins
// (a global stand-in for get_option would make drift-check.php, loaded by another test, believe WordPress is there).
function pkdeRunSitemapExclusions(array $store, bool $woo = true): array
{
    $prelude = tempnam(sys_get_temp_dir(), 'pkde-sx');
    $script = var_export(dirname(__DIR__, 2) . '/bin/deploy/seo-sitemap-exclusions.php', true);
    $wooStub = $woo ? 'function wc_get_page_id($p) { return ["cart" => 65, "checkout" => 66, "myaccount" => 67, "shop" => 64][$p] ?? -1; }' : '';
    file_put_contents($prelude, '<?php
namespace RankMath\\Sitemap {
    class Cache { public static function invalidate_storage() { echo "[cache dropped]\\n"; } }
}
namespace {
    $GLOBALS["opts"] = ' . var_export($store, true) . ';
    function get_option($k, $d = false) { return $GLOBALS["opts"][$k] ?? $d; }
    function update_option($k, $v) { $GLOBALS["opts"][$k] = $v; return true; }
    function wp_parse_id_list($l) { return array_values(array_unique(array_filter(array_map("intval", is_array($l) ? $l : preg_split("/[\\s,]+/", (string) $l))))); }
    ' . $wooStub . '
    include ' . $script . ';
    echo json_encode($GLOBALS["opts"]["rank-math-options-sitemap"] ?? null), "\\n";
}
');
    $out = shell_exec('php ' . escapeshellarg($prelude) . ' 2>&1');
    unlink($prelude);

    return explode("\n", trim((string) $out));
}

it('excludes cart, checkout and my-account, keeps the pages already excluded and drops the cached sitemap', function () {
    $out = pkdeRunSitemapExclusions(['rank-math-options-sitemap' => ['items_per_page' => 200, 'exclude_posts' => '12, 66']]);

    expect(implode("\n", $out))->toContain('[cache dropped]')->toContain('3 WooCommerce page(s) excluded')->toContain('(2 added)')
        ->and(end($out))->toBe('{"items_per_page":200,"exclude_posts":"12, 66, 65, 67"}');
});

it('changes nothing the second time and does nothing without WooCommerce', function () {
    $again = pkdeRunSitemapExclusions(['rank-math-options-sitemap' => ['exclude_posts' => '65, 66, 67']]);
    expect(implode("\n", $again))->toContain('(0 added)')->and(end($again))->toBe('{"exclude_posts":"65, 66, 67"}');

    $none = pkdeRunSitemapExclusions([], false);
    expect(implode("\n", $none))->toContain('nothing to exclude');
});
