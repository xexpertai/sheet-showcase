// sheet-showcase core: pure search / filter / sort / URL-state helpers.
// No DOM access here, so it runs unchanged in Node (node --test) and browsers.
// Keep every export on a line starting with "export function" / "export const":
// the static exporter strips that keyword to inline this file.

/** Lowercase and strip accents: "Café" -> "cafe". */
export function fold(value) {
  return String(value ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase();
}

export const NUMERIC_TYPES = ['number', 'currency'];

/** True for an empty cell. */
export function isEmpty(v) {
  return v === null || v === undefined || v === '';
}

/** Returns the URL if it is an absolute http(s) URL, otherwise null. */
export function safeHttpUrl(value) {
  if (typeof value !== 'string') return null;
  const trimmed = value.trim();
  if (!/^https?:\/\//i.test(trimmed)) return null;
  try {
    const url = new URL(trimmed);
    return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
  } catch {
    return null;
  }
}

/**
 * Precomputes one folded search string per row (all visible columns).
 * `display(value, col)` optionally adds the formatted text (e.g. "€4.50").
 */
export function buildIndex(rows, display) {
  return rows.map((row) =>
    fold(
      row
        .map((v, i) => {
          if (isEmpty(v)) return '';
          const shown = display ? display(v, i) : '';
          return shown && shown !== String(v) ? `${v}\u0001${shown}` : String(v);
        })
        .join('\u0001'),
    ),
  );
}

/** Compares two cell values of a column type. Empty values sort last. */
export function compareValues(a, b, type) {
  const ea = isEmpty(a);
  const eb = isEmpty(b);
  if (ea || eb) return ea === eb ? 0 : ea ? 1 : -1;
  if (NUMERIC_TYPES.includes(type) && typeof a === 'number' && typeof b === 'number') {
    return a - b;
  }
  // ISO dates compare correctly as strings; text uses natural, accent-insensitive order.
  return collator.compare(type === 'date' ? String(a) : fold(a), type === 'date' ? String(b) : fold(b));
}

const collator = new Intl.Collator('en', { numeric: true, sensitivity: 'base' });

/**
 * Applies a state to the data and returns matching row indices in display order.
 *
 * state = { q: string, filters: { [columnKey]: value }, sort: { key, dir } | null }
 */
export function applyState(data, index, state) {
  const { columns, rows } = data;
  const terms = fold(state.q || '').split(/\s+/).filter(Boolean);
  const filters = Object.entries(state.filters || {})
    .filter(([, v]) => !isEmpty(v))
    .map(([key, value]) => [columns.findIndex((c) => c.key === key), String(value)])
    .filter(([i]) => i >= 0);

  const result = [];
  for (let r = 0; r < rows.length; r++) {
    const row = rows[r];
    if (!filters.every(([i, value]) => String(row[i] ?? '') === value)) continue;
    if (terms.length && !terms.every((t) => index[r].includes(t))) continue;
    result.push(r);
  }

  const sort = state.sort;
  const col = sort ? columns.findIndex((c) => c.key === sort.key) : -1;
  if (col >= 0) {
    const type = columns[col].type;
    const dir = sort.dir === 'desc' ? -1 : 1;
    result.sort((a, b) => {
      const va = rows[a][col];
      const vb = rows[b][col];
      // Empty values stay at the end in both directions; ties keep sheet order.
      if (isEmpty(va) || isEmpty(vb)) return compareValues(va, vb, type) || a - b;
      return compareValues(va, vb, type) * dir || a - b;
    });
  }
  return result;
}

/** Distinct non-empty values of a column, naturally sorted. */
export function distinctValues(rows, col) {
  const set = new Set();
  for (const row of rows) if (!isEmpty(row[col])) set.add(String(row[col]));
  return [...set].sort((a, b) => collator.compare(fold(a), fold(b)));
}

/** Default state for a payload (no search, no filters, configured sort). */
export function defaultState(data) {
  const s = data.meta.sort;
  return {
    q: '',
    filters: {},
    sort: s && s.key ? { key: s.key, dir: s.dir === 'desc' ? 'desc' : 'asc' } : null,
    view: data.meta.layout === 'table' ? 'table' : 'cards',
  };
}

/**
 * Reads state from a URL hash like "#q=cafe&category=Drinks&sort=price&dir=desc&view=table".
 * Unknown keys and invalid values are ignored.
 */
export function parseHash(hash, data) {
  const state = defaultState(data);
  const params = new URLSearchParams(String(hash || '').replace(/^#/, ''));
  const keys = new Set(data.columns.map((c) => c.key));
  const filterKeys = new Set(data.meta.filters || []);

  if (params.has('q')) state.q = params.get('q').slice(0, 200);
  for (const key of filterKeys) {
    if (params.has(key)) state.filters[key] = params.get(key);
  }
  if (params.has('sort')) {
    const key = params.get('sort');
    if (key === 'none') state.sort = null;
    else if (keys.has(key)) state.sort = { key, dir: params.get('dir') === 'desc' ? 'desc' : 'asc' };
  }
  const view = params.get('view');
  if (view === 'cards' || view === 'table') state.view = view;
  return state;
}

/** Serialises state to a hash (without "#"), omitting defaults. */
export function serializeHash(state, data) {
  const def = defaultState(data);
  const params = new URLSearchParams();
  if (state.q) params.set('q', state.q);
  for (const key of data.meta.filters || []) {
    const v = state.filters?.[key];
    if (!isEmpty(v)) params.set(key, v);
  }
  const same = (a, b) => (a === null && b === null) || (a && b && a.key === b.key && a.dir === b.dir);
  if (!same(state.sort, def.sort)) {
    if (state.sort) {
      params.set('sort', state.sort.key);
      params.set('dir', state.sort.dir);
    } else {
      params.set('sort', 'none');
    }
  }
  if (state.view && state.view !== def.view) params.set('view', state.view);
  return params.toString();
}

const formatterCache = new Map();

/** Intl formatters are expensive to create, so build each one once. */
function cachedFormatter(kind, locale, options) {
  const key = `${kind}|${locale}|${JSON.stringify(options)}`;
  let f = formatterCache.get(key);
  if (!f) {
    f = kind === 'date' ? new Intl.DateTimeFormat(locale, options) : new Intl.NumberFormat(locale, options);
    formatterCache.set(key, f);
  }
  return f;
}

/** Formats a value for display. Pure (Intl only). */
export function formatValue(value, type, { locale = 'en', currency = 'USD' } = {}) {
  if (isEmpty(value)) return '';
  try {
    if (type === 'number' && typeof value === 'number') {
      return cachedFormatter('number', locale, { maximumFractionDigits: 3 }).format(value);
    }
    if (type === 'currency' && typeof value === 'number') {
      return cachedFormatter('number', locale, { style: 'currency', currency }).format(value);
    }
    if (type === 'date' && typeof value === 'string') {
      const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(value);
      if (m) {
        const d = new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0));
        const opts = m[4]
          ? { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }
          : { year: 'numeric', month: 'short', day: 'numeric' };
        return cachedFormatter('date', locale, opts).format(d);
      }
    }
  } catch {
    // Bad locale/currency in config: fall back to the raw value.
  }
  return String(value);
}

/** "https://www.example.com/a/b?x" -> "example.com/a/b" */
export function shortUrl(href) {
  try {
    const u = new URL(href);
    const s = u.hostname.replace(/^www\./, '') + u.pathname.replace(/\/$/, '');
    return s.length > 40 ? s.slice(0, 39) + '…' : s;
  } catch {
    return href;
  }
}

/** Calls fn after `ms` of inactivity. */
export function debounce(fn, ms) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), ms);
  };
}
