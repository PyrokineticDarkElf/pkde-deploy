// Content-edge check (MGR-62): the header, the page containers, a Section's content and the footer must start and end at the same x.
//
//   node edges.mjs --url http://localhost:8092 [--paths /,/about/,/404-test/] [--widths 1440,390] [--tolerance 1] --chrome PATH
//
// Per path and viewport width it measures the content edges (box less padding) of: the first header container, every footer container, every
// page container in <main> (max-w-content), and the direct children of every Section block that are not floated or full width. The header is
// the reference; anything whose left or right edge differs by more than the tolerance (px) is listed and the exit status is 1. A child that
// is narrower than the page by design belongs in a nested Group, which is not a direct child. Run through `fingerprint.sh edges`.
import { pathToFileURL } from 'node:url';

/** @param {{label: string, left: number, right: number}[]} items @param {{left: number, right: number}} ref @returns {string[]} */
export function edgeProblems(items, ref, tolerance = 1) {
  return items
    .filter((i) => Math.abs(i.left - ref.left) > tolerance || Math.abs(i.right - ref.right) > tolerance)
    .map((i) => `${i.label}: content ${Math.round(i.left)}..${Math.round(i.right)}${i.box ? ` (box ${i.box})` : ''} (header ${Math.round(ref.left)}..${Math.round(ref.right)})`);
}

const arg = (name, fallback = '') => {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
};

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const { default: puppeteer } = await import('puppeteer-core');
  const base = new URL(arg('url')).origin;
  const widths = arg('widths', '1440,390').split(',').map(Number);
  const paths = arg('paths', '/').split(',').filter(Boolean);
  const tolerance = Number(arg('tolerance', '1'));
  const auth = process.env.FINGERPRINT_AUTH || '';
  const credentials = auth.includes(':') ? { username: auth.slice(0, auth.indexOf(':')), password: auth.slice(auth.indexOf(':') + 1) } : null;
  const browser = await puppeteer.launch({ executablePath: arg('chrome'), headless: true, args: ['--no-sandbox'] });
  let problems = 0;
  let checked = 0;

  for (const path of paths) {
    for (const width of widths) {
      const page = await browser.newPage();
      await page.setViewport({ width, height: 900, deviceScaleFactor: 1 });
      if (credentials) await page.authenticate(credentials);
      await page.goto(base + path, { waitUntil: 'networkidle2', timeout: 90000 });
      const m = await page.evaluate(() => {
        const content = (el) => {
          const r = el.getBoundingClientRect();
          const s = getComputedStyle(el);
          return { left: r.left + parseFloat(s.paddingLeft), right: r.right - parseFloat(s.paddingRight) };
        };
        const label = (el) => `${el.tagName.toLowerCase()}${el.className && typeof el.className === 'string' ? '.' + el.className.split(/\s+/).slice(0, 2).join('.') : ''}`;
        const box = (el) => ({ label: label(el), ...(({ left, right }) => ({ left, right }))(el.getBoundingClientRect()) });
        const header = document.querySelector('header [class*="max-w-content"], header .site-wrap, header .container') || document.querySelector('header > div');
        const items = [];
        document.querySelectorAll('footer [class*="max-w-content"]').forEach((el) => items.push({ label: 'footer ' + label(el), ...content(el) }));
        // Elements outside the viewport sideways (the slides of a carousel) are laid out elsewhere on purpose.
        const visible = (el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.left < innerWidth && r.right > 0; };
        // Page containers in <main>: any block that is centred with equal margins and is narrower than the viewport. A narrower
        // column INSIDE a container that already matches the header (prose, a hero's text) is by design and skipped; a container that
        // starts at the top and differs (the 404's old max-w-2xl wrapper) is what this finds.
        const containerish = (el) => /(^|\s)(mx-auto|max-w-\S+)(\s|$)/.test(String(el.className));
        const near = (x, y) => Math.abs(x - y) <= 1;
        const centred = (el) => {
          const s = getComputedStyle(el);
          const r = el.getBoundingClientRect();
          return s.display !== 'inline' && (r.width > 200 || containerish(el)) && r.width < innerWidth - 2 && r.left > 1 && near(r.left, innerWidth - r.right);
        };
        // A container matches when its content box sits on the header's content edges, or its border box on the header container's own box
        // (a card or wide panel: its background spans the container, its padding is the gutter).
        const matchesHeader = (el) => {
          const h = content(header);
          const hb = header.getBoundingClientRect();
          const c = content(el);
          const b = el.getBoundingClientRect();
          return header && ((near(c.left, h.left) && near(c.right, h.right)) || (near(b.left, hb.left) && near(b.right, hb.right)));
        };
        // A full-bleed block (hero, loop panel) positions its own inner content; the Section rule below covers Sections.
        const fullBleed = (el) => { const r = el.getBoundingClientRect(); return r.left <= 1 && r.right >= innerWidth - 1 && !el.matches('.wp-block-sage-section'); };
        const main = document.querySelector('main') || document.body;
        // The page's own text column: a child of the page content grid that sits in its content track and carries no container classes of its own
        // is prose, narrower than the header by design (48rem). One that carries mx-auto or max-w-* is a container somebody sized by hand (bp's
        // home intro: a section of `mx-auto max-w-3xl px-6` stored in the page content) and is checked like any other container.
        const proseColumn = (el) => {
          for (let a = el; a && a !== main; a = a.parentElement) {
            if (a.parentElement && a.parentElement.classList.contains('content-grid')) {
              const col = getComputedStyle(a).gridColumnStart;
              return /content/.test(col) && ! /wide|full/.test(col) && ! containerish(a);
            }
          }
          return false;
        };
        const candidates = [];
        main.querySelectorAll('*').forEach((el) => {
          if (! visible(el) || ! centred(el) || el.closest('.wp-block-sage-section') || matchesHeader(el) || proseColumn(el)) return;
          for (let p = el.parentElement; p && p !== main.parentElement; p = p.parentElement) {
            if (p !== document.body && p !== main && (matchesHeader(p) || (fullBleed(p) && p.parentElement !== main && ! p.matches('.page-content')))) return; // inside a matching container or a full-bleed block
          }
          candidates.push(el);
        });
        // Only the outermost offender is reported: its children are narrow because it is.
        candidates.filter((el) => ! candidates.some((o) => o !== el && o.contains(el))).forEach((el) => {
          const b = el.getBoundingClientRect();
          items.push({ label: 'main ' + label(el), box: `${Math.round(b.left)}..${Math.round(b.right)}`, ...content(el) });
        });
        document.querySelectorAll('.wp-block-sage-section.is-layout-constrained').forEach((section) => {
          for (const child of section.children) {
            const cs = getComputedStyle(child);
            if (cs.display === 'none' || child.matches('.alignleft, .alignright, .alignfull') || cs.float !== 'none' || cs.position === 'absolute') continue;
            if (visible(child)) items.push({ label: 'section child ' + label(child), ...box(child) });
          }
        });
        return { ref: header ? content(header) : null, items };
      });
      await page.close();
      if (!m.ref) { console.log(`${path} @${width}: no header container found`); problems++; continue; }
      checked += m.items.length;
      for (const p of edgeProblems(m.items, m.ref, tolerance)) { console.log(`${path} @${width}: ${p}`); problems++; }
    }
  }
  await browser.close();
  console.log(problems ? `${problems} edge difference(s) over ${checked} measured element(s).` : `Edges match: ${checked} element(s) across ${paths.length} path(s) and ${widths.length} width(s).`);
  process.exit(problems ? 1 : 0);
}
