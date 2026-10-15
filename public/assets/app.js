// sheet-showcase UI: wires the pure core to the DOM.
// Security: data is only ever written with textContent / attribute setters,
// never innerHTML. Links/images only accept absolute http(s) URLs.
import { applyState, buildIndex, debounce, defaultState, formatValue, isEmpty, NUMERIC_TYPES, parseHash, safeHttpUrl, serializeHash, shortUrl } from './core.js';

const SEARCH_DELAY_MS = 150;

const $ = (id) => document.getElementById(id);

function el(tag, attrs = {}, text) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v !== null && v !== undefined && v !== false) node.setAttribute(k, v === true ? '' : String(v));
  }
  if (text !== undefined) node.textContent = text;
  return node;
}

function init() {
  const source = $('showcase-data');
  if (!source) return;
  const data = JSON.parse(source.textContent);
  const { columns, rows, meta } = data;
  const fmtOpts = { locale: meta.locale, currency: meta.currency };
  const show = (v, i) => formatValue(v, columns[i].type, fmtOpts);
  const index = buildIndex(rows, show);

  const ui = {
    form: $('controls'),
    q: $('q'),
    sort: $('sort'),
    clear: $('clear'),
    status: $('status'),
    results: $('results'),
    more: $('more'),
    empty: $('empty'),
    filters: [...document.querySelectorAll('[data-filter]')],
    views: [...document.querySelectorAll('[data-view]')],
  };

  let state = parseHash(location.hash, data);
  let matches = [];
  let limit = meta.pageSize;

  // ---------- rendering ----------

  function cell(i, value, title) {
    const type = columns[i].type;
    const text = show(value, i);
    if (text === '') return el('span', { class: 'empty', 'aria-label': 'empty' }, '–');
    if (type === 'url') {
      const href = safeHttpUrl(text);
      if (href) return el('a', { href, rel: 'noopener noreferrer nofollow' }, shortUrl(href));
    } else if (type === 'email') {
      if (/^[^\s@<>"]+@[^\s@<>"]+\.[^\s@<>"]+$/.test(text)) return el('a', { href: `mailto:${text}` }, text);
    } else if (type === 'image') {
      const src = safeHttpUrl(text);
      if (src) return el('img', { src, alt: title, loading: 'lazy', decoding: 'async', referrerpolicy: 'no-referrer' });
    } else if (type === 'tag') {
      return el('span', { class: 'tag' }, text);
    } else if (type === 'date' && /^\d{4}-\d{2}-\d{2}/.test(String(value))) {
      return el('time', { datetime: String(value).replace(' ', 'T') }, text);
    }
    return document.createTextNode(text);
  }

  function renderCards(list) {
    const ul = el('ul', { class: 'cards', role: 'list' });
    const t = meta.titleColumn;
    const img = meta.imageColumn;
    for (const r of list) {
      const row = rows[r];
      const title = show(row[t], t);
      const li = el('li', { class: 'card' });
      if (img !== null && safeHttpUrl(String(row[img] ?? ''))) {
        const media = el('div', { class: 'card-media' });
        media.append(cell(img, row[img], title));
        li.append(media);
      }
      li.append(el('h3', { class: 'card-title' }, title || '–'));
      const dl = el('dl', { class: 'card-fields' });
      columns.forEach((c, i) => {
        if (i === t || i === img || isEmpty(row[i])) return;
        const kv = el('div', { class: NUMERIC_TYPES.includes(c.type) ? 'kv num' : 'kv' });
        const dd = el('dd');
        dd.append(cell(i, row[i], title));
        kv.append(el('dt', {}, c.label), dd);
        dl.append(kv);
      });
      li.append(dl);
      ul.append(li);
    }
    return ul;
  }

  function renderTable(list, interactive = true) {
    const wrap = el('div', { class: 'table-wrap', role: 'region', 'aria-labelledby': 'table-caption', tabindex: '0' });
    const table = el('table');
    table.append(el('caption', { id: 'table-caption' }, document.querySelector('h1')?.textContent ?? ''));
    const tr = el('tr');
    columns.forEach((c) => {
      const numeric = NUMERIC_TYPES.includes(c.type);
      const sorted = state.sort && state.sort.key === c.key;
      const th = el('th', {
        scope: 'col',
        class: numeric ? 'num' : null,
        'aria-sort': sorted ? (state.sort.dir === 'desc' ? 'descending' : 'ascending') : null,
      });
      if (interactive && c.type !== 'image') {
        const btn = el('button', { type: 'button', class: 'th-sort', 'data-sort': c.key }, c.label);
        btn.append(el('span', { class: 'sort-icon', 'aria-hidden': 'true' }, sorted ? (state.sort.dir === 'desc' ? '▼' : '▲') : '↕'));
        th.append(btn);
      } else {
        th.textContent = c.label;
      }
      tr.append(th);
    });
    const thead = el('thead');
    thead.append(tr);
    const tbody = el('tbody');
    const t = meta.titleColumn;
    for (const r of list) {
      const row = rows[r];
      const title = show(row[t], t);
      const rtr = el('tr');
      columns.forEach((c, i) => {
        const td = el(i === t ? 'th' : 'td', {
          scope: i === t ? 'row' : null,
          class: NUMERIC_TYPES.includes(c.type) ? 'num' : null,
        });
        td.append(cell(i, row[i], title));
        rtr.append(td);
      });
      tbody.append(rtr);
    }
    table.append(thead, tbody);
    wrap.append(table);
    return wrap;
  }

  function render() {
    // User Timing marks let you measure render cost in DevTools (see README).
    performance.mark('showcase:render-start');
    matches = applyState(data, index, state);
    const visible = matches.slice(0, limit);
    const view = state.view === 'table' ? renderTable(visible) : renderCards(visible);
    ui.results.dataset.layout = state.view;
    // Keep the heading, replace the list.
    ui.results.querySelectorAll('.cards, .table-wrap').forEach((n) => n.remove());
    ui.empty.hidden = matches.length > 0;
    if (matches.length) ui.results.insertBefore(view, ui.empty);
    ui.more.hidden = matches.length <= limit;

    const filtered = Boolean(state.q) || Object.values(state.filters).some((v) => !isEmpty(v));
    const noun = matches.length === 1 ? 'item' : 'items';
    ui.status.textContent = matches.length
      ? `Showing ${visible.length} of ${matches.length} ${filtered ? `matching ${noun} (${rows.length} total)` : noun}`
      : `No matching items (${rows.length} total)`;
    ui.clear.disabled = !filtered && sameSort(state.sort, defaultState(data).sort);
    performance.measure('showcase:render', 'showcase:render-start');
  }

  const sameSort = (a, b) => (a === null && b === null) || (a && b && a.key === b.key && a.dir === b.dir);

  function syncControls() {
    ui.q.value = state.q;
    for (const sel of ui.filters) {
      const v = state.filters[sel.dataset.filter] ?? '';
      sel.value = [...sel.options].some((o) => o.value === v) ? v : '';
    }
    ui.sort.value = state.sort ? `${state.sort.key}:${state.sort.dir}` : '';
    for (const b of ui.views) b.setAttribute('aria-pressed', String(b.dataset.view === state.view));
  }

  function writeHash() {
    const hash = serializeHash(state, data);
    const url = location.pathname + location.search + (hash ? `#${hash}` : '');
    history.replaceState(null, '', url);
  }

  function update({ resetLimit = true } = {}) {
    if (resetLimit) limit = meta.pageSize;
    writeHash();
    render();
  }

  // ---------- events ----------

  ui.form.addEventListener('submit', (e) => e.preventDefault());

  const onSearch = debounce(() => {
    state.q = ui.q.value.trim();
    update();
  }, SEARCH_DELAY_MS);
  ui.q.addEventListener('input', onSearch);

  for (const sel of ui.filters) {
    sel.addEventListener('change', () => {
      state.filters[sel.dataset.filter] = sel.value;
      update();
    });
  }

  ui.sort.addEventListener('change', () => {
    const [key, dir] = ui.sort.value.split(':');
    state.sort = key ? { key, dir: dir === 'desc' ? 'desc' : 'asc' } : null;
    update();
  });

  // Table header buttons (event delegation; the table is re-rendered often).
  ui.results.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-sort]');
    if (!btn) return;
    const key = btn.dataset.sort;
    const dir = state.sort && state.sort.key === key && state.sort.dir === 'asc' ? 'desc' : 'asc';
    state.sort = { key, dir };
    syncControls();
    update();
    ui.results.querySelector(`button[data-sort="${CSS.escape(key)}"]`)?.focus();
  });

  for (const b of ui.views) {
    b.addEventListener('click', () => {
      state.view = b.dataset.view;
      syncControls();
      update({ resetLimit: false });
    });
  }

  ui.clear.addEventListener('click', () => {
    const view = state.view;
    state = { ...defaultState(data), view };
    syncControls();
    update();
    ui.q.focus();
  });

  ui.more.addEventListener('click', () => {
    const first = limit;
    limit += meta.pageSize;
    render();
    // Move focus to the first newly shown item so keyboard users continue there.
    const items = ui.results.querySelectorAll('.card, tbody tr');
    const target = items[first];
    if (target) {
      target.setAttribute('tabindex', '-1');
      target.focus();
    }
  });

  window.addEventListener('hashchange', () => {
    state = parseHash(location.hash, data);
    syncControls();
    render();
  });

  // Print: a plain table with every matching row, no controls.
  window.addEventListener('beforeprint', () => {
    ui.results.querySelectorAll('.cards, .table-wrap').forEach((n) => n.remove());
    ui.results.insertBefore(renderTable(matches, false), ui.empty);
  });
  window.addEventListener('afterprint', render);

  ui.form.hidden = false;
  syncControls();
  render();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
