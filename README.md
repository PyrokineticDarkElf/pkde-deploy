# pkde-deploy

The PKDE build-and-deploy pipeline: a **reusable GitHub Actions workflow** plus the **deploy tools** (`bin/deploy/*`) it uses, with their
tests. Private. Design: ADR 0007 in the starter template (`docs/decisions/template/0007-shared-code-as-versioned-packages.md`).

## A site uses it

`.github/workflows/deploy.yml` of a site (the caller; triggers, `paths-ignore` and `concurrency` stay in the site):

```yaml
name: Deploy
on:
  push:
    branches: [main, develop]
    paths-ignore: ['backlog/**', 'changelog.d/**', 'CHANGELOG.md', 'docs/**']
  pull_request:
  workflow_dispatch:
concurrency:
  group: deploy-${{ github.ref }}
  cancel-in-progress: false
jobs:
  deploy:
    uses: PyrokineticDarkElf/pkde-deploy/.github/workflows/deploy.yml@2026.10.21   # an exact tag; Renovate bumps it
    with:
      deploy-enabled: ${{ vars.DEPLOY_ENABLED }}
    secrets:
      PROD_SSH_KEY: ${{ secrets.PROD_SSH_KEY }}
      # ... PROD_SSH_HOST, PROD_SSH_PORT, PROD_SSH_USER, PROD_DEPLOY_PATH and the same five STAGING_*
```

Inputs: `build-artifact` (default true; false for the starter), `deploy-enabled`, `deploy-allowed`, `runs-on`, `php-version`,
`node-version`, `theme-dir`. Secrets are passed explicitly (`secrets: inherit` is for organisations and enterprises); the only secrets are the server's `PROD_*` and `STAGING_*`.

* This repository and the packages it works with are **public**: Composer and the workflow's tools checkout need no token. The tools are checked out at `PKDE_DEPLOY_VERSION`, which `bin/release.sh` stamps with the tag in the tagged commit.

What the workflow does, in order: checks out the tools, `check-site-paths.sh` on the caller, actionlint, PHP and
Node, Composer install (dev), Pint, Pest, Composer install (production, root and theme), npm ci, token check, build, assemble and check
the artifact, and on a push to `main` (production) or `develop` (staging) with `DEPLOY_ENABLED` true: rsync to a new release directory,
then `activate-release.sh` as two execs on one SSH connection (`activate`, then `post`), each logging its duration.

## Required before a production release

Run `bin/deploy/fingerprint.sh compare-sites <staging-url> <production-url> --paths /,/about/,...` (a WooCommerce site adds `--hide '.related,.upsells'`: the related products are random) after the staging deploy and before the production release: it captures both sites (box, tag, DOM path and visible text per page at 1440 and 390; a capture that crashes in node is retried once) and lists every difference. It normalises what exists on one environment only by design: a CookieYes consent cookie with every optional category off, the consent banners and widgets of CookieYes, Complianz, Cookiebot, OneTrust, Borlabs and Termly, the Google Tag Manager frame and noscript are hidden, and `<picture>` counts as its `<img>` (EWWW WebP wrappers); `--raw` turns that off. A staging site behind Basic auth: `FINGERPRINT_AUTH=user:pass` in the environment (never an argument). Each one must be explained in the release task. Exit 0 means identical.

## Tools (`bin/deploy`)

`activate-release.sh` (runs on the server), `assemble-artifact.sh` and `check-artifact.sh`, `composer-audit.sh` (the workflow runs it on the root and theme lock files: fails on a high, critical or unrated advisory; accept one deliberately with `config.audit.ignore` in that `composer.json`), `retry.sh`, `rcpt-probe.py`, `drift-check.php`
with `w3tc-standard.php` and `w3tc-settings.php`, `perf-probe.php` and `perf-probe.sh`, `wp.sh`, `prelaunch-audit.sh`/`.mjs`,
`wordfence-cloudflare.sh`, `seo/crawl.py` (`--urls FILE` crawls exactly live's URL set; to check robots and canonical before cutover, force indexing on the LOCAL rebuild only with a temporary `add_filter('pre_option_blog_public', fn () => 1)` mu-plugin, never on a deployed environment, so it behaves as production), `seo/compare.py` and `seo/text_compare.py` (the visible words of each page, live against the rebuild, over HTTP: `python3 bin/deploy/seo/text_compare.py LIVE_URL NEW_URL / /about/`) (the SEO baseline and the before/after comparison: `python3 bin/deploy/seo/crawl.py --base URL --out inventory`, then `compare.py live.csv new.csv [--accept PATHS] [--accept-file docs/seo/description-exceptions.md]`; needs python3 and `requests`), `safe-push-main.sh`, `sync-template-adrs.sh`, `check-site-paths.sh`, and `native/` (page-conversion tools).
`assemble-artifact.sh` ships `drift-check.php`, the W3TC files, `perf-probe.*` and `wp.sh` into every release (outside the docroot); the guide
in the starter explains each. A site that wants them locally requires this package with `require-dev` (`composer require --dev
pkde/deploy:<tag>`), so they are in `vendor/pkde/deploy/bin/deploy/` and never in the shipped `vendor/` (the production install runs
`--no-dev`). The native tools use the current directory as the site root (or `PKDE_SITE_ROOT`).

## Temporary PHP files in a docroot

A PHP file deleted from a 20i docroot can keep answering for about 10 seconds: OPcache's `validate_timestamps` revalidates every 10 s and the NFS attribute cache adds to that. So any temporary PHP file (the performance probe's mu-plugin, the OPcache reset probe) must be gated by a random token, must print nothing sensitive, and must not be trusted to be gone the moment `rm` returns.

## Develop and release

* `composer install`, `composer lint`, `composer test`; the shell tests are `bash tests/deploy/<name>.test.sh`, the Python ones
  `python3 -m unittest discover -s tests/native`. CI runs all of them (`.github/workflows/ci.yml`).
* Release: add a `## YYYY.MM.DD` section to `CHANGELOG.md`, push, wait for CI, then `bin/release.sh YYYY.MM.DD`. That script is the only way a
  tag is made (no branch protection on the free plan): it stamps the version into the workflow, waits for CI on that commit and tags it.
* Roll a site back by pinning the previous tag in its caller and in `composer.json`.
