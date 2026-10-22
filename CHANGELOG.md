# Changelog

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
