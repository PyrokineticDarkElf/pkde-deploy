# Changelog

## 2026.11.12.5

WP-84 bug, second data point (jsd staging: two sessions closed by the host at `wp.sh option update admin_email <address>` with the output piped through sed; the same change as `update_option()` in an eval-file, script written to the home directory and output sent to a file, worked). **Adopt:** bump the package and the workflow tag (with 2026.11.12.4).

- **`wordfence-cloudflare.sh` sends its PHP as ssh stdin into a temporary file and runs `eval-file`, its output going to a file that is read back.** The remote command line is fixed text (a path and the WP-CLI call); the PHP is no longer in it (it was an inline `wp eval '...'` of about 1.3 KB). The temporary files are removed whatever happens and the PHP's exit code is the script's. rp's failing step carried no address on the command line and printed none (the old PHP wrote `howGetIPs` and a version-named option, not `alertEmails`), so the address hypothesis does not explain rp; what the two failures share is a command or output streamed over the live session, and what worked on both is the file pattern. Test: `tests/deploy/wordfence-cloudflare.test.sh`.

## 2026.11.12.4

WP-84 bug (rp staging: 20i closed the SSH session twice at the `wp eval` step of `wordfence-cloudflare.sh`). **Adopt:** bump the package and the workflow tag; re-run `wordfence-cloudflare.sh` for sites that have not had a clean run (the sites hold that step until the manager releases it).

- **`wordfence-cloudflare.sh` no longer loads Wordfence.** It runs `php83 -d memory_limit=512M <wp> --skip-plugins --skip-themes eval '...'` and reads and writes Wordfence's `wfconfig` table with plain SQL (`howGetIPs`, `detectProxyNextCheck`, the dismiss option), the same rows `wfConfig::set()` writes for those keys. Measured on the template stack: the eval itself cost 4 ms, 6 queries, no HTTP request and no extra memory; the cost was the plugin bootstrap (98 MB peak with the template's plugins, no `memory_limit` flag on the old call). Without plugins the peak is 53 MB. The cause of the 20i disconnect is not proven (no site access); this removes the heaviest part and the missing memory flag.
- **The release step `wordfence-standard.php` takes the same path**: `activate-release.sh` runs it with `--skip-plugins --skip-themes` and it writes `alertEmails` through the new `wfconfig-sql.php` (shipped in every release). It was warn-only, killed after `WP_CLI_TIMEOUT`, and could not fail a deploy, but it was a third full bootstrap.

## 2026.11.12.3

WP-87 follow-up. **Adopt:** bump the package and the workflow tag (with 2026.11.12.2).

- **`WP_ADMIN_EMAIL` and `WORDFENCE_ALERT_EMAIL` are read from the `.env` when the site has no `Config::define` lines for them** (the constant wins, then Bedrock's `env()`, then `getenv()`), by the release step and the drift rows. A site that set the variables in its `.env` but has not ported the template's `config/application.php` block is therefore checked, not silently skipped.

## 2026.11.12.2

The site's `.env` in 1Password (WP-87, owner go 2026-11-12), part 1: the tools and the server-side checks. **Adopt:** bump the package and the workflow tag. Nothing changes on a site until its `.env` sets `WP_ADMIN_EMAIL` (and optionally `WORDFENCE_ALERT_EMAIL`) and, for the tools, until the owner has created the vault and seeded the note.

- **`env-pull.sh <host> --env staging|prod --vault ...`.** Brings the server's `shared/.env` in line with the SECURE_NOTE `<host> .env` (one field per variable, label = the variable name, value = the literal right-hand side of the line). A dry run unless `--apply`; `--check` changes nothing and exits 1 when they differ (it also names variables in `.env.example` that the note lacks). It refuses, writing nothing, when a required variable (WP_ENV, WP_HOME, WP_SITEURL, DB_*, the eight salts) is empty or missing in the note or a value spans lines; it adds and changes, and removes a server-only variable only with `--prune`. `--apply` keeps `.env.bak-<time>`, writes with umask 077 through an atomic move, then requests `WP_HOME` and prints the rollback command when it answers 5xx. Production also needs `--production-go`. Values are never printed or put on a command line (`op` to this process to ssh stdin); output is variable names only.
- **`env-seed.sh <host> --env ... --vault ...`.** Creates that note once from the server's `.env` (a 1Password write, run in a terminal; `--dry-run` prints names only); refuses an item that already exists.
- **Release step `wordfence-standard.php`** (run by `activate-release.sh`, shipped in every release): Wordfence `alertEmails` = exactly the alert address, `WORDFENCE_ALERT_EMAIL` else `WP_ADMIN_EMAIL`. A site that sets neither, or has no Wordfence, is left alone. The address is never printed.
- **Drift rows (only once `WP_ADMIN_EMAIL` or `WORDFENCE_ALERT_EMAIL` is set):** "admin_email equals WP_ADMIN_EMAIL (.env)" (no address printed) and "Wordfence alertEmails is exactly the alert address" in place of "includes admin_email". Without those variables the old row stays.

## 2026.11.12.1

Owner standard for contact forms (manager WP-83, 2026-11-12). **Adopt:** bump the package and the workflow tag; read the drift check; fix each FAIL on the site's own forms (staging first).

- **Two drift rows per Contact Form 7 form.** "CF7 form #N has a descriptive name": FAIL while the form is still called CF7's own "Contact form 1" (or "Untitled"); name it for what it is, for example "Contact form" or "Park home enquiry" (shortcodes resolve by id or hash, so renaming is safe). "CF7 form #N Mail Ccs the admin (Cc: [_site_admin_email])": FAIL unless the Mail tab's Additional headers has a `Cc:` line with the `[_site_admin_email]` special tag, so no address is stored in the form and the Cc follows `admin_email`. Mail (2), the visitor's auto-reply, is not checked and should not Cc. Facts: `title` and `headers` per form; pure checks `pkde_drift_form_name_ok()` and `pkde_drift_cc_admin_ok()`.

## 2026.11.12

From the manager (WP-84, owner 2026-11-12: Wordfence Diagnostics shows REMOTE_ADDR as the visitor, so it stays). **Adopt:** bump the package and the workflow tag, then run `vendor/pkde/deploy/bin/deploy/wordfence-cloudflare.sh staging` (and `production` in the go session).

- **`bin/deploy/wordfence-cloudflare.sh` also dismisses the wp-admin notice "How does Wordfence get IPs is misconfigured"** (Wordfence recommends CF-Connecting-IP behind Cloudflare). It sets the option Wordfence's own "No thanks" link sets, `misconfiguredHowGetIPsChoice<version>`; idempotent ("Already up to date" on a re-run). The name carries the plugin version, so a Wordfence update brings the notice back until the script runs again; the template's `config/application.php` defines `WORDFENCE_DISABLE_MISCONFIGURED_HOWGETIPS` (Wordfence's own switch) to end that. The same-named scan finding is not touched: Ignore it once in Wordfence > Scan.

## 2026.11.11

From the template backlog (TPL-133). **Adopt:** bump the package and the workflow tag; read the drift check.

- **Drift row "Relevanssi index is built (searches find products)".** Only while Relevanssi is active: FAIL on staging and production when its index table is empty (the plugin is installed and every search finds nothing), skipped on a local stack. The cure is the kit's `bin/relevanssi-settings.php`, which rebuilds the index (the template's shop setup now runs it). A site without Relevanssi is unaffected.

## 2026.11.06.2

- **Wording only:** the breadcrumb module row no longer says the kit prints its own trail when Rank Math's module is off (the kit builds none, owner 2026-11-06; sage-kit 2026.11.06.4). Off now means no trail outside the shop and no BreadcrumbList schema. **Adopt:** bump the package and the workflow tags.


## 2026.11.06.1

Breadcrumb standard (MGR-77 parity; needs sage-kit 2026.11.06.3, which owns the trail). **Adopt:** bump the package and the workflow tags; read the drift check.

- **Two drift rows.** "Breadcrumbs: the kit sets the standard (separator "/", label "Home")": the separator and home label Rank Math would actually get, with the kit's filters applied (FAIL when the kit is older than 2026.11.06.3, or a filter or a copy overrides it); and "Breadcrumbs: Rank Math's breadcrumb module is on (its BreadcrumbList schema)" (only while Rank Math is active; the kit declares theme support, so it is on unless the site turns that off). Facts: `pkde_drift_breadcrumb_facts()`, read only.
- Test: the basket-visitor rows are never raised on a site without WooCommerce, even with the option set (rp, bp, mds, jsd are not shops).

## 2026.11.06

Opt-in for caching visitors with a basket (owner go, MGR-76; needs sage-kit 2026.11.06, whose pages carry nothing personal). **Adopt:** bump the package and the workflow tag. Nothing changes until a site opts in: `wp option update pkde_cache_basket_visitors 1`, then re-run `bin/deploy/w3tc-settings.php` (the audit and the steps are in the kit's `docs/page-cache.md`).

- **`pkde_cache_basket_visitors` (a WordPress option, default off).** `pkde_w3tc_woocommerce_cookies()` still lists `woocommerce_items_in_cart` and `wp_woocommerce_session_` as the cookies that skip the page cache; with the option set to `1`, `w3tc-settings.php` takes them OUT of W3TC's reject list (and logs which way it went), so a visitor with a basket gets the cached blog, shop and home pages. Safe default: a site on an older theme keeps the old behaviour.
- **Drift check, three rows while a site is opted in:** "W3TC page cache serves visitors with a basket (opted in)" (the cookies must NOT be rejected), "Cache-safe theme for basket visitors: the kit marks personal pages uncacheable" (FAIL when `App\no_page_cache()` is missing: the kit is older than 2026.11.06) and "... no view prints per-visitor data" (FAIL while a theme view reads the basket, a notice, a cookie or the user on the server; the scan is read only, `pkde_drift_personal_views()`). Without the opt-in the old row ("W3TC page cache skips the WooCommerce cookies") stays as it was.
- **Docs fix:** the 2026.11.05 entry's cron line is the corrected Scheduled Task form.

## 2026.11.05

From the bb and tcs staging checks (a page cache that looked configured and did nothing). **Adopt:** bump the package and the workflow tag; on each site re-run `bash bin/deploy/wp.sh eval-file bin/deploy/w3tc-settings.php` once (it now schedules the cron events) and read the drift check.

- **`w3tc-settings.php` schedules W3TC's cron events.** W3TC registers `w3_pgcache_prime` (the sitemap preloader) and `w3_pgcache_cleanup` (the page-cache garbage collection) only when settings are saved in wp-admin; applying the standard by script wrote the options and left both unscheduled (bb staging: neither event existed; tcs staging: cached pages 48 hours old against a 24 hour lifetime). The script now schedules any that is missing, idempotently, from the new pure `pkde_w3tc_cron_events()` in `w3tc-standard.php`.
- **Four new drift rows, only while the page cache is on:** "WP_CACHE is true (the page cache serves)", "W3TC preload cron event is scheduled" (skipped when the preloader is off), "W3TC cleanup cron event is scheduled", and "W3TC newest cached page is younger than the lifetime" (FAIL on an empty cache or a page older than the lifetime plus an hour: the preloader or GC is not running). Local environments SKIP them.
- **`activate-release.sh` warns** (never fails) when the release contains W3 Total Cache and the shared env file has no `WP_CACHE=true`: without it WordPress never loads the page-cache drop-in (bb staging). Tested.
- **Docs (the template's launch guide):** "W3TC: WP_CACHE, the cron events and a server-side cron": WP-Cron only runs on a visit and a gated staging site gets none, so give the package a My20i Scheduled Task every 5 minutes with an interpreter and an ABSOLUTE script path only (no `cd`, `~` or `&&`): the PHP binary then the absolute path of the release's `web/wp/wp-cron.php` (corrected 2026-11-06; the launch guide has the exact form).

## 2026.11.04.3

- **`push-gh-secrets.sh` follows the owner's 1Password standard.** One SSH Key item per environment, titled `Deploy - <domain>` (production) or `Deploy - staging.<domain>` (staging), with the private key and custom fields `DEPLOY_PATH`, `SSH_HOST`, `SSH_PORT`, `SSH_USER` (and optional `SSH_KNOWN_HOSTS`): no prefix on the fields, the section they sit in does not matter. The GitHub secret names come from `--env` (now REQUIRED, never guessed): `staging` gives `STAGING_*`, `prod` gives `PROD_*`; `--env both` is gone (two items, two runs). The item is given by its title (looked up with `op item list`, which shows no values) or as `op://vault/item`. Safety added: a staging item must have "staging" in its title and a production item must not (a mix-up stops the run); a secret that ALREADY exists on the repository is refused unless `--overwrite` (a live site's secrets are never replaced by accident), and `--dry-run` marks each as new or OVERWRITES. Replaces the 2026.11.04.1 / .2 layout (prefixed fields, a `STAGING_SSH_KEY` reference field), which was one day old and only ever used with dummy items. Test: 33 checks with a fake op and gh. **Adopt:** use 2026.11.04.3; the template's launch guide has the layout.

## 2026.11.04.2

- **`push-gh-secrets.sh`: one SSH Key item can hold the key AND the host details (owner).** Add the `STAGING_DEPLOY_PATH`, `STAGING_SSH_HOST`, `STAGING_SSH_PORT`, `STAGING_SSH_USER` (and optional `STAGING_SSH_KNOWN_HOSTS`) fields to the 1Password SSH Key item itself and leave `STAGING_SSH_KEY` out: the item's own private key is used (read in OpenSSH format). With a `<PREFIX>_SSH_KEY` field the old layout still works (a reference to another item's key; use it when staging and production have different keys in one site item). A pasted key in that field is still refused. Tests: 36 checks (own-key case and its refusal when the item has no private key). **Adopt:** use 2026.11.04.2; the launch guide's item table gets the one-item layout.

## 2026.11.04.1

- **`push-gh-secrets.sh`: the sign-in check works with the 1Password desktop-app integration.** The 2026.11.04 script asked `op whoami`, which fails ("account is not signed in") with the app integration even though `op read` works, so it refused to start. It now checks with `op vault list` (what `op read` needs), and the test's fake `op whoami` fails on purpose so the script cannot depend on it again. Found by running the dry run against a real 1Password item (a dummy one) that day. **Adopt:** use 2026.11.04.1 for the script.

## 2026.11.04

- **`bin/deploy/push-gh-secrets.sh` (owner, MGR-71): the GitHub deploy secrets from one 1Password item, nothing printed or saved.** `GH_TOKEN=$(gh auth token --user ACCOUNT) bash push-gh-secrets.sh <op://vault/item> <owner/repo> [--env staging|prod|both] [--dry-run]`. The item has a field named exactly like each secret (`STAGING_DEPLOY_PATH`, `_SSH_HOST`, `_SSH_PORT`, `_SSH_USER`, optional `_SSH_KNOWN_HOSTS`; the same with `PROD_`); `<PREFIX>_SSH_KEY` holds a REFERENCE to a 1Password SSH key item's private key (`op://vault/key item/private key`), read in OpenSSH format. Each value goes `op read | gh secret set NAME -R repo` through a pipe: never printed, logged, on a command line, in a variable or on disk (only the key REFERENCE, which is not secret, is held). Every required field is checked BEFORE anything is set: a missing or empty field, a private key pasted into the key field, or a reference that does not read as an OpenSSH key stops the run with nothing changed. `--dry-run` checks and lists the names it would set and sets nothing. GH_TOKEN is required in the environment (the account is explicit, never the one that happens to be logged in), `op` must be signed in. It ends by listing the deploy secret NAMES on the repository (`gh secret list`). A CI test (`tests/deploy/push-gh-secrets.test.sh`, 32 checks) runs it with a fake `op` and `gh` and canary values: no value reaches output or a command line, one `gh secret set` per secret with the value on stdin, dry run sets nothing, the argument and sign-in guards. **Adopt:** bump the package; the 1Password item layout is in the template's launch guide ("Deploy secrets"). Creating the item and running the script (an outward step on the repository) are the owner's, with the 1Password pop-up.

## 2026.10.30.1

- The theme's folder READMEs (`resources/views/README.md`, `resources/views/vendor/pkde-kit/README.md`, `app/README.md`: where a site's view overrides and own PHP go) are developer notes and never ship: `assemble-artifact.sh` excludes them and `check-artifact.sh` fails the build if one is in the artifact (the blocks README was already handled this way). A test covers it. **Adopt:** bump the package and the workflow tag together with the template's folders (reports/releases.md).

## 2026.10.30

- **`fingerprint.sh edges` checks the painted box (bp, MGR-62 follow-up).** A block in the page content grid with a border or background that spans the page measure must sit on the header's content edges, not only its text: text can line up under a box that sticks out 24px each side. Narrower callouts inside the prose column are by design and not checked. A container or block whose box (not only its content) is on the header's content edges now counts as matching, which is how the kit 2026.10.30 grid lays out. A recreated bp case (a bordered `alignwide` strip) is flagged on kit 2026.10.29 (80..1360 against 104..1336 at 1440, 0..390 against 24..366 at 390) and passes on kit 2026.10.30. **Adopt:** bump the package and the workflow tag.

## 2026.10.29.2

From the bp Phase C trial (first site on the packages). **Adopt:** bump the package and the workflow tag.

- **The hardening probe asks for files that exist.** 20i's front end answers 404 for a missing file before `.htaccess` runs, so probing paths that do not exist warned falsely ("NOT enforced") on staging. It now writes a harmless PHP file into the shared uploads, requests it, and removes it, and requests the theme's `functions.php`, a mu-plugin file and `/wp/xmlrpc.php`, which are already there; each must answer 403. A 404 for an existing file is reported as such. With nothing to probe it says so.
- **`fingerprint.sh edges` reports containers, not columns (bp).** It listed every narrow inner column and every 48rem prose page (146 lines for one privacy policy) and buried the one real miss, a home-page intro stored in the page content as `mx-auto max-w-3xl px-6` (an `mx-auto` grid item shrinks to fit-content, so it measured 88px wide and was skipped as "too small to be a container"). Now only the outermost offending element is printed, the page's own prose column (a child of the content grid in its content track with no container classes) is by design and skipped, and a hand-sized container (`mx-auto` or `max-w-*`) is checked whatever its width. Normal pages print nothing; the bp case prints one line.

## 2026.10.29.1

ADOPT ALL audit-fix batch (MGR-67, TPL-138; bp, js, tcs, cs, rp audits). **Adopt:** bump the package and the workflow tag; sites on copies take `activate-release.sh`, `drift-check.php`, `check-artifact.sh` and (new) `edges.mjs`. Needs pkde/platform 2026.10.29 or later in the same push (the artifact check now requires `safe-svg.php` and `login-slug.php`, which every platform since 2026.10.23.2 ships).

- **Deploy proves its hardening (bp 3, js 3):** after the `.htaccess` blocks are written, `activate-release.sh` requests four paths that cannot exist (a PHP file in `app/uploads`, theme PHP, a `mu-plugins` PHP file, `/wp/xmlrpc.php`) and warns loudly, naming the path, when any does not answer 403. Before, only the marker was checked, which proves nothing when the server ignores the file. A warning, never a failed deploy (the release is already live).
- **XML-RPC denied at the server (owner decision):** a new `# BEGIN XML-RPC Hardening` block denies `xmlrpc.php` (platform 2026.10.29 also switches XML-RPC off in PHP). A site that opts in with `ALLOW_XMLRPC=true` deletes this block from its shared `.htaccess`.
- **Drift check reports the .env state (bp 2):** `WP_ENV` must agree with `WP_ENVIRONMENT_TYPE`; production must not have `DISALLOW_INDEXING` or `STAGING_MAIL_TO`; staging must have `DISALLOW_INDEXING`, and reports whether staging mail is suppressed or redirected. A `.env` split between environments no longer looks clean row by row.
- **Workflow (bp 4, js 2):** `npm ci --ignore-scripts` (the build needs no install script), and a step before the SSH key loads that fails when the pipeline tools in `.pkde-deploy` were modified by the build or are not at the tag.
- **check-artifact requires the security modules (tcs):** `safe-svg.php` and `login-slug.php` must be in the artifact.
- **New `fingerprint.sh edges` (MGR-62):** measures the content edges of the header, page containers, Section children and the footer at several widths and fails when one differs by more than a pixel; narrow text columns inside a matching container and full-bleed blocks are by design. Run it before and after a switch to the packages (docs/process/phase-c-switch.md in the template).
- GitHub Environments for the PROD secrets stay a per-site owner decision (they would add a required reviewer to every production deploy); the workflow already gives the deploy secrets to the deploy steps only. Branch protection is not available on GitHub Free: accepted risk (template ADR).

## 2026.10.29

- **Two new `.htaccess` blocks, added to every site on its next deploy (a separate marker each, so they arrive whatever the Site Hardening block holds):**
  - `# BEGIN Upload Hardening` (MGR-67: rp, bp audits): `app/uploads/*.php` (also `.phtml`, `.phar`) and any `.php` under `app/themes/*/` answer 403 (theme PHP is only ever included by WordPress, never requested; an uploaded or planted PHP file in the media library is not executed), and every `.svg` is served with `X-Content-Type-Options: nosniff` and a sandboxing `Content-Security-Policy` (`default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox`), so an SVG opened on its own cannot run script even if one slipped past the sanitiser; as an `<img>` it renders as before.
  - `# BEGIN Upload Cache` (tcs GTmetrix, MGR-68): browser caching for `app/uploads` media (30 days) and for theme/plugin assets requested with `?ver=` (one year, immutable); HTML and PHP untouched; appended last so it overrides W3TC's Browser Cache rules.
  Both are wrapped in `<IfModule>` (no module = no protection, never a 500). `activate-release.test.sh` checks each block once and its rules (107 pass on Linux). **Adopt:** bump the package and the workflow tag; after the deploy `curl -I` an upload and a `?ver=` asset on staging for `Cache-Control`, and check that an upload `.php` and `functions.php` under the theme give 403. If a site serves a theme PHP file on purpose, tell the template agent.

## 2026.10.28.1

- **Security hardening of the reusable workflows (MGR-67: cs, rp, bb audits):**
  - Every third-party action is pinned by commit SHA with the tag in a comment (`actions/checkout`, `actions/setup-node`, `shivammathur/setup-php`, `webfactory/ssh-agent`, which handles the deploy key); a tag can be moved. Renovate keeps the SHAs current.
  - Every checkout sets `persist-credentials: false`, so the job token is not left in `.git/config` while `composer install` and `npm ci` run third-party code.
  - **Host key pinning:** the deploy step uses the optional secret `PROD_SSH_KNOWN_HOSTS` / `STAGING_SSH_KNOWN_HOSTS` (the output of `ssh-keyscan -p PORT HOST`, taken once from a trusted network) with `StrictHostKeyChecking=yes`. Without it the key is still learned by `ssh-keyscan` on each run, with a warning that says it is trust on first use, so no site breaks on this bump.
  - New test `tests/deploy/workflow-hardening.test.sh` (in CI): SHA pins, persist-credentials, a permissions block per workflow, host-key pinning.
  - Checked and unchanged: all three workflows already have a top-level `permissions: contents: read`, and the deploy secrets are referenced only in the deploy steps (step-scoped, gated by a push to main or develop), never in the job env that runs `composer`/`npm`; so a same-repo pull request (Renovate) does not expose them to dependency install scripts, even though a caller forwards them.
  **Adopt:** bump the deploy package and the workflow tag. To pin the host key: `ssh-keyscan -p PORT HOST` once from a trusted network, store the output as `PROD_SSH_KNOWN_HOSTS` (and `STAGING_SSH_KNOWN_HOSTS`), and pass it in the caller's `secrets:` block (the template's caller shows the lines). GitHub Environments for the PROD secrets stay a per-site owner decision.

## 2026.10.28

- **Security fix (tcs audit, TCS-88; likely live on every site):** `assemble-artifact.sh` excluded `.htaccess` by bare name, so rsync dropped EVERY `.htaccess` under `web/`, including the plugins' own access denials (Wordfence `lib/`, `models/`, `waf/`..., W3 Total Cache `pub/` and `ini/`). Releases therefore shipped those data and include directories without the plugin's `Require all denied`. The exclude is now anchored (`/.htaccess`, only the docroot's own file, which lives in `shared/` on the server). `check-artifact.sh` now fails the build when a `.htaccess` that the build tree has under `web/app/plugins` or `web/app/mu-plugins` is missing from the artifact. A new test (`tests/deploy/assemble-artifact.test.sh`) builds a tree with fake plugins and checks both scripts. **Adopt: all sites, next push** (bump the deploy package and the workflow tag; sites on copies: take `assemble-artifact.sh` and `check-artifact.sh`). Then confirm on the server that e.g. `web/app/plugins/wordfence/lib/.htaccess` is present in the new release.

## 2026.10.26.5

- `seo/crawl.py --urls FILE` (from tcs): crawl exactly the paths in FILE, one per line, and follow no links: the rebuild checked on live's URL set, because a rebuild can hide pages (empty categories) that its own sitemap then lacks. The file comes from the path column of the live inventory; a live archive paged with a query string (WooCommerce's `?product-page=N`) stays in it with its query and is compared with `compare.py --include-query`. A test crawls a tiny local site both ways.
- Renovate configuration for this repository (composer, npm and github-actions, Monday, vulnerability alerts, Pest below 5).

## 2026.10.26.4

- `fingerprint.sh capture` and `compare-sites` take `--hide '.related,.upsells'` (from bb): CSS selectors hidden with `display:none` before measuring, for parts that change on every load by design (WooCommerce's related products are picked at random), so one build compares equal to itself (without it two captures of the same build differed in 115 positions). Hidden, not removed, so the other paths stay stable. A test with a part that changes on every request proves both sides: different without the option, identical with it.

## 2026.10.26.3

- `seo/compare.py` (js): robots noise gone: while the new site is noindex (a non-production rebuild always is) robots is not compared and one NOTE line says how many URLs were skipped; `--cutover` compares robots and canonical strictly at go-live and fails a noindex on the new side; `--ignore robots,h1,...` leaves any field out on purpose.
- `seo/compare.py` keyed rows by path only, so `/shop/?sage_view=new` overwrote `/shop/` (js read the /shop/ H1 as "New"). A URL with a query string is now skipped on both sides (with a NOTE count) unless `--include-query`, which keys rows by path and query. Tests cover robots (noindex skipped, `--cutover`, `--ignore`, a real difference) and the query strings.

## 2026.10.26.2

- New `bin/deploy/seo/text_compare.py` (from el): for each path, the words of the page's visible `<main>` text that are on one side only, live against the rebuild, over HTTP (header, footer, scripts, styles, SVG and select options ignored; case, emoji and punctuation do not count). It is what a visitor reads, not the stored content. Exit 1 when a page differs or a request fails. Tests cover the comparison without a network.

## 2026.10.26.1

- `seo/compare.py` reports a description that appears where the live page had none as `DESCRIPTION ADDED` and one that disappears as `DESCRIPTION REMOVED`, both failures: Rank Math's WooCommerce module hooks `rank_math/frontend/description` and prints fallbacks (a product's short description, an excerpt, "Products Archive - ...") the live site never had. Tests cover both.

## 2026.10.26

- New shared SEO tools `bin/deploy/seo/crawl.py` and `bin/deploy/seo/compare.py` (from the tcs, el and bb copies, so a site no longer carries its own): the crawler writes the inventory (status, redirect chain, title, description, canonical, robots, H1, Open Graph, JSON-LD types, image and alt counts, internal links); the comparison lists per URL a changed status, title, H1, canonical path or robots, and reports a changed DESCRIPTION on its own line, because a builder conversion changes the content a plugin derives it from. A description that starts with a page-builder token (Divi's dynamic-content `@ET-DC@eyJ...`, also with the full-width at sign U+FF20) is a LIVE BUG, not drift: listed, not counted, and a new-side token fails. `--accept` and `--accept-file` (the first column of the tables in docs/seo/description-exceptions.md) mark reviewed differences as ACCEPTED. A test covers each case.

## 2026.10.23.4

- `drift-check.php`: new row "Login slug managed by .env" when WPS Hide Login is active: PASS only when `LOGIN_SLUG` is set, usable and really in force (the platform's login-slug module answers `whl_page` with it); a FAIL says to copy production's `whl_page` into `LOGIN_SLUG` in `shared/.env`. The slug is never printed; SKIP on a development stack.

## 2026.10.23.3

- `drift-check.php`: new check "Post SMTP configured and ready" when Post SMTP is active: transport `smtp`, a sender (From) address, a host and a password are all present (values are never printed). A FAIL names what is missing (for example "set SMTP_FROM"); SKIP on a development stack. Pest cases cover each missing piece.

## 2026.10.23.3

- `drift-check.php`: new check "Post SMTP configured and ready" when Post SMTP is active. It fails unless the **active transport class** is `PostmanSmtpModuleTransport` (Post SMTP falls back to its Default transport, the web server's local mail, when the SMTP one is not configured and ready, whatever the stored transport says), the stored transport is smtp, and a sender (From) address, an envelope sender, a host and a password are present. A FAIL names what is missing (for example "set SMTP_FROM"); values are never printed; SKIP on a development stack. Pest cases cover each missing piece and the Default-transport fallback.

## 2026.10.23.2

- `fingerprint.sh compare-sites` normalises what only one environment has by design, so production against staging no longer fails on it: a CookieYes consent cookie (every optional category off), the consent banners and widgets of CookieYes, Complianz, Cookiebot, OneTrust, Borlabs and Termly, the Google Tag Manager iframe and noscript are hidden (display:none, so DOM paths do not shift), and `<picture><source><img>` is compared as the bare `<img>` (EWWW WebP wrappers where staging lacks the .webp files). `--raw` turns this off; `capture --normalise` does the same for a single capture. A staging site behind HTTP Basic auth is read with `FINGERPRINT_AUTH=user:pass` in the environment, never on the command line. A test runs real Chrome against local fixture servers (skipped where Chrome or the tools are missing).

## 2026.10.23.1

- `fingerprint.sh compare-sites <url-a> <url-b> [--paths ...] [--widths ...] [--tolerance N]` captures two sites and prints the differences in one command; the README makes it a required step between the staging deploy and a production release (every difference explained). A capture that crashes in node (the first one after a build sometimes does) is retried once. Tests use a fake node that crashes the first capture.

## 2026.10.23

- The deploy workflow audits the theme's npm packages: `npm audit --omit=dev --audit-level=high` (what ships to browsers) fails the run; `npm audit --audit-level=high` for the build tools (Vite and plugins, never shipped) is a warning annotation only.
- New reusable workflow `audit.yml` for a weekly `schedule:` (and `workflow_dispatch:`) caller: it runs the Composer audit of the root and theme locks and both npm audits, so a vulnerability fixed only in a transitive lock entry (which Renovate did not turn into a PR for CVE-2026-108082) still fails a run that emails the repository owner, even for a site nobody has pushed for weeks. `bin/release.sh` stamps and CI checks `PKDE_DEPLOY_VERSION` in both workflow files.
- The pre-launch audit no longer fails a cross-origin iframe (YouTube, a map) on the focus-indicator check: the page cannot style or measure a focus ring drawn inside the frame. The iframe stops are listed as INFO.
- `composer.json` pins `config.platform.php` to 8.3.0 (dev installs only).

## 2026.10.22.2

- The workflow audits the Composer lock files, root and theme, on every run including pull requests (`bin/deploy/composer-audit.sh`, shipped packages only). It fails on an advisory rated high or critical and on an unrated one (many advisories, such as CVE-2026-108082 in symfony/http-foundation, carry no rating, and "unknown" must not read as "fine"), lets low and medium through with a listing, and fails when the audit cannot run. A deliberate exception goes in the site's `config.audit.ignore` with the reason. A test covers the severities with a fake `composer`.

## 2026.10.22.1

- `drift-check.php` accepts Rank Math or Yoast (`wordpress-seo`, `wordpress-seo-premium`) as the site's SEO plugin: one check, "An SEO plugin is active", replaces the hard requirement for `seo-by-rank-math`, and the Rank Math registration check still runs only when Rank Math is active. Yoast no longer shows under "Other active plugins".
- `prelaunch-audit.sh` can no longer end after the header checks without saying so: a Chrome launch failure, a Lighthouse failure or an axe/focus failure on a page is a FAIL line with the reason, a crash prints a FAIL line and exits 1, and the wrapper reports "The audit did not finish" when no summary was written.

## 2026.10.22

- Documentation: a deleted PHP file can keep answering for about 10 s on 20i (OPcache revalidation plus the NFS attribute cache), so a temporary PHP file in a docroot must be token-gated and print nothing sensitive (perf-probe, the OPcache reset probe and the README say so). No behaviour change.

## 2026.10.21.2

- The reusable workflow installs the theme's Composer dependencies for every caller, including the starter (build-artifact false): the design-token check and the Vite build import from the theme's `vendor/` (the Sage kit), so they failed without it (found by the template's first real run as a caller).

## 2026.10.21.1

- Deploy reliability: `activate-release.sh` takes a fourth argument, `all` (default), `activate` or `post`, and the workflow runs it as TWO remote execs on the same SSH connection: the activation proper (switch, opcache reset, `.htaccess`, prune), then the post steps (flushes, `wp acorn optimize` when the theme has Acorn, cache warm-up, languages, background clean-up). A failing post exec only warns. 20i's gateway appears to close one exec after about 5 to 6 minutes; each exec is now short and logs its own duration (`Exec 'activate' finished after Ns`; the workflow logs both).
- The prune moves old releases into `<base>/.trash` (a rename) and a detached, locked background job deletes them, so a slow recursive delete on NFS can no longer hold a session open.

## 2026.10.21

- First public release: the reusable deploy workflow (checks out its own tools at the tag stamped in the workflow, no token needed) and the deploy tools (activate-release, assemble/check-artifact, retry, rcpt-probe, drift-check, W3TC standard, perf-probe, wp.sh, pre-launch audit, layout fingerprint, native-block tools, ADR sync, check-site-paths) with their tests. GPL-2.0-or-later, SECURITY.md, a secret scan and a public-safety test in CI. check-artifact requires the Sage kit's provider and forbids its tests, docs and metadata in the docroot.
