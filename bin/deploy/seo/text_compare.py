#!/usr/bin/env python3
"""Visible-text identity check between the live site and a rebuilt one, over HTTP (from el, EL-7): for each path, the words of the page's
<main> text that are on one side only. Header, footer, scripts, styles, SVG and form field options are ignored; case, emoji and punctuation do
not count. Different from the native tools' check of stored content: this is what a visitor reads.

  python3 vendor/pkde/deploy/bin/deploy/seo/text_compare.py https://www.example.com http://localhost:8092 / /contact-us/ /about/
  python3 vendor/pkde/deploy/bin/deploy/seo/text_compare.py https://www.example.com http://localhost:8092 --paths-file paths.txt [--delay 1]

Public pages only, one request per --delay seconds to each side (default 1). Exit 0 when every page has the same words on both sides,
1 when any page differs or a request failed.
"""
import argparse
import html
import re
import sys
import time
from collections import Counter

UA = {'User-Agent': 'Mozilla/5.0 (compatible; pkde-text-audit; +owner-approved)'}
TOKEN = re.compile(r"[a-z0-9£][a-z0-9'.,£%-]*")


def words(markup):
    """The words of the page's <main> (the whole document when it has none) as a Counter."""
    match = re.search(r'<main\b.*?</main>', markup, re.S | re.I)
    body = match.group(0) if match else markup
    body = re.sub(r'<(script|style|noscript|svg|select|option)\b.*?</\1>', ' ', body, flags=re.S | re.I)
    body = re.sub(r'<[^>]+>', ' ', body)
    text = html.unescape(body).lower().replace('’', "'")

    return Counter(w for w in (t.strip(".,'-") for t in TOKEN.findall(text)) if w)


def difference(live_markup, new_markup):
    """(words only on the live side, words only on the new side), each a Counter."""
    a, b = words(live_markup), words(new_markup)

    return a - b, b - a


def describe(counter, limit=40):
    return ' '.join(f'{w}x{n}' if n > 1 else w for w, n in counter.most_common(limit))


def main():
    import requests

    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('live')
    ap.add_argument('new')
    ap.add_argument('paths', nargs='*')
    ap.add_argument('--paths-file', default='', help='a file with one path per line')
    ap.add_argument('--delay', type=float, default=1.0)
    a = ap.parse_args()
    paths = list(a.paths)
    if a.paths_file:
        paths += [line.strip() for line in open(a.paths_file, encoding='utf-8') if line.strip().startswith('/')]
    if not paths:
        ap.error('give at least one path')

    differing = 0
    for path in paths:
        try:
            live = requests.get(a.live.rstrip('/') + path, headers=UA, timeout=45).text
            time.sleep(a.delay)
            new = requests.get(a.new.rstrip('/') + path, headers=UA, timeout=45).text
        except requests.RequestException as error:
            print(f'{path}: request failed: {type(error).__name__}')
            differing += 1
            continue
        only_live, only_new = difference(live, new)
        print(f'{path}: live-only {sum(only_live.values())} words, new-only {sum(only_new.values())} words')
        if only_live:
            print('   missing:', describe(only_live))
        if only_new:
            print('   added:  ', describe(only_new))
        differing += 1 if only_live or only_new else 0
        time.sleep(a.delay)
    print(f'{differing} page(s) differ out of {len(paths)}')

    return 1 if differing else 0


if __name__ == '__main__':
    sys.exit(main())
