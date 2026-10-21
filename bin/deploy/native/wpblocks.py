"""Helpers that return block markup exactly as the block editor saves it, so the editor's validator accepts what the page generators
and converters write. Only native block settings are used (layout, gap, padding, border, background colour, alignment); the one custom
block is the Section. Spacing slugs are the theme.json presets (docs/native-blocks.md); colours are palette slugs (theme.json
settings.color.palette, written by bin/generate-theme-tokens.mjs). Names given to blocks (metadata name) show in the List View and are
editable there with the block's Rename option."""
import json

# Design constants a site may change before it generates pages (a card is a white Stack with a thin border and round corners).
CARD_BORDER = {'radius': '24px', 'width': '1px', 'color': '#0000001a'}
BADGE_BG = 'brand-50'     # palette slug behind a line icon
PANEL_BG = 'brand-50'     # palette slug of a coloured panel


def meta(name=None, hide=None):
    """Block metadata: the List View name and (WordPress block visibility) the viewports it is hidden on: ('tablet',) ..."""
    m = {}
    if name:
        m['name'] = name
    if hide:
        m['blockVisibility'] = {'viewport': {v: False for v in hide}}
    return m or None


def attrs(**kw):
    """The JSON of a block comment, escaped the way WordPress writes it (so text can hold <, >, & and -- safely)."""
    kw = {k: v for k, v in kw.items() if v is not None}
    if not kw:
        return ''
    text = json.dumps(kw, separators=(',', ':'), ensure_ascii=False)
    for raw, safe in (('--', '\\u002d\\u002d'), ('<', '\\u003c'), ('>', '\\u003e'), ('&', '\\u0026'), ('\\"', '\\u0022')):
        text = text.replace(raw, safe)
    return ' ' + text


def block(name, inner, **kw):
    return f'<!-- wp:{name}{attrs(**kw)} -->\n{inner}\n<!-- /wp:{name} -->'


def sp(slug):
    return f'var:preset|spacing|{slug}'


def css_value(value):
    return f'var(--wp--preset--spacing--{value.split("|")[-1]})' if value.startswith('var:preset|spacing|') else value


def pad(value):
    """Equal padding on all four sides: a preset slug ('card') or a CSS length ('0.75rem')."""
    v = value if value.endswith(('rem', 'px', 'em', '%')) else sp(value)
    return {'top': v, 'right': v, 'bottom': v, 'left': v}


def gap(slug):
    return {'spacing': {'blockGap': sp(slug)}}


def join(blocks):
    return '\n\n'.join(blocks)


def wrapper_html(base, style=None, bg=None, extra_classes=(), anchor=None):
    """The classes and inline style the editor saves for a block's border, background and padding settings, in its own order."""
    style = style or {}
    border, colour, padding = style.get('border', {}), style.get('color', {}), style.get('spacing', {}).get('padding')
    classes = [base, *extra_classes]
    if border.get('color'):
        classes.append('has-border-color')
    if bg:
        classes += [f'has-{bg}-background-color', 'has-background']
    elif colour.get('background'):
        classes.append('has-background')
    inline = []
    for key, prop in (('color', 'border-color'), ('width', 'border-width'), ('radius', 'border-radius')):
        if border.get(key):
            inline.append(f'{prop}:{border[key]}')
    if colour.get('background'):
        inline.append(f'background-color:{colour["background"]}')
    if padding:
        inline += [f'padding-{side}:{css_value(padding[side])}' for side in ('top', 'right', 'bottom', 'left') if side in padding]
    out = f'class="{" ".join(classes)}"'
    if anchor:
        out += f' id="{anchor}"'
    if inline:
        out += f' style="{";".join(inline)}"'
    return out


def group(children, style=None, layout=None, bg=None, anchor=None, name=None, hide=None, **extra):
    """A core Group. layout: {'type': 'flex', 'orientation': 'vertical'} is the editor's Stack, 'grid' its Grid."""
    return block('group', f'<div {wrapper_html("wp-block-group", style, bg, anchor=anchor)}>{join(children)}</div>',
                 anchor=anchor, style=style, backgroundColor=bg, layout=layout, metadata=meta(name, hide), **extra)


def section(children, name=None, anchor=None, hide=None, bg=None):
    """The Section block: a full-width band; its padding and gap are the Section's styles in theme.json, background a palette colour."""
    return block('sage/section', f'<div {wrapper_html("wp-block-sage-section", None, bg, ("alignfull",), anchor)}>{join(children)}</div>',
                 anchor=anchor, backgroundColor=bg, metadata=meta(name, hide))


def stack(children, gap_slug='m', name=None, justify='stretch'):
    """A vertical Stack (Group, flex, vertical) with a gap; its blocks stretch to its width unless justify says otherwise."""
    layout = {'type': 'flex', 'orientation': 'vertical'}
    if justify:
        layout['justifyContent'] = justify
    return group(children, style=gap(gap_slug), layout=layout, name=name)


def steps(count):
    """A Grid's columns on tablet and mobile (WordPress responsive editing: Preview > Tablet or Mobile, then the Grid's Columns), so a
    grid never passes through an uneven count on the way down: 4 > 2 > 1, 6 > 3 > 1, 3 > 3 > 1, 2 > 2 > 1."""
    tablet = count // 2 if count in (4, 6) else count
    out = {'@tablet': {'layout': {'columnCount': tablet}}} if tablet != count else {}
    return {**out, '@mobile': {'layout': {'columnCount': 1}}}


def grid(count, children, name=None):
    """A Grid group with a fixed number of Columns per screen size (see steps)."""
    return group(children, style={**gap('gap'), **steps(count)}, layout={'type': 'grid', 'columnCount': count}, name=name)


def card(children):
    """A card: a vertical Stack with a white background, a thin border, rounded corners and padding (all native settings) that
    lifts on hover (its Animation setting)."""
    style = {'color': {'background': '#ffffff'}, 'border': dict(CARD_BORDER),
             'spacing': {'padding': pad('card'), 'blockGap': sp('s')}}
    return group(children, style=style, layout={'type': 'flex', 'orientation': 'vertical', 'justifyContent': 'left'}, name='Card',
                 animationHover='lift')


def panel(children, bg=None, radius='28px', padding='l', name=None, centre=False):
    """A coloured panel: a vertical Stack with a background colour, rounded corners and padding."""
    style = {'border': {'radius': radius}, 'spacing': {'padding': pad(padding), 'blockGap': sp('m')}}
    layout = {'type': 'flex', 'orientation': 'vertical', 'justifyContent': 'center' if centre else 'left'}
    if centre:
        layout['verticalAlignment'] = 'center'
    return group(children, style=style, bg=bg or PANEL_BG, layout=layout, name=name)


def icon(image_):
    """A line icon in a round tinted badge: a Group (BADGE_BG background, full radius, padding) holding a 24px image."""
    ident, url = image_
    img = block('image', f'<figure class="wp-block-image size-full is-resized"><img src="{url}" alt="" class="wp-image-{ident}" style="width:24px;height:auto"/></figure>',
                id=ident, width='24px', sizeSlug='full', linkDestination='none')
    style = {'border': {'radius': '999px'}, 'spacing': {'padding': pad('0.75rem')}}
    return group([img], style=style, bg=BADGE_BG, layout={'type': 'flex', 'justifyContent': 'center'}, name='Icon')


def heading(level, text):
    return block('heading', f'<h{level} class="wp-block-heading">{text}</h{level}>', **({'level': level} if level != 2 else {}))


def paragraph(text):
    # the editor saves a line break as <br>, not <br />
    return block('paragraph', f'<p>{text.replace("<br />", "<br>")}</p>')


def separator():
    return block('separator', '<hr class="wp-block-separator has-alpha-channel-opacity"/>')


def details(summary, paragraphs):
    return block('details', f'<details class="wp-block-details"><summary>{summary}</summary>{join(paragraphs)}</details>')


def button(url, text):
    inner = block('button', f'<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="{url}">{text}</a></div>')
    return block('buttons', f'<div class="wp-block-buttons">{inner}</div>')


def image(url, title='', alt='', width=None, align=None):
    title_attr = f' title="{title}"' if title else ''
    classes = ' '.join(x for x in ['wp-block-image', f'align{align}' if align else '', 'is-resized' if width else ''] if x)
    style = f' style="width:{width};height:auto"' if width else ''
    return block('image', f'<figure class="{classes}"><img src="{url}" alt="{alt}"{style}{title_attr}/></figure>', width=width, align=align)


def columns(children, widths=None, align='center', name=None, anchor=None):
    """Core Columns: per-column width, vertical alignment and the gap between columns, all native; they stack on small screens."""
    widths = widths or [None] * len(children)
    inner = []
    for width, kids in zip(widths, children):
        flex = f' style="flex-basis:{width}"' if width else ''
        valign = f' is-vertically-aligned-{align}' if align else ''
        inner.append(block('column', f'<div class="wp-block-column{valign}"{flex}>{join(kids)}</div>', verticalAlignment=align, width=width))
    valign = f' are-vertically-aligned-{align}' if align else ''
    id_attr = f' id="{anchor}"' if anchor else ''
    return block('columns', f'<div class="wp-block-columns{valign}"{id_attr}>{join(inner)}</div>', verticalAlignment=align,
                 anchor=anchor, style={'spacing': {'blockGap': {'top': sp('gap'), 'left': sp('gap')}}}, metadata=meta(name))
