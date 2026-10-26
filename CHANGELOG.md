# Changelog

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
