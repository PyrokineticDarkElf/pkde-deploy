<?php

/**
 * TEMPORARY performance probe. Installed as the mu-plugin web/app/mu-plugins/zz-perf-probe.php by
 * bin/deploy/perf-probe.sh, which also generates the token, makes the requests, prints the table and DELETES the file again.
 * Do not leave it installed.
 *
 * It does nothing unless the request carries ?perfprobe=<token> and the token matches the one the runner wrote into the
 * constant below. A request that matches gets an HTML comment appended to the page: `<!-- perfprobe:{json} -->` with
 *   - stage timings in ms since REQUEST_TIME_FLOAT: mu_start (this file ran), muplugins_loaded, plugins_loaded (9999),
 *     init (9999), template_redirect, shutdown (the final output flush);
 *   - OPcache: status and configuration (memory, used, free, wasted, cache_full, scripts, hits, misses, interned strings,
 *     validate_timestamps); `available: false` and a reason where the host disables the functions (the runner reports the
 *     misses delta between two requests);
 *   - included files: total and grouped by component, counted at template_redirect AND at the end of the response;
 *   - the 8 slowest init callbacks (every callback on `init` is wrapped at PHP_INT_MIN);
 *   - the database: average of 20 `SELECT 1` round trips and the number of queries the request had made;
 *   - outbound HTTP calls (host, status, ms);
 *   - memory_limit, the peak, and the size of the autoloaded options.
 *
 * A deleted PHP file can keep answering for about 10 s on 20i (OPcache validate_timestamps is 10 s and the NFS attribute cache adds to it),
 * so this file is token-gated and prints only timings and counts, never a secret, a path of a user or a credential: the same rule applies to
 * any temporary PHP file in a docroot.
 *
 * Outside WordPress (the Pest test, or `php perf-probe.php report <dir>` from the runner) only the pure functions below load.
 * No secrets, and nothing is written anywhere.
 */

// The runner replaces the placeholder on THIS line only (sed), so a copy that was never installed never matches a request.
const PKDE_PERFPROBE_TOKEN = '__PERFPROBE_TOKEN__';

/** The component a loaded file belongs to: plugin/<dir>, mu-plugin/<dir or file>, core, theme-app, theme-vendor/<pkg>, vendor/<pkg>, compiled-views. */
function pkde_probe_group(string $file): string
{
    $f = str_replace('\\', '/', $file);

    return match (true) {
        (bool) preg_match('#/app/cache/|/storage/framework/views/#', $f) => 'compiled-views',
        (bool) preg_match('#/app/plugins/([^/]+)/#', $f, $m) => 'plugin/' . $m[1],
        (bool) preg_match('#/app/mu-plugins/([^/]+)/#', $f, $m) => 'mu-plugin/' . $m[1],
        (bool) preg_match('#/app/mu-plugins/([^/]+\.php)$#', $f, $m) => 'mu-plugin/' . $m[1],
        (bool) preg_match('#/themes/[^/]+/vendor/([^/]+/[^/]+)/#', $f, $m) => 'theme-vendor/' . $m[1],
        (bool) preg_match('#/themes/[^/]+/vendor/#', $f) => 'theme-vendor/autoload',
        (bool) preg_match('#/themes/[^/]+/app/#', $f) => 'theme-app',
        (bool) preg_match('#/themes/[^/]+/#', $f) => 'theme-other',
        (bool) preg_match('#/vendor/([^/]+/[^/]+)/#', $f, $m) => 'vendor/' . $m[1],
        (bool) preg_match('#/vendor/(autoload\.php|composer/)#', $f) => 'vendor/composer',
        (bool) preg_match('#/(wp-includes|wp-admin)/|/wp/[^/]+\.php$#', $f) => 'core',
        default => 'other',
    };
}

/**
 * @param  list<string>  $files  get_included_files()
 * @return array<string,int> component => number of files, most files first
 */
function pkde_probe_group_counts(array $files): array
{
    $counts = [];
    foreach ($files as $file) {
        $key = pkde_probe_group($file);
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    uksort($counts, fn($a, $b) => [$counts[$b], $a] <=> [$counts[$a], $b]);

    return $counts;
}

/** A readable name for any callable: Class::method, function, or closure@file:line with the path trimmed to its WordPress part. */
function pkde_probe_callback_name(mixed $callback): string
{
    $trim = fn(string $path) => preg_replace('#^.*/(plugins|mu-plugins|themes|wp-includes|wp-admin|vendor)/#', '$1/', str_replace('\\', '/', $path));

    if (is_string($callback)) {
        return $callback;
    }
    if (is_array($callback)) {
        return (is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0]) . '::' . $callback[1];
    }
    if ($callback instanceof Closure) {
        $r = new ReflectionFunction($callback);

        return 'closure@' . $trim((string) $r->getFileName()) . ':' . $r->getStartLine();
    }

    return is_object($callback) ? get_class($callback) . '::__invoke' : 'unknown';
}

/** The report JSON a probed page carries, or null when the response has none (a cached page never reached PHP). */
function pkde_probe_extract(string $body): ?array
{
    if (! preg_match('/<!-- perfprobe:(\{.*?\}) -->/s', $body, $m)) {
        return null;
    }
    $data = json_decode($m[1], true);

    return is_array($data) ? $data : null;
}

/**
 * The results table the runner prints.
 *
 * @param  list<array{url:string,kind:string,status:int,ttfb:float,total:float,report:?array}>  $requests  in the order they were made
 */
function pkde_probe_table(array $requests): string
{
    $rows = [['URL', 'request', 'status', 'ttfb ms', 'total ms', 'mu', 'plugins', 'init', 'tmpl', 'shutdown', 'queries', 'db ms', 'files', 'oc misses+', 'oc full', 'peak MB']];
    $previousMisses = null;
    foreach ($requests as $r) {
        $rep = $r['report'];
        $s = $rep['stages_ms'] ?? [];
        $misses = $rep['opcache']['misses'] ?? null;
        $delta = ($misses !== null && $previousMisses !== null) ? (string) ($misses - $previousMisses) : '-';
        if ($misses !== null) {
            $previousMisses = $misses;
        }
        $rows[] = [
            $r['url'], $r['kind'], (string) $r['status'], (string) round($r['ttfb'] * 1000), (string) round($r['total'] * 1000),
            (string) ($s['muplugins_loaded'] ?? '-'), (string) ($s['plugins_loaded'] ?? '-'), (string) ($s['init'] ?? '-'),
            (string) ($s['template_redirect'] ?? '-'), (string) ($s['shutdown'] ?? '-'),
            (string) ($rep['db']['queries'] ?? '-'), (string) ($rep['db']['select1_avg_ms'] ?? '-'),
            isset($rep['files']) ? $rep['files']['at_end'] . '/' . $rep['files']['at_template_redirect'] : '-',
            $delta, isset($rep['opcache']['cache_full']) ? ($rep['opcache']['cache_full'] ? 'YES' : 'no') : '-',
            (string) ($rep['memory']['peak_mb'] ?? '-'),
        ];
    }

    $widths = [];
    foreach ($rows as $row) {
        foreach ($row as $i => $cell) {
            $widths[$i] = max($widths[$i] ?? 0, strlen($cell));
        }
    }
    $lines = array_map(fn($row) => rtrim(implode('  ', array_map(fn($cell, $i) => str_pad($cell, $widths[$i]), $row, array_keys($row)))), $rows);
    array_splice($lines, 1, 0, [str_repeat('-', strlen($lines[0]))]);

    return implode("\n", $lines) . "\n";
}

/** The detail of the last probed (uncached) request of each URL: slow init callbacks, files by component, HTTP calls, OPcache, limits. */
function pkde_probe_details(array $requests): string
{
    $last = [];
    foreach ($requests as $r) {
        if ($r['report'] !== null) {
            $last[$r['url']] = $r['report'];
        }
    }

    $out = '';
    $first = true;
    foreach ($last as $url => $rep) {
        $out .= "\n== $url (last uncached request)\n";
        $out .= 'slowest init callbacks: ' . ($rep['init_top'] ? implode(' | ', array_map(fn($c) => $c['ms'] . 'ms ' . $c['callback'], $rep['init_top'])) : 'none') . "\n";
        $groups = array_slice($rep['files']['groups'] ?? [], 0, 14, true);
        $out .= 'files at the end ' . ($rep['files']['at_end'] ?? '?') . ' (at template_redirect ' . ($rep['files']['at_template_redirect'] ?? '?') . '): '
            . implode(' | ', array_map(fn($k, $v) => "$v $k", array_keys($groups), $groups)) . "\n";
        $out .= 'outbound HTTP: ' . ($rep['http'] ? implode(', ', array_map(fn($h) => $h['ms'] . 'ms ' . $h['host'] . ' ' . $h['status'], $rep['http'])) : 'none') . "\n";
        $out .= 'memory: limit ' . ($rep['memory']['limit'] ?? '?') . ', peak ' . ($rep['memory']['peak_mb'] ?? '?') . ' MB, autoloaded options '
            . ($rep['memory']['autoload_kb'] ?? '?') . ' KB in ' . ($rep['memory']['autoload_count'] ?? '?') . " options\n";
        if ($first) {
            $oc = $rep['opcache'] ?? [];
            $out .= 'php ' . ($rep['php'] ?? '?') . ' ' . ($rep['sapi'] ?? '') . '; OPcache: ' . (empty($oc['available']) ? 'NOT available (' . ($oc['reason'] ?? 'unknown') . ')'
                : 'memory ' . ($oc['memory_mb'] ?? '?') . ' MB, used ' . ($oc['used_mb'] ?? '?') . ', free ' . ($oc['free_mb'] ?? '?') . ', wasted ' . ($oc['wasted_pct'] ?? '?') . '%, full ' . (!empty($oc['cache_full']) ? 'YES' : 'no')
                . ', scripts ' . ($oc['num_cached_scripts'] ?? '?') . '/' . ($oc['max_accelerated_files'] ?? '?') . ', hits ' . ($oc['hits'] ?? '?') . ', misses ' . ($oc['misses'] ?? '?')
                . ', interned strings ' . (($oc['interned_used_mb'] ?? '?') ?? '?') . '/' . (($oc['interned_buffer_mb'] ?? '?') ?? '?') . ' MB'
                . ', validate_timestamps ' . (int) ($oc['validate_timestamps'] ?? false) . ' every ' . ($oc['revalidate_freq'] ?? '?') . 's') . "\n";
            $first = false;
        }
    }

    return $out;
}

/** Reads what the runner saved in <dir> (NNN.meta: "url|kind|status|ttfb|total", NNN.body) and returns the table and details. */
function pkde_probe_report(string $dir): string
{
    $requests = [];
    $metas = glob(rtrim($dir, '/') . '/*.meta') ?: [];
    sort($metas);
    foreach ($metas as $meta) {
        [$url, $kind, $status, $ttfb, $total] = array_pad(explode('|', trim((string) file_get_contents($meta))), 5, '');
        $body = (string) @file_get_contents(substr($meta, 0, -5) . '.body');
        $requests[] = ['url' => $url, 'kind' => $kind, 'status' => (int) $status, 'ttfb' => (float) $ttfb, 'total' => (float) $total, 'report' => str_starts_with($kind, 'uncached') ? pkde_probe_extract($body) : null];
    }

    return pkde_probe_table($requests) . pkde_probe_details($requests);
}

// The runner's last step: `php perf-probe.php report <dir>`. Never runs inside WordPress.
if (PHP_SAPI === 'cli' && ! function_exists('add_action')) {
    if (isset($argv[1], $argv[2]) && $argv[1] === 'report' && realpath($argv[0]) === __FILE__) {
        echo pkde_probe_report($argv[2]);
    }

    return;
}

if (! function_exists('add_action')) {
    return; // loaded without WordPress (the Pest test): only the pure functions above are wanted
}

// --- The probe itself. Everything below runs only for a request that carries the right token. -----------------------------------
$pkdeProbeToken = $_GET['perfprobe'] ?? null;
if (! is_string($pkdeProbeToken) || str_contains(PKDE_PERFPROBE_TOKEN, '__') || ! hash_equals(PKDE_PERFPROBE_TOKEN, $pkdeProbeToken)) {
    return;
}

$GLOBALS['pkde_probe'] = [
    't0' => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
    'stages' => ['mu_start' => microtime(true)],
    'init' => [],
    'http_start' => [],
    'http' => [],
    'files_at_template_redirect' => null,
    'db' => null,
];

add_filter('pre_http_request', function ($pre, $args, $url) {
    $GLOBALS['pkde_probe']['http_start'][$url] = microtime(true);

    return $pre;
}, 0, 3);
add_action('http_api_debug', function ($response, $context, $class, $args, $url) {
    $started = $GLOBALS['pkde_probe']['http_start'][$url] ?? microtime(true);
    $GLOBALS['pkde_probe']['http'][] = [
        'ms' => round((microtime(true) - $started) * 1000),
        'host' => (string) parse_url($url, PHP_URL_HOST),
        'status' => is_wp_error($response) ? 'error' : (int) wp_remote_retrieve_response_code($response),
    ];
}, 10, 5);

add_action('muplugins_loaded', function () {
    $GLOBALS['pkde_probe']['stages']['muplugins_loaded'] = microtime(true);
}, PHP_INT_MAX);
add_action('plugins_loaded', function () {
    $GLOBALS['pkde_probe']['stages']['plugins_loaded'] = microtime(true);
}, 9999);
add_action('init', function () {
    $GLOBALS['pkde_probe']['stages']['init'] = microtime(true);
}, 9999);

// Time every other callback on `init`: wrap them all before init runs (this one is first, at PHP_INT_MIN).
add_action('init', function () {
    global $wp_filter;
    if (! isset($wp_filter['init'])) {
        return;
    }
    foreach ($wp_filter['init']->callbacks as &$callbacks) {
        foreach ($callbacks as &$cb) {
            $original = $cb['function'];
            if ($original instanceof Closure && (new ReflectionFunction($original))->getFileName() === __FILE__) {
                continue; // the probe's own callbacks
            }
            $name = pkde_probe_callback_name($original);
            $cb['function'] = function (...$args) use ($original, $name) {
                $start = microtime(true);
                $result = $original(...$args);
                $GLOBALS['pkde_probe']['init'][$name] = ($GLOBALS['pkde_probe']['init'][$name] ?? 0) + (microtime(true) - $start);

                return $result;
            };
        }
        unset($cb);
    }
    unset($callbacks);
}, PHP_INT_MIN);

// At template_redirect: the DB probe and the file count so far, then buffer the page so the final report can be appended.
add_action('template_redirect', function () {
    global $wpdb;
    $p = &$GLOBALS['pkde_probe'];
    $p['stages']['template_redirect'] = microtime(true);
    $p['files_at_template_redirect'] = count(get_included_files());
    $p['db'] = ['queries' => get_num_queries()];
    $start = microtime(true);
    for ($i = 0; $i < 20; $i++) {
        $wpdb->get_var('SELECT 1');
    }
    $p['db']['select1_avg_ms'] = round((microtime(true) - $start) / 20 * 1000, 2);

    ob_start(function (string $buffer): string {
        if (stripos($buffer, '</html>') === false) {
            return $buffer; // not a page (a feed, JSON, a redirect): leave it alone
        }
        $p = $GLOBALS['pkde_probe'];
        $p['stages']['shutdown'] = microtime(true);
        $ms = fn($t) => (int) round(($t - $p['t0']) * 1000);

        $opcache = ['available' => false, 'reason' => 'opcache_get_status() does not exist'];
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (function_exists('opcache_get_status') && ! in_array('opcache_get_status', $disabled, true)) {
            $status = @opcache_get_status(false);
            $config = function_exists('opcache_get_configuration') && ! in_array('opcache_get_configuration', $disabled, true) ? @opcache_get_configuration() : false;
            if (! is_array($status)) {
                $opcache = ['available' => false, 'reason' => 'OPcache is switched off for this SAPI'];
            } else {
                $d = is_array($config) ? $config['directives'] : [];
                $mb = fn($bytes) => round($bytes / 1048576, 1);
                $opcache = [
                    'available' => true,
                    'memory_mb' => isset($d['opcache.memory_consumption']) ? $mb($d['opcache.memory_consumption']) : '?',
                    'used_mb' => $mb($status['memory_usage']['used_memory']),
                    'free_mb' => $mb($status['memory_usage']['free_memory']),
                    'wasted_pct' => round($status['memory_usage']['current_wasted_percentage'], 2),
                    'cache_full' => (bool) $status['cache_full'],
                    'num_cached_scripts' => $status['opcache_statistics']['num_cached_scripts'],
                    'max_accelerated_files' => $d['opcache.max_accelerated_files'] ?? '?',
                    'hits' => $status['opcache_statistics']['hits'],
                    'misses' => $status['opcache_statistics']['misses'],
                    'interned_used_mb' => isset($status['interned_strings_usage']) ? $mb($status['interned_strings_usage']['used_memory']) : null,
                    'interned_buffer_mb' => isset($status['interned_strings_usage']) ? $mb($status['interned_strings_usage']['buffer_size']) : null,
                    'validate_timestamps' => (bool) ($d['opcache.validate_timestamps'] ?? true),
                    'revalidate_freq' => $d['opcache.revalidate_freq'] ?? '?',
                ];
            }
        }

        $files = get_included_files();
        $top = $p['init'];
        arsort($top);
        $initTop = [];
        foreach (array_slice($top, 0, 8, true) as $name => $seconds) {
            $initTop[] = ['ms' => round($seconds * 1000, 1), 'callback' => $name];
        }
        $alloptions = wp_load_alloptions();

        $report = [
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'stages_ms' => array_map($ms, $p['stages']),
            'opcache' => $opcache,
            'files' => ['at_template_redirect' => $p['files_at_template_redirect'], 'at_end' => count($files), 'groups' => pkde_probe_group_counts($files)],
            'init_top' => $initTop,
            'db' => $p['db'],
            'http' => $p['http'],
            'memory' => [
                'limit' => ini_get('memory_limit'),
                'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                'autoload_kb' => round(strlen(serialize($alloptions)) / 1024),
                'autoload_count' => count($alloptions),
            ],
        ];

        return $buffer . "\n<!-- perfprobe:" . json_encode($report, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . " -->\n";
    });
}, 0);
