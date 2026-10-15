<?php

/**
 * Live mode: /index.php?sheet=<name> renders data/<name>.xlsx or data/<name>.csv.
 *
 * Only names matching [a-z0-9_-]+ are accepted; anything else is a 404.
 * The data directory can be overridden with the SHEET_SHOWCASE_DATA env var.
 */

declare(strict_types=1);

error_reporting(E_ALL);

// Folder that contains src/, templates/ and data/. If your host forces you to
// put this file in public_html/, point this at wherever you uploaded the rest.
$appRoot = __DIR__ . '/..';

require $appRoot . '/src/autoload.php';

use SheetShowcase\Library;
use SheetShowcase\Renderer;
use SheetShowcase\Showcase;
use SheetShowcase\ShowcaseException;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

/** Sends a small, generic error page (never echoes the request). */
function showcase_error(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>', $status, '</title></head>',
        '<body><main><h1>', $status === 404 ? 'Not found' : 'Something went wrong', '</h1><p>',
        htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '</p></main></body></html>';
    exit;
}

$dataDir = getenv('SHEET_SHOWCASE_DATA') ?: $appRoot . '/data';

try {
    $library = new Library($dataDir);
} catch (ShowcaseException) {
    showcase_error(500, 'The data folder is missing.');
}

$name = $_GET['sheet'] ?? null;

if ($name === null) {
    // No sheet requested: list what is available.
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; frame-ancestors 'none'");
    $items = '';
    foreach ($library->names() as $n) {
        $items .= '<li><a href="?sheet=' . rawurlencode($n) . '">' . Renderer::e($n) . '</a></li>';
    }
    echo '<!doctype html><html lang="en" data-theme="auto"><head><meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        '<title>Sheets</title><link rel="stylesheet" href="assets/app.css"></head><body>',
        '<header class="site-header"><div class="wrap"><h1>Sheets</h1></div></header>',
        '<main class="wrap"><ul class="sheet-list">', $items ?: '<li>No sheets in data/ yet.</li>', '</ul></main></body></html>';
    exit;
}

$path = $library->resolve($name);
if ($path === null) {
    showcase_error(404, 'This page does not exist.');
}

try {
    $renderer = new Renderer($appRoot, __DIR__ . '/assets');
    $html = $renderer->render(Showcase::fromFile($path), inline: false, assetBase: 'assets/');
} catch (ShowcaseException $e) {
    error_log('sheet-showcase: ' . $e->getMessage());
    showcase_error(500, 'This sheet could not be displayed. The site owner can run "php bin/showcase inspect" to see why.');
}

header('Content-Type: text/html; charset=utf-8');
header('Content-Security-Policy: ' . $renderer->csp . "; frame-ancestors 'none'");
header('Cache-Control: no-cache');
echo $html;
