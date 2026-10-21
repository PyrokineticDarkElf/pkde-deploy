#!/usr/bin/env python3
"""Fold a stylesheet's repeated selectors together without changing what it does .

A stylesheet written in passes repeats selectors: `.card { padding: 1rem }` early, `.card { padding: 0 }` later. Two safe steps:

1. A declaration that a later rule with the *identical* selector, in the same @media/@supports block, declares again (same
   property, not weaker !important) can never apply: the later one always wins. It is removed.
2. What is left of an earlier rule moves into the last rule with that selector when no rule in between (anywhere in the file)
   declares a property of the same family (font/line-height, border-*, padding-*, gap/row-gap, inset/top ...). Moving it later
   can then change nothing. Earlier rules left empty disappear; their comments move with their declarations.

Everything is written back one declaration per line. Verify with the layout fingerprint before and after.

  python3 bin/deploy/native/tidy_css.py web/app/themes/sage/resources/css/site.css [--check]
"""
import re, sys
from pathlib import Path

FAMILY_ALIAS = {'line-height': 'font', 'row-gap': 'gap', 'column-gap': 'gap', 'top': 'inset', 'right': 'inset', 'bottom': 'inset',
                'left': 'inset', 'place-items': 'align', 'place-content': 'align', 'place-self': 'align', 'justify-items': 'align',
                'justify-content': 'align', 'justify-self': 'align', 'columns': 'column', 'translate': 'transform', 'scale': 'transform',
                'rotate': 'transform'}


def family(prop):
    if prop.startswith('--'):
        return prop
    if prop in FAMILY_ALIAS:
        return FAMILY_ALIAS[prop]
    bare = prop.lstrip('-').split('-', 1)[-1] if prop.startswith('-') else prop  # -webkit-line-clamp -> line-clamp
    return FAMILY_ALIAS.get(bare, bare.split('-')[0])


def split_top(text, sep):
    """Split on sep outside quotes and brackets."""
    out, cur, depth, quote = [], '', 0, None
    for ch in text:
        if quote:
            quote = None if ch == quote else quote
        elif ch in '"\'':
            quote = ch
        elif ch in '([':
            depth += 1
        elif ch in ')]':
            depth -= 1
        elif ch == sep and depth == 0:
            out.append(cur)
            cur = ''
            continue
        cur += ch
    out.append(cur)
    return out


class Rule:
    def __init__(self, selector, body, comments):
        self.selector = ' '.join(selector.split())
        self.key = ', '.join(s.strip() for s in split_top(self.selector, ','))
        self.comments = comments
        self.decls = []
        for d in split_top(body, ';'):
            if d.strip():
                prop, _, value = d.partition(':')
                value = ' '.join(value.split())
                self.decls.append([prop.strip(), value.replace(' !important', '').strip(), value.endswith('!important')])


class Block:
    def __init__(self, prelude, children, comments, raw=None):
        self.prelude, self.children, self.comments, self.raw = ' '.join(prelude.split()), children, comments, raw


def parse(css):
    nodes, i, n, comments = [], 0, len(css), []
    while i < n:
        if css.startswith('/*', i):
            j = css.index('*/', i) + 2
            comments.append(css[i:j])
            i = j
            continue
        if css[i].isspace():
            i += 1
            continue
        brace, semi = css.find('{', i), css.find(';', i)
        if semi != -1 and (brace == -1 or semi < brace):
            nodes.append(Block(css[i:semi + 1], None, comments, raw=css[i:semi + 1]))
            comments, i = [], semi + 1
            continue
        depth, j = 1, brace + 1
        while depth:
            if css.startswith('/*', j):
                j = css.index('*/', j) + 2
                continue
            depth += css[j] == '{'
            depth -= css[j] == '}'
            j += 1
        prelude, body = css[i:brace].strip(), css[brace + 1:j - 1]
        if prelude.startswith(('@media', '@supports', '@layer', '@container')):
            nodes.append(Block(prelude, parse(body), comments))
        elif prelude.startswith('@'):
            nodes.append(Block(prelude, None, comments, raw=css[i:j]))
        else:
            nodes.append(Rule(prelude, body, comments))
        comments, i = [], j
    if comments:
        nodes.append(Block('', None, comments, raw=''))
    return nodes


def flat_rules(nodes):
    for node in nodes:
        if isinstance(node, Rule):
            yield node
        elif node.children is not None:
            yield from flat_rules(node.children)


def tidy(nodes, order):
    """Steps 1 and 2 within one list of sibling nodes; `order` is every rule in the file, in source order."""
    rules = [x for x in nodes if isinstance(x, Rule)]
    for node in nodes:
        if isinstance(node, Block) and node.children is not None:
            tidy(node.children, order)
    groups = {}
    for r in rules:
        groups.setdefault(r.key, []).append(r)
    for same in groups.values():
        # duplicates inside one rule: the last wins
        for r in same:
            seen, keep = {}, []
            for d in reversed(r.decls):
                if d[0] in seen and not (d[2] and not seen[d[0]]):
                    continue
                seen[d[0]] = d[2]
                keep.append(d)
            r.decls = list(reversed(keep))
        if len(same) < 2:
            continue
        # step 1: overridden by a later rule with the same selector
        later = {}
        for r in reversed(same):
            r.decls = [d for d in r.decls if not (d[0] in later and (later[d[0]] or not d[2]))]
            for d in r.decls:
                later[d[0]] = later.get(d[0], False) or d[2]
        # step 2: move into the last rule when nothing in between touches the same family
        # (nearest first, so declarations keep their order; a rule already moved has no declarations left in between)
        last = same[-1]
        for r in reversed(same[:-1]):
            between = order[order.index(r) + 1:order.index(last)]
            touched = {family(d[0]) for b in between for d in b.decls}
            movable = [d for d in r.decls if family(d[0]) not in touched]
            if len(movable) == len(r.decls):
                last.decls = r.decls + last.decls
                last.comments = r.comments + last.comments
                r.decls, r.comments = [], []
    nodes[:] = [x for x in nodes if not (isinstance(x, Rule) and not x.decls)]


def write(nodes, indent=''):
    out = []
    for node in nodes:
        for c in node.comments:
            out.append(indent + c.replace('\n', '\n' + indent) if '\n' not in c else c)
        if isinstance(node, Rule):
            body = ''.join(f'{indent}  {p}: {v}{" !important" if imp else ""};\n' for p, v, imp in node.decls)
            out.append(f'{indent}{node.selector} {{\n{body}{indent}}}')
        elif node.children is not None:
            out.append(f'{indent}{node.prelude} {{\n' + write(node.children, indent + '  ') + f'\n{indent}}}')
        elif node.raw:
            out.append(indent + node.raw)
    return '\n'.join(out)


if __name__ == '__main__':
    path = Path(sys.argv[1])
    css = path.read_text()
    tree = parse(css)
    before = sum(len(r.decls) for r in flat_rules(tree)), len(list(flat_rules(tree)))
    tidy(tree, list(flat_rules(tree)))
    after = sum(len(r.decls) for r in flat_rules(tree)), len(list(flat_rules(tree)))
    print(f'{path.name}: {before[1]} rules / {before[0]} declarations -> {after[1]} rules / {after[0]} declarations')
    if '--check' not in sys.argv:
        path.write_text(write(tree) + '\n')
