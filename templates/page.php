<?php
/**
 * Page template. Variables are provided by Renderer::render().
 *
 * @var \SheetShowcase\Showcase  $s
 * @var \SheetShowcase\Formatter $fmt
 * @var \SheetShowcase\Renderer  $renderer
 * @var bool        $inline
 * @var string|null $css
 * @var string|null $js
 * @var string      $themeCss
 * @var string      $assetBase
 * @var string      $version
 * @var string      $csp
 * @var string      $dataJson
 * @var list<list<string|int|float|null>> $initialRows
 * @var int         $total
 */

use SheetShowcase\Renderer;

$e = [Renderer::class, 'e'];
$titleCol = $s->titleColumnIndex();
$imageCol = $s->imageColumnIndex();
$shown = count($initialRows);
$noun = $total === 1 ? 'item' : 'items';
$sortLabels = static fn (string $type): array => match ($type) {
    'number', 'currency' => ['low to high', 'high to low'],
    'date' => ['oldest first', 'newest first'],
    default => ['A to Z', 'Z to A'],
};
$isNumeric = static fn (string $type): bool => in_array($type, ['number', 'currency'], true);
?>
<!doctype html>
<html lang="<?= $e($s->locale) ?>" data-theme="<?= $e($s->theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="<?= $e($csp) ?>">
<meta name="referrer" content="no-referrer">
<meta name="color-scheme" content="<?= $s->theme === 'auto' ? 'light dark' : $e($s->theme) ?>">
<title><?= $e($s->title) ?></title>
<meta name="description" content="<?= $e($s->subtitle !== '' ? $s->subtitle : $s->title) ?>">
<meta name="generator" content="sheet-showcase">
<link rel="icon" href="data:,">
<?php if ($inline): ?>
<style><?= $css ?></style>
<?php else: ?>
<link rel="stylesheet" href="<?= $e($assetBase . 'app.css' . $version) ?>">
<link rel="modulepreload" href="<?= $e($assetBase . 'core.js') ?>">
<?php endif ?>
<style><?= $themeCss ?></style>
</head>
<body>
<a class="skip-link" href="#results">Skip to results</a>
<header class="site-header">
  <div class="wrap">
    <h1><?= $e($s->title) ?></h1>
<?php if ($s->subtitle !== ''): ?>
    <p class="subtitle"><?= $e($s->subtitle) ?></p>
<?php endif ?>
  </div>
</header>
<main class="wrap" id="main">
  <form class="controls" id="controls" role="search" aria-label="Search and filter" hidden>
    <div class="field field-search">
      <label for="q">Search</label>
      <input type="search" id="q" name="q" autocomplete="off" spellcheck="false" placeholder="Type to search…">
    </div>
<?php foreach ($s->filters as $key): $col = $s->column($key); if ($col === null) { continue; } ?>
    <div class="field">
      <label for="f-<?= $e($key) ?>"><?= $e($col->label) ?></label>
      <select id="f-<?= $e($key) ?>" data-filter="<?= $e($key) ?>">
        <option value="">All</option>
<?php foreach ($s->distinctValues($key) as $v): ?>
        <option value="<?= $e($v) ?>"><?= $e($fmt->format($v, $col->type === 'tag' ? 'text' : $col->type)) ?></option>
<?php endforeach ?>
      </select>
    </div>
<?php endforeach ?>
    <div class="field">
      <label for="sort">Sort by</label>
      <select id="sort">
        <option value="">Sheet order</option>
<?php foreach ($s->columns as $col): if ($col->type === 'image') { continue; } [$asc, $desc] = $sortLabels($col->type); ?>
        <option value="<?= $e($col->key) ?>:asc"><?= $e($col->label . ' (' . $asc . ')') ?></option>
        <option value="<?= $e($col->key) ?>:desc"><?= $e($col->label . ' (' . $desc . ')') ?></option>
<?php endforeach ?>
      </select>
    </div>
    <div class="field field-actions">
      <span class="label" id="view-label">View</span>
      <div class="actions">
        <div class="segmented" role="group" aria-labelledby="view-label">
          <button type="button" data-view="cards" aria-pressed="<?= $s->layout === 'cards' ? 'true' : 'false' ?>">Cards</button>
          <button type="button" data-view="table" aria-pressed="<?= $s->layout === 'table' ? 'true' : 'false' ?>">Table</button>
        </div>
        <button type="button" id="clear" class="btn-secondary">Clear filters</button>
      </div>
    </div>
  </form>

  <p class="status" id="status" role="status" aria-live="polite" aria-atomic="true">Showing <?= $shown ?> of <?= $total ?> <?= $noun ?></p>

  <section id="results" class="results" aria-labelledby="results-heading" tabindex="-1" data-layout="<?= $e($s->layout) ?>">
    <h2 id="results-heading" class="visually-hidden">Results</h2>
<?php if ($s->layout === 'cards'): ?>
    <ul class="cards" role="list">
<?php foreach ($initialRows as $row): $title = $fmt->format($row[$titleCol], $s->columns[$titleCol]->type); ?>
      <li class="card">
<?php if ($imageCol !== null && $row[$imageCol] !== '' && \SheetShowcase\TypeInferer::isHttpUrl((string) $row[$imageCol])): ?>
        <div class="card-media"><?= $renderer->cell($s, $fmt, $imageCol, $row[$imageCol], $title) ?></div>
<?php endif ?>
        <h3 class="card-title"><?= $title === '' ? '–' : $e($title) ?></h3>
        <dl class="card-fields">
<?php foreach ($s->columns as $i => $col): if ($i === $titleCol || $i === $imageCol || $row[$i] === null || $row[$i] === '') { continue; } ?>
          <div class="kv<?= $isNumeric($col->type) ? ' num' : '' ?>"><dt><?= $e($col->label) ?></dt><dd><?= $renderer->cell($s, $fmt, $i, $row[$i], $title) ?></dd></div>
<?php endforeach ?>
        </dl>
      </li>
<?php endforeach ?>
    </ul>
<?php else: ?>
    <div class="table-wrap" role="region" aria-labelledby="table-caption" tabindex="0">
      <table>
        <caption id="table-caption"><?= $e($s->title) ?></caption>
        <thead>
          <tr>
<?php foreach ($s->columns as $col): ?>
            <th scope="col"<?= $isNumeric($col->type) ? ' class="num"' : '' ?>><?= $e($col->label) ?></th>
<?php endforeach ?>
          </tr>
        </thead>
        <tbody>
<?php foreach ($initialRows as $row): $title = $fmt->format($row[$titleCol], $s->columns[$titleCol]->type); ?>
          <tr>
<?php foreach ($s->columns as $i => $col): $tag = $i === $titleCol ? 'th scope="row"' : 'td'; ?>
            <<?= $tag ?><?= $isNumeric($col->type) ? ' class="num"' : '' ?>><?= $renderer->cell($s, $fmt, $i, $row[$i], $title) ?></<?= $i === $titleCol ? 'th' : 'td' ?>>
<?php endforeach ?>
          </tr>
<?php endforeach ?>
        </tbody>
      </table>
    </div>
<?php endif ?>
    <p class="empty-state" id="empty" hidden>No matching items. Try a different search or clear the filters.</p>
  </section>

  <div class="more">
    <button type="button" id="more" class="btn-primary"<?= $total > $shown ? '' : ' hidden' ?>>Load more</button>
  </div>
<?php if ($total > $shown): ?>
  <noscript><p class="status">JavaScript is off: showing the first <?= $shown ?> items.</p></noscript>
<?php endif ?>
</main>
<footer class="site-footer">
  <div class="wrap"><p>Made from a spreadsheet with sheet-showcase.</p></div>
</footer>
<script type="application/json" id="showcase-data"><?= $dataJson ?></script>
<?php if ($inline): ?>
<script><?= $js ?></script>
<?php else: ?>
<script type="module" src="<?= $e($assetBase . 'app.js' . $version) ?>"></script>
<?php endif ?>
</body>
</html>
