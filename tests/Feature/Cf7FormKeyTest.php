<?php

// Contact Form 7 forms found by a key. The plan and the scan are pure; the script runs in a separate PHP process against small stand-ins.
require_once dirname(__DIR__, 2) . '/bin/deploy/cf7-form-key-standard.php';

function pkdeForms(array ...$rows): array
{
    return array_map(fn($r) => ['id' => $r[0], 'title' => $r[1], 'key' => $r[2] ?? ''], $rows);
}

it('tags several forms from a key to title list, each once', function () {
    $plan = pkde_cf7_key_plan(pkdeForms([5, 'Contact Form'], [8, 'Park Home Enquiry'], [9, 'Newsletter']), ['contact' => 'Contact form', 'park-home-enquiry' => 'Park Home Enquiry']);

    expect($plan)->toBe([
        ['key' => 'contact', 'title' => 'Contact form', 'action' => 'tag', 'id' => 5],
        ['key' => 'park-home-enquiry', 'title' => 'Park Home Enquiry', 'action' => 'tag', 'id' => 8],
    ]);
});

it('is idempotent and rename-safe: a key already on a form wins over any title', function () {
    $plan = pkde_cf7_key_plan(pkdeForms([5, 'Get in touch', 'contact'], [8, 'Park Home Enquiry', 'park-home-enquiry']), ['contact' => 'Contact form', 'park-home-enquiry' => 'Park Home Enquiry']);

    expect(array_column($plan, 'action'))->toBe(['already', 'already'])->and(array_column($plan, 'id'))->toBe([5, 8]);
});

it('refuses to guess: no form, several forms, a form with another key, a bad key, one form for two keys', function () {
    $forms = pkdeForms([5, 'Contact form'], [6, 'Contact form'], [7, 'Quote', 'quote-old'], [8, 'Shared']);
    $plan = pkde_cf7_key_plan($forms, ['contact' => 'Contact form', 'enquiry' => 'Nothing here', 'quote' => 'Quote', 'Bad Key' => 'Shared', 'a' => 'Shared', 'b' => 'Shared']);

    expect(array_column($plan, 'action'))->toBe(['ambiguous', 'missing', 'taken', 'invalid', 'tag', 'taken']);
});

it('reads key=Title arguments and defaults to the template form', function () {
    expect(pkde_cf7_key_wanted([]))->toBe(['contact' => 'Contact form'])
        ->and(pkde_cf7_key_wanted(['contact=Contact Form', 'park-home-enquiry=Park Home Enquiry', 'junk']))->toBe(['contact' => 'Contact Form', 'park-home-enquiry' => 'Park Home Enquiry']);
});

it('finds the keys a theme file asks for and a leftover title lookup', function () {
    $scan = pkde_cf7_key_scan("<?php \$a = \\App\\form_id('park-home-enquiry'); echo form_shortcode(\"contact\"); \$b = \\App\\form_id();");
    expect($scan['keys'])->toBe(['park-home-enquiry', 'contact'])->and($scan['by_title'])->toBeFalse();

    $old = pkde_cf7_key_scan("\$f = get_page_by_title('Contact Form', OBJECT, 'wpcf7_contact_form');");
    expect($old['by_title'])->toBeTrue()->and($old['keys'])->toBe([]);
    expect(pkde_cf7_key_scan('function wpcf7_form_id() {}')['keys'])->toBe([]);
});

function pkdeRunCf7Key(array $posts, array $args = []): array
{
    $prelude = tempnam(sys_get_temp_dir(), 'pkde-ck');
    $script = var_export(dirname(__DIR__, 2) . '/bin/deploy/cf7-form-key.php', true);
    file_put_contents($prelude, '<?php
$GLOBALS["posts"] = ' . var_export($posts, true) . ';
$GLOBALS["meta"] = [];
foreach ($GLOBALS["posts"] as $p) { if (! empty($p[2])) { $GLOBALS["meta"][$p[0]] = $p[2]; } }
function post_type_exists($t) { return $t === "wpcf7_contact_form"; }
function get_posts($a) { return array_map(fn($p) => (object) ["ID" => $p[0], "post_title" => $p[1]], $GLOBALS["posts"]); }
function get_post_meta($id, $k, $single) { return $GLOBALS["meta"][$id] ?? ""; }
function update_post_meta($id, $k, $v) { $GLOBALS["meta"][$id] = $v; }
$args = ' . var_export($args, true) . ';
register_shutdown_function(function () { echo "META:", json_encode($GLOBALS["meta"]), "\n"; });
include ' . $script . ';
');
    $out = shell_exec('php ' . escapeshellarg($prelude) . ' 2>&1');
    unlink($prelude);

    return explode("\n", trim((string) $out));
}

it('tags the forms and prints key and ID only, and a second run changes nothing', function () {
    $posts = [[5, 'Contact Form'], [8, 'Park Home Enquiry']];
    $first = pkdeRunCf7Key($posts, ['contact=Contact Form', 'park-home-enquiry=Park Home Enquiry']);
    expect($first)->toBe(['contact -> form #5 (tagged)', 'park-home-enquiry -> form #8 (tagged)', 'META:{"5":"contact","8":"park-home-enquiry"}']);

    $again = pkdeRunCf7Key([[5, 'Renamed', 'contact'], [8, 'Park Home Enquiry', 'park-home-enquiry']], ['contact=Contact Form', 'park-home-enquiry=Park Home Enquiry']);
    expect($again)->toBe(['contact -> form #5 (already tagged)', 'park-home-enquiry -> form #8 (already tagged)', 'META:{"5":"contact","8":"park-home-enquiry"}']);
});

it('reports a form it cannot tag and tags nothing it was not sure of', function () {
    $out = pkdeRunCf7Key([[5, 'Something else']], []);
    expect($out[0])->toBe('contact -> NOT TAGGED: no form has that title')->and(end($out))->toBe('META:[]');
});
