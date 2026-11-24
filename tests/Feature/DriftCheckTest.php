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
        'w3tc' => ['settings' => pkde_w3tc_standard('https://example.test/sitemap_index.xml'), 'reject_cookies' => ['woocommerce_items_in_cart', 'wp_woocommerce_session_', 'other'], 'reject_uris' => pkde_w3tc_reject_uris(), 'expected_sitemap' => 'https://example.test/sitemap_index.xml'],
        'rankmath' => ['skip' => true, 'connected' => false],
        'woocommerce_active' => true,
        'cf7_forms' => [['id' => 5, 'title' => 'Contact form', 'headers' => "Reply-To: [your-email]\nCc: [_site_admin_email]", 'has_subject' => false, 'subject' => PKDE_DRIFT_SUBJECT, 'mail2_active' => false, 'mail2_subject' => '']],
        'cf7_active' => true,
        'rest_users' => ['list' => 404, 'single' => 404],
        'author_archive' => ['module' => true, 'roles' => ['author'], 'authors' => 2, 'without_role' => 0, 'allowed_users' => 2, 'slug_equals_login' => 0],
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
    'wordfence username discovery protection off' => [fn(&$f) => $f['wordfence']['loginSec_disableAuthorScan'] = '0', 'Wordfence loginSec_disableAuthorScan'],
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
    'author archives module missing' => [fn(&$f) => $f['author_archive']['module'] = false, 'Author archives are closed for users without an allowed role (platform module author-archives)'],
    'rest users list open' => [fn(&$f) => $f['rest_users']['list'] = 200, 'Anonymous GET /wp/v2/users does not list users (401, 403 or 404)'],
    'rest users single open' => [fn(&$f) => $f['rest_users']['single'] = 200, 'Anonymous GET /wp/v2/users/<id> does not list users (401, 403 or 404)'],
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
    'w3tc sitemap not rejected' => [fn(&$f) => $f['w3tc']['reject_uris'] = ['robots\\.txt'], 'W3TC page cache never caches robots.txt or the sitemaps'],
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

    // an active Wordfence on staging is held to the username-discovery rule too (WP-88)
    $facts['wordfence']['alertOn_update'] = '0';
    $facts['wordfence']['loginSec_disableAuthorScan'] = '0';
    expect(pkdeStatuses($facts)['Wordfence loginSec_disableAuthorScan'])->toBe('FAIL');
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

it('fails a user with published posts and no allowed role, with counts only', function () {
    $facts = pkdeGoodFacts();
    $row = 'Every user with published posts has an allowed author role';
    expect(pkdeStatuses($facts)[$row])->toBe('PASS');

    $facts['author_archive']['without_role'] = 1;
    $found = array_values(array_filter(pkde_drift_evaluate($facts), fn($r) => $r[1] === $row))[0];

    expect($found[0])->toBe('FAIL')
        ->and($found[2])->toContain('1 of 2')
        ->and($found[2])->toContain('wp user add-role');
});

it('fails an allowed-role user whose archive slug equals the login, and prints no name', function () {
    $facts = pkdeGoodFacts();
    $row = 'Author archive slug (user_nicename) differs from the login';
    expect(pkdeStatuses($facts)[$row])->toBe('PASS');

    $facts['author_archive']['slug_equals_login'] = 1;
    $found = array_values(array_filter(pkde_drift_evaluate($facts), fn($r) => $r[1] === $row))[0];

    expect($found[0])->toBe('FAIL')->and($found[2])->toContain('1 of 2');
});

it('skips the author archive rows for facts that do not carry them', function () {
    $facts = pkdeGoodFacts();
    unset($facts['author_archive']);

    expect(pkdeStatuses($facts))->not->toHaveKey('Every user with published posts has an allowed author role');
});

it('accepts 401, 403 and 404 for the anonymous user routes and skips a probe it could not make', function () {
    $facts = pkdeGoodFacts();
    foreach ([401, 403, 404] as $status) {
        $facts['rest_users'] = ['list' => $status, 'single' => $status];
        expect(pkdeStatuses($facts)['Anonymous GET /wp/v2/users does not list users (401, 403 or 404)'])->toBe('PASS');
    }

    $facts['rest_users'] = ['list' => 404, 'single' => null];
    expect(pkdeStatuses($facts))->not->toHaveKey('Anonymous GET /wp/v2/users/<id> does not list users (401, 403 or 404)');
});

it('checks Wordfence Extended Protection only for a site that opted in, and fails a dangling or missing prepend target', function () {
    $facts = pkdeGoodFacts();
    $row = 'Wordfence Extended Protection: web/.user.ini points at the shared shim';
    expect(pkdeStatuses($facts))->not->toHaveKey($row);

    $facts['waf_prepend'] = ['enabled' => false, 'ini_target' => null, 'target_exists' => false, 'target_has_bootstrap' => false];
    expect(pkdeStatuses($facts))->not->toHaveKey($row);

    $facts['waf_prepend'] = ['enabled' => true, 'ini_target' => '/x/waf/wordfence-waf.php', 'target_exists' => true, 'target_has_bootstrap' => true];
    expect(pkdeStatuses($facts)[$row])->toBe('PASS');

    foreach ([['ini_target', null], ['target_exists', false], ['target_has_bootstrap', false]] as [$key, $value]) {
        $broken = $facts;
        $broken['waf_prepend'][$key] = $value;
        expect(pkdeStatuses($broken)[$row])->toBe('FAIL');
    }
});

it('fails an opted-in site whose /.user.ini can be downloaded, skips what it cannot tell, and still runs on a staging site without Wordfence (WP-85 pilot)', function () {
    $row = 'Wordfence Extended Protection: /.user.ini is not downloadable';
    $facts = pkdeGoodFacts();
    $facts['waf_prepend'] = ['enabled' => true, 'ini_target' => '/x/waf/wordfence-waf.php', 'target_exists' => true, 'target_has_bootstrap' => true];
    expect(pkdeStatuses($facts))->not->toHaveKey($row); // no probe fact: nothing to say

    foreach ([403 => 'PASS', 404 => 'PASS', 301 => 'PASS', 200 => 'FAIL', 0 => 'SKIP', 401 => 'SKIP'] as $status => $expected) {
        $facts['waf_prepend']['userini_status'] = $status;
        expect(pkdeStatuses($facts)[$row])->toBe($expected);
    }

    // a staging site where Wordfence is inactive: the mechanism rows still run (that is where the pilot proves it)
    $facts['env'] = 'staging';
    $facts['wordfence'] = null;
    $facts['active_plugins'] = array_values(array_diff($facts['active_plugins'], ['wordfence']));
    $statuses = pkdeStatuses($facts);
    expect($statuses['Wordfence'])->toBe('SKIP')
        ->and($statuses['Wordfence Extended Protection: web/.user.ini points at the shared shim'])->toBe('PASS')
        ->and($statuses[$row])->toBe('SKIP');
});

// Search engine signals as the site answers them (WP-91): the probe rows.
function pkdeSeoProbe(array $over = []): array
{
    return array_replace_recursive([
        'home' => ['status' => 200, 'x_robots' => '', 'body' => '', 'security' => ['x-content-type-options' => 'nosniff', 'referrer-policy' => 'strict-origin-when-cross-origin', 'x-frame-options' => 'SAMEORIGIN', 'permissions-policy' => 'camera=()']],
        'robots_txt' => ['status' => 200, 'x_robots' => '', 'body' => "User-agent: *\nDisallow: /wp/wp-admin/\nSitemap: https://example.test/sitemap_index.xml\n"],
        'static' => ['status' => 200, 'x_robots' => '', 'body' => ''],
        'favicon' => ['status' => 200, 'x_robots' => '', 'type' => 'image/vnd.microsoft.icon', 'body' => ''],
        'apple_touch' => ['status' => 200, 'x_robots' => '', 'type' => 'image/png', 'body' => ''],
        'flood' => ['status' => 410, 'x_robots' => '', 'body' => ''],
        'faceted' => ['status' => 200, 'x_robots' => 'noindex, follow', 'meta_noindex' => true],
        'sitemap' => ['status' => 200, 'noindex' => []],
    ], $over);
}

it('passes the production search engine signals and fails a staging leak or an indexable faceted URL', function () {
    $facts = pkdeGoodFacts();
    $facts['wp_env'] = 'production';
    $facts['seo_probe'] = pkdeSeoProbe();
    $s = pkdeStatuses($facts);

    expect($s['Production homepage is indexable (no noindex in X-Robots-Tag)'])->toBe('PASS')
        ->and($s['Production robots.txt does not disallow the whole site'])->toBe('PASS')
        ->and($s['Faceted shop URL (?orderby=) is noindex in the X-Robots-Tag header'])->toBe('PASS')
        ->and($s['Faceted shop URL (?orderby=) is noindex in the robots meta tag'])->toBe('PASS')
        ->and($s['Sitemap lists no noindex URL (cart, checkout, my-account, query-string URLs)'])->toBe('PASS');

    $facts['seo_probe'] = pkdeSeoProbe(['home' => ['x_robots' => 'noindex, nofollow'], 'robots_txt' => ['body' => "User-agent: *\nDisallow: /\n"], 'faceted' => ['x_robots' => '', 'meta_noindex' => false], 'sitemap' => ['noindex' => ['cart', 'a query-string URL']]]);
    $s = pkdeStatuses($facts);

    expect($s['Production homepage is indexable (no noindex in X-Robots-Tag)'])->toBe('FAIL')
        ->and($s['Production robots.txt does not disallow the whole site'])->toBe('FAIL')
        ->and($s['Faceted shop URL (?orderby=) is noindex in the X-Robots-Tag header'])->toBe('FAIL')
        ->and($s['Faceted shop URL (?orderby=) is noindex in the robots meta tag'])->toBe('FAIL')
        ->and($s['Sitemap lists no noindex URL (cart, checkout, my-account, query-string URLs)'])->toBe('FAIL');
});

it('holds staging to the safety net: header on pages and files, robots.txt disallow without a sitemap', function () {
    $facts = pkdeGoodFacts();
    $facts['env'] = 'staging';
    $facts['wp_env'] = 'staging';
    $facts['disallow_indexing'] = true;
    $facts['seo_probe'] = pkdeSeoProbe(['home' => ['x_robots' => 'noindex, nofollow'], 'static' => ['x_robots' => 'noindex, nofollow'], 'robots_txt' => ['body' => "User-agent: *\nDisallow: /\n"]]);
    $s = pkdeStatuses($facts);

    expect($s['Staging: pages carry X-Robots-Tag noindex, nofollow (platform module staging-safety-net)'])->toBe('PASS')
        ->and($s['Staging: static files carry X-Robots-Tag noindex, nofollow (.htaccess block Staging Noindex)'])->toBe('PASS')
        ->and($s['Staging: robots.txt disallows everything and names no sitemap'])->toBe('PASS');

    $facts['seo_probe'] = pkdeSeoProbe(['home' => ['x_robots' => 'noindex'], 'static' => ['x_robots' => ''], 'robots_txt' => ['body' => "User-agent: *\nDisallow: /\nSitemap: https://example.test/sitemap_index.xml\n"]]);
    $s = pkdeStatuses($facts);

    expect($s['Staging: pages carry X-Robots-Tag noindex, nofollow (platform module staging-safety-net)'])->toBe('FAIL')
        ->and($s['Staging: static files carry X-Robots-Tag noindex, nofollow (.htaccess block Staging Noindex)'])->toBe('FAIL')
        ->and($s['Staging: robots.txt disallows everything and names no sitemap'])->toBe('FAIL');
});

it('skips a probe that the site did not answer with 200 instead of failing it', function () {
    $facts = pkdeGoodFacts();
    $facts['wp_env'] = 'production';
    $facts['seo_probe'] = pkdeSeoProbe(['home' => ['status' => 401], 'sitemap' => ['status' => 0]]);
    $s = pkdeStatuses($facts);

    expect($s['Production homepage is indexable'])->toBe('SKIP')
        ->and($s['Sitemap lists no noindex URL'])->toBe('SKIP')
        ->and(array_keys($s, 'FAIL'))->toBe([]);
});

it('checks the security headers the shared .htaccess sets, and not HSTS', function () {
    $facts = pkdeGoodFacts();
    $facts['wp_env'] = 'production';
    $facts['seo_probe'] = pkdeSeoProbe();
    $s = pkdeStatuses($facts);

    expect($s['Security header x-content-type-options: nosniff'])->toBe('PASS')
        ->and($s['Security header referrer-policy: strict-origin-when-cross-origin'])->toBe('PASS')
        ->and($s['Security header x-frame-options: SAMEORIGIN'])->toBe('PASS')
        ->and($s['Security header permissions-policy is set'])->toBe('PASS')
        ->and(array_filter(array_keys($s), fn($c) => stripos($c, 'strict-transport') !== false))->toBe([]);

    $facts['seo_probe'] = pkdeSeoProbe(['home' => ['security' => ['x-frame-options' => '', 'permissions-policy' => '']]]);
    $s = pkdeStatuses($facts);

    expect($s['Security header x-frame-options: SAMEORIGIN'])->toBe('FAIL')
        ->and($s['Security header permissions-policy is set'])->toBe('FAIL');
});

it('holds a site to the Rank Math baseline and tells it what differs', function () {
    $good = ['knowledgegraph_type' => 'company', 'knowledgegraph_name' => 'Acme', 'knowledgegraph_logo' => 'https://example.test/logo.png', 'open_graph_image' => 'https://example.test/logo.png',
        'pt_page_default_rich_snippet' => 'off', 'pt_post_default_rich_snippet' => 'article', 'pt_post_default_article_type' => 'BlogPosting', 'pt_product_default_rich_snippet' => 'product',
        'pt_post_title' => '%title% %sep% %sitename%', 'tax_category_title' => '%term% %page% %sep% %sitename%', 'tax_category_description' => '%term_description%'];
    $facts = pkdeGoodFacts();
    $facts['rankmath_seo'] = ['titles' => $good, 'post_types' => ['page', 'post', 'product'], 'locale' => 'en_GB', 'want_locale' => 'en_GB'];
    $s = pkdeStatuses($facts);

    expect($s['Rank Math: the organization is a company, not a person'])->toBe('PASS')
        ->and($s['Rank Math: the organization name and logo are set'])->toBe('PASS')
        ->and($s['Rank Math: pages are a WebPage, posts a BlogPosting, products a Product'])->toBe('PASS')
        ->and($s['Rank Math: title templates have no "Archives" and the site name once'])->toBe('PASS')
        ->and($s['Rank Math: archive descriptions come from the term description'])->toBe('PASS')
        ->and($s['Rank Math: a default share image (og:image) is set'])->toBe('PASS')
        ->and($s['Site language is en_GB'])->toBe('PASS');

    $bad = array_replace($good, ['knowledgegraph_type' => 'person', 'knowledgegraph_logo' => '', 'open_graph_image' => '', 'pt_page_default_rich_snippet' => 'article',
        'tax_category_title' => '%term% Archives %sep% %sitename% %sep% %sitename%', 'tax_category_description' => '']);
    $facts['rankmath_seo'] = ['titles' => $bad, 'post_types' => ['page', 'post', 'product'], 'locale' => 'en_US', 'want_locale' => 'en_GB'];
    $s = pkdeStatuses($facts);

    expect($s['Rank Math: the organization is a company, not a person'])->toBe('FAIL')
        ->and($s['Rank Math: the organization name and logo are set'])->toBe('FAIL')
        ->and($s['Rank Math: pages are a WebPage, posts a BlogPosting, products a Product'])->toBe('FAIL')
        ->and($s['Rank Math: title templates have no "Archives" and the site name once'])->toBe('FAIL')
        ->and($s['Rank Math: archive descriptions come from the term description'])->toBe('FAIL')
        ->and($s['Rank Math: a default share image (og:image) is set'])->toBe('FAIL')
        ->and($s['Site language is en_GB'])->toBe('FAIL');
});

it('cleans a Rank Math title template and plans only what differs', function () {
    expect(pkde_rankmath_clean_title('%term% Archives %page% %sep% %sitename%'))->toBe('%term% %page% %sep% %sitename%')
        ->and(pkde_rankmath_clean_title('%title% %sep% %sitename% %sep% %sitename%'))->toBe('%title% %sep% %sitename%')
        ->and(pkde_rankmath_clean_title('%sitename% %page% %sep% %sitedesc%'))->toBe('%sitename% %page% %sep% %sitedesc%')
        ->and(pkde_rankmath_clean_title('%name% Archives'))->toBe('%name%');

    $titles = ['knowledgegraph_type' => 'person', 'pt_page_title' => '%title% %sep% %sitename%', 'tax_category_title' => '%term% Archives %sep% %sitename%', 'tax_category_description' => '%term_description%'];
    $plan = pkde_rankmath_baseline($titles, ['page', 'post'], ['name' => 'Acme', 'logo_url' => 'https://example.test/l.png', 'logo_id' => 7]);

    expect($plan)->toMatchArray(['knowledgegraph_type' => 'company', 'knowledgegraph_name' => 'Acme', 'knowledgegraph_logo' => 'https://example.test/l.png', 'knowledgegraph_logo_id' => 7, 'open_graph_image' => 'https://example.test/l.png',
        'tax_category_title' => '%term% %sep% %sitename%', 'pt_page_default_rich_snippet' => 'off'])
        ->and($plan)->not->toHaveKey('pt_page_title')
        ->and($plan)->not->toHaveKey('tax_category_description')
        ->and($plan)->not->toHaveKey('pt_product_default_rich_snippet')
        ->and(pkde_rankmath_baseline(['knowledgegraph_logo' => 'https://own/logo.png', 'open_graph_image' => 'https://own/og.png'], [], ['name' => '', 'logo_url' => 'https://example.test/l.png', 'logo_id' => 7]))->not->toHaveKey('open_graph_image')->not->toHaveKey('knowledgegraph_logo');
});

it('wants real icon files and a 410 for a layered-nav query, and skips what it could not reach (TPL-184)', function () {
    $facts = pkdeGoodFacts();
    $facts['wp_env'] = 'production';
    $facts['seo_probe'] = pkdeSeoProbe();
    $s = pkdeStatuses($facts);

    expect($s['Static /favicon.ico is a real image file, not a WordPress page'])->toBe('PASS')
        ->and($s['Static /apple-touch-icon.png is a real image file, not a WordPress page'])->toBe('PASS')
        ->and($s['A WooCommerce layered-nav query (?filter_x=a,b&query_type_x=or) answers 410'])->toBe('PASS');

    $facts['seo_probe'] = pkdeSeoProbe(['favicon' => ['type' => 'text/html; charset=UTF-8'], 'apple_touch' => ['status' => 404, 'type' => 'text/html'], 'flood' => ['status' => 200]]);
    $s = pkdeStatuses($facts);

    expect($s['Static /favicon.ico is a real image file, not a WordPress page'])->toBe('FAIL')
        ->and($s['Static /apple-touch-icon.png is a real image file, not a WordPress page'])->toBe('FAIL')
        ->and($s['A WooCommerce layered-nav query (?filter_x=a,b&query_type_x=or) answers 410'])->toBe('FAIL');

    $facts['seo_probe'] = pkdeSeoProbe(['flood' => ['status' => 401]]);
    expect(pkdeStatuses($facts)['A WooCommerce layered-nav query (?filter_x=a,b&query_type_x=or) answers 410'])->toBe('SKIP');
});

it('matches layered-nav query arguments with the rule in activate-release.sh and nothing the kit sends (TPL-184)', function () {
    $script = file_get_contents(dirname(__DIR__, 2) . '/bin/deploy/activate-release.sh');
    preg_match('/RewriteCond %\{QUERY_STRING\} (\S+) \[NC\]/', $script, $m);
    $regex = '~' . str_replace('~', '\\~', $m[1]) . '~i';

    foreach (['filter_colour=a,b&query_type_colour=or', 'x=1&filter_size=m', 'FILTER_size=x', 'rating_filter=4', 'orderby=price&query_type_pa_colour=and', 'filter_product_cat=clocks'] as $blocked) {
        expect(preg_match($regex, $blocked))->toBe(1, $blocked);
    }
    foreach (['', 'orderby=price', 'sage_filter_product_cat%5B%5D=clocks', 'sage_filter_pa_colour%5B%5D=red&min_price=5', 'filter_product_cat%5B%5D=a', 'filter_product_tag[]=a', 'refilter_a=1', 'sage_view=sale', 's=filter_x'] as $allowed) {
        expect(preg_match($regex, $allowed))->toBe(0, $allowed);
    }
});

it('reports which CF7 forms carry a lookup key, and fails only a key a theme file asks for that no form has (TPL-186)', function () {
    $facts = pkdeGoodFacts();
    $facts['cf7_forms'][0]['key'] = 'contact';
    $facts['cf7_key_usage'] = ['keys' => ['contact'], 'by_title' => []];
    $statuses = pkdeStatuses($facts);
    expect($statuses['CF7 forms with a lookup key (_pkde_form_key)'])->toBe('INFO')
        ->and($statuses['A theme file asks for CF7 form key "contact": a form carries it'])->toBe('PASS')
        ->and($statuses)->not->toHaveKey('A theme file still finds a CF7 form by its title');

    $facts['cf7_key_usage'] = ['keys' => ['contact', 'park-home-enquiry'], 'by_title' => ['views/front-page.blade.php']];
    $statuses = pkdeStatuses($facts);
    expect($statuses['A theme file asks for CF7 form key "park-home-enquiry": a form carries it'])->toBe('FAIL')
        ->and($statuses['A theme file still finds a CF7 form by its title'])->toBe('INFO');

    // no key asked for and no form tagged: INFO only, never a FAIL; without CF7 the rows are absent
    $facts = pkdeGoodFacts();
    expect(pkdeStatuses($facts)['CF7 forms with a lookup key (_pkde_form_key)'])->toBe('INFO');
    $facts['cf7_active'] = false;
    expect(pkdeStatuses($facts))->not->toHaveKey('CF7 forms with a lookup key (_pkde_form_key)');
});

it('fails a declared shop filter source that does not resolve, and lists the groups (WP-96)', function () {
    $facts = pkdeGoodFacts();
    expect(pkdeStatuses($facts))->not->toHaveKey('Shop filters: declared groups resolve'); // no shop: no rows

    $facts['woocommerce_active'] = true;
    $facts['shop_filters'] = ['declared' => true, 'groups' => ['price', 'product_cat', 'pa_colour'], 'invalid' => []];
    $statuses = pkdeStatuses($facts);
    expect($statuses['Shop filters: declared groups resolve'])->toBe('PASS')->and($statuses['Shop filters: groups'])->toBe('INFO');

    $facts['shop_filters']['invalid'] = ['pa_colur'];
    $rows = pkde_drift_evaluate($facts);
    $row = array_values(array_filter($rows, fn($r) => $r[1] === 'Shop filters: declared groups resolve'))[0];
    expect($row[0])->toBe('FAIL')->and($row[2])->toContain('pa_colur');

    // the kit without the declaration (an older kit): the facts are null and nothing is said
    $facts['shop_filters'] = null;
    expect(pkdeStatuses($facts))->not->toHaveKey('Shop filters: declared groups resolve');

    $facts['shop_filters'] = ['declared' => false, 'groups' => ['price', 'product_cat'], 'invalid' => []];
    $info = array_values(array_filter(pkde_drift_evaluate($facts), fn($r) => $r[1] === 'Shop filters: groups'))[0];
    expect($info[2])->toContain('not declared')->toContain('price, product_cat');
});

it('lists kit view overrides with how far they are from the kit\'s view, INFO only (TPL-199)', function () {
    expect(pkde_drift_line_diff("a\nb\nc\n", "a\nb\nc\n"))->toBe(0)
        ->and(pkde_drift_line_diff("a\nb\nc\n", "  a \n\nc\nb"))->toBe(0) // whitespace and order do not count
        ->and(pkde_drift_line_diff("a\nb\n", "a\nx\ny\n"))->toBe(3);

    $theme = sys_get_temp_dir() . '/pkde-theme-' . bin2hex(random_bytes(4));
    mkdir("$theme/resources/views/vendor/pkde-kit/partials/blocks", 0777, true);
    mkdir("$theme/vendor/pkde/sage-kit/resources/views/partials/blocks", 0777, true);
    file_put_contents("$theme/vendor/pkde/sage-kit/resources/views/partials/blocks/tiles.blade.php", "one\ntwo\nthree\nextra tile\n");
    file_put_contents("$theme/vendor/pkde/sage-kit/resources/views/partials/same.blade.php", "same\n");
    file_put_contents("$theme/resources/views/vendor/pkde-kit/partials/blocks/tiles.blade.php", "one\ntwo\nthree\n"); // lost a line
    file_put_contents("$theme/resources/views/vendor/pkde-kit/partials/same.blade.php", "same\n"); // identical
    file_put_contents("$theme/resources/views/vendor/pkde-kit/partials/gone.blade.php", "x\n"); // the kit dropped it
    file_put_contents("$theme/resources/views/vendor/pkde-kit/README.md", "not a view\n");

    expect(pkde_drift_kit_overrides($theme))->toBe(['partials/blocks/tiles.blade.php' => 1, 'partials/gone.blade.php' => -1, 'partials/same.blade.php' => 0]);

    $facts = pkdeGoodFacts();
    $facts['kit_overrides'] = pkde_drift_kit_overrides($theme);
    $row = array_values(array_filter(pkde_drift_evaluate($facts), fn($r) => $r[1] === 'Kit view overrides'))[0];
    expect($row[0])->toBe('INFO')->and($row[2])->toContain('3:')->toContain('tiles.blade.php (1 lines differ')->toContain('same.blade.php (identical to the kit: delete it)')->toContain('gone.blade.php (the kit has no such view any more)');

    // no override folder, or no kit installed: nothing to say
    expect(pkde_drift_kit_overrides(sys_get_temp_dir() . '/pkde-no-theme'))->toBe([]);
    $facts['kit_overrides'] = [];
    expect(pkdeStatuses($facts))->not->toHaveKey('Kit view overrides');

    exec('rm -rf ' . escapeshellarg($theme));
});

it('judges the block editor by role from the platform module (WP-100): PASS when an Editor is limited, FAIL when not, INFO when the module is absent', function () {
    $facts = pkdeGoodFacts();

    // module loaded and right
    $facts['editor_settings'] = ['can_lock' => false, 'custom_colors' => false, 'remote_patterns' => false, 'blocks_limited' => true, 'shortcode' => false];
    $statuses = pkdeStatuses($facts);
    foreach (['Block editor: an Editor cannot lock or unlock blocks', 'Block editor: custom colours and gradients are for Administrators only', 'Block editor: remote block patterns are off', 'Block editor: an Editor gets the curated block list (no shortcode block)'] as $check) {
        expect($statuses[$check])->toBe('PASS');
    }

    // module loaded, and an Editor can unlock, pick any colour and insert shortcodes
    $facts['editor_settings'] = ['can_lock' => true, 'custom_colors' => true, 'remote_patterns' => true, 'blocks_limited' => false, 'shortcode' => true];
    $statuses = pkdeStatuses($facts);
    expect($statuses['Block editor: an Editor cannot lock or unlock blocks'])->toBe('FAIL')
        ->and($statuses['Block editor: custom colours and gradients are for Administrators only'])->toBe('FAIL')
        ->and($statuses['Block editor: remote block patterns are off'])->toBe('FAIL')
        ->and($statuses['Block editor: an Editor gets the curated block list (no shortcode block)'])->toBe('FAIL');

    // an older platform (or the module switched off): one INFO row, no failures
    $facts['editor_settings'] = null;
    $statuses = pkdeStatuses($facts);
    expect($statuses['Block editor settings by role'])->toBe('INFO')->and($statuses)->not->toHaveKey('Block editor: remote block patterns are off');

    // a run that does not carry the fact says nothing
    unset($facts['editor_settings']);
    expect(pkdeStatuses($facts))->not->toHaveKey('Block editor settings by role');
});

it('checks the editor catalogue and the global blocks (WP-100): no bundled patterns, Rank Math hidden, a used global block has its declaration', function () {
    $facts = pkdeGoodFacts();
    $facts['editor_catalog'] = ['bundled_patterns' => 0, 'rank_math_visible' => []];
    $facts['global_blocks'] = ['cta-band' => ['pages' => 3, 'declared' => true]];
    $statuses = pkdeStatuses($facts);

    expect($statuses['Block editor: no core or WooCommerce patterns registered'])->toBe('PASS')
        ->and($statuses['Block editor: Rank Math blocks hidden from the inserter'])->toBe('PASS')
        ->and($statuses['Global block cta-band has its declared content'])->toBe('PASS');

    $facts['editor_catalog'] = ['bundled_patterns' => 112, 'rank_math_visible' => ['rank-math/faq-block']];
    $facts['global_blocks'] = ['cta-band' => ['pages' => 3, 'declared' => false]];
    $statuses = pkdeStatuses($facts);

    expect($statuses['Block editor: no core or WooCommerce patterns registered'])->toBe('FAIL')
        ->and($statuses['Block editor: Rank Math blocks hidden from the inserter'])->toBe('FAIL')
        ->and($statuses['Global block cta-band has its declared content'])->toBe('FAIL');

    // a block no page uses needs no declaration; no Rank Math blocks or no module: no row
    $facts['global_blocks'] = ['cta-band' => ['pages' => 0, 'declared' => false]];
    $facts['editor_catalog'] = ['bundled_patterns' => null, 'rank_math_visible' => null];
    $statuses = pkdeStatuses($facts);
    expect($statuses)->not->toHaveKey('Global block cta-band has its declared content')->not->toHaveKey('Block editor: Rank Math blocks hidden from the inserter')->not->toHaveKey('Block editor: no core or WooCommerce patterns registered');
});

it('checks the FAQs post type (WP-102): no public URL or sitemap entry, who manages it, the hand-typed blocks and the empty groups', function () {
    $facts = pkdeGoodFacts();
    $facts['faq'] = ['public' => false, 'rewrite' => false, 'has_archive' => false, 'in_sitemap' => false, 'admin_cap' => true, 'editor_cap' => false, 'seo_role' => true, 'seo_cap' => true, 'typed_blocks' => [], 'empty_groups' => []];
    $statuses = pkdeStatuses($facts);

    expect($statuses['FAQs: no public URL, archive or search'])->toBe('PASS')
        ->and($statuses['FAQs: not listed in the sitemap'])->toBe('PASS')
        ->and($statuses['FAQs: Administrators and the SEO role manage them, Editors do not'])->toBe('PASS')
        ->and($statuses)->not->toHaveKey('FAQ blocks still typed by hand')->not->toHaveKey('FAQ groups with no published FAQ');

    $facts['faq']['public'] = true;
    $facts['faq']['in_sitemap'] = true;
    $facts['faq']['editor_cap'] = true;
    $facts['faq']['seo_role'] = false;
    $facts['faq']['typed_blocks'] = ['shipping' => 3, 'returns' => 2];
    $facts['faq']['empty_groups'] = ['care'];
    $statuses = pkdeStatuses($facts);

    expect($statuses['FAQs: no public URL, archive or search'])->toBe('FAIL')
        ->and($statuses['FAQs: not listed in the sitemap'])->toBe('FAIL')
        ->and($statuses['FAQs: Administrators and the SEO role manage them, Editors do not'])->toBe('FAIL')
        ->and($statuses['FAQ blocks still typed by hand'])->toBe('INFO')
        ->and($statuses['FAQ groups with no published FAQ'])->toBe('WARN');

    $facts['faq'] = null;
    expect(pkdeStatuses($facts)['FAQs post type'])->toBe('INFO');

    unset($facts['faq']);
    expect(pkdeStatuses($facts))->not->toHaveKey('FAQs post type');
});

it('says which motion mode the site runs (WP-103), INFO only', function () {
    $facts = pkdeGoodFacts();
    $facts['motion'] = 'reduced';
    $statuses = pkdeStatuses($facts);

    expect($statuses['Motion (pkde_kit/motion)'])->toBe('INFO');

    $facts['motion'] = null;
    expect(pkdeStatuses($facts)['Motion (pkde_kit/motion)'])->toBe('INFO');

    unset($facts['motion']);
    expect(pkdeStatuses($facts))->not->toHaveKey('Motion (pkde_kit/motion)');
});

it('checks the legal documents (WP-104): Privacy and Cookies everywhere, Terms and Returns on a shop, the settings, dates and address clashes', function () {
    $facts = pkdeGoodFacts();
    $facts['woocommerce_active'] = false;
    $facts['legal'] = ['types' => ['privacy' => 1, 'cookies' => 1], 'no_date' => [], 'privacy_setting' => 'document', 'terms_setting' => 'unset', 'clashes' => []];
    $statuses = pkdeStatuses($facts);

    expect($statuses['Legal: a Privacy document is published'])->toBe('PASS')
        ->and($statuses['Legal: a Cookies document is published'])->toBe('PASS')
        ->and($statuses['Legal: the core privacy setting points at a published document'])->toBe('PASS')
        ->and($statuses['Legal: every document has an effective date'])->toBe('PASS')
        ->and($statuses['Legal: no document shares its address with a Page'])->toBe('PASS')
        ->and($statuses)->not->toHaveKey('Legal: a Terms document is published')->not->toHaveKey('Legal: the WooCommerce terms setting points at a published document');

    // a shop also needs Terms, Returns and the terms setting
    $facts['woocommerce_active'] = true;
    $statuses = pkdeStatuses($facts);
    expect($statuses['Legal: a Terms document is published'])->toBe('FAIL')
        ->and($statuses['Legal: a Returns document is published'])->toBe('FAIL')
        ->and($statuses['Legal: the WooCommerce terms setting points at a published document'])->toBe('FAIL');

    $facts['legal'] = ['types' => ['privacy' => 1, 'cookies' => 1, 'terms' => 1, 'returns' => 1], 'no_date' => ['privacy-policy'], 'privacy_setting' => 'other', 'terms_setting' => 'document', 'clashes' => ['cookies']];
    $statuses = pkdeStatuses($facts);
    expect($statuses['Legal: a Terms document is published'])->toBe('PASS')
        ->and($statuses['Legal: the WooCommerce terms setting points at a published document'])->toBe('PASS')
        ->and($statuses['Legal: the core privacy setting points at a published document'])->toBe('FAIL')
        ->and($statuses['Legal: every document has an effective date'])->toBe('FAIL')
        ->and($statuses['Legal: no document shares its address with a Page'])->toBe('FAIL');

    $facts['legal'] = null;
    expect(pkdeStatuses($facts)['Legal documents'])->toBe('INFO');

    unset($facts['legal']);
    expect(pkdeStatuses($facts))->not->toHaveKey('Legal documents');
});


it('checks Site details (WP-106): the type\'s required fields, stored values differing from the declaration, and a different value typed in Rank Math', function () {
    $facts = pkdeGoodFacts();
    $facts['site_details'] = ['type' => 'local-business', 'missing' => [], 'differs' => [], 'rank_math' => [], 'locked' => 4];
    $statuses = pkdeStatuses($facts);

    expect($statuses['Site details: the required fields for a local-business are present'])->toBe('PASS')
        ->and($statuses)->not->toHaveKey('Site details differing from the declaration');

    $facts['site_details'] = ['type' => 'shop', 'missing' => ['an address', 'the legal name'], 'differs' => ['business.trading_name'], 'rank_math' => ['phone', 'email'], 'locked' => 0];
    $statuses = pkdeStatuses($facts);

    expect($statuses['Site details: the required fields for a shop are present'])->toBe('FAIL')
        ->and($statuses['Site details differing from the declaration'])->toBe('INFO')
        ->and($statuses['Rank Math holds a different phone, email than Site details'])->toBe('WARN');

    $facts['site_details'] = null;
    expect(pkdeStatuses($facts)['Site details'])->toBe('INFO');

    unset($facts['site_details']);
    expect(pkdeStatuses($facts))->not->toHaveKey('Site details');
});


it('says which shape the site runs (WP-108 A), INFO only', function () {
    $facts = pkdeGoodFacts();
    $facts['shape'] = ['preset' => 'round', 'radius' => ['sm' => '0.75rem', 'md' => '1.5rem', 'lg' => '3rem', 'full' => '9999px'], 'border' => ['thin' => '1px', 'thick' => '2px']];
    expect(pkdeStatuses($facts)['Shape (corner radius and border thickness)'])->toBe('INFO');

    $facts['shape'] = null;
    expect(pkdeStatuses($facts)['Shape (corner radius and border thickness)'])->toBe('INFO');

    unset($facts['shape']);
    expect(pkdeStatuses($facts))->not->toHaveKey('Shape (corner radius and border thickness)');
});


it('says whether the site declares the global buttons (WP-109), INFO only', function () {
    $facts = pkdeGoodFacts();
    $facts['buttons'] = ['primary' => ['style' => 'text', 'colour' => '#353b3d'], 'secondary' => ['style' => 'outline', 'colour' => 'black'], 'text' => ['style' => 'text', 'colour' => 'black'], 'type' => [], 'hover' => 'darken'];
    expect(pkdeStatuses($facts)['Buttons (global button system)'])->toBe('INFO');

    foreach ([false, null] as $none) {
        $facts['buttons'] = $none;
        expect(pkdeStatuses($facts)['Buttons (global button system)'])->toBe('INFO');
    }

    unset($facts['buttons']);
    expect(pkdeStatuses($facts))->not->toHaveKey('Buttons (global button system)');
});
