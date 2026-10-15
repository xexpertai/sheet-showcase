// Shared helpers for the browser tests and measurement scripts.
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
export const PHP = process.env.PHP_BINARY || 'php';

/** A fresh temp directory under $TMPDIR. */
export function tempDir(prefix = 'sheet-showcase-') {
  return mkdtempSync(path.join(process.env.TMPDIR || os.tmpdir(), prefix));
}

function freePort() {
  return new Promise((resolve, reject) => {
    const srv = net.createServer();
    srv.listen(0, '127.0.0.1', () => {
      const { port } = srv.address();
      srv.close(() => resolve(port));
    });
    srv.on('error', reject);
  });
}

async function waitForPort(port) {
  for (let i = 0; i < 100; i++) {
    const ok = await new Promise((resolve) => {
      const s = net.connect(port, '127.0.0.1', () => {
        s.end();
        resolve(true);
      });
      s.on('error', () => resolve(false));
    });
    if (ok) return;
    await new Promise((r) => setTimeout(r, 50));
  }
  throw new Error(`server on ${port} did not start`);
}

/**
 * Starts `php -S` serving `docroot` (default: public/) with an optional data dir.
 * Returns { url, stop }.
 */
export async function startPhp({ docroot = path.join(ROOT, 'public'), dataDir } = {}) {
  const port = await freePort();
  const env = { ...process.env };
  if (dataDir) env.SHEET_SHOWCASE_DATA = dataDir;
  const proc = spawn(PHP, ['-d', 'display_errors=1', '-d', 'error_reporting=-1', '-S', `127.0.0.1:${port}`, '-t', docroot], {
    env,
    stdio: 'ignore',
  });
  await waitForPort(port);
  return {
    url: `http://127.0.0.1:${port}`,
    stop: () => proc.kill(),
  };
}

/** Runs `php bin/showcase export <file> -o <out>`. */
export function exportSheet(file, out) {
  execFileSync(PHP, [path.join(ROOT, 'bin/showcase'), 'export', file, '-o', out], { cwd: ROOT, stdio: 'pipe' });
  return out;
}

/** Text of the result-count line. */
export async function statusText(page) {
  return (await page.locator('#status').textContent()).trim();
}

/** Types into the search box and waits for the debounced update. */
export async function search(page, text) {
  const before = await statusText(page);
  await page.fill('#q', text);
  await page.waitForFunction((prev) => document.getElementById('status').textContent.trim() !== prev, before, { timeout: 2000 }).catch(() => {});
  await page.waitForTimeout(200);
}
