// Measures acceptance criterion 9 on a generated 5,000-row sheet.
//   npm run perf
import { statSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';
import { exportSheet, tempDir } from './helpers.mjs';

const ROWS = 5000;
const tmp = tempDir();
const csv = ['Product,Category,Price,Added,Website,Description'];
const cats = ['Coffee', 'Tea', 'Bakery', 'Lunch', 'Drinks', 'Cakes', 'Breakfast', 'Retail'];
for (let i = 0; i < ROWS; i++) {
  const d = new Date(Date.UTC(2020, 0, 1) + i * 86400000).toISOString().slice(0, 10);
  csv.push(`Product ${i} ${i % 7 === 0 ? 'Café' : 'Blend'},${cats[i % cats.length]},${(2 + (i % 400) / 10).toFixed(2)},${d},https://example.org/p/${i},"Fictional item number ${i}, roasted and packed in small batches"`);
}
writeFileSync(path.join(tmp, 'big.csv'), csv.join('\n'));
const html = exportSheet(path.join(tmp, 'big.csv'), path.join(tmp, 'big.html'));
const bytes = statSync(html).size;

const browser = await chromium.launch();
const results = { firstRender: [], search: [] };
for (let run = 0; run < 5; run++) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(pathToFileURL(html).href, { waitUntil: 'load' });
  await page.waitForSelector('#controls:not([hidden])');
  // First render: navigation start -> end of the first client render.
  const first = await page.evaluate(() => {
    const m = performance.getEntriesByName('showcase:render')[0];
    return m.startTime + m.duration;
  });
  results.firstRender.push(first);
  // Search: time of the render triggered by typing (excludes the 150 ms debounce).
  for (const q of ['cafe', 'product 49', 'tea 12']) {
    const before = await page.evaluate(() => performance.getEntriesByName('showcase:render').length);
    await page.fill('#q', q);
    await page.waitForFunction((n) => performance.getEntriesByName('showcase:render').length > n, before);
    results.search.push(await page.evaluate(() => performance.getEntriesByName('showcase:render').at(-1).duration));
  }
  await ctx.close();
}
await browser.close();

const median = (a) => [...a].sort((x, y) => x - y)[Math.floor(a.length / 2)];
const max = (a) => Math.max(...a);
console.log(`Machine: ${os.cpus()[0].model}, ${os.cpus().length} threads, Node ${process.version}`);
console.log(`Rows: ${ROWS}`);
console.log(`Export size: ${(bytes / 1024 / 1024).toFixed(2)} MB (${bytes} bytes)`);
console.log(`First render (navigation -> list painted): median ${median(results.firstRender).toFixed(0)} ms, max ${max(results.firstRender).toFixed(0)} ms`);
console.log(`Search update (filter + sort + render 50): median ${median(results.search).toFixed(1)} ms, max ${max(results.search).toFixed(1)} ms`);
const ok = bytes <= 2 * 1024 * 1024 && max(results.firstRender) < 1000 && max(results.search) < 100;
console.log(ok ? 'PASS: within the targets (<= 2 MB, < 1 s, < 100 ms)' : 'FAIL: outside the targets');
process.exitCode = ok ? 0 : 1;
