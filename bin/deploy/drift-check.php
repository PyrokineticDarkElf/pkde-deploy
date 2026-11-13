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
require_once __DIR__ . '/alert-email.php';

const PKDE_DRIFT_SUBJECT = 'New website enquiry - [_site_title] - [your-name]';

/** Does a CF7 mail's Additional headers Cc the site admin through the special tag (no address stored in the form)? */
function pkde_drift_cc_admin_ok(string $headers): bool
{
    return preg_match('/^\s*Cc:[^\r\n]*\[_site_admin_email\]/mi', $headers) === 1;
}

/** Is a CF7 form name descriptive (not CF7's own "Contact form 1" or "Untitled")? */
function pkde_drift_form_name_ok(string $title): bool
{
    $title = trim($title);

    return $title !== '' && preg_match('/^(contact form \d+|untitled)$/i', $title) !== 1;
}

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
    return ['wordfence', 'ewww-image-optimizer', 'w3-total-cache', 'speculation-rules'];
}

/** The SEO plugins a site may use: exactly one of these is expected (Rank Math is the template's default; Yoast, free or premium, is accepted). */
function pkde_drift_seo_plugins(): array
{
    return ['seo-by-rank-math', 'wordpress-seo', 'wordpress-seo-premium'];
}

function pkde_drift_is_placeholder_email(string $email): bool
{
    $email = strtolower(trim($email));

    return $email === '' || preg_match('/@(example\.(com|org|net)|localhost|.*\.test|.*\.local)$/', $email) === 1;
}

/**
 * Theme view files that read per-visitor data on the server (the basket, a pending notice, a cookie, the user): each one is a leak when the page
 * cache serves visitors with a basket (MGR-76). Read only; the kit's own views live in the package, not here. Returns paths relative to $dir.
 *
 * @return list<string>
 */
function pkde_drift_personal_views(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $pattern = '/WC\(\)->(?:cart|session|customer)|get_cart_contents_count|wc_print_notices|wc_get_notices|wp_get_current_user|get_current_user_id|\$_COOKIE|wp_get_current_commenter|comment_form\(/';
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php') && preg_match($pattern, (string) file_get_contents($file->getPathname()))) {
            $found[] = ltrim(substr($file->getPathname(), strlen($dir)), '/');
        }
    }
    sort($found);

    return $found;
}

/**
 * What the breadcrumb standard looks like on this site: whether the kit's standard is present, the separator and the home label Rank Math would
 * actually get (the kit's filters applied), and whether Rank Math's breadcrumb module is on. Read only.
 *
 * @return array<string, mixed>
 */
function pkde_drift_breadcrumb_facts(): array
{
    $settings = (array) apply_filters('rank_math/frontend/breadcrumb/settings', []);
    $strings = (array) apply_filters('rank_math/frontend/breadcrumb/strings', []);
    $rankMath = class_exists('RankMath\\Helper');

    return [
        'kit' => function_exists('App\\breadcrumb_standard'),
        'separator' => (string) ($settings['separator'] ?? ''),
        'home' => (string) ($strings['home'] ?? ''),
        'rank_math' => $rankMath,
        'module_on' => $rankMath && \RankMath\Helper::is_breadcrumbs_enabled(),
    ];
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

    // The environment as .env declares it (MGR-67, bp): a .env split between environments (a production WP_ENV with a staging
    // DISALLOW_INDEXING, or a staging file copied to production) looks clean row by row. Only when the facts carry WP_ENV.
    if (array_key_exists('wp_env', $f)) {
        $wpEnv = (string) $f['wp_env'];
        $type = (string) ($f['env'] ?? '');
        $expected = ['production' => ['production'], 'staging' => ['staging'], 'development' => ['local', 'development']][$wpEnv] ?? null;
        $cmp('WP_ENV and WP_ENVIRONMENT_TYPE agree', $expected !== null && in_array($type, $expected, true), "WP_ENV is '$wpEnv' but the environment type is '$type'");
        $indexing = ! empty($f['disallow_indexing']);
        if ($wpEnv === 'production') {
            $cmp('DISALLOW_INDEXING is off on production', ! $indexing, 'DISALLOW_INDEXING is true: search engines are blocked');
            $cmp('STAGING_MAIL_TO is not set on production', empty($f['staging_mail_to']), 'a staging variable is set (a staging .env copied here?)');
        } elseif ($wpEnv === 'staging') {
            $cmp('DISALLOW_INDEXING is on for staging', $indexing, 'DISALLOW_INDEXING is not true: staging can be indexed');
            $add('PASS', 'STAGING_MAIL_TO', ! empty($f['staging_mail_to']) ? 'staging mail goes to the one test address' : 'staging mail is suppressed (no STAGING_MAIL_TO)');
        }
    }

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
        $alertAddress = pkde_alert_address((string) ($f['alert_email_const'] ?? ''), (string) ($f['wp_admin_email'] ?? ''));
        if ($alertAddress !== '') {
            // The .env names the alert address (WP-87): Wordfence's recipients are exactly it. No address is printed.
            $cmp(
                'Wordfence alertEmails is exactly the alert address (WORDFENCE_ALERT_EMAIL, else WP_ADMIN_EMAIL)',
                pkde_alert_emails_exact((string) ($wf['alertEmails'] ?? ''), $alertAddress),
                'alertEmails differs from the alert address in .env: the next release sets it (bin/deploy/wordfence-standard.php), or run that file with wp eval-file',
            );
        } else {
            $emails = array_map('trim', explode(',', strtolower((string) ($wf['alertEmails'] ?? ''))));
            $admin = strtolower((string) ($f['admin_email'] ?? ''));
            $cmp(
                'Wordfence alertEmails includes admin_email',
                $admin !== '' && in_array($admin, $emails, true),
                $emails === [''] ? 'alertEmails is empty: no alert reaches anyone' : 'admin_email is not in alertEmails',
            );
        }
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
            // Default: the basket cookies skip the page cache. Opted in (pkde_cache_basket_visitors = 1, MGR-76): they must NOT, or the opt-in does nothing.
            if (! empty($f['cache_basket_visitors'])) {
                $rejected = array_intersect(pkde_w3tc_basket_cookies(), (array) ($w3['reject_cookies'] ?? []));
                $cmp('W3TC page cache serves visitors with a basket (opted in)', $rejected === [], 'still rejected: ' . implode(', ', $rejected) . ' (re-run bin/deploy/w3tc-settings.php)', true);
                // The opt-in is only safe while no page prints per-visitor data: the kit's cache-safe basket count (App\no_page_cache exists) and no theme view reading the basket.
                $cmp('Cache-safe theme for basket visitors: the kit marks personal pages uncacheable', ! empty($f['kit_cache_safe']), 'App\\no_page_cache() is missing: bump pkde/sage-kit to 2026.11.06 or later before opting in', true);
                $personal = (array) ($f['personal_views'] ?? []);
                $cmp('Cache-safe theme for basket visitors: no view prints per-visitor data', $personal === [], 'read the basket, a notice, a cookie or the user: ' . implode(', ', $personal), true);
            } else {
                $missing = array_diff(pkde_w3tc_woocommerce_cookies(), (array) ($w3['reject_cookies'] ?? []));
                $cmp('W3TC page cache skips the WooCommerce cookies', $missing === [], 'missing: ' . implode(', ', $missing), true);
            }
        }
    }

    // W3TC's working parts beyond its options (bb and tcs staging, 2026-11-05): the page cache only serves with WP_CACHE true, the cleanup and
    // preload events must be scheduled (applying the standard by script does not schedule them; w3tc-settings.php does), and the newest cached page
    // must be younger than the lifetime, or the preloader and the garbage collection are not running (WP-Cron needs visits, or a server-side cron).
    // Only when the facts carry them, and only while the page cache is switched on.
    if (is_array($w3) && ! empty($w3['settings']['pgcache.enabled']) && array_key_exists('page_cache', $f)) {
        $pc = (array) $f['page_cache'];
        $cmp('WP_CACHE is true (the page cache serves)', ! empty($pc['wp_cache']), 'WP_CACHE is not true: WordPress never loads the page-cache drop-in (add WP_CACHE=true to the shared .env)', true);
        foreach (['w3_pgcache_prime' => 'preload', 'w3_pgcache_cleanup' => 'cleanup'] as $event => $label) {
            if ($event === 'w3_pgcache_prime' && empty($w3['settings']['pgcache.prime.enabled'])) {
                continue;
            }
            $cmp("W3TC $label cron event is scheduled", ! empty($pc['cron'][$event]), "$event is not scheduled (run bin/deploy/w3tc-settings.php, which schedules it)", true);
        }
        $lifetime = (int) ($w3['settings']['pgcache.lifetime'] ?? 86400);
        $age = $pc['newest_age'] ?? null;
        $cmp(
            'W3TC newest cached page is younger than the lifetime',
            $age !== null && (int) $age <= $lifetime + 3600,
            $age === null ? 'no cached page found: the cache is empty or not being filled (preloader/cron not running?)' : sprintf('the newest cached page is %d hours old against a %d hour lifetime: the preloader and garbage collection are not running (is a server-side cron set?)', intdiv((int) $age, 3600), intdiv($lifetime, 3600)),
            true,
        );
    }

    // Rank Math must be registered (the setup wizard skipped, or an account connected). Unregistered, Rank Math silently disables its
    // modules: the sitemap, titles and schema stop. A fresh or scrubbed database loses the flag; bin/setup sets it.
    if (in_array('seo-by-rank-math', (array) ($f['active_plugins'] ?? []), true)) {
        $rm = $f['rankmath'] ?? [];
        $cmp('Rank Math is registered (registration skipped or account connected)', ! empty($rm['skip']) || ! empty($rm['connected']), 'neither rank_math_registration_skip nor a connected account: Rank Math disables its modules (sitemap, titles, schema); run `wp option update rank_math_registration_skip 1`');
    }

    // Relevanssi (the shop's search, TPL-133): installed but never indexed, it answers every search with nothing. settings script: the kit's
    // bin/relevanssi-settings.php rebuilds the index. Only while the plugin is active; the fact is the number of rows in its index table.
    // Author archives (WP-88): closed for users without an allowed role by the platform module; INFO when real authors lack the role (their archive is a 404 by design)
    if (is_array($f['author_archive'] ?? null)) {
        $aa = $f['author_archive'];
        $cmp('Author archives are closed for users without an allowed role (platform module author-archives)', ! empty($aa['module']), 'the pkde-platform module author-archives is not loaded: bump pkde/platform to 2026.11.13 or later');
        $without = (int) ($aa['without_role'] ?? 0);
        if ($without > 0) {
            $add('INFO', 'Users with published posts without an allowed author role', $without . ' of ' . (int) ($aa['authors'] ?? 0) . ' users with published posts hold none of (' . implode(', ', (array) ($aa['roles'] ?? [])) . '): their archive is a 404 and their byline links home; give them the role or set AUTHOR_ARCHIVE_ROLES in .env');
        } else {
            $add('PASS', 'Users with published posts without an allowed author role', '');
        }
    }

    if (in_array('relevanssi', (array) ($f['active_plugins'] ?? []), true) && array_key_exists('relevanssi_indexed', $f)) {
        $cmp('Relevanssi index is built (searches find products)', (int) $f['relevanssi_indexed'] > 0, 'the Relevanssi index is empty: run wp eval-file vendor/pkde/sage-kit/bin/relevanssi-settings.php (it rebuilds the index)', true);
    }

    // Breadcrumbs (MGR-77 parity): the kit owns the trail. Its filters force Rank Math's settings to the standard (" / ", "Home") and the kit declares
    // theme support so the module is on whatever the wp-admin setting says. The kit builds no trail of its own (owner, 2026-11-06).
    // Sites on a kit older than 2026.11.06.3 have no standard: their trail follows each site's own Rank Math settings.
    $bc = $f['breadcrumbs'] ?? null;
    if (is_array($bc)) {
        $cmp(
            'Breadcrumbs: the kit sets the standard (separator "/", label "Home")',
            ! empty($bc['kit']) && ($bc['separator'] ?? '') === '/' && ($bc['home'] ?? '') === 'Home',
            empty($bc['kit']) ? 'App\\breadcrumb_standard() is missing: bump pkde/sage-kit to 2026.11.06.3 or later' : 'separator "' . ($bc['separator'] ?? '') . '", label "' . ($bc['home'] ?? '') . '" (a filter or a copy overrides the kit)',
        );
        if (! empty($bc['rank_math'])) {
            $cmp('Breadcrumbs: Rank Math\'s breadcrumb module is on (its BreadcrumbList schema)', ! empty($bc['module_on']), 'off: pages outside the shop have no trail and no BreadcrumbList schema (the kit declares theme support unless pkde_kit/force_rank_math_breadcrumbs is false)', true);
        }
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
    // The slug must come from .env (LOGIN_SLUG), the same on staging and production; the value is never printed.
    if (! empty($hl['active'])) {
        $cmp('Login slug managed by .env', ! empty($hl['managed']), 'LOGIN_SLUG is not set (or unusable, or the login-slug module is off): the slug is the stored whl_page option and can differ between staging and production; copy production\'s whl_page into LOGIN_SLUG in shared/.env', true);
    }
    $cmp('WPS Hide Login slug changed from the default', $slug !== '' && $slug !== 'login', $slug === '' ? 'no custom slug set' : 'slug is still the default "login"', true);

    // Contact Form 7 forms
    foreach ($f['cf7_forms'] ?? [] as $form) {
        $label = 'CF7 form #' . (int) ($form['id'] ?? 0);
        $cmp("$label has no visitor Subject field", empty($form['has_subject']), 'form still has a your-subject field');
        $cmp("$label has a descriptive name", pkde_drift_form_name_ok((string) ($form['title'] ?? '')), 'named "' . (string) ($form['title'] ?? '') . '": give it a name that says what it is, such as "Contact form" or "Park home enquiry"');
        $cmp("$label Mail Ccs the admin (Cc: [_site_admin_email])", pkde_drift_cc_admin_ok((string) ($form['headers'] ?? '')), 'the Mail tab\'s Additional headers has no "Cc: [_site_admin_email]" line');
        $cmp("$label mail subject is the fixed text", pkde_drift_subject_ok((string) ($form['subject'] ?? ''), (string) ($f['blogname'] ?? '')), 'got "' . (string) ($form['subject'] ?? '') . '"');
        if (! empty($form['mail2_active'])) {
            $cmp("$label Mail (2) subject is the fixed text", pkde_drift_subject_ok((string) ($form['mail2_subject'] ?? ''), (string) ($f['blogname'] ?? '')), 'got "' . (string) ($form['mail2_subject'] ?? '') . '"');
        }
    }

    // Site identity
    if (trim((string) ($f['wp_admin_email'] ?? '')) !== '') {
        $cmp('admin_email equals WP_ADMIN_EMAIL (.env)', strcasecmp(trim((string) ($f['admin_email'] ?? '')), trim((string) $f['wp_admin_email'])) === 0, 'admin_email differs from WP_ADMIN_EMAIL in .env (addresses are not printed here): wp option update admin_email "<the .env value>"', true);
    }
    $cmp('admin_email is not a placeholder', ! pkde_drift_is_placeholder_email((string) ($f['admin_email'] ?? '')), 'admin_email is empty or a placeholder domain', true);
    $desc = trim((string) ($f['blogdescription'] ?? ''));
    $cmp('blogdescription is set and not the WordPress default', $desc !== '' && stripos($desc, 'just another wordpress') === false, $desc === '' ? 'empty' : 'still the default tagline', true);

    // Plugins
    $active = (array) ($f['active_plugins'] ?? []);
    $cmp('An SEO plugin is active (Rank Math or Yoast)', array_intersect(pkde_drift_seo_plugins(), $active) !== [], 'none of ' . implode(', ', pkde_drift_seo_plugins()) . ' is active');
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
    // Post SMTP must be able to send, not just be active: 4.0.2 marks its transport "not ready" when the From address is empty. Only
    // presence is checked (a value is never printed).
    if (in_array('post-smtp', $active, true)) {
        $ps = $f['post_smtp'] ?? null;
        $missing = [];
        if (! is_array($ps)) {
            $missing[] = 'its options could not be read';
        } else {
            $transport = (string) ($ps['transport'] ?? '');
            $transport === 'smtp' || $missing[] = 'transport is ' . ($transport === '' ? 'unset' : "'$transport'") . ', not smtp';
            // The class that really sends: Post SMTP falls back to its Default transport (the web server's local mail) when the SMTP
            // one is not configured and ready, whatever the stored transport says.
            $sending = (string) ($ps['active_transport'] ?? '');
            $sending === 'PostmanSmtpModuleTransport' || $missing[] = 'the active transport is ' . ($sending === '' ? 'unknown' : $sending) . ', not PostmanSmtpModuleTransport (mail would not go through SMTP)';
            empty($ps['sender_set']) && $missing[] = 'no sender (From) address: set SMTP_FROM';
            empty($ps['envelope_set']) && $missing[] = 'no envelope sender: SMTP_FROM should fill it';
            empty($ps['host_set']) && $missing[] = 'no SMTP host: set SMTP_HOST';
            empty($ps['password_set']) && $missing[] = 'no SMTP password: set SMTP_PASSWORD';
        }
        $cmp('Post SMTP configured and ready', $missing === [], implode('; ', $missing), true);
    }
    $extra = array_values(array_diff($active, $expected, pkde_drift_seo_plugins(), ['contact-form-7', 'woocommerce', 'wps-hide-login', 'post-smtp', 'simple-cloudflare-turnstile']));
    if ($extra !== []) {
        $add('INFO', 'Other active plugins', implode(', ', $extra));
    }

    return $out;
}

/** Reads the facts from WordPress. Only reads; secrets are reduced to presence. */
/** Rows in Relevanssi's index table (0 when the table does not exist yet). */
function pkde_drift_relevanssi_indexed(): int
{
    global $wpdb;

    $table = $wpdb->prefix . 'relevanssi';

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return 0;
    }

    return (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
}

/** Author archive facts: is the module loaded, how many users have published posts, how many of them hold no allowed role. Counts only, no names. */
function pkde_drift_author_archive(): array
{
    $module = function_exists('App\\MuPlugins\\author_has_allowed_role') && function_exists('App\\MuPlugins\\author_archive_allowed_roles');
    $roles = $module ? \App\MuPlugins\author_archive_allowed_roles() : ['author'];
    $users = get_users(['has_published_posts' => true, 'fields' => ['ID']]);
    $without = 0;

    foreach ($users as $u) {
        $user = get_userdata((int) $u->ID);
        if (! $user || array_intersect($roles, (array) $user->roles) === []) {
            $without++;
        }
    }

    return ['module' => $module, 'roles' => $roles, 'authors' => count($users), 'without_role' => $without];
}

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
                'title' => (string) $p->post_title,
                'headers' => (string) ($mail['additional_headers'] ?? ''),
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

    $pageCache = null;
    if ($w3 !== null) {
        $dir = defined('W3TC_CACHE_PAGE_ENHANCED_DIR') ? W3TC_CACHE_PAGE_ENHANCED_DIR : WP_CONTENT_DIR . '/cache/page_enhanced';
        $newest = 0;
        $seen = 0;
        if (is_dir($dir)) {
            // The newest cached page (never more than 5000 files looked at: this is a check, not a crawl). Names: _index*.html, _index*.html_gzip.
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && str_starts_with($file->getFilename(), '_index')) {
                    $newest = max($newest, $file->getMTime());
                }
                if (++$seen >= 5000) {
                    break;
                }
            }
        }
        $pageCache = [
            'wp_cache' => defined('WP_CACHE') && WP_CACHE,
            'cron' => ['w3_pgcache_prime' => (bool) wp_next_scheduled('w3_pgcache_prime'), 'w3_pgcache_cleanup' => (bool) wp_next_scheduled('w3_pgcache_cleanup')],
            'newest_age' => $newest ? time() - $newest : null,
        ];
    }

    $active = array_map(fn($p) => dirname($p) === '.' ? basename($p, '.php') : dirname($p), (array) get_option('active_plugins', []));

    return [
        'env' => wp_get_environment_type(),
        'wp_env' => $const('WP_ENV'),
        'disallow_indexing' => defined('DISALLOW_INDEXING') && DISALLOW_INDEXING,
        'staging_mail_to' => $const('STAGING_MAIL_TO') !== '' || trim((string) getenv('STAGING_MAIL_TO')) !== '',
        'admin_email' => (string) get_option('admin_email'),
        'wp_admin_email' => pkde_env_setting('WP_ADMIN_EMAIL'),
        'alert_email_const' => pkde_env_setting('WORDFENCE_ALERT_EMAIL'),
        'blogname' => (string) get_option('blogname'),
        'blogdescription' => (string) get_option('blogdescription'),
        'wordfence' => $wf,
        'w3tc' => $w3,
        'page_cache' => $pageCache,
        'rankmath' => [
            'skip' => (bool) get_option('rank_math_registration_skip'),
            'connected' => ! empty(((array) get_option('rank_math_connect_data'))['api_key'] ?? ''),
        ],
        'relevanssi_indexed' => in_array('relevanssi', $active, true) ? pkde_drift_relevanssi_indexed() : null,
        'author_archive' => pkde_drift_author_archive(),
        'woocommerce_active' => in_array('woocommerce', $active, true),
        'breadcrumbs' => pkde_drift_breadcrumb_facts(),
        'cache_basket_visitors' => pkde_w3tc_cache_basket_visitors(),
        'kit_cache_safe' => function_exists('App\\no_page_cache'),
        'personal_views' => pkde_drift_personal_views(get_template_directory() . '/resources/views'),
        'turnstile' => [
            'appearance' => get_option('cfturnstile_appearance', 'always'),
            'login' => get_option('cfturnstile_login'),
            'cf7_all' => get_option('cfturnstile_cf7_all'),
            'tested' => get_option('cfturnstile_tested'),
            'reset' => get_option('cfturnstile_reset'),
            'keys_present' => $keys,
        ],
        'hide_login' => [
            'active' => in_array('wps-hide-login', $active, true),
            'slug' => (string) get_option('whl_page'),
            // LOGIN_SLUG set, usable, and really in force (the platform's login-slug module answers get_option with it).
            'managed' => $const('LOGIN_SLUG') !== '' && (string) get_option('whl_page') === sanitize_title_with_dashes($const('LOGIN_SLUG')),
        ],
        'cf7_forms' => $forms,
        'cf7_active' => in_array('contact-form-7', $active, true),
        'smtp_host_set' => $const('SMTP_HOST') !== '',
        'post_smtp' => class_exists('PostmanOptions') ? (function () {
            $o = PostmanOptions::getInstance();

            $active = class_exists('PostmanTransportRegistry') ? PostmanTransportRegistry::getInstance()->getActiveTransport() : null;

            return [
                'transport' => (string) $o->getTransportType(),
                'active_transport' => $active ? get_class($active) : '',
                'sender_set' => trim((string) $o->getMessageSenderEmail()) !== '',
                'envelope_set' => trim((string) $o->getEnvelopeSender()) !== '',
                'host_set' => trim((string) $o->getHostname()) !== '',
                'password_set' => (string) $o->getPassword() !== '',
            ];
        })() : null,
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
