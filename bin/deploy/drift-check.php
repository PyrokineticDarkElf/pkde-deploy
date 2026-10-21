<?php

/**
 * Drift check: compares a site's server-side settings with the template standard. READ ONLY and secret-free.
 *
 * Run it on the server in ONE SSH session (it must start with <?php: `wp eval-file` prints a file without the tag as
 * plain text and `wp eval-file -` / stdin does not work over SSH on 20i). From the site's release directory:
 *
 *   bash bin/deploy/wp.sh eval-file bin/deploy/drift-check.php
 *
 * or locally: docker compose run --rm wpcli wp --allow-root eval-file bin/deploy/drift-check.php
 *
 * Prints one PASS / FAIL / SKIP / INFO line per check and exits 1 when anything FAILs. It never writes (no update_option,
 * no wfConfig::set), and it never prints secrets: Turnstile keys are reported only as present or missing. SKIP marks checks
 * that only apply outside development (keys, login slug, Post SMTP, the W3TC standard). Standards: the Wordfence alert settings, Turnstile, the fixed CF7 mail subject and
 * the deployment guide's "Server-settings drift check" section.
 *
 * Loaded without WordPress (the Pest test) it only defines the pure functions below.
 */

require_once __DIR__ . '/w3tc-standard.php';

const PKDE_DRIFT_SUBJECT = 'New website enquiry - [_site_title] - [your-name]';

/**
 * Is a CF7 mail subject the fixed standard? Fixed prefix "New website enquiry - ", then [_site_title] or the literal site name,
 * then " - [your-name]", then optionally one more " - [mail-tag]" (a form that also names the page or listing it came from).
 * A visitor-controlled [your-subject], or a different prefix, never passes.
 */
function pkde_drift_subject_ok(string $subject, string $siteTitle = ''): bool
{
    $titles = ['\[_site_title\]'];
    foreach (array_unique([trim($siteTitle), trim(html_entity_decode($siteTitle))]) as $name) {
        if ($name !== '') {
            $titles[] = preg_quote($name, '/');
        }
    }

    return preg_match('/^New website enquiry - (?:' . implode('|', $titles) . ') - \[your-name\](?: - \[[A-Za-z0-9_-]+\])?$/', trim($subject)) === 1;
}

/** The Wordfence values bin/setup seeds; alertEmails is checked separately (must include admin_email). */
function pkde_drift_wordfence_standard(): array
{
    return [
        'alertOn_update' => '0', 'alertOn_severityLevel' => '75', 'alertOn_block' => '0', 'alertOn_loginLockout' => '0',
        'alertOn_lostPasswdForm' => '0', 'alertOn_breachLogin' => '1', 'alertOn_adminLogin' => '1',
        'alertOn_firstAdminLoginOnly' => '1', 'alertOn_wordfenceDeactivated' => '1', 'alertOn_wafDeactivated' => '1',
        'alertOn_scanIssues' => '1', 'scansEnabled_plugins' => '1', 'scansEnabled_themes' => '1',
        'alert_maxHourly' => '3', 'email_summary_enabled' => '1', 'email_summary_interval' => 'weekly',
    ];
}

/** Plugins every site is expected to have active (slug = plugin directory). */
function pkde_drift_core_plugins(): array
{
    return ['wordfence', 'seo-by-rank-math', 'ewww-image-optimizer', 'w3-total-cache', 'speculation-rules'];
}

function pkde_drift_is_placeholder_email(string $email): bool
{
    $email = strtolower(trim($email));

    return $email === '' || preg_match('/@(example\.(com|org|net)|localhost|.*\.test|.*\.local)$/', $email) === 1;
}

/**
 * Pure comparison of collected facts with the standard.
 *
 * @param  array<string,mixed>  $f  facts from pkde_drift_collect()
 * @return list<array{0:string,1:string,2:string}> [status, check, detail]
 */
function pkde_drift_evaluate(array $f): array
{
    $dev = in_array($f['env'] ?? 'production', ['development', 'local'], true);
    $out = [];
    $add = function (string $status, string $check, string $detail = '') use (&$out) {
        $out[] = [$status, $check, $detail];
    };
    $cmp = function (string $check, bool $ok, string $detail = '', bool $devSkip = false) use ($add, $dev) {
        $add($ok ? 'PASS' : ($devSkip && $dev ? 'SKIP' : 'FAIL'), $check, $ok ? '' : $detail);
    };

    // Wordfence is inactive on staging by design (by design): there it is a SKIP, not a FAIL, and only while it really is inactive.
    // An ACTIVE Wordfence on staging is still held to the standard.
    $wfOffOnStaging = ($f['env'] ?? '') === 'staging' && ! in_array('wordfence', (array) ($f['active_plugins'] ?? []), true);

    // Wordfence
    $wf = $f['wordfence'] ?? null;
    if ($wfOffOnStaging) {
        $add('SKIP', 'Wordfence', 'inactive on staging by design');
    } elseif (! is_array($wf)) {
        $add('FAIL', 'Wordfence', 'wfConfig is not available (plugin inactive?)');
    } else {
        foreach (pkde_drift_wordfence_standard() as $key => $want) {
            $got = isset($wf[$key]) ? (string) $wf[$key] : '(unset)';
            $cmp("Wordfence $key", $got === $want, "expected $want, got $got");
        }
        $emails = array_map('trim', explode(',', strtolower((string) ($wf['alertEmails'] ?? ''))));
        $admin = strtolower((string) ($f['admin_email'] ?? ''));
        $cmp(
            'Wordfence alertEmails includes admin_email',
            $admin !== '' && in_array($admin, $emails, true),
            $emails === [''] ? 'alertEmails is empty: no alert reaches anyone' : 'admin_email is not in alertEmails',
        );
    }

    // W3 Total Cache (the standard in bin/deploy/w3tc-standard.php, written by bin/deploy/w3tc-settings.php)
    $w3 = $f['w3tc'] ?? null;
    if (! is_array($w3)) {
        $cmp('W3 Total Cache', false, 'W3TC is not available (plugin inactive?)', true);
    } else {
        $sitemap = (string) ($w3['expected_sitemap'] ?? '');
        foreach (pkde_w3tc_standard($sitemap) as $key => $want) {
            $got = $w3['settings'][$key] ?? null;
            $show = fn($v) => is_bool($v) ? ($v ? 'on' : 'off') : ($v === null ? '(unset)' : (string) $v);
            $ok = is_bool($want) ? (bool) $got === $want && $got !== null : (string) $got === (string) $want;
            $cmp("W3TC $key", $ok, 'expected ' . $show($want) . ', got ' . $show($got), true);
        }
        if (! empty($f['woocommerce_active'])) {
            $missing = array_diff(pkde_w3tc_woocommerce_cookies(), (array) ($w3['reject_cookies'] ?? []));
            $cmp('W3TC page cache skips the WooCommerce cookies', $missing === [], 'missing: ' . implode(', ', $missing), true);
        }
    }

    // Rank Math must be registered (the setup wizard skipped, or an account connected). Unregistered, Rank Math silently disables its
    // modules: the sitemap, titles and schema stop. A fresh or scrubbed database loses the flag; bin/setup sets it.
    if (in_array('seo-by-rank-math', (array) ($f['active_plugins'] ?? []), true)) {
        $rm = $f['rankmath'] ?? [];
        $cmp('Rank Math is registered (registration skipped or account connected)', ! empty($rm['skip']) || ! empty($rm['connected']), 'neither rank_math_registration_skip nor a connected account: Rank Math disables its modules (sitemap, titles, schema); run `wp option update rank_math_registration_skip 1`');
    }

    // Turnstile
    $ts = $f['turnstile'] ?? [];
    $cmp('Turnstile widget always visible (cfturnstile_appearance)', ($ts['appearance'] ?? 'always') === 'always', 'expected always, got ' . (string) ($ts['appearance'] ?? ''));
    $cmp('Turnstile on the login form (cfturnstile_login)', (string) ($ts['login'] ?? '') === '1', 'expected 1, got ' . (string) ($ts['login'] ?? '(unset)'), true);
    $cmp('Turnstile on all Contact Form 7 forms (cfturnstile_cf7_all)', (string) ($ts['cf7_all'] ?? '') === '1' || ! ($f['cf7_active'] ?? false), 'expected 1, got ' . (string) ($ts['cf7_all'] ?? '(unset)'), true);
    $cmp('Turnstile site and secret keys present', ($ts['keys_present'] ?? false) === true, 'a key is missing (values are never printed)', true);
    $cmp('Turnstile keys tested in wp-admin (cfturnstile_tested)', ($ts['tested'] ?? '') === 'yes', 'expected yes, got ' . (string) ($ts['tested'] ?? '(unset)'), true);
    $add('INFO', 'Turnstile cfturnstile_reset', 'is ' . ((string) ($ts['reset'] ?? '') === '1' ? 'on' : 'off') . ' (no standard decided)');

    // WPS Hide Login
    $hl = $f['hide_login'] ?? [];
    $slug = (string) ($hl['slug'] ?? '');
    $cmp('WPS Hide Login active', ($hl['active'] ?? false) === true, 'plugin is not active', true);
    $cmp('WPS Hide Login slug changed from the default', $slug !== '' && $slug !== 'login', $slug === '' ? 'no custom slug set' : 'slug is still the default "login"', true);

    // Contact Form 7 forms
    foreach ($f['cf7_forms'] ?? [] as $form) {
        $label = 'CF7 form #' . (int) ($form['id'] ?? 0);
        $cmp("$label has no visitor Subject field", empty($form['has_subject']), 'form still has a your-subject field');
        $cmp("$label mail subject is the fixed text", pkde_drift_subject_ok((string) ($form['subject'] ?? ''), (string) ($f['blogname'] ?? '')), 'got "' . (string) ($form['subject'] ?? '') . '"');
        if (! empty($form['mail2_active'])) {
            $cmp("$label Mail (2) subject is the fixed text", pkde_drift_subject_ok((string) ($form['mail2_subject'] ?? ''), (string) ($f['blogname'] ?? '')), 'got "' . (string) ($form['mail2_subject'] ?? '') . '"');
        }
    }

    // Site identity
    $cmp('admin_email is not a placeholder', ! pkde_drift_is_placeholder_email((string) ($f['admin_email'] ?? '')), 'admin_email is empty or a placeholder domain', true);
    $desc = trim((string) ($f['blogdescription'] ?? ''));
    $cmp('blogdescription is set and not the WordPress default', $desc !== '' && stripos($desc, 'just another wordpress') === false, $desc === '' ? 'empty' : 'still the default tagline', true);

    // Plugins
    $active = (array) ($f['active_plugins'] ?? []);
    $expected = pkde_drift_core_plugins();
    if ($f['cf7_active'] ?? false) {
        $expected[] = 'simple-cloudflare-turnstile';
    }
    if (! empty($f['smtp_host_set'])) {
        $expected[] = 'post-smtp';
    }
    if (! $dev) {
        $expected[] = 'wps-hide-login';
    }
    // The opt-ins (Turnstile with CF7, Post SMTP with SMTP_HOST, the hidden login) are not active on a development stack.
    foreach ($expected as $slugName) {
        if ($slugName === 'wordfence' && $wfOffOnStaging) {
            $add('SKIP', 'Plugin wordfence active', 'inactive on staging by design');

            continue;
        }
        $cmp("Plugin $slugName active", in_array($slugName, $active, true), 'not active', in_array($slugName, ['simple-cloudflare-turnstile', 'post-smtp', 'wps-hide-login'], true));
    }
    $extra = array_values(array_diff($active, $expected, ['contact-form-7', 'woocommerce', 'wps-hide-login', 'post-smtp', 'simple-cloudflare-turnstile']));
    if ($extra !== []) {
        $add('INFO', 'Other active plugins', implode(', ', $extra));
    }

    return $out;
}

/** Reads the facts from WordPress. Only reads; secrets are reduced to presence. */
function pkde_drift_collect(): array
{
    $wfKeys = array_merge(array_keys(pkde_drift_wordfence_standard()), ['alertEmails']);
    $wf = null;
    if (class_exists('wfConfig')) {
        $wf = [];
        foreach ($wfKeys as $k) {
            $wf[$k] = wfConfig::get($k);
        }
    }

    $const = fn(string $name) => defined($name) ? trim((string) constant($name)) : '';
    $keys = ($const('CF_TURNSTILE_SITE_KEY') !== '' && $const('CF_TURNSTILE_SECRET_KEY') !== '')
        || (trim((string) get_option('cfturnstile_key')) !== '' && trim((string) get_option('cfturnstile_secret')) !== '');

    $forms = [];
    if (post_type_exists('wpcf7_contact_form')) {
        foreach (get_posts(['post_type' => 'wpcf7_contact_form', 'post_status' => 'any', 'numberposts' => -1]) as $p) {
            $mail = (array) get_post_meta($p->ID, '_mail', true);
            $mail2 = (array) get_post_meta($p->ID, '_mail_2', true);
            $forms[] = [
                'id' => $p->ID,
                'has_subject' => strpos((string) get_post_meta($p->ID, '_form', true), 'your-subject') !== false,
                'subject' => (string) ($mail['subject'] ?? ''),
                'mail2_active' => ! empty($mail2['active']),
                'mail2_subject' => (string) ($mail2['subject'] ?? ''),
            ];
        }
    }

    $w3 = null;
    if (class_exists('\\W3TC\\Dispatcher')) {
        $config = \W3TC\Dispatcher::config();
        $w3 = ['settings' => [], 'reject_cookies' => (array) $config->get_array('pgcache.reject.cookie'), 'expected_sitemap' => home_url('/sitemap_index.xml')];
        foreach (array_keys(pkde_w3tc_standard('')) as $k) {
            $w3['settings'][$k] = $config->get($k);
        }
    }

    $active = array_map(fn($p) => dirname($p) === '.' ? basename($p, '.php') : dirname($p), (array) get_option('active_plugins', []));

    return [
        'env' => wp_get_environment_type(),
        'admin_email' => (string) get_option('admin_email'),
        'blogname' => (string) get_option('blogname'),
        'blogdescription' => (string) get_option('blogdescription'),
        'wordfence' => $wf,
        'w3tc' => $w3,
        'rankmath' => [
            'skip' => (bool) get_option('rank_math_registration_skip'),
            'connected' => ! empty(((array) get_option('rank_math_connect_data'))['api_key'] ?? ''),
        ],
        'woocommerce_active' => in_array('woocommerce', $active, true),
        'turnstile' => [
            'appearance' => get_option('cfturnstile_appearance', 'always'),
            'login' => get_option('cfturnstile_login'),
            'cf7_all' => get_option('cfturnstile_cf7_all'),
            'tested' => get_option('cfturnstile_tested'),
            'reset' => get_option('cfturnstile_reset'),
            'keys_present' => $keys,
        ],
        'hide_login' => ['active' => in_array('wps-hide-login', $active, true), 'slug' => (string) get_option('whl_page')],
        'cf7_forms' => $forms,
        'cf7_active' => in_array('contact-form-7', $active, true),
        'smtp_host_set' => $const('SMTP_HOST') !== '',
        'active_plugins' => array_values($active),
    ];
}

if (! function_exists('get_option')) {
    return; // loaded without WordPress: only the pure functions are wanted
}

$pkdeFails = 0;
foreach (pkde_drift_evaluate(pkde_drift_collect()) as [$status, $check, $detail]) {
    echo str_pad($status, 5) . ' ' . $check . ($detail !== '' ? ': ' . $detail : '') . "\n";
    $pkdeFails += $status === 'FAIL' ? 1 : 0;
}
echo $pkdeFails === 0 ? "\nNo drift against the template standard.\n" : "\n$pkdeFails check(s) FAILED.\n";

if ($pkdeFails > 0) {
    exit(1);
}
