"""Tests for bin/deploy/native (the page conversion tools). Pure functions only: nothing here needs Docker or a database.
Run: python3 -m unittest discover -s tests/native -v"""
import re, sys, unittest
from pathlib import Path

NATIVE = Path(__file__).resolve().parents[2] / 'bin/deploy/native'
sys.argv = [sys.argv[0], '--prefix', 'zz']  # the class prefix nativize reads at import
sys.path.insert(0, str(NATIVE))

import even_grids, nativize, prune_css, tidy_css, unprefix, wpblocks  # noqa: E402


def text(markup):
    return nativize.visible_text(markup)


class WpBlocks(unittest.TestCase):
    def test_attrs_escape_the_way_wordpress_writes_them(self):
        self.assertEqual(wpblocks.attrs(a='x<y>&"--z'), ' {"a":"x\\u003cy\\u003e\\u0026\\u0022\\u002d\\u002dz"}')
        self.assertEqual(wpblocks.attrs(a=None), '')

    def test_grid_steps_down_evenly(self):
        self.assertEqual(wpblocks.steps(4)['@tablet']['layout']['columnCount'], 2)
        self.assertEqual(wpblocks.steps(6)['@tablet']['layout']['columnCount'], 3)
        self.assertNotIn('@tablet', wpblocks.steps(3))
        self.assertEqual(wpblocks.steps(3)['@mobile']['layout']['columnCount'], 1)

    def test_section_is_the_full_width_block_the_editor_saves(self):
        out = wpblocks.section([wpblocks.paragraph('Hi')], name='Intro', bg='brand-600')
        self.assertTrue(out.startswith('<!-- wp:sage/section {"backgroundColor":"brand-600","metadata":{"name":"Intro"}} -->'))
        self.assertIn('<div class="wp-block-sage-section alignfull has-brand-600-background-color has-background">', out)

    def test_no_site_specific_design_leaks_in(self):
        self.assertNotIn('peach', wpblocks.panel([]) + wpblocks.icon((1, 'x.svg')))


class EvenGrids(unittest.TestCase):
    def test_minimum_width_becomes_fixed_counts_and_is_idempotent(self):
        old = '<!-- wp:group {"layout":{"type":"grid","columnCount":4,"minimumColumnWidth":"14rem"}} -->'
        new = '<!-- wp:group {"layout":{"type":"grid","columnCount":4},"style":{"@tablet":{"layout":{"columnCount":2}},"@mobile":{"layout":{"columnCount":1}}}} -->'
        self.assertEqual(even_grids.even(old), new)
        self.assertEqual(even_grids.even(new), new)


class Nativize(unittest.TestCase):
    PAGE = (
        '<!-- wp:group {"className":"zz-section zz-section--faq"} --><div class="wp-block-group zz-section zz-section--faq">'
        '<!-- wp:group {"className":"zz-row"} --><div class="wp-block-group zz-row">'
        '<!-- wp:group {"className":"zz-col zz-col--1_2"} --><div class="wp-block-group zz-col zz-col--1_2">'
        '<!-- wp:heading --><h2 class="wp-block-heading">One</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Alpha</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
        '<!-- wp:group {"className":"zz-col zz-col--1_2"} --><div class="wp-block-group zz-col zz-col--1_2">'
        '<!-- wp:paragraph --><p>Beta</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
        '</div><!-- /wp:group --></div><!-- /wp:group -->')

    def test_converts_to_section_and_grid_keeping_every_word(self):
        nativize.FLAT_SECTIONS = False
        out = nativize.convert(self.PAGE)
        self.assertEqual(text(out), text(self.PAGE))
        self.assertIn('<!-- wp:sage/section', out)
        self.assertIn('"name":"Faq"', out)
        self.assertIn('"type":"grid","columnCount":2', out)
        self.assertNotIn('zz-', out)

    def test_blog_posts_get_no_section_band(self):
        nativize.FLAT_SECTIONS = True
        try:
            self.assertNotIn('wp:sage/section', nativize.convert(self.PAGE))
        finally:
            nativize.FLAT_SECTIONS = False

    def test_hide_classes_become_block_visibility(self):
        page = ('<!-- wp:group {"className":"zz-section zz-hide-phone"} --><div class="wp-block-group zz-section zz-hide-phone">'
                '<!-- wp:paragraph --><p>Only wide</p><!-- /wp:paragraph --></div><!-- /wp:group -->')
        self.assertIn('"blockVisibility":{"viewport":{"mobile":false}}', nativize.convert(page))


class Unprefix(unittest.TestCase):
    def test_rename_is_longest_first_and_respects_word_ends(self):
        names = [['zz-card', 'product-card'], ['zz-card__title', 'product-card__title'], ['--zz-ink', '--wp--preset--color--ink'], ['zz-loop-', 'product-loop-']]
        text_in = '.zz-card__title, .zz-card, .zz-cardboard { color: var(--zz-ink) } .zz-loop-grid'
        self.assertEqual(unprefix.rename(text_in, names),
                         '.product-card__title, .product-card, .zz-cardboard { color: var(--wp--preset--color--ink) } .product-loop-grid')

    def test_dead_rules_go_only_when_every_selector_is_dead(self):
        css = '.a:not(.old) { x: 1 }\n.b { y: 2 }\n.c:not(.old), .d { z: 3 }'
        out = unprefix.drop_dead_rules(css, r':not\(\.old\)')
        self.assertNotIn('x: 1', out)
        self.assertIn('y: 2', out)
        self.assertIn('z: 3', out)


class PruneCss(unittest.TestCase):
    def test_unused_theme_classes_are_removed_and_others_kept(self):
        used = {'pkde-btn'}
        css = '.pkde-btn { a: 1 }\n.pkde-gone { b: 2 }\n.plain { c: 3 }\n@media (min-width: 1px) { .pkde-gone { d: 4 } }\n'
        report = []
        out = prune_css.prune(css, used, report)
        self.assertIn('a: 1', out)
        self.assertIn('c: 3', out)
        self.assertNotIn('b: 2', out)
        self.assertNotIn('d: 4', out)
        self.assertNotIn('@media', out)

    def test_is_where_need_one_live_alternative_and_not_never_kills(self):
        used = {'pkde-a'}
        self.assertTrue(prune_css.live('.x:is(.pkde-a, .pkde-b)', used))
        self.assertFalse(prune_css.live('.x:is(.pkde-b, .pkde-c)', used))
        self.assertTrue(prune_css.live('.x:not(.pkde-b)', used))


class TidyCss(unittest.TestCase):
    def test_an_overridden_declaration_goes_and_the_rest_moves_to_the_last_rule(self):
        css = '.card { padding: 1rem; color: red }\n.other { margin: 0 }\n.card { padding: 0 }\n'
        tree = tidy_css.parse(css)
        tidy_css.tidy(tree, list(tidy_css.flat_rules(tree)))
        out = tidy_css.write(tree)
        self.assertEqual(out.count('.card {'), 1)
        self.assertIn('color: red', out)
        self.assertNotIn('padding: 1rem', out)
        self.assertIn('padding: 0', out)


class NoSiteStrings(unittest.TestCase):
    def test_tools_name_no_site(self):
        for path in NATIVE.glob('*.py'):
            body = path.read_text()
            self.assertIsNone(re.search(r'\b[A-Z]{2,3}_(posts|options|postmeta)\b', body), path.name)  # no hard-coded table prefix


if __name__ == '__main__':
    unittest.main()
