// Layout fingerprint of a site's pages. Run through bin/deploy/fingerprint.sh, which installs the pinned tools.
//
//   node fingerprint.mjs capture --url http://localhost:8092 --out before.json [--paths /,/block-demo/] [--widths 1440,390] [--chrome PATH]
//   node fingerprint.mjs compare before.json after.json [--tolerance 0]
//
// capture: for every path and viewport width, the tag, DOM position and box (x, y, width, height, rounded) of every rendered element of
// the page, and its visible text. Motion is switched off (reduced motion, no animations or transitions) and lazy images are scrolled in
// first, so two captures of unchanged code are identical. compare: exits 1 and lists what differs (boxes beyond the tolerance in px,
// different tags, different text, different element counts); a refactor that must not change the page (moving code into a package)
// passes when nothing differs. Read-only: it only makes GET requests.

import { readFileSync, writeFileSync } from 'node:fs';

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const mode = process.argv[2];

if (mode === 'compare') {
  const [a, b] = process.argv.slice(3).filter((x) => !x.startsWith('--') && x !== arg('tolerance'));
  const tolerance = Number(arg('tolerance', 0));
  const A = JSON.parse(readFileSync(a, 'utf8'));
  const B = JSON.parse(readFileSync(b, 'utf8'));
  const problems = [];
  for (const key of new Set([...Object.keys(A.pages), ...Object.keys(B.pages)])) {
    const x = A.pages[key];
    const y = B.pages[key];
    if (!x || !y) { problems.push(`${key}: only in ${x ? a : b}`); continue; }
    if (x.text !== y.text) {
      const i = [...x.text].findIndex((c, n) => c !== y.text[n]);
      problems.push(`${key}: visible text differs near "${x.text.slice(Math.max(0, i - 30), i + 50)}" vs "${y.text.slice(Math.max(0, i - 30), i + 50)}"`);
    }
    if (x.elements.length !== y.elements.length) problems.push(`${key}: ${x.elements.length} elements before, ${y.elements.length} after`);
    let boxes = 0;
    let tags = 0;
    const first = [];
    for (let i = 0; i < Math.min(x.elements.length, y.elements.length); i++) {
      const [pa, ta, ...ba] = x.elements[i];
      const [pb, tb, ...bb] = y.elements[i];
      if (ta !== tb || pa !== pb) { tags++; if (first.length < 3) first.push(`#${i} ${pa} <${ta}> vs ${pb} <${tb}>`); continue; }
      if (ba.some((v, n) => Math.abs(v - bb[n]) > tolerance)) { boxes++; if (first.length < 3) first.push(`#${i} ${pa} <${ta}> ${ba} vs ${bb}`); }
    }
    if (tags) problems.push(`${key}: ${tags} elements changed tag or position in the DOM (${first.join('; ')})`);
    if (boxes) problems.push(`${key}: ${boxes} elements moved or resized (${first.join('; ')})`);
  }
  if (problems.length) {
    console.log(`DIFFERENT (${problems.length}):\n- ${problems.join('\n- ')}`);
    process.exit(1);
  }
  const pages = Object.keys(A.pages).length;
  const els = Object.values(A.pages).reduce((n, p) => n + p.elements.length, 0);
  console.log(`IDENTICAL: ${pages} page/width combinations, ${els} elements, visible text equal (tolerance ${tolerance}px).`);
  process.exit(0);
}

if (mode !== 'capture') {
  console.error('usage: fingerprint.mjs capture --url URL --out FILE | compare A.json B.json');
  process.exit(2);
}

const { default: puppeteer } = await import('puppeteer-core');
const base = new URL(arg('url')).origin;
const out = arg('out');
const widths = (arg('widths', '1440,390')).split(',').map(Number);
const paths = (arg('paths', '/')).split(',').filter(Boolean);
const browser = await puppeteer.launch({ executablePath: arg('chrome'), headless: true, args: ['--no-sandbox'] });
const result = { url: base, widths, pages: {} };

for (const path of paths) {
  for (const width of widths) {
    const page = await browser.newPage();
    await page.setViewport({ width, height: 900, deviceScaleFactor: 1 });
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
    await page.goto(base + path, { waitUntil: 'networkidle2', timeout: 90000 });
    await page.addStyleTag({ content: '*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important;caret-color:transparent!important}' });
    // Scroll to the bottom and back so lazy images load and their boxes settle, then wait for fonts.
    await page.evaluate(async () => {
      for (let y = 0; y < document.body.scrollHeight; y += 600) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); }
      window.scrollTo(0, 0);
      await document.fonts.ready;
      await new Promise((r) => setTimeout(r, 400));
    });
    result.pages[`${path} @${width}`] = await page.evaluate(() => {
      const skip = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'LINK', 'META', 'HEAD', 'TITLE', 'BR']);
      const elements = [];
      const walk = (el, path) => {
        let i = 0;
        for (const child of el.children) {
          const here = `${path}.${i++}`;
          if (skip.has(child.tagName)) continue;
          const r = child.getBoundingClientRect();
          if (r.width > 0 || r.height > 0) {
            elements.push([here, child.tagName.toLowerCase(), Math.round(r.x + scrollX), Math.round(r.y + scrollY), Math.round(r.width), Math.round(r.height)]);
          }
          walk(child, here);
        }
      };
      walk(document.body, 'b');
      return { text: document.body.innerText.replace(/\s+/g, ' ').trim(), elements };
    });
    await page.close();
  }
}
await browser.close();
writeFileSync(out, JSON.stringify(result));
const count = Object.values(result.pages).reduce((n, p) => n + p.elements.length, 0);
console.log(`Captured ${Object.keys(result.pages).length} page/width combinations, ${count} elements -> ${out}`);
