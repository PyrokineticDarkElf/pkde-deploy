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
the artifact, and on a push to `main` (production) or `develop` (staging) with `DEPLOY_ENABLED` true: rsync to a new release directory
and `activate-release.sh` over one SSH connection.

## Tools (`bin/deploy`)

`activate-release.sh` (runs on the server), `assemble-artifact.sh` and `check-artifact.sh`, `retry.sh`, `rcpt-probe.py`, `drift-check.php`
with `w3tc-standard.php` and `w3tc-settings.php`, `perf-probe.php` and `perf-probe.sh`, `wp.sh`, `prelaunch-audit.sh`/`.mjs`,
`wordfence-cloudflare.sh`, `safe-push-main.sh`, `sync-template-adrs.sh`, `check-site-paths.sh`, and `native/` (page-conversion tools).
`assemble-artifact.sh` ships `drift-check.php`, the W3TC files, `perf-probe.*` and `wp.sh` into every release (outside the docroot); the guide
in the starter explains each. A site that wants them locally requires this package with `require-dev` (`composer require --dev
pkde/deploy:<tag>`), so they are in `vendor/pkde/deploy/bin/deploy/` and never in the shipped `vendor/` (the production install runs
`--no-dev`). The native tools use the current directory as the site root (or `PKDE_SITE_ROOT`).

## Develop and release

* `composer install`, `composer lint`, `composer test`; the shell tests are `bash tests/deploy/<name>.test.sh`, the Python ones
  `python3 -m unittest discover -s tests/native`. CI runs all of them (`.github/workflows/ci.yml`).
* Release: add a `## YYYY.MM.DD` section to `CHANGELOG.md`, push, wait for CI, then `bin/release.sh YYYY.MM.DD`. That script is the only way a
  tag is made (no branch protection on the free plan): it stamps the version into the workflow, waits for CI on that commit and tags it.
* Roll a site back by pinning the previous tag in its caller and in `composer.json`.
