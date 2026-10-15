// End-to-end browser tests (Playwright + node:test).
// Covers acceptance criteria 1, 2, 6, 7, 8 and 11 in a real Chromium.
//   npm run e2e
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';
import { exportSheet, ROOT, search, startPhp, statusText, tempDir } from './helpers.mjs';

let browser;
let live;
let liveXss;
let tmp;

before(async () => {
  browser = await chromium.launch();
  live = await startPhp();
  liveXss = await startPhp({ dataDir: path.join(ROOT, 'tests/php/fixtures') });
  tmp = tempDir();
  exportSheet('data/cafe-menu.xlsx', path.join(tmp, 'cafe-menu.html'));
  exportSheet('data/club-members.csv', path.join(tmp, 'club-members.html'));
  exportSheet('tests/php/fixtures/xss.csv', path.join(tmp, 'xss.html'));
});

after(async () => {
  await browser?.close();
  live?.stop();
  liveXss?.stop();
});

/** New page that fails the test on console errors, page errors or dialogs. */
async function strictPage(t, options = {}, { allow404 = false } = {}) {
  const context = await browser.newContext(options);
  const page = await context.newPage();
  const problems = [];
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    if (allow404 && /status of 404/.test(m.text())) return;
    problems.push(`console: ${m.text()}`);
  });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  page.on('dialog', async (d) => {
    problems.push(`dialog fired: ${d.message()}`);
    await d.dismiss();
  });
  t.after(async () => {
    await context.close();
    assert.deepEqual(problems, []);
  });
  return { page, context, problems };
}

const exported = (name) => pathToFileURL(path.join(tmp, `${name}.html`)).href;

async function cardTitles(page) {
  return page.locator('.card-title').allTextContents();
}

describe('live mode', () => {
  test('cafe menu renders as cards and becomes interactive', async (t) => {
    const { page } = await strictPage(t);
    const res = await page.goto(`${live.url}/?sheet=cafe-menu`);
    assert.equal(res.status(), 200);
    assert.equal(await page.locator('.card').count(), 26);
    assert.ok(await page.locator('#controls').isVisible(), 'controls shown once JS runs');
    assert.match(await page.content(), /^(?![\s\S]*(Warning|Notice|Deprecated):)/);
  });

  test('path traversal returns a generic 404', async (t) => {
    const { page } = await strictPage(t, {}, { allow404: true });
    for (const bad of ['../../etc/passwd', '..%2Fsecret']) {
      const res = await page.goto(`${live.url}/?sheet=${bad}`);
      assert.equal(res.status(), 404);
      assert.equal(await page.locator('h1').textContent(), 'Not found');
    }
  });
});

describe('static export', () => {
  test('works offline with zero network requests, same as live mode', async (t) => {
    const { page, context } = await strictPage(t);
    await context.setOffline(true);
    const requests = [];
    page.on('request', (r) => requests.push(r.url()));
    await page.goto(exported('cafe-menu'));
    await page.waitForSelector('#controls:not([hidden])');
    await search(page, 'cafe');
    await page.selectOption('#f-category', 'Coffee');
    await page.waitForTimeout(50);
    const offlineTitles = await cardTitles(page);
    const offlineStatus = await statusText(page);
    const network = requests.filter((u) => !u.startsWith('file:') && !u.startsWith('data:'));
    assert.deepEqual(network, [], 'no network requests');
    assert.equal(requests.filter((u) => u.startsWith('file:')).length, 1, 'only the HTML file itself');

    // Same interaction in live mode gives the same result.
    const { page: livePage } = await strictPage(t);
    await livePage.goto(`${live.url}/?sheet=cafe-menu`);
    await search(livePage, 'cafe');
    await livePage.selectOption('#f-category', 'Coffee');
    await livePage.waitForTimeout(50);
    assert.deepEqual(await cardTitles(livePage), offlineTitles);
    assert.equal(await statusText(livePage), offlineStatus);
    assert.deepEqual(offlineTitles, ['Café au lait']);
  });
});

describe('XSS payloads stay inert', () => {
  for (const [label, url] of [
    ['live', () => `${liveXss.url}/?sheet=xss`],
    ['export', () => exported('xss')],
  ]) {
    test(`${label}: no dialog fires in cards, table, search and print views`, async (t) => {
      const { page } = await strictPage(t);
      await page.goto(url());
      await page.waitForSelector('#controls:not([hidden])');
      // Exercise every client-side rendering path.
      await page.click('button[data-view="table"]');
      await search(page, 'script');
      await search(page, '');
      await page.selectOption('#f-category', 'A');
      await page.click('button[data-view="cards"]');
      await page.emulateMedia({ media: 'print' });
      await page.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
      await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
      await page.waitForTimeout(300);

      const unsafe = await page.evaluate(() => {
        const bad = [];
        for (const el of document.querySelectorAll('*')) {
          for (const a of el.attributes) {
            if (/^on/i.test(a.name)) bad.push(`${el.tagName} ${a.name}`);
            if (['href', 'src'].includes(a.name) && /^\s*(javascript|data:(?!,$))/i.test(a.value)) bad.push(`${el.tagName} ${a.name}=${a.value}`);
          }
        }
        if (document.querySelectorAll('script').length !== 2) bad.push('extra <script> elements');
        if (document.querySelector('svg, iframe')) bad.push('svg/iframe injected');
        return bad;
      });
      assert.deepEqual(unsafe, []);
      assert.ok((await page.textContent('body')).includes('<script>alert(1)</script>'), 'payload visible as text');
    });
  }
});

describe('search, filter, sort and URL hash', () => {
  test('state persists in the hash and is restored on reload', async (t) => {
    const { page } = await strictPage(t);
    await page.goto(exported('cafe-menu'));
    await search(page, 'cafe');
    assert.deepEqual(await cardTitles(page), ['Café au lait'], '"cafe" matches "Café"');
    await search(page, '');
    await page.selectOption('#f-category', 'Bakery');
    await page.selectOption('#sort', 'price:desc');
    await search(page, 'a');
    const hash = new URL(page.url()).hash;
    assert.match(hash, /q=a/);
    assert.match(hash, /category=Bakery/);
    assert.match(hash, /sort=price&dir=desc/);
    const titles = await cardTitles(page);

    await page.reload();
    await page.waitForSelector('#controls:not([hidden])');
    assert.equal(await page.inputValue('#q'), 'a');
    assert.equal(await page.inputValue('#f-category'), 'Bakery');
    assert.equal(await page.inputValue('#sort'), 'price:desc');
    assert.deepEqual(await cardTitles(page), titles);

    await page.click('#clear');
    assert.equal(new URL(page.url()).hash, '');
    assert.equal(await page.locator('.card').count(), 26);
  });

  test('table header sorting, keyboard only', async (t) => {
    const { page } = await strictPage(t);
    await page.goto(exported('club-members'));
    await page.focus('button[data-sort="city"]');
    await page.keyboard.press('Enter');
    assert.equal(await page.getAttribute('th:has(button[data-sort="city"])', 'aria-sort'), 'ascending');
    await page.keyboard.press('Enter');
    assert.equal(await page.getAttribute('th:has(button[data-sort="city"])', 'aria-sort'), 'descending');
    assert.equal(await page.evaluate(() => document.activeElement?.dataset.sort), 'city', 'focus kept on the header');
  });

  test('load more shows the next 50', async (t) => {
    const big = tempDir();
    const csv = ['Name,Group'];
    for (let i = 0; i < 130; i++) csv.push(`Row ${i},G${i % 4}`);
    const { writeFileSync } = await import('node:fs');
    writeFileSync(path.join(big, 'big.csv'), csv.join('\n'));
    exportSheet(path.join(big, 'big.csv'), path.join(big, 'big.html'));
    const { page } = await strictPage(t);
    await page.goto(pathToFileURL(path.join(big, 'big.html')).href);
    assert.equal(await page.locator('.card').count(), 50);
    await page.click('#more');
    assert.equal(await page.locator('.card').count(), 100);
    assert.equal(await page.evaluate(() => document.activeElement?.classList.contains('card')), true);
    await page.click('#more');
    assert.equal(await page.locator('.card').count(), 130);
    assert.ok(await page.locator('#more').isHidden());
  });
});

describe('responsive layout', () => {
  for (const width of [360, 768, 1280]) {
    test(`no horizontal scroll at ${width}px`, async (t) => {
      const { page } = await strictPage(t, { viewport: { width, height: 800 } });
      for (const name of ['cafe-menu', 'club-members']) {
        await page.goto(exported(name));
        for (const view of ['cards', 'table']) {
          await page.click(`button[data-view="${view}"]`);
          const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
          assert.equal(overflow, 0, `${name} ${view} overflows by ${overflow}px`);
        }
      }
    });
  }
});
