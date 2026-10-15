# SPEC: sheet-showcase

## Problem
Small businesses, clubs and NGOs keep their product lists, member directories, price lists and event schedules in Excel. They want this on a website, but they don't want a CMS, a database or a developer every time a row changes. sheet-showcase takes the sheet and makes a clean, mobile-friendly, searchable page from it. The page can run on any cheap PHP host, or be exported as one HTML file that can be uploaded anywhere (including WordPress media or GitHub Pages).

## Scope (must have)
- **Stack:** PHP 8.1+ with no Composer runtime dependencies (only ext-zip, ext-xml and ext-mbstring), plus vanilla HTML, CSS and JavaScript. No frameworks, no build step, no database. Composer is allowed for dev-only PHPUnit.
- **Input:** `.csv` (delimiter auto-detected among `,` `;` tab `|`; UTF-8 with or without a BOM; fallback conversion from Windows-1252) and `.xlsx` (first sheet only, parsed with ZipArchive + XMLReader; handles shared strings, inline strings, numbers, booleans, and dates stored as serial numbers when the cell style is a date format).
- **Config:** an optional `showcase.json` placed next to the data file. Fields: `title`, `subtitle`, `columns` (order, labels, hidden), `type` per column (`text|number|currency|date|url|email|image|tag`), `filters` (list of columns), `sort_default`, `theme` (`light|dark|auto`), `accent_color`, `layout` (`cards|table`), `currency`, `locale`. If no config is given, sensible defaults are inferred from the data (column types are auto-detected from the first 200 rows).
- **Live mode:** `public/index.php?sheet=<name>` renders a sheet from the `data/` folder. Names are whitelisted (`[a-z0-9_-]+`), so path traversal is impossible.
- **Static export:** `php bin/showcase export data/menu.xlsx -o dist/menu.html` writes ONE self-contained HTML file with CSS and JS inlined, the data embedded as JSON, and no network requests.
- **UI:**
  - Card layout and table layout, with a toggle between them.
  - Instant full-text search (debounced at 150 ms; accent- and case-insensitive).
  - A dropdown or chip filter per `filters` column, populated with that column's distinct values.
  - Sort by any visible column (numeric, date and text aware).
  - A result count and a "clear filters" control.
  - The URL hash reflects the search, filter and sort state (shareable links).
  - Pagination or "load more" at 50 items.
- **Design:**
  - Mobile-first CSS using custom properties for theming. Breakpoints at 600 px and 1024 px.
  - Dark mode via `prefers-color-scheme` when the theme is `auto`.
  - A print stylesheet: plain table, no controls.
- **Accessibility:** semantic HTML, labelled controls, a visible focus ring, the table uses `<th scope>`, colour contrast ≥ 4.5:1 for the default and dark themes, everything usable with the keyboard alone, and result-count changes announced via `aria-live`.
- **Security:**
  - All cell output is HTML-escaped (`htmlspecialchars` on the server; `textContent` on the client, never `innerHTML` with data).
  - `url` and `image` columns only allow `http`/`https` URLs.
  - Formula cells are never evaluated.
  - The JSON embedded in the static export is escaped so it cannot break out of `</script>`.
  - Uploads are disabled. The app only reads files the owner puts in `data/`.
- **Samples:** 3 fictional, clearly labelled sample datasets in `data/`: a cafe menu (xlsx), a club member directory with fake names (csv `;`), and an event schedule (csv with dates).

## Out of scope
Editing data in the browser, logins, a database, multiple sheets per workbook, formula evaluation, and image uploads.

## Acceptance criteria
1. `php -S localhost:8000 -t public` then opening `/?sheet=cafe-menu` shows the sample menu as cards, with no PHP warnings or notices (`error_reporting(E_ALL)`).
2. `php bin/showcase export data/cafe-menu.xlsx -o dist/cafe-menu.html` produces a single file. Opened offline (network disabled in DevTools), it works the same as live mode and makes zero network requests.
3. The CSV parser correctly reads all 4 delimiters, BOM and non-BOM UTF-8 files, a Windows-1252 file with `é`/`€`, quoted fields with embedded commas and newlines, and ragged rows (missing cells become empty). Each case is covered by a PHPUnit test with a fixture.
4. The XLSX reader returns the same rows as a CSV export of the same fixture (shared strings, numbers, booleans, an empty cell in the middle of a row, and a date cell → ISO `YYYY-MM-DD`). Covered by PHPUnit.
5. Type inference: on the fixtures, columns of numbers, dates, URLs, emails and low-cardinality text (≤ 12 distinct values → `tag`) are detected correctly. Covered by PHPUnit.
6. XSS: a fixture containing `<script>alert(1)</script>`, `"><img src=x onerror=alert(1)>`, a `javascript:` URL and the string `</script>` renders as inert text in both live and export modes. Verified by a PHPUnit check on the server HTML and by a Playwright (or plain headless-browser) test that confirms no dialog fires.
7. Path traversal: `?sheet=../../etc/passwd` and `?sheet=..%2Fsecret` return HTTP 404 with a generic message.
8. Search, filters and sort combine correctly: searching "cafe" matches "Café"; filter + search + sort persists in the URL hash and is restored on reload. Covered by a small JS test (Node `node:test` against the pure filter/sort module).
9. With 5,000 rows, the export file is ≤ 2 MB, first render is < 1 s, and the search updates in < 100 ms on a mid-range laptop (documented measurement in the README).
10. Lighthouse on the exported sample (mobile): Accessibility ≥ 95, Best Practices ≥ 95, Performance ≥ 90. Scores recorded in the README.
11. The layout has no horizontal scroll at 360 px, 768 px or 1280 px widths. Screenshots of the light card view, the dark table view and mobile are in `docs/screenshots/`.
12. `composer test` (PHPUnit) and `node --test` both pass. A GitHub Actions workflow runs both on PHP 8.1 and 8.3.
13. README covers: what it is, a 60-second quick start, a config reference table, deploy notes (shared PHP hosting, static hosting), security notes, a statement that the sample data is fictional, an AI-assistance disclosure, and the MIT licence.

## Suggested structure
```
bin/showcase          # CLI: export, inspect (prints inferred schema)
src/CsvReader.php  src/XlsxReader.php  src/TypeInferer.php  src/Renderer.php  src/Exporter.php
public/index.php  public/assets/app.css  public/assets/app.js (ES module, pure core in core.js)
templates/page.php
data/ (samples + showcase.json)   tests/php/  tests/js/  docs/screenshots/
```

## Time budget (~5 h)
Readers + inference: 1.5 h · renderer + export: 1 h · CSS/design + a11y: 1.5 h · JS search/filter/sort: 0.5 h · tests, CI, README: 0.5 h.