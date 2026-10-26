#!/usr/bin/env python3
"""Compare two crawl CSVs from crawl.py (the live baseline against the local or staging site), by path.

  python3 vendor/pkde/deploy/bin/deploy/seo/compare.py docs/seo/baseline-YYYY-MM-DD/inventory-live.csv /tmp/inventory-new.csv \
      [--accept /about-us/,/contact-us/] [--accept-file docs/seo/description-exceptions.md]

Per URL it reports a changed status, final status, title, DESCRIPTION, H1, and a changed canonical path or robots value (the last two only
when both sides print one: a non-production site prints noindex and no canonical, so re-check those at cutover). A description that changes, appears (the live
page had none: Rank Math's WooCommerce module prints fallbacks) or disappears is reported on its own line, because it is the quiet one: a builder conversion changes the content a plugin derives it from (pin every
auto-derived description as the page's own before converting).

A description that starts with a page-builder token is a bug, not drift: Divi's dynamic-content token `@ET-DC@eyJ...` (also written with the
full-width at sign U+FF20). On the live side it is listed under "Live bugs" and does not count as a difference (do not pin it: list the page
in docs/seo/description-exceptions.md for the owner to approve a derived or hand-written description); on the new side it is a failure.
`--accept` (paths, comma separated) and `--accept-file` (the first column of the markdown tables in that file that starts with a path)
mark reviewed, explained differences: they are listed as ACCEPTED and not counted.

Rows are keyed by path. A URL with a query string (`/shop/?sage_view=new`, a filter, a utm tag) is skipped on both sides unless
`--include-query` is given, which keys rows by path AND query: otherwise it overwrites its own page (the /shop/ H1 read "New"). Robots is
not compared while the new site is noindex (a non-production site always is, so every URL would differ); a one-line note says so. At
cutover run with `--cutover`: robots and canonical are then compared strictly, and a noindex on the new side fails. `--ignore robots,h1`
(any of status, final_status, title, description, h1, canonical, robots) leaves fields out on purpose.

Exit 0 when there is no unexplained difference, 1 otherwise.
"""
import argparse
import csv
import re
import sys
from urllib.parse import urlparse

PLACEHOLDER = re.compile(r'^\s*[@＠]ET-DC[@＠]')
FIELDS = ('status', 'final_status', 'title', 'description', 'h1')


def key_of(url, include_query):
    parsed = urlparse(url)
    path = parsed.path or '/'
    return f'{path}?{parsed.query}' if include_query and parsed.query else path


def load(path, include_query=False):
    """(rows by key, number of query-string URLs skipped). Without include_query a URL with a query string is dropped, never overwriting its page."""
    rows, skipped = {}, 0
    with open(path, newline='', encoding='utf-8') as handle:
        for row in csv.DictReader(handle):
            if not include_query and urlparse(row['url']).query:
                skipped += 1
                continue
            rows[key_of(row['url'], include_query)] = row
    return rows, skipped


def accepted_paths(accept, accept_file):
    paths = {p.strip() for p in (accept or '').split(',') if p.strip()}
    if accept_file:
        with open(accept_file, encoding='utf-8') as handle:
            for line in handle:
                match = re.match(r'^\|\s*(/[^\s|]*)', line)
                if match:
                    paths.add(match.group(1))
    return paths


def compare(live, new, accepted, ignore=frozenset(), cutover=False):
    """Returns (lines, unexplained difference count, live bug count, accepted count)."""
    lines, bad, live_bugs, accepted_count = [], 0, 0, 0
    robots_skipped = 0

    def report(path, text):
        nonlocal bad, accepted_count
        if path in accepted:
            accepted_count += 1
            lines.append(f'ACCEPTED {path}: {text}')
        else:
            bad += 1
            lines.append(f'{path}: {text}')

    for path, x in live.items():
        y = new.get(path)
        if y is None:
            report(path, 'MISSING in new')
            continue
        for field in FIELDS:
            before, after = x.get(field, ''), y.get(field, '')
            if before == after or field in ignore:
                continue
            if field == 'description':
                if PLACEHOLDER.match(after):
                    bad += 1
                    lines.append(f'{path}: NEW BUG, the new description is a page-builder token: {after[:60]!r}')
                elif PLACEHOLDER.match(before):
                    live_bugs += 1
                    lines.append(f'LIVE BUG {path}: the live description is a page-builder token ({before[:40]!r}...), not text; new: {after[:90]!r}')
                elif not before:
                    # Rank Math's WooCommerce module (and Yoast) print fallbacks the live site never had: a difference, not an improvement.
                    report(path, f'DESCRIPTION ADDED, the live page has none: {after!r}')
                elif not after:
                    report(path, f'DESCRIPTION REMOVED: {before!r} -> nothing')
                else:
                    report(path, f'DESCRIPTION CHANGED: {before!r} -> {after!r}')
            else:
                report(path, f'{field}: {before!r} -> {after!r}')
        for field in ('canonical', 'robots'):
            before, after = x.get(field, ''), y.get(field, '')
            if field in ignore:
                continue
            if field == 'robots':
                if 'noindex' in after.lower() and not cutover and 'noindex' not in before.lower():
                    robots_skipped += 1
                    continue
                if (before or cutover) and before != after:
                    report(path, f'robots: {before!r} -> {after!r}')
            elif (before and after or cutover and before != after) and urlparse(before).path != urlparse(after).path:
                report(path, f'canonical: {before} -> {after}')
    for path in sorted(new.keys() - live.keys()):
        lines.append(f'NEW in new: {path}')
    if robots_skipped:
        lines.append(f'NOTE robots not compared on {robots_skipped} URL(s): the new site is noindex (non-production). Run with --cutover at go-live.')
    return lines, bad, live_bugs, accepted_count


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('live')
    ap.add_argument('new')
    ap.add_argument('--accept', default='', help='comma-separated paths whose differences were reviewed')
    ap.add_argument('--accept-file', default='', help='markdown file whose tables list the reviewed paths in their first column')
    ap.add_argument('--include-query', action='store_true', help='compare URLs with a query string too, keyed by path and query')
    ap.add_argument('--ignore', default='', help='comma-separated fields to leave out: ' + ', '.join(FIELDS + ('canonical', 'robots')))
    ap.add_argument('--cutover', action='store_true', help='go-live check: compare robots and canonical strictly, a noindex on the new side fails')
    a = ap.parse_args()
    live, skipped_live = load(a.live, a.include_query)
    new, skipped_new = load(a.new, a.include_query)
    ignore = frozenset(f.strip() for f in a.ignore.split(',') if f.strip())
    lines, bad, live_bugs, accepted_count = compare(live, new, accepted_paths(a.accept, a.accept_file), ignore, a.cutover)
    print('\n'.join(lines))
    if skipped_live or skipped_new:
        print(f'NOTE {skipped_live} baseline and {skipped_new} new URL(s) with a query string were skipped (--include-query compares them).')
    print(f'{bad} unexplained difference(s), {live_bugs} live bug(s), {accepted_count} accepted, over {len(live)} baseline URLs')
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main())
