#!/usr/bin/env python3
"""Polite crawler for a site's SEO inventory and the before/after comparison (compare.py). From the tcs, el and bb crawlers.

Starts from the sitemap index, then follows internal links to category/tag archives and their pagination.
Writes one row per URL (status, redirect chain, title, description, canonical, robots, H1s, OG, JSON-LD types,
image and alt counts, internal link count) to <out>.csv and the internal link sets to <out>-links.json.gz.
Public pages only, no login, no personal data. One request every --delay seconds.

  python3 vendor/pkde/deploy/bin/deploy/seo/crawl.py --base https://www.example.com --out docs/seo/baseline-YYYY-MM-DD/inventory-live
  python3 vendor/pkde/deploy/bin/deploy/seo/crawl.py --base http://localhost:8092 --out /tmp/inventory-new      # later, for the diff
  python3 vendor/pkde/deploy/bin/deploy/seo/crawl.py --base http://localhost:8092 --urls paths.txt --out /tmp/inventory-new   # exactly live's URL set
  python3 vendor/pkde/deploy/bin/deploy/seo/compare.py docs/seo/baseline-YYYY-MM-DD/inventory-live.csv /tmp/inventory-new.csv

--urls FILE (from tcs) crawls exactly the paths in FILE, one per line, and follows no links: the rebuild checked on live's URL set, because a
rebuild can hide pages (empty categories) its own sitemap then lacks. Make the file from the baseline: the path column of inventory-live.csv.
A live archive paged with a query string (WooCommerce's ?product-page=N) is in that file with its query string; compare it with --include-query.
"""
import argparse, csv, gzip, html, json, re, sys, time
from urllib.parse import urljoin, urlparse, urldefrag

import requests

UA = 'Mozilla/5.0 (compatible; pkde-seo-audit; +owner-approved)'
# Archive URL patterns whose pages (and their /page/N/ pagination) are followed; override with --follow (a regex).
FOLLOW = re.compile(r'/(product-category|product-tag|category|tag)/')
SKIP_EXT = re.compile(r'\.(?:jpe?g|png|gif|webp|avif|svg|pdf|css|js|ico|woff2?|xml|zip)(?:\?|$)', re.I)


def grab(pattern, text, flags=re.S | re.I):
    m = re.search(pattern, text, flags)
    return html.unescape(m.group(1)).strip() if m else ''


def parse(body, url, host):
    h1s = [html.unescape(re.sub(r'<[^>]+>', '', h)).strip() for h in re.findall(r'<h1\b[^>]*>(.*?)</h1>', body, re.S | re.I)]
    types = []
    for blk in re.findall(r'<script[^>]+application/ld\+json[^>]*>(.*?)</script>', body, re.S | re.I):
        try:
            j = json.loads(blk)
        except ValueError:
            continue
        for node in (j.get('@graph', [j]) if isinstance(j, dict) else j):
            t = node.get('@type') if isinstance(node, dict) else None
            types += t if isinstance(t, list) else [t] if t else []
    imgs = re.findall(r'<img\b[^>]*>', body, re.I)
    no_alt = [i for i in imgs if not re.search(r'\balt=("[^"]+"|\'[^\']+\')', i, re.I)]
    links = set()
    for href in re.findall(r'<a\b[^>]*\shref=["\']([^"\']+)["\']', body, re.I):
        href = urldefrag(urljoin(url, html.unescape(href)))[0]
        p = urlparse(href)
        if p.netloc == host and not SKIP_EXT.search(href) and '/wp-' not in p.path and 'add-to-cart' not in href:
            links.add(href)
    return {
        'title': grab(r'<title[^>]*>(.*?)</title>', body),
        'description': grab(r'<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']', body),
        'canonical': grab(r'<link\s+rel=["\']canonical["\']\s+href=["\'](.*?)["\']', body),
        'robots': grab(r'<meta\s+name=["\']robots["\']\s+content=["\'](.*?)["\']', body),
        'h1': ' | '.join(h1s),
        'h1_count': len(h1s),
        'og_title': grab(r'<meta\s+property=["\']og:title["\']\s+content=["\'](.*?)["\']', body),
        'og_image': grab(r'<meta\s+property=["\']og:image["\']\s+content=["\'](.*?)["\']', body),
        'jsonld_types': ' '.join(sorted(set(types))),
        'images': len(imgs),
        'images_without_alt': len(no_alt),
        'internal_links': len(links),
    }, sorted(links)


def sitemap_urls(session, base, delay):
    out = []
    idx = session.get(base + '/sitemap_index.xml', timeout=30).text
    for sm in re.findall(r'<loc>([^<]+)</loc>', idx):
        time.sleep(delay)
        out += re.findall(r'<loc>([^<]+)</loc>', session.get(sm, timeout=30).text)
    return out


def main():
    global FOLLOW
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', required=True)
    ap.add_argument('--out', required=True)
    ap.add_argument('--delay', type=float, default=0.7)
    ap.add_argument('--follow', default='', help='regex of archive URL paths to follow, replacing the default')
    ap.add_argument('--probe', default='', help='extra comma-separated paths to request, for example /product/,/product-category/')
    ap.add_argument('--urls', default='', help="a file with one path per line: crawl exactly these paths and follow no links (the rebuild checked on live's URL set)")
    ap.add_argument('--limit', type=int, default=0, help='stop after N URLs (testing)')
    a = ap.parse_args()
    base = a.base.rstrip('/')
    host = urlparse(base).netloc
    s = requests.Session()
    if a.follow:
        FOLLOW = re.compile(a.follow)
    s.headers['User-Agent'] = UA
    queue = []
    seen = set()

    def add(u, source):
        u = u.rstrip('#')
        if u not in seen:
            seen.add(u)
            queue.append((u, source))

    if a.urls:
        with open(a.urls, encoding='utf-8') as handle:
            for line in handle:
                if line.strip().startswith('/'):
                    add(base + line.strip(), 'list')
    else:
        for u in sitemap_urls(s, base, a.delay):
            # A sitemap lists the site's own host; when crawling a local copy, point those URLs at --base.
            add(urlparse(base)._replace(path=urlparse(u).path, query=urlparse(u).query).geturl(), 'sitemap')
        for extra in ('/feed/', '/robots.txt', '/sitemap_index.xml', '/wp-json/', *a.probe.split(',')):
            if extra:
                add(base + extra, 'probe')

    rows, links = [], {}
    i = 0
    while i < len(queue):
        url, source = queue[i]
        i += 1
        if a.limit and i > a.limit:
            break
        time.sleep(a.delay)
        row = {'url': url, 'source': source, 'status': '', 'final_url': '', 'redirects': '', 'content_type': ''}
        try:
            r = s.get(url, timeout=45, allow_redirects=True)
        except requests.RequestException as e:
            row['status'] = 'ERR ' + type(e).__name__
            rows.append(row)
            continue
        row['status'] = r.history[0].status_code if r.history else r.status_code
        row['final_status'] = r.status_code
        row['final_url'] = r.url
        row['redirects'] = ' > '.join(f'{h.status_code} {h.url}' for h in r.history)
        row['content_type'] = r.headers.get('content-type', '').split(';')[0]
        if r.status_code == 200 and 'html' in row['content_type']:
            data, found = parse(r.text, r.url, host)
            row.update(data)
            links[url] = found
            for l in [] if a.urls else found:
                if FOLLOW.search(l) or re.search(r'/page/\d+/?$', l) and FOLLOW.search(l):
                    add(l, 'link')
        rows.append(row)
        if i % 50 == 0:
            print(f'{i}/{len(queue)} {url} {row["status"]}', file=sys.stderr, flush=True)

    cols = ['url', 'source', 'status', 'final_status', 'final_url', 'redirects', 'content_type', 'title', 'description', 'canonical',
            'robots', 'h1_count', 'h1', 'og_title', 'og_image', 'jsonld_types', 'images', 'images_without_alt', 'internal_links']
    with open(a.out + '.csv', 'w', newline='') as f:
        w = csv.DictWriter(f, cols, extrasaction='ignore')
        w.writeheader()
        w.writerows(rows)
    with gzip.open(a.out + '-links.json.gz', 'wt') as f:
        json.dump(links, f)
    print(f'{len(rows)} URLs written to {a.out}.csv', file=sys.stderr)


if __name__ == '__main__':
    main()
