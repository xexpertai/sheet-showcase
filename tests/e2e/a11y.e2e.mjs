// Automated accessibility checks (axe-core) in light AND dark themes,
// for card and table views. Complements the Lighthouse run in the README.
import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';
import { exportSheet, tempDir } from './helpers.mjs';

const axePath = createRequire(import.meta.url).resolve('axe-core/axe.min.js');
let browser;
let tmp;

before(async () => {
  browser = await chromium.launch();
  tmp = tempDir();
  for (const f of ['cafe-menu.xlsx', 'club-members.csv', 'event-schedule.csv']) {
    exportSheet(`data/${f}`, path.join(tmp, `${path.parse(f).name}.html`));
  }
});
after(() => browser?.close());

for (const colorScheme of ['light', 'dark']) {
  for (const name of ['cafe-menu', 'club-members', 'event-schedule']) {
    test(`${name}: no axe violations (${colorScheme}, cards + table)`, async () => {
      // bypassCSP only so the test can inject axe; the page itself is unchanged.
      // reducedMotion disables the 150 ms button transition so axe never samples mid-fade.
      const ctx = await browser.newContext({ colorScheme, reducedMotion: 'reduce', bypassCSP: true, viewport: { width: 1024, height: 800 } });
      const page = await ctx.newPage();
      await page.goto(pathToFileURL(path.join(tmp, `${name}.html`)).href);
      await page.addScriptTag({ path: axePath });
      for (const view of ['cards', 'table']) {
        await page.click(`button[data-view="${view}"]`);
        const result = await page.evaluate(() =>
          window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21aa', 'best-practice'] }),
        );
        const violations = result.violations.map((v) => `${v.id}: ${v.nodes.length} node(s) — ${v.nodes[0]?.failureSummary ?? ''}`);
        assert.deepEqual(violations, [], `${view} view`);
      }
      await ctx.close();
    });
  }
}
