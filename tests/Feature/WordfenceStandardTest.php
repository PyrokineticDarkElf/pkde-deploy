<?php

/**
 * bin/deploy/wordfence-standard.php (WP-87) in a child PHP with a fake wfConfig: the release step sets Wordfence's alertEmails to exactly the alert address from
 * .env, leaves a site alone that names none, and never prints an address.
 */
function pkdeRunWordfenceStandard(array $constants, ?string $current, bool $wordfence = true): array
{
    $defines = '';
    foreach ($constants as $name => $value) {
        $defines .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
    }
    $fake = $wordfence ? 'class wfConfig { public static array $d = ' . var_export(['alertEmails' => $current], true) . '; public static function get($k) { return self::$d[$k] ?? null; } public static function set($k, $v) { self::$d[$k] = $v; } }' : '';
    $prelude = "<?php\n$defines$fake\nrequire " . var_export(dirname(__DIR__, 2) . '/bin/deploy/wordfence-standard.php', true) . ";\n"
        . ($wordfence ? "echo 'FINAL=', wfConfig::get('alertEmails'), \"\\n\";\n" : '');
    $file = tempnam(sys_get_temp_dir(), 'wfstd');
    file_put_contents($file, $prelude);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
    unlink($file);
    preg_match('/FINAL=(.*)/', $out, $m);

    return [$out, $m[1] ?? null];
}

it('sets alertEmails to exactly the admin address when only WP_ADMIN_EMAIL is set', function () {
    [$out, $final] = pkdeRunWordfenceStandard(['WP_ADMIN_EMAIL' => 'office@acme-widgets.co.uk'], 'office@acme-widgets.co.uk, old@else.test');

    expect($final)->toBe('office@acme-widgets.co.uk')
        ->and($out)->toContain('set to exactly the alert address');
});

it('uses WORDFENCE_ALERT_EMAIL when it is set and valid, and falls back to the admin address when it is not', function () {
    [, $final] = pkdeRunWordfenceStandard(['WP_ADMIN_EMAIL' => 'office@acme-widgets.co.uk', 'WORDFENCE_ALERT_EMAIL' => 'alerts@acme-widgets.co.uk'], 'office@acme-widgets.co.uk');
    expect($final)->toBe('alerts@acme-widgets.co.uk');

    [, $final] = pkdeRunWordfenceStandard(['WP_ADMIN_EMAIL' => 'office@acme-widgets.co.uk', 'WORDFENCE_ALERT_EMAIL' => ''], '');
    expect($final)->toBe('office@acme-widgets.co.uk');
});

it('changes nothing when alertEmails is already exactly the address, and prints no address', function () {
    [$out, $final] = pkdeRunWordfenceStandard(['WP_ADMIN_EMAIL' => 'office@acme-widgets.co.uk'], 'Office@Acme-Widgets.co.uk');
    $printed = preg_replace('/FINAL=.*/', '', $out);

    expect($out)->toContain('already exactly')
        ->and($final)->toBe('Office@Acme-Widgets.co.uk')
        ->and($printed)->not->toContain('@');
});

it('leaves a site alone that names no address, or has no Wordfence', function () {
    [$out, $final] = pkdeRunWordfenceStandard([], 'keep@else.test');
    expect($out)->toContain('left alone')->and($final)->toBe('keep@else.test');

    [$out] = pkdeRunWordfenceStandard(['WP_ADMIN_EMAIL' => 'office@acme-widgets.co.uk'], null, false);
    expect($out)->toContain('Wordfence is not active');
});
