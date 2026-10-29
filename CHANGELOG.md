# Changelog

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
