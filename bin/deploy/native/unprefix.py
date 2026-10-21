#!/usr/bin/env python3
"""Rename a site's prefixed names to plain ones, so its templates, styles and scripts carry over to other sites.

The renames come from a JSON map the site writes once, not from this script:

  {
    "names":   [["acme-card", "product-card"], ["--acme-ink", "--wp--preset--color--ink"], ["acme-wrap", "site-wrap"]],
    "dead_selectors": ":not\\\\(\\\\.old-divi-class\\\\)|\\\\.acme-toggle:not\\\\(\\\\.acme-toggle--term\\\\)"
  }

`names` are applied longest first, so list the longer name before a name it contains; a name only matches when no letter or digit
follows (unless it ends in - or _, which makes it a prefix: ["acme-loop-", "product-loop-"]). `dead_selectors` (optional) is a regular
expression; a CSS rule whose selectors ALL match it can never match an element any more and is removed. Colour and size variables
become the theme.json presets they equal (--wp--preset--color--ink, --wp--preset--spacing--gutter, --wp--custom--radius).

Dry run by default: prints what would change. Files: the theme's app, resources/views, js, blocks, css, woocommerce (never generated/).

  python3 bin/deploy/native/unprefix.py rename-map.json            # report
  python3 bin/deploy/native/unprefix.py rename-map.json --apply    # write
"""
import json, re, sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from db import ROOT, THEME  # noqa: E402


def files():
    return [p for d in ('app', 'resources/views', 'resources/js', 'resources/blocks', 'resources/css', 'woocommerce')
            if (THEME / d).is_dir() for p in (THEME / d).rglob('*')
            if p.suffix in ('.php', '.js', '.jsx', '.css', '.json', '.md') and 'generated' not in p.parts]


def rename(text, names):
    for old, new in sorted(names, key=lambda pair: -len(pair[0])):
        text = re.sub(re.escape(old) + ('' if old.endswith(('-', '_')) else r'(?![a-zA-Z0-9])'), new, text)
    return text


def drop_dead_rules(css, dead):
    """Remove whole rules (selector { ... }) whose every selector is dead. Rules here are flat or one level inside @media."""
    pattern = re.compile(dead)
    out, i = [], 0
    for m in re.finditer(r'([^{}]*)\{([^{}]*)\}', css):
        prelude = m.group(1)
        sels = [s for s in prelude.split(',') if s.strip()]
        if sels and all(pattern.search(s) for s in sels) and not prelude.strip().startswith('@'):
            lead = re.match(r'(\s*(?:/\*.*?\*/\s*)*)', prelude, re.S).group(1)  # keep a comment or newline before the selector
            out.append(css[i:m.start()] + lead.rstrip(' '))
            i = m.end()
    out.append(css[i:])
    return ''.join(out)


if __name__ == '__main__':
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    if not args:
        sys.exit(__doc__)
    spec = json.loads(Path(args[0]).read_text())
    names, dead = spec.get('names', []), spec.get('dead_selectors')
    for path in files():
        before = path.read_text()
        after = drop_dead_rules(before, dead) if dead and path.suffix == '.css' else before
        after = rename(after, names)
        if after != before:
            print(f'{path.relative_to(ROOT)}: changed')
            if '--apply' in sys.argv:
                path.write_text(after)
