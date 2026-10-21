<?php

// bin/deploy/drift-check.php returns early outside WordPress, so its pure functions load here without WordPress.
require_once dirname(__DIR__, 2) . '/bin/deploy/drift-check.php';

function pkdeGoodFacts(): array
{
    return [
        'env' => 'production',
        'admin_email' => 'office@example.test',
        'blogdescription' => 'Quality widgets for everyone',
        'wordfence' => array_merge(pkde_drift_wordfence_standard(), ['alertEmails' => 'office@example.test']),
        'turnstile' => ['appearance' => 'always', 'login' => '1', 'cf7_all' => '1', 'tested' => 'yes', 'reset' => '', 'keys_present' => true],
        'hide_login' => ['active' => true, 'slug' => 'staff-entrance'],
        'w3tc' => ['settings' => pkde_w3tc_standard('https://example.test/sitemap_index.xml'), 'reject_cookies' => ['woocommerce_items_in_cart', 'wp_woocommerce_session_', 'other'], 'expected_sitemap' => 'https://client.co.uk/sitemap_index.xml'],
        'rankmath' => ['skip' => true, 'connected' => false],
        'woocommerce_active' => true,
        'cf7_forms' => [['id' => 5, 'has_subject' => false, 'subject' => PKDE_DRIFT_SUBJECT, 'mail2_active' => false, 'mail2_subject' => '']],
        'cf7_active' => true,
        'smtp_host_set' => true,
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
