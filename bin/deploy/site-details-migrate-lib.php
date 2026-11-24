<?php

/**
 * The pure parts of site-details-migrate.php (WP-106): how the old `client_*` options (and a site adapter) map onto Site details, and how the result fills a site's stored values.
 * No WordPress needed.
 */

/** The old options and where each goes: option => [what it is, the Site details field]. */
const PKDE_SD_MAP = [
    'client_phone' => ['the phone number', 'phones[0]'],
    'client_email' => ['the email address', 'emails[0]'],
    'client_address' => ['the address (one line, kept as the street)', 'locations[0].street'],
    'client_facebook' => ['Facebook', 'socials: facebook'],
    'client_instagram' => ['Instagram', 'socials: instagram'],
    'client_linkedin' => ['LinkedIn', 'socials: linkedin'],
    'client_x' => ['X', 'socials: x'],
    'client_youtube' => ['YouTube', 'socials: youtube'],
];

/**
 * The submission-shaped array (what the screen would post) built from the old options, the site name and an optional adapter.
 *
 * @param  array<string, string>  $options  option name => raw value, as stored (not through the read shim)
 * @param  array<string, mixed>  $adapter  a site's own values in the Site details shape (cs: from App\Business), merged over the options' ones
 * @return array<string, mixed>
 */
function pkde_sd_from_options(array $options, string $siteName, array $adapter = []): array
{
    $in = ['business' => ['trading_name' => trim($siteName)], 'phones' => [], 'emails' => [], 'locations' => [], 'socials' => []];
    $get = fn(string $key) => trim((string) ($options[$key] ?? ''));

    if ($get('client_phone') !== '') {
        $in['phones'][] = ['label' => '', 'number' => $get('client_phone')];
    }

    if ($get('client_email') !== '') {
        $in['emails'][] = ['label' => '', 'address' => $get('client_email')];
    }

    if ($get('client_address') !== '') {
        $in['locations'][] = ['label' => '', 'street' => $get('client_address')];
    }

    foreach (['facebook', 'instagram', 'linkedin', 'x', 'youtube'] as $network) {
        if ($get("client_$network") !== '') {
            $in['socials'][] = ['network' => $network, 'url' => $get("client_$network")];
        }
    }

    foreach ($adapter as $group => $value) {
        if (is_array($value) && $value !== [] && isset($in[$group]) && is_array($in[$group])) {
            $in[$group] = $group === 'business' ? array_replace($in[$group], array_filter($value, fn($v) => $v !== '')) : $value;
        } elseif (! isset($in[$group]) && $value !== [] && $value !== '') {
            $in[$group] = $value;
        }
    }

    return $in;
}

/**
 * The stored details filled from the migrated ones: a field that already has a value is KEPT (a second run, or a value edited in wp-admin, is never overwritten); an empty one takes
 * the migrated value. Idempotent.
 *
 * @param  array<string, mixed>  $stored  the option as it is (may be empty)
 * @param  array<string, mixed>  $migrated  from site_details_sanitize()
 * @return array<string, mixed>
 */
function pkde_sd_fill(array $stored, array $migrated): array
{
    $out = $stored + $migrated;

    foreach ($migrated['business'] ?? [] as $key => $value) {
        if (($stored['business'][$key] ?? '') === '') {
            $out['business'][$key] = $value;
        }
    }

    foreach (['phones', 'emails', 'locations', 'socials'] as $group) {
        $out[$group] = ($stored[$group] ?? []) !== [] ? $stored[$group] : ($migrated[$group] ?? []);
    }

    if (($stored['whatsapp'] ?? '') === '') {
        $out['whatsapp'] = $migrated['whatsapp'] ?? '';
    }

    $hasHours = fn($h) => array_filter((array) ($h['weekly'] ?? []), fn($d) => ($d['open'] ?? '') !== '') !== [];
    $out['hours'] = $hasHours($stored['hours'] ?? []) ? $stored['hours'] : ($migrated['hours'] ?? ($stored['hours'] ?? []));

    return $out;
}

/** One line per old option for the plan: "client_phone -> phones[0] (the phone number): 0113 200 0000". */
function pkde_sd_plan_lines(array $options): array
{
    $lines = [];

    foreach (PKDE_SD_MAP as $option => [$what, $target]) {
        $value = trim((string) ($options[$option] ?? ''));
        $lines[] = $value === '' ? "$option: not set" : "$option -> $target ($what): $value";
    }

    return $lines;
}
