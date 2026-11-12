<?php

// bin/deploy/drift-check.php returns early outside WordPress, so its pure functions load here without WordPress.
require_once dirname(__DIR__, 2) . '/bin/deploy/drift-check.php';

function pkdeGoodFacts(): array
{
    return [
        'env' => 'production',
        'admin_email' => 'office@acme-widgets.co.uk',
        'blogdescription' => 'Quality widgets for everyone',
        'wordfence' => array_merge(pkde_drift_wordfence_standard(), ['alertEmails' => 'office@acme-widgets.co.uk']),
        'turnstile' => ['appearance' => 'always', 'login' => '1', 'cf7_all' => '1', 'tested' => 'yes', 'reset' => '', 'keys_present' => true],
        'hide_login' => ['active' => true, 'slug' => 'staff-entrance', 'managed' => true],
        'w3tc' => ['settings' => pkde_w3tc_standard('https://example.test/sitemap_index.xml'), 'reject_cookies' => ['woocommerce_items_in_cart', 'wp_woocommerce_session_', 'other'], 'expected_sitemap' => 'https://example.test/sitemap_index.xml'],
        'rankmath' => ['skip' => true, 'connected' => false],
        'woocommerce_active' => true,
        'cf7_forms' => [['id' => 5, 'title' => 'Contact form', 'headers' => "Reply-To: [your-email]\nCc: [_site_admin_email]", 'has_subject' => false, 'subject' => PKDE_DRIFT_SUBJECT, 'mail2_active' => false, 'mail2_subject' => '']],
        'cf7_active' => true,
        'smtp_host_set' => true,
        'post_smtp' => ['transport' => 'smtp', 'active_transport' => 'PostmanSmtpModuleTransport', 'sender_set' => true, 'envelope_set' => true, 'host_set' => true, 'password_set' => true],
        'active_plugins' => ['wordfence', 'seo-by-rank-math', 'ewww-image-optimizer', 'w3-total-cache', 'speculation-rules', 'wps-hide-login', 'simple-cloudflare-turnstile', 'contact-form-7', 'post-smtp'],
    ];
}

function pkdeStatuses(array $facts): array
{
    $out = [];
    foreach (pkde_drift_evaluate($facts) as [$status, $check]) {
        $out[$check] = $status;
    }

    return $out;
}

it('passes a site that matches the standard', function () {
    $statuses = pkdeStatuses(pkdeGoodFacts());

    expect(array_keys($statuses, 'FAIL'))->toBe([])
        ->and($statuses['Wordfence alertOn_severityLevel'])->toBe('PASS')
        ->and($statuses['Plugin post-smtp active'])->toBe('PASS');
});

it('fails each deviation on its own check', function (callable $break, string $check) {
    $facts = pkdeGoodFacts();
    $break($facts);

    expect(pkdeStatuses($facts)[$check])->toBe('FAIL');
})->with([
    'wordfence update alerts on' => [fn(&$f) => $f['wordfence']['alertOn_update'] = '1', 'Wordfence alertOn_update'],
    'wordfence hourly cap' => [fn(&$f) => $f['wordfence']['alert_maxHourly'] = '5', 'Wordfence alert_maxHourly'],
    'wordfence value unset' => [fn(&$f) => $f['wordfence']['scansEnabled_themes'] = null, 'Wordfence scansEnabled_themes'],
    'alertEmails empty' => [fn(&$f) => $f['wordfence']['alertEmails'] = '', 'Wordfence alertEmails includes admin_email'],
    'alertEmails other address only' => [fn(&$f) => $f['wordfence']['alertEmails'] = 'someone@else.test', 'Wordfence alertEmails includes admin_email'],
    'turnstile hidden' => [fn(&$f) => $f['turnstile']['appearance'] = 'interaction-only', 'Turnstile widget always visible (cfturnstile_appearance)'],
    'turnstile login off' => [fn(&$f) => $f['turnstile']['login'] = '', 'Turnstile on the login form (cfturnstile_login)'],
    'turnstile keys missing' => [fn(&$f) => $f['turnstile']['keys_present'] = false, 'Turnstile site and secret keys present'],
    'turnstile not tested' => [fn(&$f) => $f['turnstile']['tested'] = 'no', 'Turnstile keys tested in wp-admin (cfturnstile_tested)'],
    'login slug default' => [fn(&$f) => $f['hide_login']['slug'] = 'login', 'WPS Hide Login slug changed from the default'],
    'cf7 subject field' => [fn(&$f) => $f['cf7_forms'][0]['has_subject'] = true, 'CF7 form #5 has no visitor Subject field'],
    'cf7 no admin Cc' => [fn(&$f) => $f['cf7_forms'][0]['headers'] = 'Reply-To: [your-email]', 'CF7 form #5 Mail Ccs the admin (Cc: [_site_admin_email])'],
    'cf7 default name' => [fn(&$f) => $f['cf7_forms'][0]['title'] = 'Contact form 1', 'CF7 form #5 has a descriptive name'],
    'cf7 subject text' => [fn(&$f) => $f['cf7_forms'][0]['subject'] = '[your-subject]', 'CF7 form #5 mail subject is the fixed text'],
    'w3tc minify on' => [fn(&$f) => $f['w3tc']['settings']['minify.enabled'] = true, 'W3TC minify.enabled'],
    'w3tc browser cache on' => [fn(&$f) => $f['w3tc']['settings']['browsercache.enabled'] = true, 'W3TC browsercache.enabled'],
    'w3tc object cache on' => [fn(&$f) => $f['w3tc']['settings']['objectcache.enabled'] = true, 'W3TC objectcache.enabled'],
    'w3tc page cache off' => [fn(&$f) => $f['w3tc']['settings']['pgcache.enabled'] = false, 'W3TC pgcache.enabled'],
    'w3tc page cache engine' => [fn(&$f) => $f['w3tc']['settings']['pgcache.engine'] = 'file', 'W3TC pgcache.engine'],
    'w3tc lifetime' => [fn(&$f) => $f['w3tc']['settings']['pgcache.lifetime'] = 3600, 'W3TC pgcache.lifetime'],
    'w3tc preloader off' => [fn(&$f) => $f['w3tc']['settings']['pgcache.prime.enabled'] = false, 'W3TC pgcache.prime.enabled'],
    'w3tc preloader sitemap unset' => [fn(&$f) => $f['w3tc']['settings']['pgcache.prime.sitemap'] = '', 'W3TC pgcache.prime.sitemap'],
    'w3tc preloader limit unset' => [function (&$f) {
        unset($f['w3tc']['settings']['pgcache.prime.limit']);
    }, 'W3TC pgcache.prime.limit'],
    'w3tc shop cookies missing' => [fn(&$f) => $f['w3tc']['reject_cookies'] = ['other'], 'W3TC page cache skips the WooCommerce cookies'],
    'placeholder admin email' => [fn(&$f) => $f['admin_email'] = 'admin@example.com', 'admin_email is not a placeholder'],
    'default tagline' => [fn(&$f) => $f['blogdescription'] = 'Just another WordPress site', 'blogdescription is set and not the WordPress default'],
    'core plugin inactive' => [fn(&$f) => $f['active_plugins'] = array_values(array_diff($f['active_plugins'], ['wordfence'])), 'Plugin wordfence active'],
    'post-smtp inactive with SMTP_HOST' => [fn(&$f) => $f['active_plugins'] = array_values(array_diff($f['active_plugins'], ['post-smtp'])), 'Plugin post-smtp active'],
]);

it('accepts the fixed mail subject in its allowed forms and nothing else', function () {
    $ok = [ // [subject, the site's blogname]
        ['New website enquiry - [_site_title] - [your-name]', 'Example Park'],
        ['New website enquiry - Example Park - [your-name]', 'Example Park'],
        ['New website enquiry - Example Park - [your-name] - [park-home-title]', 'Example Park'],
        ['New website enquiry - [_site_title] - [your-name] - [listing_title]', 'Example Park'],
        ['New website enquiry - Smith & Sons - [your-name]', 'Smith &amp; Sons'],
        [' New website enquiry - Smith &amp; Sons - [your-name] ', 'Smith &amp; Sons'],
    ];
    foreach ($ok as [$subject, $title]) {
        expect(pkde_drift_subject_ok($subject, $title))->toBeTrue($subject);
    }

    $bad = [
        '[your-subject]',
        'New website enquiry - [_site_title] - [your-subject]',
        'New website enquiry - Another Name - [your-name]',
        'Website enquiry - Example Park - [your-name]',
        'New website enquiry - Example Park - [your-name] - [a] - [b]',
        'New website enquiry - Example Park - [your-name] - free text',
        'New website enquiry - Example Park',
        '',
    ];
    foreach ($bad as $subject) {
        expect(pkde_drift_subject_ok($subject, 'Example Park'))->toBeFalse($subject);
    }
});

it('does not ask for the WooCommerce cookies without WooCommerce, and skips W3TC on a development stack', function () {
    $facts = pkdeGoodFacts();
    $facts['woocommerce_active'] = false;
    $facts['w3tc']['reject_cookies'] = [];
    expect(pkdeStatuses($facts))->not->toHaveKey('W3TC page cache skips the WooCommerce cookies');

    $facts = pkdeGoodFacts();
    $facts['env'] = 'development';
    $facts['w3tc']['settings']['minify.enabled'] = true;
    expect(pkdeStatuses($facts)['W3TC minify.enabled'])->toBe('SKIP');
    $facts['w3tc'] = null;
    expect(pkdeStatuses($facts)['W3 Total Cache'])->toBe('SKIP');
    $facts['env'] = 'production';
    expect(pkdeStatuses($facts)['W3 Total Cache'])->toBe('FAIL');
});

it('skips Wordfence on staging while it is inactive, and still holds an active one to the standard', function () {
    $facts = pkdeGoodFacts();
    $facts['env'] = 'staging';
    $facts['wordfence'] = null;
    $facts['active_plugins'] = array_values(array_diff($facts['active_plugins'], ['wordfence']));
    $statuses = pkdeStatuses($facts);
    expect($statuses['Wordfence'])->toBe('SKIP')
        ->and($statuses['Plugin wordfence active'])->toBe('SKIP')
        ->and($statuses)->not->toHaveKey('Wordfence alertOn_update');

    // the owner step that stays a FAIL on staging: Turnstile keys tested in wp-admin
    $facts['turnstile']['tested'] = 'no';
    expect(pkdeStatuses($facts)['Turnstile keys tested in wp-admin (cfturnstile_tested)'])->toBe('FAIL');

    // production without Wordfence is a FAIL, and so is staging with Wordfence active but misconfigured
    $facts['env'] = 'production';
    expect(pkdeStatuses($facts)['Wordfence'])->toBe('FAIL')
        ->and(pkdeStatuses($facts)['Plugin wordfence active'])->toBe('FAIL');
    $facts = pkdeGoodFacts();
    $facts['env'] = 'staging';
    $facts['wordfence']['alertOn_update'] = '1';
    expect(pkdeStatuses($facts)['Wordfence alertOn_update'])->toBe('FAIL');
});

it('requires Rank Math to be registered: skipped or connected', function () {
    $check = 'Rank Math is registered (registration skipped or account connected)';
    $facts = pkdeGoodFacts();
    expect(pkdeStatuses($facts)[$check])->toBe('PASS');

    $facts['rankmath'] = ['skip' => false, 'connected' => true];
    expect(pkdeStatuses($facts)[$check])->toBe('PASS');

    $facts['rankmath'] = ['skip' => false, 'connected' => false];
    expect(pkdeStatuses($facts)[$check])->toBe('FAIL');
    $facts['env'] = 'development';
    expect(pkdeStatuses($facts)[$check])->toBe('FAIL');

    $facts['active_plugins'] = array_values(array_diff($facts['active_plugins'], ['seo-by-rank-math']));
    expect(pkdeStatuses($facts))->not->toHaveKey($check);
});

it('accepts Rank Math or Yoast as the SEO plugin, and keeps the Rank Math registration check for Rank Math only', function () {
    $check = 'An SEO plugin is active (Rank Math or Yoast)';
    $without = fn(array $f) => array_values(array_diff($f['active_plugins'], ['seo-by-rank-math']));

    $facts = pkdeGoodFacts();
    expect(pkdeStatuses($facts)[$check])->toBe('PASS');

    foreach (['wordpress-seo', 'wordpress-seo-premium'] as $yoast) {
        $facts = pkdeGoodFacts();
        $facts['active_plugins'] = [...$without($facts), $yoast];
        $facts['rankmath'] = ['skip' => false, 'connected' => false];
        $statuses = pkdeStatuses($facts);
        expect($statuses[$check])->toBe('PASS')
            ->and($statuses)->not->toHaveKey('Rank Math is registered (registration skipped or account connected)')
            ->and($statuses)->not->toHaveKey('Plugin seo-by-rank-math active');
    }

    $facts = pkdeGoodFacts();
    $facts['active_plugins'] = $without($facts);
    expect(pkdeStatuses($facts)[$check])->toBe('FAIL');
});

it('checks Mail (2) only when it is switched on', function () {
    $facts = pkdeGoodFacts();
    $facts['cf7_forms'][0]['mail2_subject'] = 'anything';
    expect(pkdeStatuses($facts))->not->toHaveKey('CF7 form #5 Mail (2) subject is the fixed text');

    $facts['cf7_forms'][0]['mail2_active'] = true;
    expect(pkdeStatuses($facts)['CF7 form #5 Mail (2) subject is the fixed text'])->toBe('FAIL');
});

it('skips the opt-in checks on a development stack instead of failing them', function () {
    $facts = pkdeGoodFacts();
    $facts['env'] = 'development';
    $facts['turnstile'] = ['appearance' => 'always', 'login' => null, 'cf7_all' => null, 'tested' => null, 'reset' => null, 'keys_present' => false];
    $facts['hide_login'] = ['active' => false, 'slug' => ''];
    $facts['admin_email'] = 'admin@example.com';
    $facts['wordfence']['alertEmails'] = 'admin@example.com';
    $facts['active_plugins'] = array_values(array_diff($facts['active_plugins'], ['wps-hide-login', 'simple-cloudflare-turnstile', 'post-smtp']));
    $statuses = pkdeStatuses($facts);

    expect(array_keys($statuses, 'FAIL'))->toBe([])
        ->and($statuses['Turnstile site and secret keys present'])->toBe('SKIP')
        ->and($statuses['Plugin wps-hide-login active'] ?? 'SKIP')->toBe('SKIP');
});

it('recognises placeholder admin emails', function () {
    expect(pkde_drift_is_placeholder_email('admin@example.com'))->toBeTrue()
        ->and(pkde_drift_is_placeholder_email(''))->toBeTrue()
        ->and(pkde_drift_is_placeholder_email('me@shop.test'))->toBeTrue()
        ->and(pkde_drift_is_placeholder_email('info@acme-widgets.co.uk'))->toBeFalse();
});

it('is read only', function () {
    $code = file_get_contents(dirname(__DIR__, 2) . '/bin/deploy/drift-check.php');
    $code = preg_replace('#/\*.*?\*/#s', '', $code);   // ignore the docblock
    $code = preg_replace('#^\s*//.*$#m', '', $code);

    expect($code)->not->toMatch('/\b(update_option|add_option|delete_option|update_post_meta|delete_post_meta|wp_insert_post|wp_update_post|wp_delete_post|file_put_contents|unlink|activate_plugin|deactivate_plugins)\s*\(/')
        ->and($code)->not->toMatch('/wfConfig::(set|remove)\b/')
        ->and($code)->not->toMatch('/->(query|insert|update|delete)\s*\(/');
});

it('requires Post SMTP to be configured and ready, not only active, and never prints a value', function () {
    $check = 'Post SMTP configured and ready';
    expect(pkdeStatuses(pkdeGoodFacts())[$check])->toBe('PASS');

    $cases = [
        'sender_set' => 'SMTP_FROM',
        'envelope_set' => 'envelope',
        'host_set' => 'SMTP_HOST',
        'password_set' => 'SMTP_PASSWORD',
    ];
    foreach ($cases as $key => $hint) {
        $facts = pkdeGoodFacts();
        $facts['post_smtp'][$key] = false;
        $rows = array_values(array_filter(pkde_drift_evaluate($facts), fn($r) => $r[1] === $check));

        expect($rows[0][0])->toBe('FAIL')
            ->and($rows[0][2])->toContain($hint);
    }

    $facts = pkdeGoodFacts();
    $facts['post_smtp']['transport'] = 'default';
    expect(pkdeStatuses($facts)[$check])->toBe('FAIL');

    $facts = pkdeGoodFacts();
    $facts['post_smtp']['active_transport'] = 'PostmanDefaultModuleTransport';
    $rows = array_values(array_filter(pkde_drift_evaluate($facts), fn($r) => $r[1] === $check));
    expect($rows[0][0])->toBe('FAIL')
        ->and($rows[0][2])->toContain('PostmanDefaultModuleTransport');

    $facts = pkdeGoodFacts();
    $facts['post_smtp'] = null;
    expect(pkdeStatuses($facts)[$check])->toBe('FAIL');

    $facts = pkdeGoodFacts();
    $facts['active_plugins'] = array_values(array_diff($facts['active_plugins'], ['post-smtp']));
    $facts['smtp_host_set'] = false;
    expect(pkdeStatuses($facts))->not->toHaveKey($check);
});

it('requires the login slug to be managed by .env, and never prints it', function () {
    $check = 'Login slug managed by .env';
    expect(pkdeStatuses(pkdeGoodFacts())[$check])->toBe('PASS');

    $facts = pkdeGoodFacts();
    $facts['hide_login']['managed'] = false;
    $rows = array_values(array_filter(pkde_drift_evaluate($facts), fn($r) => $r[1] === $check));
    expect($rows[0][0])->toBe('FAIL')
        ->and($rows[0][2])->toContain('LOGIN_SLUG')
        ->and($rows[0][2])->not->toContain('staff-entrance');

    $facts['env'] = 'development';
    expect(pkdeStatuses($facts)[$check])->toBe('SKIP');

    $facts = pkdeGoodFacts();
    $facts['hide_login']['active'] = false;
    expect(pkdeStatuses($facts))->not->toHaveKey($check);
});

it('fails a .env split between environments: WP_ENV, DISALLOW_INDEXING and STAGING_MAIL_TO (bp, MGR-67)', function () {
    $good = array_merge(pkdeGoodFacts(), ['wp_env' => 'production', 'disallow_indexing' => false, 'staging_mail_to' => false]);
    expect(array_keys(pkdeStatuses($good), 'FAIL'))->toBe([]);

    $hidden = pkdeStatuses(array_merge($good, ['disallow_indexing' => true]));
    expect($hidden['DISALLOW_INDEXING is off on production'])->toBe('FAIL');

    $mismatch = pkdeStatuses(array_merge($good, ['wp_env' => 'staging', 'disallow_indexing' => true]));
    expect($mismatch['WP_ENV and WP_ENVIRONMENT_TYPE agree'])->toBe('FAIL');

    $copied = pkdeStatuses(array_merge($good, ['staging_mail_to' => true]));
    expect($copied['STAGING_MAIL_TO is not set on production'])->toBe('FAIL');

    $staging = pkdeStatuses(array_merge(pkdeGoodFacts(), ['env' => 'staging', 'wp_env' => 'staging', 'disallow_indexing' => false, 'staging_mail_to' => false]));
    expect($staging['DISALLOW_INDEXING is on for staging'])->toBe('FAIL')
        ->and($staging['STAGING_MAIL_TO'])->toBe('PASS');
});

it('does not evaluate the environment rows when the facts carry no WP_ENV', function () {
    expect(pkdeStatuses(pkdeGoodFacts()))->not->toHaveKey('WP_ENV and WP_ENVIRONMENT_TYPE agree');
});

function pkdeCacheFacts(array $overrides = []): array
{
    return array_merge(pkdeGoodFacts(), ['page_cache' => array_merge(['wp_cache' => true, 'cron' => ['w3_pgcache_prime' => true, 'w3_pgcache_cleanup' => true], 'newest_age' => 1800], $overrides)]);
}

it('passes a page cache that serves, is scheduled and is fresh', function () {
    $statuses = pkdeStatuses(pkdeCacheFacts());

    expect(array_keys($statuses, 'FAIL'))->toBe([])
        ->and($statuses['WP_CACHE is true (the page cache serves)'])->toBe('PASS')
        ->and($statuses['W3TC preload cron event is scheduled'])->toBe('PASS')
        ->and($statuses['W3TC cleanup cron event is scheduled'])->toBe('PASS')
        ->and($statuses['W3TC newest cached page is younger than the lifetime'])->toBe('PASS');
});

it('fails the W3TC rows bb and tcs staging showed: no WP_CACHE, no cron events, a stale cache (2026-11-05)', function () {
    $noCache = pkdeStatuses(pkdeCacheFacts(['wp_cache' => false]));
    expect($noCache['WP_CACHE is true (the page cache serves)'])->toBe('FAIL');

    $noCron = pkdeStatuses(pkdeCacheFacts(['cron' => ['w3_pgcache_prime' => false, 'w3_pgcache_cleanup' => false]]));
    expect($noCron['W3TC preload cron event is scheduled'])->toBe('FAIL')
        ->and($noCron['W3TC cleanup cron event is scheduled'])->toBe('FAIL');

    $stale = pkdeStatuses(pkdeCacheFacts(['newest_age' => 48 * 3600]));
    expect($stale['W3TC newest cached page is younger than the lifetime'])->toBe('FAIL');

    $empty = pkdeStatuses(pkdeCacheFacts(['newest_age' => null]));
    expect($empty['W3TC newest cached page is younger than the lifetime'])->toBe('FAIL');
});

it('skips the preload row when the preloader is off, and every page-cache row when the facts carry none', function () {
    $facts = pkdeCacheFacts(['cron' => ['w3_pgcache_prime' => false, 'w3_pgcache_cleanup' => true]]);
    $facts['w3tc']['settings']['pgcache.prime.enabled'] = false;

    expect(pkdeStatuses($facts))->not->toHaveKey('W3TC preload cron event is scheduled')
        ->and(pkdeStatuses(pkdeGoodFacts()))->not->toHaveKey('WP_CACHE is true (the page cache serves)');
});

it('names the cron events the W3TC standard needs', function () {
    expect(pkde_w3tc_cron_events(pkde_w3tc_standard('https://example.test/sitemap_index.xml')))->toBe(['w3_pgcache_cleanup', 'w3_pgcache_prime'])
        ->and(pkde_w3tc_cron_events(['pgcache.enabled' => true, 'pgcache.engine' => 'file_generic', 'pgcache.prime.enabled' => false]))->toBe(['w3_pgcache_cleanup'])
        ->and(pkde_w3tc_cron_events(['pgcache.enabled' => false, 'pgcache.engine' => 'file_generic', 'pgcache.prime.enabled' => true]))->toBe([]);
});

it('keeps the basket cookies rejected by default and requires them NOT rejected once the site opted in to caching basket visitors (MGR-76)', function () {
    $facts = pkdeGoodFacts();
    expect(pkdeStatuses($facts)['W3TC page cache skips the WooCommerce cookies'])->toBe('PASS');

    $facts['cache_basket_visitors'] = true;
    expect(pkdeStatuses($facts)['W3TC page cache serves visitors with a basket (opted in)'])->toBe('FAIL');

    $facts['w3tc']['reject_cookies'] = ['other'];
    $facts['kit_cache_safe'] = true;
    $facts['personal_views'] = [];
    expect(pkdeStatuses($facts)['W3TC page cache serves visitors with a basket (opted in)'])->toBe('PASS')
        ->and(pkdeStatuses($facts)['Cache-safe theme for basket visitors: the kit marks personal pages uncacheable'])->toBe('PASS')
        ->and(pkdeStatuses($facts)['Cache-safe theme for basket visitors: no view prints per-visitor data'])->toBe('PASS')
        ->and(pkde_w3tc_woocommerce_cookies())->toBe(['woocommerce_items_in_cart', 'wp_woocommerce_session_'])
        ->and(pkde_w3tc_woocommerce_cookies(true))->toBe([]);
});

it('fails an opted-in site whose theme still reads the basket on the server or lacks the kit\'s cache-safe helper (MGR-76)', function () {
    $facts = pkdeGoodFacts();
    $facts['cache_basket_visitors'] = true;
    $facts['w3tc']['reject_cookies'] = [];
    $facts['kit_cache_safe'] = false;
    $facts['personal_views'] = ['sections/header.blade.php'];

    $statuses = pkdeStatuses($facts);

    expect($statuses['Cache-safe theme for basket visitors: the kit marks personal pages uncacheable'])->toBe('FAIL')
        ->and($statuses['Cache-safe theme for basket visitors: no view prints per-visitor data'])->toBe('FAIL');
});

it('finds the theme views that read the basket, a notice, a cookie or the user, and none in a clean theme', function () {
    $dir = sys_get_temp_dir() . '/pkde-views-' . bin2hex(random_bytes(4));
    mkdir("$dir/sections", 0777, true);
    file_put_contents("$dir/sections/header.blade.php", "@if ((\$count = WC()->cart->get_cart_contents_count()) > 0)");
    file_put_contents("$dir/sections/footer.blade.php", '<footer>{{ date("Y") }}</footer>');
    file_put_contents("$dir/notice.blade.php", '@php(wc_print_notices())');

    expect(pkde_drift_personal_views($dir))->toBe(['notice.blade.php', 'sections/header.blade.php'])
        ->and(pkde_drift_personal_views("$dir/missing"))->toBe([]);

    array_map('unlink', ["$dir/sections/header.blade.php", "$dir/sections/footer.blade.php", "$dir/notice.blade.php"]);
    rmdir("$dir/sections");
    rmdir($dir);
});

it('never raises the basket-visitor rows on a site without WooCommerce, even if the option is set (rp, bp, mds, jsd are not shops)', function () {
    $facts = pkdeGoodFacts();
    $facts['woocommerce_active'] = false;
    $facts['cache_basket_visitors'] = true;
    $facts['kit_cache_safe'] = false;
    $facts['personal_views'] = ['sections/header.blade.php'];

    $names = array_keys(pkdeStatuses($facts));

    expect(array_filter($names, fn($n) => str_contains($n, 'basket') || str_contains($n, 'Cache-safe theme') || str_contains($n, 'WooCommerce cookies')))->toBe([]);
});

it('requires the kit\'s breadcrumb standard, and tells when Rank Math\'s module is off (MGR-77 parity)', function () {
    $facts = pkdeGoodFacts();
    $facts['breadcrumbs'] = ['kit' => true, 'separator' => '/', 'home' => 'Home', 'rank_math' => true, 'module_on' => true];
    $names = pkdeStatuses($facts);

    expect($names['Breadcrumbs: the kit sets the standard (separator "/", label "Home")'])->toBe('PASS')
        ->and($names['Breadcrumbs: Rank Math\'s breadcrumb module is on (its BreadcrumbList schema)'])->toBe('PASS');

    $facts['breadcrumbs']['kit'] = false;
    $facts['breadcrumbs']['separator'] = '-';
    $facts['breadcrumbs']['module_on'] = false;
    $names = pkdeStatuses($facts);

    expect($names['Breadcrumbs: the kit sets the standard (separator "/", label "Home")'])->toBe('FAIL')
        ->and($names['Breadcrumbs: Rank Math\'s breadcrumb module is on (its BreadcrumbList schema)'])->toBe('FAIL');

    unset($facts['breadcrumbs']);
    expect(array_keys(pkdeStatuses($facts)))->not->toContain('Breadcrumbs: the kit sets the standard (separator "/", label "Home")');
});

it('fails an active Relevanssi whose index is empty and says nothing when the plugin is not there (TPL-133)', function () {
    $check = 'Relevanssi index is built (searches find products)';
    $facts = pkdeGoodFacts();

    expect(pkdeStatuses($facts))->not->toHaveKey($check);

    $facts['active_plugins'][] = 'relevanssi';
    $facts['relevanssi_indexed'] = 240;
    expect(pkdeStatuses($facts)[$check])->toBe('PASS');

    $facts['relevanssi_indexed'] = 0;
    expect(pkdeStatuses($facts)[$check])->toBe('FAIL');

    // A local stack may not have built the index: skipped there, as the other cache rows are.
    $facts['env'] = 'development';
    expect(pkdeStatuses($facts)[$check])->toBe('SKIP');
});

it('accepts the admin Cc line wherever it sits in the headers, but only through the special tag', function () {
    expect(pkde_drift_cc_admin_ok("Reply-To: [your-email]\nCc: [_site_admin_email]"))->toBeTrue()
        ->and(pkde_drift_cc_admin_ok('cc: [_site_admin_email], [other]'))->toBeTrue()
        ->and(pkde_drift_cc_admin_ok('Cc: someone@example.com'))->toBeFalse()
        ->and(pkde_drift_cc_admin_ok('Bcc: [_site_admin_email]'))->toBeFalse()
        ->and(pkde_drift_cc_admin_ok(''))->toBeFalse();
});

it('rejects the CF7 sample names', function () {
    expect(pkde_drift_form_name_ok('Contact form 1'))->toBeFalse()
        ->and(pkde_drift_form_name_ok('Untitled'))->toBeFalse()
        ->and(pkde_drift_form_name_ok(''))->toBeFalse()
        ->and(pkde_drift_form_name_ok('Park home enquiry'))->toBeTrue()
        ->and(pkde_drift_form_name_ok('Contact form'))->toBeTrue();
});

it('picks the alert address: WORDFENCE_ALERT_EMAIL, else WP_ADMIN_EMAIL, and ignores anything that is not an address', function () {
    expect(pkde_alert_address('alerts@acme-widgets.co.uk', 'office@acme-widgets.co.uk'))->toBe('alerts@acme-widgets.co.uk')
        ->and(pkde_alert_address('', 'office@acme-widgets.co.uk'))->toBe('office@acme-widgets.co.uk')
        ->and(pkde_alert_address('not an address', ' office@acme-widgets.co.uk '))->toBe('office@acme-widgets.co.uk')
        ->and(pkde_alert_address('', ''))->toBe('')
        ->and(pkde_alert_address('x', 'y'))->toBe('');
});

it('compares Wordfence alertEmails with the one address exactly, ignoring case and spaces', function () {
    expect(pkde_alert_emails_exact('Office@Acme-Widgets.co.uk', 'office@acme-widgets.co.uk'))->toBeTrue()
        ->and(pkde_alert_emails_exact(' office@acme-widgets.co.uk ,', 'office@acme-widgets.co.uk'))->toBeTrue()
        ->and(pkde_alert_emails_exact('office@acme-widgets.co.uk, other@x.test', 'office@acme-widgets.co.uk'))->toBeFalse()
        ->and(pkde_alert_emails_exact('', 'office@acme-widgets.co.uk'))->toBeFalse()
        ->and(pkde_alert_emails_exact('office@acme-widgets.co.uk', ''))->toBeFalse();
});

it('keeps the old alertEmails row until the .env names an address, then checks for exactly that address', function () {
    $facts = pkdeGoodFacts();
    expect(pkdeStatuses($facts))->toHaveKey('Wordfence alertEmails includes admin_email');

    $facts['wp_admin_email'] = 'office@acme-widgets.co.uk';
    $exact = 'Wordfence alertEmails is exactly the alert address (WORDFENCE_ALERT_EMAIL, else WP_ADMIN_EMAIL)';
    $statuses = pkdeStatuses($facts);
    expect($statuses)->not->toHaveKey('Wordfence alertEmails includes admin_email')
        ->and($statuses[$exact])->toBe('PASS')
        ->and($statuses['admin_email equals WP_ADMIN_EMAIL (.env)'])->toBe('PASS');

    $facts['wordfence']['alertEmails'] = 'office@acme-widgets.co.uk, someone@else.test';
    expect(pkdeStatuses($facts)[$exact])->toBe('FAIL');

    // a separate alert address: the recipients must be that one, not the admin email
    $facts['alert_email_const'] = 'alerts@acme-widgets.co.uk';
    $facts['wordfence']['alertEmails'] = 'office@acme-widgets.co.uk';
    expect(pkdeStatuses($facts)[$exact])->toBe('FAIL');
    $facts['wordfence']['alertEmails'] = 'alerts@acme-widgets.co.uk';
    expect(pkdeStatuses($facts)[$exact])->toBe('PASS');
});

it('fails admin_email when it differs from WP_ADMIN_EMAIL and never prints either address', function () {
    $facts = pkdeGoodFacts();
    $facts['wp_admin_email'] = 'owner@acme-widgets.co.uk';
    $row = array_values(array_filter(pkde_drift_evaluate($facts), fn($r) => $r[1] === 'admin_email equals WP_ADMIN_EMAIL (.env)'))[0];

    expect($row[0])->toBe('FAIL')
        ->and(json_encode($row))->not->toContain('owner@acme-widgets.co.uk')
        ->and(json_encode($row))->not->toContain('office@acme-widgets.co.uk');
});
