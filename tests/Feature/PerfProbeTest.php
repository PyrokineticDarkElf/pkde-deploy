<?php

// bin/deploy/perf-probe.php returns early outside WordPress, so its pure functions load here without WordPress.
require_once dirname(__DIR__, 2) . '/bin/deploy/perf-probe.php';

it('groups loaded files by component', function (string $file, string $group) {
    expect(pkde_probe_group($file))->toBe($group);
})->with([
    ['/srv/site/web/app/plugins/woocommerce/includes/class-wc.php', 'plugin/woocommerce'],
    ['/srv/site/web/app/plugins/seo-by-rank-math/rank-math.php', 'plugin/seo-by-rank-math'],
    ['/srv/site/web/app/mu-plugins/bedrock-autoloader/bedrock-autoloader.php', 'mu-plugin/bedrock-autoloader'],
    ['/srv/site/web/app/mu-plugins/staging-mail-guard.php', 'mu-plugin/staging-mail-guard.php'],
    ['/srv/site/web/wp/wp-includes/class-wp-hook.php', 'core'],
    ['/srv/site/web/wp/wp-settings.php', 'core'],
    ['/srv/site/web/app/themes/sage/app/blocks.php', 'theme-app'],
    ['/srv/site/web/app/themes/sage/vendor/roots/acorn/src/Roots/Acorn/Application.php', 'theme-vendor/roots/acorn'],
    ['/srv/site/web/app/themes/sage/vendor/autoload.php', 'theme-vendor/autoload'],
    ['/srv/site/web/app/themes/sage/functions.php', 'theme-other'],
    ['/srv/site/vendor/roots/wordpress-no-content/wp-settings.php', 'vendor/roots/wordpress-no-content'],
    ['/srv/site/vendor/composer/ClassLoader.php', 'vendor/composer'],
    ['/srv/site/vendor/autoload.php', 'vendor/composer'],
    ['/srv/site/web/app/cache/acorn/framework/views/abc123.php', 'compiled-views'],
    ['/srv/site/config/application.php', 'other'],
    ['C:\\site\\web\\app\\plugins\\w3-total-cache\\w3-total-cache.php', 'plugin/w3-total-cache'],
]);

it('counts files per component, most files first, ties by name', function () {
    $files = [
        '/s/web/app/plugins/b/1.php', '/s/web/app/plugins/b/2.php', '/s/web/app/plugins/a/1.php', '/s/web/app/plugins/a/2.php',
        '/s/web/wp/wp-includes/x.php', '/s/web/wp/wp-includes/y.php', '/s/web/wp/wp-includes/z.php', '/s/config/application.php',
    ];

    expect(pkde_probe_group_counts($files))->toBe(['core' => 3, 'plugin/a' => 2, 'plugin/b' => 2, 'other' => 1])
        ->and(array_sum(pkde_probe_group_counts($files)))->toBe(count($files));
});

it('names callbacks readably', function () {
    expect(pkde_probe_callback_name('wp_default_scripts'))->toBe('wp_default_scripts')
        ->and(pkde_probe_callback_name(['WP_Widget_Factory', 'register']))->toBe('WP_Widget_Factory::register')
        ->and(pkde_probe_callback_name([new ArrayObject(), 'count']))->toBe('ArrayObject::count')
        ->and(pkde_probe_callback_name(fn() => 1))->toStartWith('closure@')->toContain('PerfProbeTest.php:');
});

it('finds the report comment in a page and ignores pages without one', function () {
    $report = json_encode(['stages_ms' => ['init' => 120]], JSON_HEX_TAG | JSON_HEX_AMP);

    expect(pkde_probe_extract("<html><body>x</body></html>\n<!-- perfprobe:$report -->\n")['stages_ms']['init'])->toBe(120)
        ->and(pkde_probe_extract('<html>a cached page</html>'))->toBeNull()
        ->and(pkde_probe_extract('<!-- perfprobe:not json -->'))->toBeNull();
    // a value that contains --> cannot end the comment early
    expect(pkde_probe_extract('<!-- perfprobe:' . json_encode(['x' => 'a --> b'], JSON_HEX_TAG | JSON_HEX_AMP) . ' -->')['x'])->toBe('a --> b');
});

function pkdeProbeRequest(string $kind, ?int $misses, int $init = 100): array
{
    $report = $misses === null ? null : [
        'stages_ms' => ['muplugins_loaded' => 20, 'plugins_loaded' => 80, 'init' => $init, 'template_redirect' => 130, 'shutdown' => 300],
        'opcache' => ['available' => true, 'misses' => $misses, 'cache_full' => false],
        'db' => ['queries' => 24, 'select1_avg_ms' => 0.4],
        'files' => ['at_template_redirect' => 400, 'at_end' => 430, 'groups' => ['core' => 100]],
        'memory' => ['peak_mb' => 32.0],
    ];

    return ['url' => 'https://example.test/', 'kind' => $kind, 'status' => 200, 'ttfb' => 0.31, 'total' => 0.35, 'report' => $report];
}

it('prints the table with the opcache misses added since the previous probed request', function () {
    $table = pkde_probe_table([
        pkdeProbeRequest('uncached 1', 5000),
        pkdeProbeRequest('uncached 2', 5012),
        pkdeProbeRequest('uncached 3', 5012),
        pkdeProbeRequest('cached', null),
    ]);
    $lines = explode("\n", trim($table));

    expect($lines)->toHaveCount(2 + 4)
        ->and($lines[0])->toContain('oc misses+')
        ->and($lines[2])->toContain('uncached 1')
        ->and(preg_split('/\s{2,}/', $lines[2])[13])->toBe('-')       // first probed request: no previous
        ->and(preg_split('/\s{2,}/', $lines[3])[13])->toBe('12')
        ->and(preg_split('/\s{2,}/', $lines[4])[13])->toBe('0')
        ->and($lines[5])->toContain('cached')->not->toContain('YES');
});

it('degrades when OPcache is not available', function () {
    $r = pkdeProbeRequest('uncached 1', 1);
    $r['report']['opcache'] = ['available' => false, 'reason' => 'opcache_get_status() is disabled'];
    $r['report']['init_top'] = [];
    $r['report']['http'] = [];
    $r['report']['memory'] += ['limit' => '128M', 'autoload_kb' => 90, 'autoload_count' => 300];

    $out = pkde_probe_table([$r]) . pkde_probe_details([$r]);

    expect($out)->toContain('NOT available (opcache_get_status() is disabled)')->toContain('outbound HTTP: none')->toContain('slowest init callbacks: none');
});

it('builds the report from the files the runner saved', function () {
    $dir = sys_get_temp_dir() . '/pkde-probe-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $report = json_encode(pkdeProbeRequest('uncached 1', 100)['report'] + ['init_top' => [['ms' => 12.5, 'callback' => 'plugins/x::init']], 'http' => [], 'php' => '8.3.1', 'sapi' => 'fpm-fcgi'], JSON_HEX_TAG);
    file_put_contents("$dir/001.meta", "https://example.test/|uncached 1|200|0.3|0.35\n");
    file_put_contents("$dir/001.body", "<html></html>\n<!-- perfprobe:$report -->\n");
    file_put_contents("$dir/002.meta", "https://example.test/|cached|200|0.02|0.03\n");
    file_put_contents("$dir/002.body", '<html>cached</html>');

    $out = pkde_probe_report($dir);

    expect($out)->toContain('uncached 1')->toContain('cached')->toContain('12.5ms plugins/x::init')->toContain('files at the end 430');
});
