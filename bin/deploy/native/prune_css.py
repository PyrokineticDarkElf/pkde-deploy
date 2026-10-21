#!/usr/bin/env python3
"""Remove CSS rules for theme classes that nothing uses any more.

A class counts as used when it appears in saved content (every non-revision post, widgets, theme mods, menu item classes) or in the
theme's templates, PHP, blocks, scripts or the mu-plugins. The classes checked are the ones whose prefix is given (the site's own, and
the template's pkde-) plus the theme's block classes (wp-block-sage-*, gone when a block is deleted). A selector that names an unused
one can never match, so it is dropped; a rule with no selector left is removed with the comment directly above it. @media/@supports
blocks left empty go too.

  python3 bin/deploy/native/prune_css.py --prefix acme [--prefix ...] [--table-prefix wp_]            # report what would go
  python3 bin/deploy/native/prune_css.py --prefix acme [--table-prefix wp_] --apply                   # rewrite resources/css/*.css

--prefix is repeated for each class prefix (without the dash); pkde is always included. Run the layout fingerprint before and after.
"""
import re, sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from db import ROOT, THEME, mysql, table_prefix  # noqa: E402
FILES = [p for p in (THEME / 'resources/css').glob('*.css')]
PREFIXES = ['pkde'] + [sys.argv[i + 1] for i, a in enumerate(sys.argv) if a == '--prefix']
CLASS = re.compile(r'(?:' + '|'.join(re.escape(x) for x in PREFIXES + ['wp-block-sage']) + r')-[a-z0-9_]+(?:-[a-z0-9_]+)*(?:--[a-z0-9_-]+)?')


def used_classes():
    t = table_prefix()
    sql = (f'select post_content from {t}posts where post_type<>"revision" and post_status in ("publish","draft","private","future","pending"); '
           f'select option_value from {t}options where option_name like "widget_%" or option_name like "theme_mods_%"; '
           f'select meta_value from {t}postmeta where meta_key="_menu_item_classes"')
    content = mysql(sql)
    code = ''.join(p.read_text(errors='ignore') for d in ('resources/views', 'app', 'resources/blocks', 'resources/js') for p in (THEME / d).rglob('*') if p.is_file())
    code += ''.join(p.read_text(errors='ignore') for p in (ROOT / 'web/app/mu-plugins').rglob('*.php'))
    blocks = {f'wp-block-sage-{d.name}' for d in (THEME / 'resources/blocks').iterdir() if (d / 'block.json').is_file()}  # added at render time
    return set(CLASS.findall(content)) | set(CLASS.findall(code)) | blocks


def split_selectors(prelude):
    out, depth, cur = [], 0, ''
    for ch in prelude:
        depth += ch in '(['
        depth -= ch in ')]'
        if ch == ',' and depth == 0:
            out.append(cur)
            cur = ''
        else:
            cur += ch
    out.append(cur)
    return out


def live(selector, used):
    """A selector can match when every theme class it requires is in use. Inside :is()/:where()/:has() one live alternative is
    enough; classes inside :not() never make a selector dead."""
    def reduce(s):
        while True:
            m = re.search(r':(is|where|has|not)\(([^()]*)\)', s)
            if not m:
                return s
            kind, inner = m.groups()
            ok = kind == 'not' or any(live(alt, used) for alt in split_selectors(inner))
            s = s[:m.start()] + ('' if ok else '.pkde-never-used') + s[m.end():]  # a class nothing uses (pkde- is always a checked prefix)
    s = reduce(selector)
    return all(c in used for c in re.findall(r'\.(' + CLASS.pattern + r')', s))


def prune(css, used, report):
    """Walk one level of CSS; return the pruned text."""
    out, i, n = [], 0, len(css)
    pending_comment = ''
    while i < n:
        if css.startswith('/*', i):
            j = css.index('*/', i) + 2
            pending_comment += css[i:j]
            i = j
            continue
        if css[i].isspace():
            k = i
            while k < n and css[k].isspace():
                k += 1
            ws = css[i:k]
            if pending_comment:
                pending_comment += ws
            else:
                out.append(ws)
            i = k
            continue
        brace = css.find('{', i)
        semi = css.find(';', i)
        if semi != -1 and (brace == -1 or semi < brace):  # an at-rule without a block (@import ...)
            out.append(pending_comment + css[i:semi + 1])
            pending_comment = ''
            i = semi + 1
            continue
        prelude = css[i:brace]
        depth, j = 1, brace + 1
        while depth:
            if css.startswith('/*', j):
                j = css.index('*/', j) + 2
                continue
            depth += css[j] == '{'
            depth -= css[j] == '}'
            j += 1
        body = css[brace + 1:j - 1]
        if prelude.strip().startswith(('@media', '@supports', '@container', '@layer')):
            inner = prune(body, used, report)
            if inner.strip():
                out.append(pending_comment + prelude + '{' + inner + '}')
        elif prelude.strip().startswith('@'):
            out.append(pending_comment + css[i:j])
        else:
            selectors = split_selectors(prelude)
            kept = [s for s in selectors if live(s, used)]
            if not kept:
                report.append(prelude.strip()[:120])
            else:
                if len(kept) < len(selectors):
                    report.append('(part) ' + ', '.join(s.strip() for s in selectors if s not in kept)[:120])
                    prelude = ','.join(kept)
                    if not prelude.endswith(' '):
                        prelude += ' '
                out.append(pending_comment + prelude + '{' + body + '}')
        pending_comment = ''
        i = j
    if pending_comment:
        out.append(pending_comment)
    return ''.join(out)


if __name__ == '__main__':
    used = used_classes()
    for path in FILES:
        report = []
        before = path.read_text()
        after = re.sub(r'\n{3,}', '\n\n', prune(before, used, report))
        print(f'{path.relative_to(ROOT)}: {before.count(chr(10))} -> {after.count(chr(10))} lines, {len(report)} rules or selectors removed')
        for r in report[:400]:
            print('   -', r)
        if '--apply' in sys.argv:
            path.write_text(after)
