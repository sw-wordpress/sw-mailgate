/* global document, window */
// Browser check of assets/js/sw-mailgate-updates.js with a simulated admin-ajax.php (no WordPress, no GitHub).
// Verifies: single-flight manual check, disabled button while running, result/error text, deferred daily check,
// never more than the declared AJAX action, and strictly > 80 % executable-line coverage of the script.
import assert from 'node:assert/strict';
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join, resolve } from 'node:path';
import { chromium } from 'playwright-core';

const script = readFileSync(resolve(import.meta.dirname, '../../assets/js/sw-mailgate-updates.js'), 'utf8');

function findChrome() {
  if (process.env.CHROME_PATH) return process.env.CHROME_PATH;
  const cache = join(homedir(), '.cache', 'ms-playwright');
  if (!existsSync(cache)) return undefined;
  for (const d of readdirSync(cache).filter((n) => /^chromium-\d+$/.test(n)).sort().reverse()) {
    const p = join(cache, d, 'chrome-linux', 'chrome');
    if (existsSync(p)) return p;
  }
  return undefined;
}

const config = (due) => ({
  url: 'https://wp.test/wp-admin/admin-ajax.php',
  action: 'swmg_check_github_updates',
  nonce: 'n0nce',
  due,
  messages: { checking: 'Checking…', done: 'Done.', failed: 'Unreachable.' },
});
const html = (due, withButton = true) => `<!doctype html><html><head><meta charset="utf-8"></head><body>
${withButton ? '<button id="swmg-verified-updates-check">Check</button><span id="swmg-verified-updates-result" hidden></span>' : ''}
<script>window.swmgVerifiedUpdates=${JSON.stringify(config(due))};</script>
<script src="https://wp.test/updates.js"></script></body></html>`;

const browser = await chromium.launch({ executablePath: findChrome(), args: ['--no-sandbox'] });
// Union of the lines executed across all scenarios.
const union = new Uint8Array(script.length);

async function open(due, handler, opts = {}) {
  const context = await browser.newContext();
  const page = await context.newPage();
  const requests = [];
  if (opts.noIdle) await page.addInitScript(() => { delete window.requestIdleCallback; });
  if (opts.fastTimeout) {
    await page.addInitScript(() => {
      const original = window.setTimeout.bind(window);
      window.setTimeout = (fn, ms, ...rest) => original(fn, ms === 90000 ? 100 : ms, ...rest);
    });
  }
  await page.route('https://wp.test/**', async (route) => {
    const url = route.request().url();
    if (url.endsWith('/page')) return route.fulfill({ contentType: 'text/html', body: html(due, opts.button !== false) });
    if (url.endsWith('/updates.js')) return route.fulfill({ contentType: 'application/javascript; charset=utf-8', body: script });
    requests.push(Object.fromEntries(new URLSearchParams(route.request().postData() ?? '')));
    return handler(route, requests.length);
  });
  await page.coverage.startJSCoverage({ resetOnNavigation: false });
  await page.goto('https://wp.test/page');
  return { page, requests, context };
}
async function finish({ page, context }) {
  const entries = await page.coverage.stopJSCoverage();
  const updates = entries.filter((x) => x.url.endsWith('/updates.js'));
  if (process.env.NODE_V8_COVERAGE && updates.length) {
    writeFileSync(join(process.env.NODE_V8_COVERAGE, `swmg-browser-${Date.now()}-${Math.random()}.json`), JSON.stringify({ result: updates.map((e) => ({ ...e, scriptId: '0', url: resolve(import.meta.dirname, '../../assets/js/sw-mailgate-updates.js') })) }));
  }
  for (const e of updates) {
    // V8 block coverage: ranges are nested, later (inner) ranges override outer ones.
    const marks = new Uint8Array(script.length);
    const ranges = e.functions.flatMap((f) => f.ranges).sort((a, b) => a.startOffset - b.startOffset || b.endOffset - a.endOffset);
    for (const r of ranges) marks.fill(r.count > 0 ? 1 : 0, r.startOffset, Math.min(r.endOffset, script.length));
    marks.forEach((m, i) => { union[i] |= m; });
  }
  await context.close();
}
const json = (body) => ({ contentType: 'application/json', body: JSON.stringify(body) });

// 1. Manual check: button disabled while pending, second click is single-flight, message shown, no daily request.
{
  let release;
  const gate = new Promise((r) => (release = r));
  const ctx = await open(false, async (route) => { await gate; await route.fulfill(json({ success: true, data: { message: 'Verified.' } })); });
  const { page, requests } = ctx;
  await page.waitForTimeout(300);
  assert.equal(requests.length, 0, 'no request without due flag or click');
  await page.click('#swmg-verified-updates-check');
  assert.equal(await page.locator('#swmg-verified-updates-check').isDisabled(), true);
  assert.equal(await page.locator('#swmg-verified-updates-result').isVisible(), true);
  assert.equal(await page.locator('#swmg-verified-updates-result').textContent(), 'Checking…');
  await page.evaluate(() => document.getElementById('swmg-verified-updates-check').disabled = false);
  await page.click('#swmg-verified-updates-check');
  release();
  await page.waitForFunction(() => !document.getElementById('swmg-verified-updates-check').disabled);
  assert.equal(requests.length, 1, 'single-flight');
  assert.deepEqual(requests[0], { action: 'swmg_check_github_updates', nonce: 'n0nce', manual: '1' });
  assert.equal(await page.locator('#swmg-verified-updates-result').textContent(), 'Verified.');
  await finish(ctx);
}
// 2. Server-side refusal (success:false) shows the server message; fallback text if none.
{
  const ctx = await open(false, (route) => route.fulfill(json({ success: false, data: { message: 'Throttled.' } })));
  await ctx.page.click('#swmg-verified-updates-check');
  await ctx.page.waitForFunction(() => document.getElementById('swmg-verified-updates-result').textContent === 'Throttled.');
  await finish(ctx);
}
{
  const ctx = await open(false, (route) => route.fulfill(json({ success: true })));
  await ctx.page.click('#swmg-verified-updates-check');
  await ctx.page.waitForFunction(() => document.getElementById('swmg-verified-updates-result').textContent === 'Done.');
  await finish(ctx);
}
// 3. HTTP error and network failure show the failure text and re-enable the button.
for (const handler of [(route) => route.fulfill({ status: 500, body: 'x' }), (route) => route.abort()]) {
  const ctx = await open(false, handler);
  await ctx.page.click('#swmg-verified-updates-check');
  await ctx.page.waitForFunction(() => document.getElementById('swmg-verified-updates-result').textContent === 'Unreachable.');
  assert.equal(await ctx.page.locator('#swmg-verified-updates-check').isDisabled(), false);
  await finish(ctx);
}
// 4. Due flag: exactly one silent request after page load, no visible message, with manual=0.
{
  const ctx = await open(true, (route) => route.fulfill(json({ success: true, data: { message: 'Silent.' } })));
  await ctx.page.waitForFunction(() => true);
  await ctx.page.waitForTimeout(1800);
  assert.equal(ctx.requests.length, 1);
  assert.equal(ctx.requests[0].manual, '0');
  assert.equal(await ctx.page.locator('#swmg-verified-updates-result').isHidden(), true);
  await finish(ctx);
}
// 5. Due flag without any button (other admin screens) and silent failure.
{
  const ctx = await open(true, (route) => route.abort(), { button: false });
  await ctx.page.waitForTimeout(1800);
  assert.equal(ctx.requests.length, 1);
  await finish(ctx);
}
// 5b. Browsers without requestIdleCallback use the timer fallback.
{
  const ctx = await open(true, (route) => route.fulfill(json({ success: true })), { noIdle: true });
  await ctx.page.waitForTimeout(2200);
  assert.equal(ctx.requests.length, 1);
  await finish(ctx);
}
// 5c. A request that never answers is aborted by the client-side timeout and the button is released.
{
  const ctx = await open(false, () => new Promise(() => {}), { fastTimeout: true });
  await ctx.page.click('#swmg-verified-updates-check');
  await ctx.page.waitForFunction(() => document.getElementById('swmg-verified-updates-result').textContent === 'Unreachable.');
  assert.equal(await ctx.page.locator('#swmg-verified-updates-check').isDisabled(), false);
  await finish(ctx);
}
// 6. Without configuration the script does nothing.
{
  const context = await browser.newContext();
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e));
  await page.route('https://wp.test/**', (route) => route.fulfill({ contentType: 'text/html', body: '<script>' + script + '</script>' }));
  await page.goto('https://wp.test/x');
  assert.equal(errors.length, 0);
  await context.close();
}
await browser.close();

// Executable-line coverage (not byte ratio): a code line counts as covered when its first token was executed.
// Blank lines, comment-only lines and lines holding only closing punctuation are not executable.
const lines = script.split('\n');
let offset = 0;
let executable = 0;
let coveredLines = 0;
const uncovered = [];
lines.forEach((line, index) => {
  const first = offset + (line.length - line.trimStart().length);
  offset += line.length + 1;
  const code = line.trim();
  if (code === '' || /^(\/\/|\/\*|\*)/.test(code) || /^[\])}\s;,.]+$/.test(code)) return;
  executable += 1;
  if (union[first] === 1) coveredLines += 1;
  else uncovered.push(index + 1);
});
const ratio = coveredLines / executable;
console.log(`updates script line coverage: ${coveredLines}/${executable} = ${(ratio * 100).toFixed(1)} % (uncovered lines: ${uncovered.join(', ') || 'none'})`);
assert.ok(ratio > 0.8, 'line coverage must be above 80 %');
console.log('PASS: update check UI');
