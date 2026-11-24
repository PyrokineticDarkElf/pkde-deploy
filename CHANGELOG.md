# Changelog

## 2026.11.24.1

The rollback probe covers pools of more than 16 PHP workers (TPL-271, tcs) and a drift row shows the shape preset (WP-108 A, TPL-266). **Adopt:** every site with the next deploy workflow tag (bb and tcs now; the others in February). Not Train-ready.

- **Rollback and activation probe (TPL-271):** `hit_all_workers` asks again with twice as many parallel requests (cap `OPCACHE_MAX_HITS`, default 64) until one worker has answered twice, so every worker of a pool of more than 16 resets its opcache and realpath cache; a home page that answers 5xx once after activation is looked at again (`PKDE_FRESH_RENDER_5XX_RETRIES`, default 3) with the web PHP reset again before the run says BROKEN SITE. A site that recovers says so in a WARN line. New `tests/deploy/hit-workers.test.sh` (11 checks), run in CI.
- **Drift row:** an INFO row, `Shape (corner radius and border thickness)`, shows the preset and the radius and border tokens the theme was generated with (`resources/generated/shape.php`); older kits say they have no shape tokens. Never a FAIL.

## 2026.11.24

`site-details-migrate.php` and the Site details drift rows (WP-106, TPL-265). **Adopt:** bb and tcs now (staging pilots) with platform and kit 2026.11.24; cs also names its adapter; the live sites after their December release. Not Train-ready.

- **`bin/deploy/site-details-migrate.php`:** `plan` (read-only) lists the old `client_*` options (read from the database, not through the read shim) and the Site details field each goes to, the declaration, and what is stored; `apply [adapter=PATH]` writes Site details. A field that already has a value is KEPT, so a second run changes nothing and a value edited in wp-admin is never overwritten; locked fields are not stored; a number or link it cannot read is named and left out. A site that keeps its details elsewhere (cs: the `App\Business` constants) names a small adapter file that returns them in the Site details shape. The old options are not deleted (the platform's read shim answers them for one release). It ships in the artifact.
- **Drift rows:** the required fields for the declared type are present (a name, a phone or an email, an address on a local business, the legal name on a shop); an INFO row lists stored values that differ from the declaration; a WARN row flags a name, phone or email typed separately in Rank Math that differs from Site details; an INFO row when the platform has no module.
- **`apply-pages.php` and draft legal documents (TPL-268, bb).** A DRAFT document is found (WordPress's `any` status hides a document's drafts from a request with no user, so the statuses are named; a draft made without a slug, which has an empty `post_name`, is found by its title). A MISSING document is created as a DRAFT from `content/legal/<slug>.html` when the file gives its type: a first line `<!-- pkde-legal {"type": "privacy", "title": "Privacy policy", "effective": "2026-11-01"} -->` (not part of the content) or a sidecar `<slug>.json` with the same keys; a bad type or date is a problem line, no type means "document missing" as before, the legal block list still applies, a page is never created, and a second run changes nothing (`plan` says "would create"). A site needs no script of its own. Real WordPress: a slug-less draft updated by its title (revision kept), two documents created (front matter, sidecar) with type and date, a cover block and a bogus type refused, a second plan and apply matched. Pest 163.
- Real WordPress on the template stack with old options seeded: plan listed them and flagged a bad link; apply wrote 1 phone, 1 email, 1 address and 2 profiles (the adapter's legal name and company number were dropped because the declaration locks them); a second apply changed nothing; the shim answered `client_email` and the others from Site details while the database rows stayed; the drift facts query returned the right values. Pest 162, shell test 13.

## 2026.11.20.7

`apply-pages.php` applies legal documents (TPL-261). **Adopt:** bb (3 legal page files) and tcs with the legal pilots; every other site when it converts its legal Pages.

- **`content/legal/<slug>.html` is applied to the `pkde_legal` post with that slug:** the same plan and apply, a revision kept, a second run changes nothing, nothing is created (a missing document is reported: `legal-migrate apply` converts the Page first, then `apply-pages`). A file with any block outside the legal list (headings, paragraph, list, list-item, table, quote, details, separator) is refused and the blocks are named (`PROBLEM: block(s) not allowed in a legal document: core/cover, core/group`). `legal=` names another folder; the artifact already ships `content/` whole.
- Real WordPress on the template stack with the platform `legal` module: plan listed an update, a missing document and a refused file; apply updated the document (a revision kept, the new text in it) and a plain page together; the second plan matched; the refused file stayed refused. Pest 157, shell test 12.

## 2026.11.20.6

Two tcs staging findings (TPL-258, TPL-259). **Adopt:** bump `pkde/deploy` on tcs (staging) and bb; every other site in the February train.

- **A manual rollback resets the web PHP behind the staging gate.** The opcache reset, its parallel worker hits, the second probe and the hardening probes now go through `gate_curl`, which sends `STAGING_GATE_AUTH` from `shared/.env` the way the fresh-render check does (as a curl config line on stdin: on no command line and in no URL). tcs's rehearsal printed "WARN: could not request the opcache reset ... 401" at each switch. Shell test `tests/deploy/gate-curl.test.sh` (11 checks with a fake curl).
- **No script can print credentials.** Audited: no deploy script uses `-u`, `--user`, `%{redirect_url}` or `%{url_effective}`; the test fails if one appears. (The credentials tcs saw came from a hand-run `curl -u ... -w %{redirect_url}`: never print a URL curl built with `-u`.)
- **`apply-pages.php` resolves `{{faq-group:<slug>}}`** to the id of that FAQ group, so a page file can carry the kit FAQ block in group mode (`<!-- wp:sage/faq {"groups":[{{faq-group:general-questions}}],"order":"manual"} /-->`) and the order apply-pages then faq-migrate is not needed. The group must exist (a missing one is a PROBLEM line; nothing is created). Real WordPress on the template stack: plan, apply (the page carried group id 61, the FAQ showed on the page), a second plan matched, a missing group was reported. Pest 156, shell test 11 for the gate helper.

## 2026.11.20.5

`legal-migrate.php` and the legal drift rows (WP-104, TPL-255). **Adopt:** with platform 2026.11.20.3 and kit 2026.11.20.6: bb (a shop, staging pilot) and cs (after its December release); every other site through a later manager brief. Not Train-ready.

- **`bin/deploy/legal-migrate.php`:** `plan` (read-only) lists the Pages that look legal (slug or title) and the ones chosen as the core Privacy Policy page and the WooCommerce Terms page, with ID, title, slug, address, status and a proposed type (a page with a parent is flagged). `apply list=<approved file>` converts ONLY the listed Pages: `post_type` becomes `pkde_legal` keeping the ID, content, slug, status, revisions and menu items (a menu item now says it is a document), sets the type and the effective date (the file's, else the day the page last changed), flushes the rewrite rules (the normal flush, never hard). It refuses to run without the file, changes nothing when the file has a problem, and a second run changes nothing. It then checks that each address still answers 200 with the same title (when the site can be fetched from where it runs) and that the core privacy address and the WooCommerce terms address are unchanged. It ships in the artifact. The list is `config/pkde-legal-documents.php` (an example is in the template).
- **Drift rows:** Privacy and Cookies published (every site), Terms and Returns published and the WooCommerce terms setting pointing at a document (a shop), the core privacy setting pointing at a published document, an effective date on each document, no address shared with a Page; an INFO row when the platform has no `legal` module.
- Real WordPress on the template stack: plan listed 7 pages; apply without the file and with a bad file changed nothing; apply converted 3 (ID, slug, status, revisions, content and menu item kept; the core privacy address and the terms address unchanged; host `curl` showed 200 and the same title before and after); the second run converted none; the new drift facts query returned the expected counts. The in-script address check could not fetch the site from inside the container here (the check says so). Pest 155, shell test 12.

## 2026.11.20.4

`apply-pages.php` (from bb), a top-level `content/` folder in the deploy artifact, the FAQ migration scripts finally shipped, `faq-migrate` finding a FAQ heading in a sibling Group and applying nested runs, and a drift row for the motion switch (TPL-248, TPL-252, TPL-240). **Adopt:** bump `pkde/deploy` on tcs and bb now (staging pilots); tcs and el also re-run `faq-migrate.php plan` (el and tcs now list their FAQ); every other site in the February train. tcs: put its page files in `content/pages/` (the folder `apply-pages` and the artifact read first); bb: move its files from `config/content/pages/` to `content/pages/` at its next push (the old folder still works as a fallback). Proved in real WordPress on the template stack; not on a server.

- **`bin/deploy/apply-pages.php`** (`plan` by default, `apply`): applies `content/pages/<slug>.html` to the page with that slug, never creating a page. Resolves `{{form:<key>}}` to the site's form (CF7), maps `/uploads/` pictures to media-library attachments by file name (`imageUrl` to `imageId`, `extraImages[].url` to `.id`), keeps a revision of the page before updating, skips pages that already match (idempotent) and prints slugs and counts only. Real WordPress: plan found 2 to update, 1 page missing, 1 unknown form key and a missing picture; apply updated 2 with revisions; a second plan found 2 matching; no page files found changed nothing.
- **The artifact carries `content/`** (`assemble-artifact.sh` copies it when it exists; the shell test ships a page file through it). **`faq-migrate.php` and `faq-migrate-lib.php` were never copied into the artifact** (a fixed list of scripts); they are now, together with the two `apply-pages` files, and `check-artifact.sh` requires all four.
- **`faq-migrate.php` finds a FAQ heading in a sibling Group (TPL-252; tcs and el).** A run of core Details blocks with no heading of its own now takes the FIRST heading matching /faq|f.a.q|questions/i found anywhere in the nearest enclosing Section (the top-level Group when there is no Section), at any depth, before the run in document order and not inside a Details block. A heading directly in front of the run, or directly in the run's own Group or Section, still wins. tcs's shape: Section > grid Group > { intro Group > flex Group > "F.A.Q." and "General Questions", beside a Group of 5 Details }; el's: Section > { Group with the heading and an intro paragraph, Group of 10 Details }. The heading stays where it is and the FAQ block prints none; only a wrapper Group that holds nothing but that heading is removed, and then the FAQ block takes the heading.
- **Fix: a run of Details inside a Group or Section could not be applied.** Replacing blocks inside a container left the container's `innerContent` with the old number of slots, so `serialize_blocks()` stopped with "Undefined array key". It worked only for a run at the top level of a page, which is all the earlier proof covered; tcs's and el's shapes are nested. The container is now rewritten to match.
- **Drift check:** an INFO row says what `pkde_kit/motion` is set to (kit 2026.11.20.5).
- Real WordPress on the template stack (a page in tcs's shape, a synced pattern in el's shape): `plan` listed both (3 and 2 questions); `apply` switched both to group mode with the headings and intro paragraph in place; a second `apply` changed nothing; a revision of each was kept. The wrapper-removal case is covered by unit tests only.
- Pest 149, shell test 11.

## 2026.11.20.3

`faq-migrate.php` finds FAQs kept as core Details blocks and in synced patterns (WP-102, TPL-243, TPL-245; found by tcs and el). **Adopt:** bump `pkde/deploy` on tcs and el (and any site whose FAQs are Details blocks); nothing else changes for the other sites. Run `plan` again: it now lists what it missed before.

- **Core Details blocks.** A run of one or more consecutive `core/details` blocks is one FAQ block when a heading matching /faq|f.a.q|questions/i comes directly before it (that heading becomes the FAQ block's heading and is removed), or when the Group or Section it sits in has such a heading (the heading stays where it is and the new block prints none). The question is the summary, the answer the inner blocks. `apply` replaces the run with one `sage/faq` group-mode block; the plan's inventory lists them with the kind `details`. tcs-like markup (five Details under an "F.A.Q." heading) and an el-like one are tested.
- **Details blocks elsewhere are not touched:** `plan` lists them as "CHECK BY HAND" (page and number of runs).
- **Synced patterns (`wp_block` posts).** `plan` and `apply` scan them like pages (the slug of the pattern is the page slug of the mapping key); `plan` lists where each is used: posts that place it, term descriptions that hold it, and the theme files that may print it ("CHECK BY HAND"). `apply` rewrites the pattern's content to one group-mode FAQ block, so every place that uses it follows; the old content stays as a revision.
- Proved in real WordPress on the template stack: a contact page with five Details under "F.A.Q.", a Details block under another heading (left alone, listed), a synced pattern of three Details placed on a page and in a category description (plan listed both), `apply` twice (the second changed nothing, the pattern had 2 revisions, the placing page showed the three FAQs). Pest 138.

## 2026.11.20.2

`bin/deploy/faq-migrate.php` and FAQ drift rows (WP-102, TPL-234; needs pkde/platform 2026.11.20.1). **Adopt:** bump `pkde/deploy`: bb now (staging pilot); tcs, js, el now; rp, bp, mds, jsd, cs after their December production release. Not Train-ready.

- **`faq-migrate.php plan out=PATH` (read-only):** lists every page with a kit FAQ block typed by hand, a Rank Math FAQ block or an accordion headed "FAQ" with its question count, names theme files that look like a hand-coded FAQ, and writes the inventory CSV (page slug, block index, block heading, question, the first 120 characters of the answer, a stable question hash, the block kind; no personal data).
- **`faq-migrate.php apply mapping=config/pkde-faq-groups.php`:** uses only the approved mapping file: creates or reuses its groups, creates one FAQ per unique question hash (identical questions are one FAQ), puts each FAQ in its groups in the file's order, switches each FAQ block the file names to group mode, keeps the old page content as a revision. It refuses to run without a mapping file or with one that has a problem (changing nothing), is idempotent, and prints counts and slugs only. The file's format is `config/pkde-faq-groups.php.example` in the template; the runbook is `bin/docs/faq-migration.md`.
- **Drift rows:** FAQs have no public URL, archive or sitemap entry; Administrators and the SEO role manage them and Editors do not; INFO for FAQ blocks still typed by hand; WARN (new status) for groups with no published FAQ.
- Pest 132.

## 2026.11.20.1

Drift rows for the block editor (WP-100, TPL-209, TPL-219, TPL-220, TPL-221). **Adopt:** bump `pkde/deploy`: bb now (staging pilot); every other site the February train.

- **Block editor by role** (needs the platform `editor-settings` module): custom colours, locking and the block list are Administrator-only.
- **No bundled patterns, Rank Math blocks hidden:** FAIL when WordPress's or WooCommerce's patterns are registered or a Rank Math block is still insertable (the Rank Math row only when its blocks are registered).
- **A used global block has its declaration:** FAIL when published pages carry the CTA band and `config/pkde-sections.php` declares no content for it.
- Pest 123.

## 2026.11.20

Two items (WP-99): the Rank Math titles part on its own (B) and the Relevanssi settings script moved here from the kit (D). **Adopt:** bump `pkde/deploy` and the workflow tags (January train); then every site runs the Rank Math titles part (below).

### Relevanssi settings script (D, TPL-202)

- **`bin/deploy/relevanssi-settings.php`** (moved from the kit's `bin/`; TPL-202 U-8): the shop search settings and index rebuild, run once per environment. Ships in the artifact. The drift row's hint names the new path. Content unchanged apart from the header.

### Rank Math: one baseline part on its own (B, TPL-201)

One Rank Math baseline part on its own: "Archives" off the titles (WP-99 B, TPL-201; owner 2026-11-19). **Adopt:** bump `pkde/deploy` and the workflow tags (January train); then EVERY site runs the titles part on staging after its next deploy and on production in the window.

- **`bin/deploy/rankmath-baseline.php only=titles|schema|share-image`** (one or more, comma-separated): applies that part of the baseline and nothing else (no language, logo or organization change). `only=titles` takes "Archives" and the repeated site name out of every title template (`pkde_rankmath_clean_title()`: the post types, the taxonomies, the home, author, date, search and 404 titles). No argument: the whole baseline, as before. An unknown part is refused and nothing is written. Idempotent; it prints option key names, never values. On a site: `bash bin/deploy/wp.sh eval-file bin/deploy/rankmath-baseline.php only=titles`.
- Tested: Pest 121 (the parser, the part split, and the script run as a separate process with stand-ins: one option written and named, a second run writes nothing, an unknown part writes nothing). Not run against a real Rank Math site: the titles function is the one the baseline has used since 2026.11.19.

## 2026.11.19.8

Drift: kit view overrides (TPL-199, triage TPL-189). **Adopt:** bump `pkde/deploy` and the workflow tags (January train).

- **INFO row "Kit view overrides":** every file under the theme's `resources/views/vendor/pkde-kit/**` is compared with the kit's current view of the same path (lines that differ, both ways; order and whitespace do not count). The row lists each with how many lines differ, marks one identical to the kit's ("delete it") and one the kit no longer has. Never a FAIL (manager ruling). It would have shown bb's category tiles and tcs's product card falling behind the kit. Read only. Run read-only on the real themes: tcs 2 overrides (52 and 69 lines differ), bb 1 (37), jsd 5 (2 to 24), js none. Pest 117.

## 2026.11.19.7

Shop filters v2: the Apache cap and the drift rows (WP-96, TPL-192). **Adopt:** bump `pkde/deploy` and the workflow tags. NOT part of the December train.

- **Query Flood Guard: a cap on `sage_filter_` arguments.** 120 or more in one query string are answered 410 before WordPress boots: `RewriteCond %{QUERY_STRING} ^(?>.*?(?:^|&)sage_filter_){120}`. A fixed number above any legal request (the kit's limit is `max_active_groups` x `max_values_per_group`, 6 x 10 = 60 by default; the kit answers anything above that with a 404). The pattern is anchored and uses an atomic group: an unanchored first version took 650 ms on a 7 kB query string (it restarted the scan at every position); anchored it takes 15 ms. Proved in a real Apache 2.4 with the block exactly as written: 119 occurrences 200, 120 410, a longer string 414 (Apache's own limit), the earlier layered-nav rule still 410, `filter_product_cat[]` and a `sage_filter_` inside a value still 200. A block from 2026.11.19.1 to .6 that still has its rule gets the cap pair inserted inside it; a block a site emptied (markers only) stays empty and is not added again (tested, scenario 21; 182 checks in a Linux container).
- **Drift rows (WooCommerce sites with the kit 2026.11.19.4):** "Shop filters: declared groups resolve" (FAIL names a declared source that is not a product taxonomy, price or stock) and an INFO row listing the declared groups, or the defaults, so a change between releases shows. Proved on the template stack with a deliberately wrong source in a temporary `config/shop-filters.php`. Pest 116.

## 2026.11.19.6

The fresh render check behind the staging gate, a broken home page, and `/.user.ini` (TPL-191, tcs TCS-131; WP-85). **Adopt:** bump `pkde/deploy` and the workflow tags; a staging behind the HTTP gate adds `STAGING_GATE_AUTH` to its shared env file to have the check really run.

- **Behind the staging gate:** the check used to print "not done, the home page could not be fetched (401)" and prove nothing. It now sends the gate's `user:password` from `STAGING_GATE_AUTH` in the shared env file when that is set (to curl on stdin as a config line, never on a command line, never printed or logged; same name as tcs's own self-requests use), and a 401/403 without it, or with credentials the gate refuses, is reported as "skipped: behind the staging gate" with the variable to set. A password holding a quote is not supported by the env reader (quotes are stripped, as for every value). Checked with real curl against a real Basic-auth server: 401 without, 200 with, for a password containing a quote and a backslash.
- **A home page that answers 5xx fails the deploy** (it was "not done"): "BROKEN SITE: the home page of <url> answers HTTP 503", with the rollback command in the output, the release stays live and the run ends red (exit 3, "answers HTTP 503 (see BROKEN SITE above)"); `PKDE_FRESH_RENDER_CHECK=warn` only warns, `off` skips as before.
- **User INI Guard block** in the shared `.htaccess`, for every site: `<Files ".user.ini">` / `Require all denied` inside `<IfModule mod_authz_core.c>`. A default Apache 2.4 serves `/.user.ini` (200; checked); it holds the opcache setting and, with Wordfence Extended Protection, the firewall shim's path. Added once, re-added when W3TC drops it; 20i's own stack already answers 403 on the live sites, so on 20i this changes nothing visible.
- Tests: `activate-release.test.sh` 176 checks in a Linux container (new scenario 20: the guard once and re-added, 401 without credentials, credentials sent on stdin and found in no log or argument, a refused gate, 200 with credentials, 503 fails with exit 3 and warns under `warn`; the curl stub now speaks the `-o`/`-w` interface). NOT run on 20i or behind a real gate.

## 2026.11.19.5

One-command rollback of a release (WP-95, TPL-187). **Adopt:** bump `pkde/deploy` and the workflow tags (the deploy copies the script to the server on every run, so the command exists after a site's next deploy to staging or production).

- **`bash <DEPLOY_PATH>/activate-release.sh <DEPLOY_PATH> previous`** switches `current` back to the complete release just before it (names are UTC timestamps; a release without `web/` or `vendor/` is skipped). Same code path as a deploy, so the opcache reset, the "web PHP serves release X" check, the `.htaccess` blocks and the flushes all run. Repeat to go back further; with no earlier complete release, or no `current`, it exits 1 and changes nothing. Code only: the database and `shared/` are not rolled back. Tested in a Linux container: scenario 19 of `activate-release.test.sh` (166 checks in all): the no-current error, skipping a half-copied release, the opcache reset line, going two back, nothing older left, and rolling forward by name. NOT rehearsed on 20i.

## 2026.11.19.4

Wordfence Extended Protection: pilot preparation (WP-85, TPL-188). **Adopt:** bump `pkde/deploy` and the workflow tags; nothing changes for a site that has not opted in.

- **One-command rollback:** `bash <root>/current/bin/deploy/waf-prepend.sh --rollback <root>/shared <root>/current` sets `WORDFENCE_EXTENDED_PROTECTION=0` in `shared/.env` (that one line; appended if absent) and removes only Wordfence's block from the live release's `web/.user.ini`; the `; pkde opcache` block stays (deleting the whole file, as the old advice said, drops it until the next release). Tested: `waf-prepend.test.sh` 44 checks (11 new: block gone, opcache block kept, flag 0, rest of `.env` untouched, no value printed, a later release does not bring the block back, a flag-less `.env` without a final newline, a missing link is a usage error), in a Linux container with PHP.
- **Drift:** a new row for opted-in sites, "/.user.ini is not downloadable" (403, 404 or a redirect PASS; any 2xx FAIL with the remedy; no answer or a 401 SKIP). Checked in a real Apache 2.4: a default Apache answers 200 for `/.user.ini`, and `<Files ".user.ini">` / `Require all denied` in `.htaccess` makes it 403 without touching the site. The existing pointer row and the new row now also run on a staging site where Wordfence is inactive (they read files and one URL, not Wordfence), because that is where the pilot proves the mechanism. Pest 115.

## 2026.11.19.3

Contact Form 7 forms found by a stable key (TPL-186, WP-83). **Adopt:** bump `pkde/deploy` and the workflow tags; an existing site runs the tagging script once on staging and again on production.

- **`bin/deploy/cf7-form-key.php`** (`cf7-form-key-standard.php` holds the rules): tags SEVERAL forms per run from `key="the form's title today"` arguments (`bash bin/deploy/wp.sh eval-file bin/deploy/cf7-form-key.php contact="Contact Form" park-home-enquiry="Park Home Enquiry"`; no arguments tags "Contact form" as `contact`). Each form is tagged once (meta `_pkde_form_key`), a second run changes nothing, a form that already carries the key counts whatever its title is now, and it prints the key and the form ID only. A title that matches no form, several forms, or a form with another key is reported and not touched (exit 1). Run on the template stack through WP-CLI with Contact Form 7 active: no arguments and a title that does not match gives NOT TAGGED; the arguments tag it; the second run and a run after a rename say "already tagged".
- **Drift rows:** INFO "CF7 forms with a lookup key" (which keys, with form IDs); FAIL "A theme file asks for CF7 form key 'x': a form carries it" only when a theme file calls `form_id('x')` / `form_shortcode('x')` and no form has the key (with the command to run); INFO when a theme file still finds a form by `get_page_by_title(... 'wpcf7_contact_form')`.
- Both files ship in the artifact (`assemble-artifact.sh`, `check-artifact.sh`). Pest 114 (the plan, the argument parser, the source scan, the script in a separate process, the drift rows).

## 2026.11.19.2

Small corrections (TPL-185). **Adopt:** bump `pkde/deploy` and the workflow tags.

- **`env-sync.py` (env-pull/push):** the health probe after an apply tries twice (15 s each, a pause between) before it rolls back, so one slow answer from a 20i host no longer undoes a good apply. After an apply that changes `WP_ADMIN_EMAIL` or `WORDFENCE_ALERT_EMAIL` it prints that Wordfence `alertEmails` follows at the next release, not now. Tested: a flaky 503 then 200 passes after exactly 2 tries, a persistent 503 gives PROBE FAILED after 2 (`env-sync.test.sh`, 73 checks).
- **Drift: the author-role rows count blog authors only** (`post` type): an administrator who only published products or pages no longer counts as a blog author without the `author` role (tcs). The REST users probe still uses any REST post type, as WordPress's own users route does.
- **Drift wording:** "Wordfence inactive on staging by design" was wrong (rp, tcs, mds, jsd and cs run it active on staging). The rows say "not active on this staging site"; the SKIP applies only while Wordfence is inactive, an active one is held to the whole standard (new Pest case for `loginSec_disableAuthorScan` on an active staging).

## 2026.11.19.1

Layered-navigation bot flood guard (TPL-184, tcs TCS-123, owner go 2026-11-16). **Adopt:** bump `pkde/deploy` and the workflow tags; a shop adds its icons (below).

- **Query Flood Guard block** in the shared `.htaccess`, inserted ABOVE WordPress's rules (WordPress's catch-all ends the rewrite pass for every request that is not a file, so a rule below it never sees a virtual URL such as `/shop/`), once, and re-added when W3TC or Wordfence drop it: `RewriteCond %{QUERY_STRING} (^|&)(filter_[a-z0-9_-]+|query_type_[a-z0-9_-]+|rating_filter)= [NC]` and `RewriteRule ^ - [G,L]`, so `?filter_colour=a,b&query_type_colour=or` answers **410 Gone** from Apache before WordPress boots. Proved in a real Apache 2.4 (container, `AllowOverride All`, the block above the WordPress block): the layered-nav, `rating_filter` and mixed-case queries answer 410; `?orderby=`, `sage_filter_*[]`, `filter_product_cat[]` (raw and `%5B%5D`), `refilter_a=` and a plain `/favicon.ico` answer as before. The exemption asked for the array-valued `filter_product_cat[]` / `filter_product_tag[]` is by construction: the name is followed by `[` or `%5B`, never `=`. A site that uses WooCommerce's layered-nav widgets or Product Filter blocks deletes the block and leaves a `# BEGIN Query Flood Guard` comment so it is not added again.
- **Drift rows:** `/favicon.ico` and `/apple-touch-icon.png` answer as image files (a WordPress-served one answers 200 `text/html`; a missing one 404 is a FAIL too), and `?filter_x=a,b&query_type_x=or` answers 410. A gate or firewall (401, 403, no response) is a SKIP.
- Tests: `activate-release.test.sh` scenario 18 (above WordPress, once, re-added, other blocks survive; run in a Linux container), Pest 106 including the rule's regex against 6 blocked and 9 allowed query strings. NOT run on a real host: whether 20i's Apache honours `RewriteRule ... [G]` in `.htaccess` (the drift row says).

## 2026.11.19

Security headers, the Rank Math baseline and more drift rows (WP-91 phase 2, TPL-180, TPL-182). **Adopt:** bump `pkde/deploy` and the workflow tags; HSTS is NOT included (owner decision pending).

- **Security Headers block** in the shared `.htaccess`, added once and re-added when W3TC drops it (`activate-release.sh`): `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN`, `Permissions-Policy: camera=(), microphone=(), usb=(), interest-cohort=()`, all `always set` inside `<IfModule mod_headers.c>`. Nothing in the template needs to be framed by another origin; the editor, the Customizer, WooCommerce payment iframes, Turnstile and GTM are not affected (they are our page framing their site, or same-origin). A site that must be framed deletes the `X-Frame-Options` line; an edited block stays. Tested in a Linux container (scenario 17, 152 checks in all). NOT proved on a real server: that `mod_headers` is on at 20i; four drift rows request the home page and say.
- **Rank Math baseline:** `bin/deploy/rankmath-standard.php` (pure rules, shared) and `bin/deploy/rankmath-baseline.php` (writes them; `bin/setup` runs it): company not person, the site's name, its logo and a default share image once a logo exists, pages as WebPage (no extra Article), posts as BlogPosting, products as Product, title templates without "Archives" and with the site name once, archive descriptions from the term description, and the site language `en_GB` (or `SITE_LOCALE` in the `.env`; the language pack is downloaded first, because WordPress stores an uninstalled WPLANG as empty). It writes only the keys that differ and prints their names; run twice it changes nothing. Seven drift rows, one per rule; SKIP on local.
- Both files ship in the artifact (`assemble-artifact.sh`, `check-artifact.sh`). Tests: deploy Pest 104, `activate-release.test.sh` 152 checks in a Linux container. The baseline ran on the template stack: before, six rows differed; after, the five rules PASS, the logo and share-image rows wait for a logo, and the site is `en_GB` (`<html lang="en-GB">`, `og:locale en_GB`).

## 2026.11.18

Search engine safety (WP-91, TPL-176, 177, 178). **Adopt:** bump `pkde/deploy` and the workflow tags; each site then runs two things once (below).

- **Staging Noindex block** in the shared `.htaccess`: `Header set X-Robots-Tag "noindex, nofollow"` for images and files, ADDED where the server `.env` has `WP_ENV` other than `production` and REMOVED where it is `production` or unset (a staging `.htaccess` copied to production loses it). Re-asserted every release. Tested in a Linux container (`activate-release.test.sh`, scenario 16: add, once, re-add after W3TC drops it, any non-production value, removal on production with every other block intact). Whether `mod_headers` is on at 20i is not proved: the drift row says.
- **W3TC never caches `robots.txt` or sitemaps:** `w3tc-standard.php` has `pkde_w3tc_reject_uris()` (`robots\.txt`, `sitemap[^/]*\.(xml|xsl)`), `w3tc-settings.php` adds them to "Never cache the following pages", the drift check has the row "W3TC page cache never caches robots.txt or the sitemaps". Existing sites re-run `w3tc-settings.php` once, then `fix_environment`.
- **The page cache is purged at every release:** `wp w3-total-cache flush posts` runs after the flushes and before the warm-up. The cache directory stays per release (a shared one would keep pages linking the previous release's asset hashes).
- **`seo-sitemap-exclusions.php`** (shipped in the artifact): takes the WooCommerce cart, checkout and my-account pages out of Rank Math's sitemap and drops its cached sitemap files. `bin/setup` runs it for a new site; an existing site runs `bash bin/deploy/wp.sh eval-file bin/deploy/seo-sitemap-exclusions.php` once (staging, then production). Idempotent.
- **New drift rows**, from requests the check makes to the site's own address (a probe that does not get 200 is a SKIP naming the status): production homepage has no noindex and its `robots.txt` is not `Disallow: /`; staging pages and a static file carry `noindex, nofollow` and its `robots.txt` names no sitemap; `?orderby=price` on the shop is noindex in the header AND the meta tag; the sitemap lists no cart, checkout, my-account or query-string URL.
- Tests: deploy Pest 101, `activate-release.test.sh` 146 checks in a Linux container, assemble-artifact 9. The probe rows were run through `pkde_drift_collect()` on the template stack (WooCommerce, Rank Math), FAIL before the sitemap exclusion and PASS after. Not run on a real server.

## 2026.11.13.7

Second fix for the stale release after a green deploy (TPL-172; bb and tcs staging, 2026-11-18: pages unstyled because the web PHP rendered the previous release's asset hashes, which 404 in the new docroot, while the cache primer's PHP saw the new release). **Adopt:** bump the package and the workflow tag; this replaces 2026.11.13.6.

- **What 2026.11.13.6 missed, found in the follow-up evidence and reproduced.** `opcache_reset()` does not clear a PHP-FPM worker's own **realpath cache**, and `clearstatcache(true)` clears only the worker that runs it. With a long `realpath_cache_ttl` (20i's evidently is) a worker keeps resolving `current` to the OLD release for the whole TTL, and `opcache.revalidate_path=1` does not help while that cache says so. Reproduced on PHP-FPM 8.3 with 4 and 8 workers and `realpath_cache_ttl=3600`: after the switch every request ran the old release; `revalidate_path=1` alone did not change that; a web-side `opcache_reset()` plus `clearstatcache(true)` in EVERY worker did. And sequential probe requests do not reach every worker (an idle worker takes the next request), so the 7 sequential hits of .13.6 could leave workers untouched.
- **The probe now clears the worker's realpath cache and is sent as a PARALLEL batch.** `hit_all_workers`: `OPCACHE_EXTRA_HITS` requests (default 16) at once, each with `?hold=1`, which makes the probe sleep 0.45 s so that the requests spread over distinct workers; every one runs `opcache_reset()` and `clearstatcache(true)` in its worker. The log counts the distinct worker pids and opcache instances (`Web PHP: 16 parallel probe requests reached 8 distinct PHP workers in 1 opcache instance(s)`), warns when every request got a worker of its own (there may be more workers: raise `OPCACHE_EXTRA_HITS`) and when more than one opcache instance answers. Reproduced: 16 held parallel requests reached all 8 workers of a pool and the next 16 requests all ran the new release. The same batch runs again on every retry of the fresh render check. The batch waits only for its own requests.
- `opcache.revalidate_path=1` stays (it makes OPcache follow `current` once a worker's realpath cache is clean), the fresh render check stays (it is what turns a stale site into a red run and tells which hash the page still links).
- Not done, on purpose: moving W3 Total Cache's page-cache directory to `shared/`. It is per release by design (web/app/cache is disposable): a shared page cache would keep pages that link the previous release's asset hashes across a deploy and need a flush at every switch, which is the same problem in a worse place. With the workers fixed, a flush after a deploy clears the cache the new release actually serves.
- Immediate cure for a site that is stale now: any deploy with this version (the fix is in the uploaded activate-release.sh); or a restart of PHP for the package in My20i, which replaces the workers (bp reported earlier that it did not clear an OPcache problem, so confirm with an uncached page afterwards).

## 2026.11.13.6

Fix: after a green deploy the web PHP kept rendering the PREVIOUS release (TPL-172; tcs staging, release 20261118192614, 2026-11-18: 15 of 15 uncached renders over 6+ minutes had the old CSS hash and the old views, while the release's manifest named the new hash and the deploy log said "Web opcache reset; the web server serves release ..."). **Adopt:** bump the package and the workflow tag; the next deploy brings the fix itself (activate-release.sh is uploaded with every deploy), and a site that is stale right now is cured by that deploy.

- **Cause, reproduced.** Under PHP-FPM with OPcache and `opcache.revalidate_path=0` (20i's setting) a script cached under the UNRESOLVED path `current/web/index.php` keeps its bytecode, and that bytecode has the old release's directory compiled in (`__DIR__`): after the symlink switch the pool runs the previous release for good (tested on PHP 8.3 FPM: still the old release 14 s later and after waiting, correct only after a web-side `opcache_reset()`). The old probe answered "ok <release>" because the probe file is new and not cached: it proved the web server can reach the new release, not that the pool had dropped the old code, and it never looked at `opcache_reset()`'s return value.
- **Structural fix: `opcache.revalidate_path=1` in every release's `web/.user.ini`** (a marked `; pkde opcache` block, merged with any other lines and with the Wordfence Extended Protection block). The setting is INI_ALL, so a per-directory `.user.ini` may set it; with it the very next request after the switch runs the new release (tested the same way). Opt out with `PKDE_OPCACHE_REVALIDATE_PATH=0` in the server `.env`. A first deploy that adds the block takes effect when the pool next reads `.user.ini` (`user_ini.cache_ttl`, 300 s), which is why the reset stays.
- **The reset probe now reports what it found**: whether `opcache_reset()` returned true (it returns false, and the page still printed "ok", when `opcache.restrict_api` blocks it: now a loud warning), the pool's opcache instance (start time), the host, PHP version, `revalidate_path`, `revalidate_freq`, `validate_timestamps`, `restrict_api`, `realpath_cache_ttl` on a `Web PHP:` line. It is requested seven times (`OPCACHE_EXTRA_HITS`, default 6 more): every PHP pool or node that answers gets its own reset, and more than one distinct opcache instance is warned about.
- **Fresh render check after the reset.** The home page is requested with a one-off query string (not page-cached) and the stylesheet it links (`assets/app-<hash>.css`) is compared with the one in this release's Vite manifest. A match is logged. A difference triggers another reset and a re-read (4 tries); a page that still differs marks the deploy failed: the release stays live, the post steps run, and the run ends with exit 3 and a "STALE RELEASE" block (the activation exec leaves a marker, the post exec fails the run). It never guesses: no manifest entry, a page that cannot be fetched (401, 403, 5xx, a coming-soon gate) or one that links no such stylesheet is reported as not checked. `PKDE_FRESH_RENDER_CHECK=warn|off` in the server `.env` (default `fail`).
- Tests: `tests/deploy/activate-release.test.sh` 140 checks (new scenarios 9h, 9i, 9j: diagnostics, extra hits, two pools, reset false, match, stale, stale then fresh, warn, off, 401, no manifest, no stylesheet, split execs, the `.user.ini` block, idempotence, with the Wordfence block, opt-out).

## 2026.11.13.5

WP-85 follow-up (the standing rule "do not enable Optimize the Firewall"). **Adopt:** bump the package and the workflow tag.

- `waf-prepend.sh` refuses to write `web/.user.ini` unless the shim it points at exists and is readable (a dangling `auto_prepend_file` fails every request). `activate-release.sh`'s header comment now says the Optimize button stays forbidden and the opt-in `WORDFENCE_EXTENDED_PROTECTION=1` is the one supported way. The rp retrospective's rule is updated in place in the template (F2, checklist, lesson 4).

## 2026.11.13.4

The TPL-129 second pass (three of the four strongest audit leads, all low severity) and the Wordfence Extended Protection build (WP-85). **Adopt:** bump the package and the workflow tag. Nothing changes for a site until it opts in (below); a caller that has the `SSH_KNOWN_HOSTS` secrets can set `require-pinned-host-key: true`.

- **Docker images in the workflows are pinned by digest** (`rhysd/actionlint`, `zricethezav/gitleaks`: tag kept, `@sha256:` added); `tests/deploy/workflow-hardening.test.sh` fails any `docker run` image without a digest. The local-development images (compose, Dockerfiles) are not pinned on purpose. Renovate does not see images inside `run:` steps, so bumps are by hand.
- **`require-pinned-host-key` input on `deploy.yml` (default false).** When true, the deploy step fails instead of learning the server's host key with `ssh-keyscan` (trust on first use, repeated on every run because runners are ephemeral) when the `<PROD|STAGING>_SSH_KNOWN_HOSTS` secret is empty. Default false so no site breaks on the bump; sites whose secrets are set should turn it on, and bb, el, js and tcs production need the secret first (`bin/deploy/push-gh-secrets.sh` pushes it from the 1Password item). Verified: a network attacker on the path gains the build artifact and a fake successful deploy, not the deploy key and not code execution on the real server, hence low.
- **Wordfence Extended Protection (opt-in, `WORDFENCE_EXTENDED_PROTECTION=1` in the server `.env`).** New `bin/deploy/waf-prepend.sh`, run by `activate-release.sh` on every release: a guarded shim at `shared/waf/wordfence-waf.php` (a path that never changes, reaching the plugin through the `current` link) and a `web/.user.ini` with Wordfence's own markers in each new release. Wordfence's own installer writes both inside the release and the path dangles after the next release (Fatal error: Failed opening required on every request). Drift row (opted-in sites): "Wordfence Extended Protection: web/.user.ini points at the shared shim". Proved on the template stack with a real PHP-FPM pool: applied, survives a release switch and a pruned release, harmless when the plugin is gone. See reports/template/2026-11-13-wp-85-wordfence-extended-protection.md.
- **Not changed (recorded):** `FINGERPRINT_AUTH` is handed to Puppeteer `authenticate()`, which answers any Basic challenge from any origin (never pre-emptively); a scoped version needs request interception, which sends the header pre-emptively and would put the staging credential on production in the compare run. Low severity, left as documented.

## 2026.11.13.3

REST users closed for anonymous visitors on every site (owner yes, WP-88; needs pkde-platform 2026.11.13.1). **Adopt:** bump pkde/platform to 2026.11.13.1, then the package and the workflow tag to this one.

- **Two drift rows, an anonymous probe:** "Anonymous GET /wp/v2/users does not list users (401, 403 or 404)" and "... /wp/v2/users/<id> ...". The check dispatches the two requests inside WordPress as a logged-out visitor (so staging's Basic auth and a firewall do not matter, and no HTTP is sent) and records the status codes only; 200 is a FAIL. It passes with the platform module `rest-users-private` (404 in every environment, Wordfence or not) or with Wordfence's username discovery protection on (401). The single-user probe uses the ID of a real author.

## 2026.11.13.2

Wordfence "Prevent discovery of usernames" joins the standard (owner yes, WP-88). **Adopt:** bump the package and the workflow tag (after 2026.11.13.1); production gets it at its next release.

- **`loginSec_disableAuthorScan = 1`** (Wordfence: no username discovery through `?author=N` and the REST users list for anonymous visitors) is a Wordfence standard key: a drift row "Wordfence loginSec_disableAuthorScan" (FAIL when off; Wordfence is inactive on staging by design, so that row is skipped there), and the release step `wordfence-standard.php` turns it on at every release with plain SQL, before the alert-address part, so it applies even to a site that names no alert address. Proved on the template stack: with the option off an anonymous `GET /wp-json/wp/v2/users` answered 200, after the release step 401; a second run says "already on".

## 2026.11.13.1

Author archive rows, owner decision (WP-88): blog authors who are administrators keep administrator and get the author role added. **Adopt:** bump the package and the workflow tag.

- **"Every user with published posts has an allowed author role" is a FAIL** (it was an INFO): wp-admin's profile form has one role dropdown, so saving the profile of an administrator who also holds `author` silently drops the second role. The role check is the intersection, so administrator plus author passes. The detail names counts only and the cure: `wp user add-role <ID> author`, or `AUTHOR_ARCHIVE_ROLES`.
- **New FAIL row "Author archive slug (user_nicename) differs from the login"** for users with an allowed role: the archive URL contains the nicename, so a nicename equal to the login gives the login away. Counts only, no names.

## 2026.11.13

Drift rows for the author archives (WP-88; needs pkde-platform 2026.11.13) and two small env-tool changes from the mds pilot (WP-87). **Adopt:** bump the package and the workflow tag; bump pkde/platform to 2026.11.13 (or the new row FAILs).

- **Drift rows, author archives.** "Author archives are closed for users without an allowed role (platform module author-archives)": FAIL until the platform module is loaded. "Users with published posts without an allowed author role": INFO with counts only (no names, logins or addresses) when some users who have published posts hold none of the allowed roles; their archive is a 404 by design and their byline links home.
- **`env-seed.sh` always creates `WP_ADMIN_EMAIL` and `WORDFENCE_ALERT_EMAIL`** (section "WP Admin", CONCEALED), empty when the server `.env` lacks them, so the empty alert field documents the option. The note's own notes carry the line "WORDFENCE_ALERT_EMAIL empty = Wordfence alerts go to WP_ADMIN_EMAIL". `env-pull.sh` treats an empty `WORDFENCE_ALERT_EMAIL` (or `WP_ADMIN_EMAIL`) as unset: neither is a required variable, so it writes `WORDFENCE_ALERT_EMAIL=` and does not refuse (tested).

- **`env-pull.sh --check` compares the note with the server only** (mds pilot, WP-87). Variables that `.env.example` has and the note lacks are listed as `INFO` and no longer make `--check` say DIFFERS or exit 1.

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
