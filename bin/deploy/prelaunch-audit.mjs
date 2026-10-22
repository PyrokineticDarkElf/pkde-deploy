// Pre-launch audit worker. Run through bin/deploy/prelaunch-audit.sh, which installs the pinned tools and copies this file
// next to them so the bare imports below resolve. It only makes HTTP GET requests and drives a local headless Chrome; it
// never writes outside --out and never touches a server.
//
//   node audit.mjs --url https://staging.example.com --out DIR [--paths /,/about/] [--expect staging|production] [--chrome PATH]

import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import puppeteer from 'puppeteer-core';
import lighthouse from 'lighthouse';
import desktopConfig from 'lighthouse/core/config/desktop-config.js';
import axe from 'axe-core';

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const base = new URL(arg('url')).origin;
const out = arg('out');
const expectEnv = arg('expect', 'staging');
const chromePath = arg('chrome');
let paths = (arg('paths', '') || '').split(',').filter(Boolean);
mkdirSync(out, { recursive: true });

process.on('unhandledRejection', (e) => { console.error(`\nFAIL  The audit crashed before it finished: ${(e && e.stack) || e}`); process.exit(1); });

const lines = [];
let fails = 0;
const report = (status, check, detail = '') => {
  if (status === 'FAIL') fails++;
  const line = `${status.padEnd(5)} ${check}${detail ? ': ' + detail : ''}`;
  lines.push(line);
  console.log(line);
};

// 1. Headers: Cloudflare, caching and exposure ----------------------------------------------------------------------
async function headerChecks() {
  const res = await fetch(base + '/', { redirect: 'manual' });
  const h = res.headers;
  report(res.status === 200 ? 'PASS' : 'FAIL', 'Home page answers 200', `got ${res.status}`);
  report(h.get('x-powered-by') ? 'FAIL' : 'PASS', 'No X-Powered-By header', h.get('x-powered-by') || '');
  // Staging must say noindex, either as the X-Robots-Tag header or as a meta robots tag (Bedrock's disallow-indexing sets the
  // meta tag); production must have neither. The detail says which one was found.
  const html = await res.text();
  const robotsHeader = h.get('x-robots-tag') || '';
  const robotsMeta = [...html.matchAll(/<meta\b[^>]*>/gi)].map((m) => m[0]).filter((t) => /name=["']robots["']/i.test(t))
    .map((t) => (t.match(/content=["']([^"']*)["']/i) || [])[1] || '').join(', ');
  const found = [/noindex/i.test(robotsHeader) && `header "${robotsHeader}"`, /noindex/i.test(robotsMeta) && `meta robots "${robotsMeta}"`].filter(Boolean);
  const noindex = found.length > 0;
  report(expectEnv === 'staging' ? (noindex ? 'PASS' : 'FAIL') : (noindex ? 'FAIL' : 'PASS'),
    `noindex ${expectEnv === 'staging' ? 'present (staging): X-Robots-Tag header or meta robots' : 'absent (production): X-Robots-Tag header and meta robots'}`,
    found.length ? `found in ${found.join(' and ')}` : 'neither a header nor a meta tag');
  // The sitemap is what Rank Math serves only when it is registered (drift check); search engines and the preloader read it.
  const sitemap = await fetch(base + '/sitemap_index.xml', { redirect: 'manual' }).catch(() => null);
  const sitemapBody = sitemap && sitemap.status === 200 ? await sitemap.text() : '';
  report(sitemap?.status === 200 && /<sitemapindex/i.test(sitemapBody) ? 'PASS' : 'FAIL', '/sitemap_index.xml answers 200 with a sitemap index',
    sitemap ? `got ${sitemap.status}${sitemap.status === 200 && !/<sitemapindex/i.test(sitemapBody) ? ' but no <sitemapindex>' : ''}` : 'no answer');
  report(h.get('content-encoding') ? 'PASS' : 'FAIL', 'Responses are compressed', h.get('content-encoding') || 'no content-encoding');
  report('INFO', 'Cloudflare', `server=${h.get('server') || '-'} cf-ray=${h.get('cf-ray') ? 'yes' : 'no'} cf-cache-status=${h.get('cf-cache-status') || '-'}`);
  report('INFO', 'HTML Cache-Control', h.get('cache-control') || 'none');

  if (base.startsWith('https://')) {
    const r = await fetch(base.replace('https://', 'http://') + '/', { redirect: 'manual' }).catch(() => null);
    const loc = r?.headers.get('location') || '';
    report(r && [301, 302, 307, 308].includes(r.status) && loc.startsWith('https://') ? 'PASS' : 'FAIL', 'HTTP redirects to HTTPS', r ? `${r.status} ${loc}` : 'no answer');
  }

  const asset = html.match(/["'(]([^"'()\s]*\/build\/assets\/[^"'()\s]+\.(?:css|js))/);
  if (!asset) {
    report('INFO', 'Hashed asset cache headers', 'no /build/assets/ file referenced on the home page (dev server or inlined)');
    return html;
  }
  const a = await fetch(new URL(asset[1], base + '/').href, { method: 'HEAD' });
  const cc = a.headers.get('cache-control') || '';
  const maxAge = Number((cc.match(/max-age=(\d+)/) || [])[1] || 0);
  report(/immutable/.test(cc) && maxAge >= 31536000 ? 'PASS' : 'FAIL', 'Hashed Vite asset is cached for a year, immutable', `Cache-Control: ${cc || 'none'} (${asset[1].split('/').pop()})`);
  return html;
}

// Pages to audit: --paths, else the home page plus the first URL of each sitemap (page, post, ...) ------------------
async function discoverPaths() {
  if (paths.length) return paths;
  const found = ['/'];
  try {
    const idx = await (await fetch(base + '/sitemap_index.xml')).text();
    for (const m of [...idx.matchAll(/<loc>([^<]+)<\/loc>/g)].slice(0, 6)) {
      const sm = await (await fetch(new URL(new URL(m[1]).pathname, base).href)).text();
      const first = sm.match(/<loc>([^<]+)<\/loc>/);
      if (first) found.push(new URL(first[1]).pathname);
    }
  } catch { /* no sitemap: home only */ }
  return [...new Set(found)].slice(0, 8);
}

// A section that cannot run is a FAIL with its reason, never a silent skip: an audit that printed only the header checks must not
// look like an audit that passed.
const abort = (what, e) => report('FAIL', `${what} could not run`, String((e && e.message) || e).split('\n')[0]);
const browser = await puppeteer.launch({ executablePath: chromePath, headless: true, args: ['--no-sandbox', '--remote-debugging-port=0'] }).catch((e) => { abort('Chrome launch', e); return null; });
const html = await headerChecks().catch((e) => { abort('Header checks', e); return ''; });
if (!browser) {
  writeFileSync(join(out, 'summary.txt'), lines.join('\n') + `\n\n${fails} check(s) FAILED\n`);
  console.log(`\n${fails} check(s) FAILED. Lighthouse, axe and the focus check did NOT run. Reports: ${out}`);
  process.exit(1);
}
paths = await discoverPaths();
report('INFO', 'Pages audited', paths.join(' '));

// 2. Lighthouse, mobile and desktop, on the home page ----------------------------------------------------------------
for (const mode of ['mobile', 'desktop']) {
  try {
    const port = Number(new URL(browser.wsEndpoint()).port);
    const r = await lighthouse(base + '/', { port, output: ['json', 'html'], logLevel: 'error', onlyCategories: ['performance', 'accessibility', 'best-practices', 'seo'] }, mode === 'desktop' ? desktopConfig : undefined);
    writeFileSync(join(out, `lighthouse-${mode}.html`), r.report[1]);
    writeFileSync(join(out, `lighthouse-${mode}.json`), r.report[0]);
    const s = Object.fromEntries(Object.entries(r.lhr.categories).map(([k, v]) => [k, Math.round(v.score * 100)]));
    const lcp = r.lhr.audits['largest-contentful-paint']?.displayValue || '-';
    report('INFO', `Lighthouse ${mode}`, `performance ${s.performance}, accessibility ${s.accessibility}, best-practices ${s['best-practices']}, seo ${s.seo}, LCP ${lcp}`);
    report(s.accessibility >= 90 ? 'PASS' : 'FAIL', `Lighthouse ${mode} accessibility >= 90`, `got ${s.accessibility}`);
    report(s['best-practices'] >= 90 ? 'PASS' : 'FAIL', `Lighthouse ${mode} best practices >= 90`, `got ${s['best-practices']}`);
    // A staging site is noindex on purpose, which Lighthouse scores down in SEO.
    report(s.seo >= 90 || expectEnv === 'staging' ? (s.seo >= 90 ? 'PASS' : 'INFO') : 'FAIL', `Lighthouse ${mode} SEO >= 90`, `got ${s.seo}${expectEnv === 'staging' && s.seo < 90 ? ' (noindex on staging is expected)' : ''}`);
  } catch (e) { abort(`Lighthouse ${mode}`, e); }
}

// 3. axe-core on each page, and 4. the keyboard focus check -----------------------------------------------------------
const hasIndicator = (el) => {
  const cs = getComputedStyle(el);
  const outline = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0;
  return { outline, shadow: cs.boxShadow !== 'none', border: cs.borderColor, bg: cs.backgroundColor, color: cs.color, textDecoration: cs.textDecorationLine };
};

for (const path of paths) {
  const page = await browser.newPage();
  try {
    await page.setViewport({ width: 1280, height: 900 });
    await page.goto(base + path, { waitUntil: 'networkidle2', timeout: 60000 });

    await page.evaluate(axe.source);
    const result = await page.evaluate(() => axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice'] } }));
    writeFileSync(join(out, `axe-${path.replace(/\W+/g, '_') || 'home'}.json`), JSON.stringify(result.violations, null, 2));
    const blocking = result.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
    report(blocking.length ? 'FAIL' : 'PASS', `axe ${path}: no serious or critical violations`, blocking.map((v) => `${v.id} (${v.nodes.length})`).join(', '));
    const minor = result.violations.filter((v) => !['serious', 'critical'].includes(v.impact));
    if (minor.length) report('INFO', `axe ${path}: minor/moderate`, minor.map((v) => `${v.id} (${v.nodes.length})`).join(', '));

    // Tab through the page: every focus stop needs a visible indicator, and focus must not get stuck.
    const seen = [];
    for (let i = 0; i < 80; i++) {
      await page.keyboard.press('Tab');
      const stop = await page.evaluate((fn) => {
        const el = document.activeElement;
        if (!el || el === document.body) return null;
        const f = new Function('el', `return (${fn})(el)`);
        const focused = f(el);
        el.blur();
        const blurred = f(el);
        el.focus();
        const same = JSON.stringify(focused) === JSON.stringify(blurred);
        const r = el.getBoundingClientRect();
        // A unique key per element (its position in the DOM), so two plain links are not mistaken for one stop.
        const path = [];
        for (let n = el; n && n.parentElement; n = n.parentElement) path.push(Array.prototype.indexOf.call(n.parentElement.children, n));
        return { key: path.join('.'), id: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.getAttribute('aria-label') ? `[${el.getAttribute('aria-label')}]` : (el.textContent || '').trim().slice(0, 24) ? `[${(el.textContent || '').trim().slice(0, 24)}]` : ''), noIndicator: same && !focused.outline, visible: r.width > 0 && r.height > 0 };
      }, hasIndicator.toString());
      if (!stop) break;
      if (seen.some((s) => s.key === stop.key)) break; // focus came back round: the page's tab cycle is complete
      seen.push(stop);
    }
    const bad = seen.filter((s) => s.noIndicator && s.visible);
    report(seen.length > 0 ? 'PASS' : 'FAIL', `Focus ${path}: page has keyboard focus stops`, `${seen.length} tabbable stops reached`);
    report(bad.length ? 'FAIL' : 'PASS', `Focus ${path}: every focus stop shows a visible indicator`, bad.slice(0, 10).map((b) => b.id).join(', '));
  } catch (e) { abort(`axe and focus check for ${path}`, e); }
  await page.close().catch(() => {});
}

await browser.close();
writeFileSync(join(out, 'summary.txt'), lines.join('\n') + `\n\n${fails} check(s) FAILED\n`);
console.log(fails ? `\n${fails} check(s) FAILED. Reports: ${out}` : `\nAll checks passed. Reports: ${out}`);
process.exit(fails ? 1 : 0);
