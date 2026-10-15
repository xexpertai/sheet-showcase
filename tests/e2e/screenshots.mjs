// Regenerates docs/screenshots/*.png from the exported samples.
//   npm run screenshots
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';
import { exportSheet, ROOT, tempDir } from './helpers.mjs';

const tmp = tempDir();
const out = (f) => path.join(ROOT, 'docs/screenshots', f);
const page = (name) => pathToFileURL(exportSheet(`data/${name}`, path.join(tmp, `${path.parse(name).name}.html`))).href;

const shots = [
  { file: 'cards-light.png', url: page('cafe-menu.xlsx'), viewport: { width: 1280, height: 900 }, colorScheme: 'light' },
  { file: 'table-dark.png', url: page('club-members.csv'), viewport: { width: 1280, height: 900 }, colorScheme: 'dark' },
  { file: 'mobile.png', url: page('event-schedule.csv'), viewport: { width: 360, height: 780 }, colorScheme: 'light', deviceScaleFactor: 2 },
  { file: 'mobile-dark-search.png', url: `${page('cafe-menu.xlsx')}#q=cafe`, viewport: { width: 360, height: 780 }, colorScheme: 'dark', deviceScaleFactor: 2 },
];

const browser = await chromium.launch();
for (const s of shots) {
  const ctx = await browser.newContext({ viewport: s.viewport, colorScheme: s.colorScheme, deviceScaleFactor: s.deviceScaleFactor ?? 1 });
  const p = await ctx.newPage();
  await p.goto(s.url);
  await p.waitForSelector('#controls:not([hidden])');
  await p.screenshot({ path: out(s.file) });
  console.log('wrote', path.relative(ROOT, out(s.file)));
  await ctx.close();
}
await browser.close();
