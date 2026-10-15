// Acceptance criterion 8: search, filters and sort combine; state survives the URL hash.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  applyState,
  buildIndex,
  compareValues,
  defaultState,
  distinctValues,
  fold,
  formatValue,
  parseHash,
  safeHttpUrl,
  serializeHash,
} from '../../public/assets/core.js';

const data = {
  meta: { layout: 'cards', sort: { key: null, dir: 'asc' }, filters: ['category'], pageSize: 50, locale: 'en', currency: 'EUR' },
  columns: [
    { key: 'item', label: 'Item', type: 'text' },
    { key: 'category', label: 'Category', type: 'tag' },
    { key: 'price', label: 'Price', type: 'currency' },
    { key: 'since', label: 'Since', type: 'date' },
  ],
  rows: [
    ['Café au lait', 'Coffee', 3.2, '2024-05-01'],
    ['Espresso', 'Coffee', 2.1, '2023-01-15'],
    ['Cafe bombon', 'Coffee', null, '2025-02-01'],
    ['Lemon tart', 'Cakes', 4.4, ''],
    ['CAFÉ CAKE', 'Cakes', 3.9, '2022-12-31'],
    ['Item 10', 'Other', 10, '2024-01-01'],
    ['Item 9', 'Other', 9, '2024-01-02'],
  ],
};
const index = buildIndex(data.rows);
const names = (ids) => ids.map((i) => data.rows[i][0]);

test('fold removes accents and case', () => {
  assert.equal(fold('Café CRÈME Ñandú'), 'cafe creme nandu');
});

test('search "cafe" matches Café, CAFÉ and Cafe (accent and case insensitive)', () => {
  const ids = applyState(data, index, { q: 'cafe', filters: {}, sort: null });
  assert.deepEqual(names(ids), ['Café au lait', 'Cafe bombon', 'CAFÉ CAKE']);
});

test('accented query also matches unaccented text', () => {
  assert.deepEqual(names(applyState(data, index, { q: 'BOMBÓN', filters: {}, sort: null })), ['Cafe bombon']);
});

test('multiple words must all match (AND)', () => {
  assert.deepEqual(names(applyState(data, index, { q: 'cafe cake', filters: {}, sort: null })), ['CAFÉ CAKE']);
});

test('search + filter + sort combine', () => {
  const state = { q: 'cafe', filters: { category: 'Coffee' }, sort: { key: 'price', dir: 'desc' } };
  // Empty price sorts last even when descending.
  assert.deepEqual(names(applyState(data, index, state)), ['Café au lait', 'Cafe bombon']);
  const asc = { ...state, sort: { key: 'price', dir: 'asc' } };
  assert.deepEqual(names(applyState(data, index, asc)), ['Café au lait', 'Cafe bombon']);
});

test('numeric sort is numeric, text sort is natural', () => {
  const byPrice = applyState(data, index, { q: '', filters: { category: 'Other' }, sort: { key: 'price', dir: 'asc' } });
  assert.deepEqual(names(byPrice), ['Item 9', 'Item 10']);
  const byName = applyState(data, index, { q: 'item', filters: {}, sort: { key: 'item', dir: 'asc' } });
  assert.deepEqual(names(byName), ['Item 9', 'Item 10']);
});

test('date sort puts empty dates last', () => {
  const ids = applyState(data, index, { q: '', filters: {}, sort: { key: 'since', dir: 'asc' } });
  assert.equal(names(ids)[0], 'CAFÉ CAKE');
  assert.equal(names(ids).at(-1), 'Lemon tart');
  const desc = applyState(data, index, { q: '', filters: {}, sort: { key: 'since', dir: 'desc' } });
  assert.equal(names(desc)[0], 'Cafe bombon');
  assert.equal(names(desc).at(-1), 'Lemon tart');
});

test('compareValues handles empties and types', () => {
  assert.ok(compareValues(2, 10, 'number') < 0);
  assert.ok(compareValues('', 1, 'number') > 0);
  assert.ok(compareValues('éclair', 'Eclairs', 'text') < 0);
});

test('state round-trips through the URL hash', () => {
  const state = { q: 'cafe', filters: { category: 'Coffee' }, sort: { key: 'price', dir: 'desc' }, view: 'table' };
  const hash = serializeHash(state, data);
  assert.equal(hash, 'q=cafe&category=Coffee&sort=price&dir=desc&view=table');
  assert.deepEqual(parseHash('#' + hash, data), state);
  // Restored state produces the same result ("reload").
  assert.deepEqual(applyState(data, index, parseHash('#' + hash, data)), applyState(data, index, state));
});

test('hash encodes special characters safely', () => {
  const state = { q: 'a&b=c #1', filters: { category: 'Cakes & more' }, sort: null, view: 'cards' };
  const restored = parseHash('#' + serializeHash(state, data), data);
  assert.equal(restored.q, 'a&b=c #1');
  assert.equal(restored.filters.category, 'Cakes & more');
});

test('defaults are omitted from the hash; "sheet order" overrides a default sort', () => {
  assert.equal(serializeHash(defaultState(data), data), '');
  const sorted = { ...data, meta: { ...data.meta, sort: { key: 'item', dir: 'asc' } } };
  assert.equal(serializeHash(defaultState(sorted), sorted), '');
  const unsorted = { ...defaultState(sorted), sort: null };
  assert.equal(serializeHash(unsorted, sorted), 'sort=none');
  assert.equal(parseHash('#sort=none', sorted).sort, null);
});

test('unknown hash keys and bad values are ignored', () => {
  const s = parseHash('#sort=__proto__&view=evil&nope=1&price=3', data);
  assert.deepEqual(s, defaultState(data));
});

test('distinct values are sorted naturally', () => {
  assert.deepEqual(distinctValues(data.rows, 1), ['Cakes', 'Coffee', 'Other']);
});

test('safeHttpUrl only accepts http(s)', () => {
  assert.equal(safeHttpUrl('https://example.org/a'), 'https://example.org/a');
  assert.equal(safeHttpUrl('javascript:alert(1)'), null);
  assert.equal(safeHttpUrl(' JaVaScRiPt:alert(1)'), null);
  assert.equal(safeHttpUrl('data:text/html,x'), null);
  assert.equal(safeHttpUrl('//example.org'), null);
  assert.equal(safeHttpUrl(42), null);
});

test('formatValue uses Intl and never throws on bad config', () => {
  assert.equal(formatValue(3.2, 'currency', { locale: 'en', currency: 'EUR' }), '€3.20');
  assert.equal(formatValue(1234.5, 'number', { locale: 'en' }), '1,234.5');
  assert.equal(formatValue('2026-03-05', 'date', { locale: 'en-GB' }), '5 Mar 2026');
  assert.equal(formatValue(3, 'currency', { locale: 'en', currency: 'NOPE!' }), '3');
  assert.equal(formatValue(null, 'number'), '');
});

test('search over 5,000 rows stays well under 100 ms', () => {
  const big = { ...data, rows: [] };
  for (let i = 0; i < 5000; i++) big.rows.push([`Product ${i} Café`, ['A', 'B', 'C'][i % 3], i / 7, '2024-01-01']);
  const idx = buildIndex(big.rows);
  const t0 = performance.now();
  const ids = applyState(big, idx, { q: 'cafe 49', filters: { category: 'B' }, sort: { key: 'price', dir: 'desc' } });
  const ms = performance.now() - t0;
  assert.ok(ids.length > 0);
  assert.ok(ms < 100, `took ${ms.toFixed(1)} ms`);
});
