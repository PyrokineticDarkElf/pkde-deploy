#!/usr/bin/env python3
"""Give every saved Grid group a fixed column count per screen size, so it steps down evenly (4 > 2 > 1) instead of auto-filling by a
minimum column width (4 > 3 > 2 > 1 with orphans). Edits only the block comments: the Minimum column width goes, the tablet and mobile
counts from wpblocks.steps are added, and a grid child spanning columns spans one on mobile. The saved HTML is unchanged, so blocks
stay valid. Idempotent.

  python3 bin/deploy/native/even_grids.py [--table-prefix wp_]            # report which posts would change
  python3 bin/deploy/native/even_grids.py [--table-prefix wp_] --apply    # write them (old content kept as a revision)
"""
import json, re, sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from db import save, source, sql, table_prefix  # noqa: E402
from wpblocks import attrs, steps  # noqa: E402

COMMENT = re.compile(r'<!-- wp:group (\{.*?\}) -->', re.S)


def even(markup):
    def fix(m):
        a = json.loads(m.group(1))
        layout, style = a.get('layout', {}), a.setdefault('style', {})
        if layout.get('type') == 'grid' and layout.pop('minimumColumnWidth', None):
            style.update(steps(layout['columnCount']))
        if style.get('layout', {}).get('columnSpan', 1) > 1:
            style.setdefault('@mobile', {'layout': {'columnSpan': 1}})
        return f'<!-- wp:group{attrs(**a)} -->'
    return COMMENT.sub(fix, markup)


if __name__ == '__main__':
    apply = '--apply' in sys.argv
    ids = sql(f'SELECT ID FROM {table_prefix()}posts WHERE post_type<>"revision" AND post_status IN ("publish","draft","private") '
              'AND (post_content LIKE \'%"minimumColumnWidth"%\' OR post_content LIKE \'%"columnSpan"%\')').split()
    for post_id in ids:
        old = source(int(post_id))
        new = even(old)
        print(f'{post_id}: {"unchanged" if new == old else "updated" if apply else "would change"}')
        if new != old and apply:
            save(post_id, new)
