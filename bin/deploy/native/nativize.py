#!/usr/bin/env python3
"""Rewrite a class-driven page structure (a Divi conversion, say) into native blocks.

Pages converted from Divi are core blocks inside Group wrappers that carry the site's own classes (PREFIX-section, PREFIX-row,
PREFIX-col, PREFIX-text, PREFIX-blurb ...), which only CSS gives meaning. This script turns that structure into the Section block
holding core blocks with native settings:

  PREFIX-section            -> Section (named after its old label, hide-on-device classes -> block visibility)
  PREFIX-row, one column    -> the column's blocks directly
  PREFIX-row, equal columns -> Grid group (Columns per screen size: wpblocks.steps)
  PREFIX-row, uneven        -> Columns with per-column widths (vertically centred in a page header)
  PREFIX-col                -> a Stack when it holds more than one block
  PREFIX-text, -button, -toggle, -contact-form -> removed (their blocks stand on their own); a small label heading followed by a
                            heading becomes a tight Stack
  PREFIX-blurb, PREFIX-promo__card -> card (Stack with white background, border, radius, padding)
  image.PREFIX-icon         -> round icon badge; image.PREFIX-hero-photo -> 4:3 cover image with rounded corners
  any other PREFIX-* class (and the --divi-classes) is dropped from the blocks that keep their markup

Every text, link and image is carried over verbatim. Read-only on the database unless --apply is given; with --apply the old content
is first saved as a WordPress revision of the post, and nothing is written when the visible text differs or prefixed classes remain.

  python3 bin/deploy/native/nativize.py --prefix acme 20 21 3939      # print a text check per post
  python3 bin/deploy/native/nativize.py --prefix acme --apply 20 21   # write the new content (after a revision of the old)
  python3 bin/deploy/native/nativize.py --prefix acme --apply --from-revision 21   # redo a page from its last saved version that still had the old structure

Site constants are arguments, not code: --prefix (the class prefix without the dash), --table-prefix (default: DB_PREFIX from .env,
else wp_), --divi-classes a,b,c (Divi's own custom classes to drop), --section-names header=Header,faq=FAQ (a section's label ->
its List View name; any other label is capitalised). Blog posts (post_type post) get no Section bands: the article column is already
the measure, so a section's blocks stand on their own.
"""
import html, json, re, sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from db import option, save, source, sql, table_prefix  # noqa: E402,F401
from wpblocks import *  # noqa: E402,F401,F403

P = option('prefix', 'site') + '-'  # the site's class prefix, with its dash
HERO_RADIUS = '32px'

DIVI_CUSTOM = set(filter(None, option('divi-classes', '').split(',')))
SECTION_NAMES = dict(pair.split('=', 1) for pair in filter(None, option('section-names', '').split(',')))
WIDTHS = {'1_2': '50%', '1_3': '33.33%', '2_3': '66.66%', '1_4': '25%', '3_4': '75%', '1_5': '20%', '2_5': '40%', '3_5': '60%', '4_4': '100%'}
OPEN = re.compile(r'<!-- (/?)wp:([a-z0-9/-]+)(?: (\{.*?\}))? (/?)-->', re.S)


class Node:
    def __init__(self, name, attrs, start):
        self.name, self.attrs, self.start, self.children, self.end = name, attrs, start, [], None

    def classes(self):
        return (self.attrs.get('className') or '').split()

    def has(self, cls):
        return any(c == cls or c.startswith(cls + '--') for c in self.classes())


def parse(markup):
    root, stack = Node('root', {}, 0), []
    stack.append(root)
    for m in OPEN.finditer(markup):
        closing, name, raw_attrs, selfclose = m.groups()
        name = name if '/' in name else 'core/' + name
        if closing:
            node = stack.pop()
            node.end = m.end()
            continue
        node = Node(name, json.loads(raw_attrs) if raw_attrs else {}, m.start())
        stack[-1].children.append(node)
        if selfclose:
            node.end = m.end()
        else:
            stack.append(node)
    return root


def clean(raw):
    """A block's own markup with the site's prefixed and Divi custom classes removed from its comments and HTML."""
    def comment(m):
        closing, name, raw_attrs, selfclose = m.groups()
        if closing or not raw_attrs:
            return m.group(0)
        data = json.loads(raw_attrs)
        if 'className' in data:
            kept = [c for c in data['className'].split() if not c.startswith(P) and c not in DIVI_CUSTOM]
            if kept:
                data['className'] = ' '.join(kept)
            else:
                del data['className']
        return f'<!-- wp:{name}{attrs(**data)} {selfclose}-->'.replace(' -->', ' -->').replace('  /-->', ' /-->')

    raw = OPEN.sub(comment, raw)
    raw = re.sub(r'class="([^"]*)"', lambda m: 'class="' + ' '.join(c for c in m.group(1).split() if not c.startswith(P) and c not in DIVI_CUSTOM) + '"', raw)
    return raw.replace(' class=""', '')


def leaf(markup, node):
    return clean(markup[node.start:node.end])


def hidden(node):
    hide = [v for v, cls in (('mobile', P + 'hide-phone'), ('tablet', P + 'hide-tablet'), ('desktop', P + 'hide-desktop')) if cls in node.classes()]
    return tuple(hide) or None


def img_parts(markup, node):
    raw = markup[node.start:node.end]
    src = re.search(r'src="([^"]*)"', raw).group(1)
    alt = (re.search(r'alt="([^"]*)"', raw) or [None, ''])[1]
    title = (re.search(r'title="([^"]*)"', raw) or [None, ''])[1]
    link = re.search(r'<a href="([^"]*)"', raw)
    return node.attrs.get('id'), src, alt, title, link.group(1) if link else None


def hero_photo(markup, node):
    ident, src, alt, title, link = img_parts(markup, node)
    data = {'id': ident, 'aspectRatio': '4/3', 'scale': 'cover', 'sizeSlug': node.attrs.get('sizeSlug'), 'linkDestination': node.attrs.get('linkDestination'),
            'style': {'border': {'radius': HERO_RADIUS}}}
    cls = ' '.join(x for x in ['wp-block-image', f'size-{data["sizeSlug"]}' if data['sizeSlug'] else '', 'has-custom-border'] if x)
    img = f'<img src="{src}" alt="{alt}"' + (f' class="wp-image-{ident}"' if ident else '') + f' style="border-radius:{HERO_RADIUS};aspect-ratio:4/3;object-fit:cover"' + (f' title="{title}"' if title else '') + '/>'
    if link:
        img = f'<a href="{link}">{img}</a>'
    return block('image', f'<figure class="{cls}">{img}</figure>', **data)


CARD_HEADING = {'typography': {'fontSize': '0.72rem', 'fontStyle': 'normal', 'fontWeight': '400', 'letterSpacing': '0.18em'}}


def card_heading(markup, node):
    raw = markup[node.start:node.end]
    level = node.attrs.get('level', 2)
    text = re.search(rf'<h{level}[^>]*>(.*?)</h{level}>', raw, re.S).group(1)
    data = {'level': level if level != 2 else None, 'style': CARD_HEADING}
    return block('heading', f'<h{level} class="wp-block-heading" style="font-size:0.72rem;font-style:normal;font-weight:400;letter-spacing:0.18em">{text}</h{level}>', **data)


FLAT_SECTIONS = False  # blog posts: no bands inside the article column, the section's blocks stand on their own


def blocks_of(markup, node, in_header=False, in_card=False):
    """The converted blocks for a node, as a list (wrappers that only carried a class disappear)."""
    n = node
    if n.name == 'core/group':
        if n.has(P + 'section'):
            label = next((c.split('--', 1)[1] for c in n.classes() if c.startswith(P + 'section--')), None)
            kids = [b for c in n.children for b in blocks_of(markup, c, in_header=label == 'header')]
            if FLAT_SECTIONS:
                return kids
            kids = stack_text_runs(kids)
            return [section(kids, SECTION_NAMES.get(label, (label or 'Section').replace('-', ' ').capitalize()), anchor=n.attrs.get('anchor'), hide=hidden(n))]
        if n.has(P + 'promo') and not n.has(P + 'promo__card'):
            cards = [card(inner(markup, c, in_card=True)) for c in n.children]
            return [grid(len(cards), cards)]
        if n.has(P + 'row'):
            cols = [c for c in n.children if c.name == 'core/group' and c.has(P + 'col')]
            if len(cols) <= 1:
                kids = [b for c in n.children for b in (inner(markup, c, in_header) if c in cols else blocks_of(markup, c, in_header))]
                if hidden(n) or n.attrs.get('anchor'):
                    return [group(kids, layout={'type': 'flex', 'orientation': 'vertical', 'justifyContent': 'stretch'}, anchor=n.attrs.get('anchor'), hide=hidden(n))]
                return kids
            types = [next((c.split('--', 1)[1] for c in col.classes() if c.startswith(P + 'col--')), '4_4') for col in cols]
            cells = [cell(inner(markup, col, in_header)) for col in cols]
            if len(set(types)) == 1:
                out = grid(len(cols), cells)
            else:
                out = columns([[c] for c in cells], widths=[WIDTHS.get(t) for t in types], align='center' if in_header else None)
            if hidden(n) or n.attrs.get('anchor'):
                out = group([out], layout={'type': 'flex', 'orientation': 'vertical', 'justifyContent': 'stretch'}, anchor=n.attrs.get('anchor'), hide=hidden(n))
            return [out]
        if n.has(P + 'col'):
            return [cell(inner(markup, n, in_header))]
        if n.has(P + 'blurb') and not n.has(P + 'blurb__text') and not n.has(P + 'blurb__title') or n.has(P + 'promo__card'):
            return [card(inner(markup, n, in_card=True))]
        if any(n.has(c) for c in (P + 'text', P + 'button', P + 'toggle', P + 'contact-form', P + 'blurb__text')):
            return inner(markup, n, in_header, in_card)
        # any other group keeps its blocks, without a class
        return [group(inner(markup, n, in_header, in_card), layout={'type': 'flex', 'orientation': 'vertical', 'justifyContent': 'stretch'})]
    if n.name == 'core/image':
        if P + 'icon' in n.classes():
            ident, src, *_ = img_parts(markup, n)
            return [icon((ident, src))]
        if P + 'hero-photo' in n.classes():
            return [hero_photo(markup, n)]
    if n.name == 'core/heading' and in_card and n.attrs.get('level') == 4:
        return [card_heading(markup, n)]
    return [leaf(markup, n)]


def inner(markup, node, in_header=False, in_card=False):
    out = [b for c in node.children for b in blocks_of(markup, c, in_header, in_card)]
    # a small label heading (h4) directly followed by a heading: one tight Stack, as on the native pages
    merged, i = [], 0
    while i < len(out):
        a = out[i]
        b = out[i + 1] if i + 1 < len(out) else ''
        if not in_card and a.startswith('<!-- wp:heading {"level":4}') and b.startswith('<!-- wp:heading'):
            merged.append(stack([a, b], 'xs'))
            i += 2
            continue
        merged.append(a)
        i += 1
    return merged


TEXT_BLOCKS = ('<!-- wp:separator', '<!-- wp:heading', '<!-- wp:paragraph', '<!-- wp:buttons')


def stack_text_runs(kids):
    """In a Section (a constrained layout) every narrow block is centred on its own; a run of text blocks that holds a separator
    goes into one Stack so the separator lines up with the text."""
    out, run = [], []
    def flush():
        if any(k.startswith('<!-- wp:separator') for k in run):
            out.append(stack(run, 'm'))
        else:
            out.extend(run)
        run.clear()
    for k in kids:
        if k.startswith(TEXT_BLOCKS) or (k.startswith('<!-- wp:group') and '"orientation":"vertical"' in k[:200] and k.count('<!-- wp:heading') == 2 and k.count('<!-- wp:') <= 3):
            run.append(k)
        else:
            flush()
            out.append(k)
    flush()
    return out


def cell(kids):
    if len(kids) == 1:
        return kids[0]
    gap_slug = 's' if all(k.startswith('<!-- wp:details') for k in kids) else 'm'
    return stack(kids, gap_slug)


def convert(markup):
    root = parse(markup)
    return join([b for c in root.children for b in blocks_of(markup, c)]) + '\n'


def visible_text(markup):
    t = re.sub(r'<!--.*?-->', ' ', markup, flags=re.S)
    t = re.sub(r'<[^>]+>', ' ', t)
    return re.sub(r'\s+', ' ', html.unescape(t)).strip()


if __name__ == '__main__':
    apply = '--apply' in sys.argv
    for post_id in [a for a in sys.argv[1:] if a.isdigit()]:
        FLAT_SECTIONS = sql(f'SELECT post_type FROM {table_prefix()}posts WHERE ID={int(post_id)}') == 'post'
        old = source(int(post_id))
        if '--from-revision' in sys.argv:  # convert the content saved before the last --apply, not the current one
            rev = sql(f"SELECT ID FROM {table_prefix()}posts WHERE post_parent={int(post_id)} AND post_type='revision' AND post_content LIKE '%{P}section%' ORDER BY ID DESC LIMIT 1")
            old = source(int(rev))
        new = convert(old)
        same = visible_text(old) == visible_text(new)
        left = sorted(set(re.findall(r'\b' + re.escape(P) + r'(?!icon-)[a-z0-9_-]+', new)))
        print(f'{post_id}: text {"identical" if same else "DIFFERS"}, prefixed classes left {left or "none"}, {len(old)} -> {len(new)} bytes')
        if not same:
            a, b = visible_text(old), visible_text(new)
            i = next((k for k in range(min(len(a), len(b))) if a[k] != b[k]), min(len(a), len(b)))
            print('   old:', a[max(0, i - 60):i + 80]); print('   new:', b[max(0, i - 60):i + 80])
        elif apply and not left:
            save(post_id, new)
            print('   written (old content kept as a revision)')
