<?php

/**
 * bin/deploy/site-details-migrate.php, the pure parts in site-details-migrate-lib.php. The two steps against a real WordPress (old options, plan, apply, a second apply,
 * the read shim, an adapter, a locked field) were run on the template stack; the report says so.
 */
require_once dirname(__DIR__, 2) . '/bin/deploy/site-details-migrate-lib.php';

it('maps the old options and the site title onto the Site details shape, an adapter filling what the options do not', function () {
    $in = pkde_sd_from_options(['client_phone' => ' 0113 200 0000 ', 'client_email' => 'hello@example.test', 'client_address' => '1 High St, Leeds', 'client_facebook' => 'https://facebook.com/x', 'client_x' => ''], 'Acme Clocks');

    expect($in['business'])->toBe(['trading_name' => 'Acme Clocks'])
        ->and($in['phones'])->toBe([['label' => '', 'number' => '0113 200 0000']])
        ->and($in['emails'])->toBe([['label' => '', 'address' => 'hello@example.test']])
        ->and($in['locations'])->toBe([['label' => '', 'street' => '1 High St, Leeds']])
        ->and($in['socials'])->toBe([['network' => 'facebook', 'url' => 'https://facebook.com/x']]);

    // cs: the constants of App\Business, through the adapter, win over an empty option and add the business fields
    $withAdapter = pkde_sd_from_options(['client_phone' => ''], 'Acme', ['business' => ['legal_name' => 'Acme Ltd', 'company_number' => '123', 'vat_number' => ''], 'phones' => [['label' => 'Showroom', 'number' => '0113 111 1111']], 'hours' => ['weekly' => ['mon' => ['open' => '09:00', 'close' => '17:00']]]]);
    expect($withAdapter['business'])->toBe(['trading_name' => 'Acme', 'legal_name' => 'Acme Ltd', 'company_number' => '123'])
        ->and($withAdapter['phones'][0]['label'])->toBe('Showroom')
        ->and($withAdapter['hours']['weekly']['mon']['open'])->toBe('09:00');
});

it('fills only what is empty, so a second run and a value edited in wp-admin are never overwritten', function () {
    $migrated = ['business' => ['trading_name' => 'Acme', 'legal_name' => 'Acme Ltd'], 'phones' => [['label' => '', 'number' => '+441132000000', 'display' => '0113 200 0000']], 'emails' => [], 'locations' => [], 'socials' => [['network' => 'facebook', 'url' => 'https://facebook.com/x', 'label' => '']], 'whatsapp' => '',
        'hours' => ['weekly' => ['mon' => ['open' => '09:00', 'close' => '17:00']], 'exceptions' => []]];
    $stored = ['business' => ['trading_name' => 'Edited', 'legal_name' => ''], 'phones' => [['label' => 'Own', 'number' => '+442222222222', 'display' => '02222 222222']]];

    $filled = pkde_sd_fill($stored, $migrated);

    expect($filled['business'])->toBe(['trading_name' => 'Edited', 'legal_name' => 'Acme Ltd'])
        ->and($filled['phones'][0]['number'])->toBe('+442222222222')
        ->and($filled['socials'][0]['network'])->toBe('facebook')
        ->and($filled['hours']['weekly']['mon']['open'])->toBe('09:00')
        ->and(pkde_sd_fill($filled, $migrated))->toBe($filled); // the second run changes nothing
});

it('lists every old option in the plan, set or not', function () {
    $lines = pkde_sd_plan_lines(['client_phone' => '0113 200 0000']);

    expect($lines)->toHaveCount(count(PKDE_SD_MAP))
        ->and($lines[0])->toBe('client_phone -> phones[0] (the phone number): 0113 200 0000')
        ->and($lines[1])->toBe('client_email: not set');
});

it('ships the script with the two steps and the refusal when the module is missing', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/bin/deploy/site-details-migrate.php');

    expect($source)->toContain('PLAN (nothing was changed)', 'The Site details module is not loaded', 'Nothing was changed', 'adapter=PATH', 'site_details_strip_locked', 'SITE_DETAILS_OPTION')
        ->and($source)->not->toContain('delete_option');
});
