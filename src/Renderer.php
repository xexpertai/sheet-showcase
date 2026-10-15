<?php

declare(strict_types=1);

namespace SheetShowcase;

/**
 * Turns a Showcase into a complete HTML page.
 *
 * - live mode: CSS/JS are linked from public/assets/.
 * - inline mode (static export): CSS and JS are inlined, nothing is fetched.
 *
 * Every cell value goes through htmlspecialchars(); URLs are only turned into
 * links when they are absolute http(s) URLs. The embedded JSON is encoded with
 * JSON_HEX_TAG etc. so a cell containing "</script>" cannot end the block.
 */
final class Renderer
{
    public const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    private const LIGHT_SURFACE = '#eef1f5';
    private const DARK_SURFACE = '#1f2937';

    /** Content-Security-Policy computed by the last render() call. */
    public string $csp = '';

    private readonly string $assetsDir;

    /**
     * @param string      $root      Project root (contains templates/).
     * @param string|null $assetsDir Folder with app.css/app.js/core.js (default: <root>/public/assets).
     */
    public function __construct(
        private readonly string $root = __DIR__ . '/..',
        ?string $assetsDir = null,
    ) {
        $this->assetsDir = $assetsDir ?? $root . '/public/assets';
    }

    /**
     * @param bool   $inline    Inline CSS/JS (static export) instead of linking them.
     * @param string $assetBase URL prefix of public/assets/ in live mode.
     */
    public function render(Showcase $showcase, bool $inline = false, string $assetBase = 'assets/'): string
    {
        $themeCss = $this->themeCss($showcase->accent);
        $css = $inline ? $this->asset('app.css') : null;
        $js = $inline ? self::bundleJs($this->asset('core.js'), $this->asset('app.js')) : null;

        $styleHashes = [self::hash($themeCss)];
        if ($css !== null) {
            $styleHashes[] = self::hash($css);
        }
        $scriptSrc = $js !== null ? "'" . self::hash($js) . "'" : "'self'";
        $styleSrc = ($inline ? '' : "'self' ") . implode(' ', array_map(static fn (string $h): string => "'$h'", $styleHashes));
        $this->csp = "default-src 'none'; script-src $scriptSrc; style-src $styleSrc; "
            . "img-src http: https: data:; base-uri 'none'; form-action 'none'";

        $version = $inline ? '' : '?v=' . substr(md5($this->asset('app.css') . $this->asset('app.js') . $this->asset('core.js')), 0, 10);

        $vars = [
            's' => $showcase,
            'fmt' => new Formatter($showcase->locale, $showcase->currency),
            'renderer' => $this,
            'inline' => $inline,
            'css' => $css,
            'js' => $js,
            'themeCss' => $themeCss,
            'assetBase' => $assetBase,
            'version' => $version,
            'csp' => $this->csp,
            'dataJson' => json_encode($showcase->payload(), self::JSON_FLAGS),
            'initialRows' => array_slice($showcase->sortedRows(), 0, Showcase::PAGE_SIZE),
            'total' => count($showcase->rows),
        ];

        return self::template($this->root . '/templates/page.php', $vars);
    }

    public static function e(string|int|float|null $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * HTML for one cell value (shared by card and table views).
     */
    public function cell(Showcase $s, Formatter $fmt, int $col, string|int|float|null $value, string $title): string
    {
        $type = $s->columns[$col]->type;
        $text = $fmt->format($value, $type);
        if ($text === '') {
            return '<span class="empty" aria-label="empty">–</span>';
        }

        switch ($type) {
            case 'url':
                if (TypeInferer::isHttpUrl($text)) {
                    return '<a href="' . self::e($text) . '" rel="noopener noreferrer nofollow">' . self::e(self::shortUrl($text)) . '</a>';
                }
                break;
            case 'email':
                if (TypeInferer::isEmail($text)) {
                    return '<a href="mailto:' . self::e($text) . '">' . self::e($text) . '</a>';
                }
                break;
            case 'image':
                if (TypeInferer::isHttpUrl($text)) {
                    return '<img src="' . self::e($text) . '" alt="' . self::e($title) . '" loading="lazy" decoding="async" referrerpolicy="no-referrer">';
                }
                break;
            case 'tag':
                return '<span class="tag">' . self::e($text) . '</span>';
            case 'date':
                if (is_string($value) && TypeInferer::parseDate($value) !== null) {
                    return '<time datetime="' . self::e(str_replace(' ', 'T', $value)) . '">' . self::e($text) . '</time>';
                }
                break;
        }

        return self::e($text);
    }

    /** "https://www.example.com/a/b?x" -> "example.com/a/b" */
    public static function shortUrl(string $url): string
    {
        $p = parse_url($url);
        $host = preg_replace('/^www\./', '', (string) ($p['host'] ?? ''));
        $path = rtrim((string) ($p['path'] ?? ''), '/');
        $short = $host . $path;

        return mb_strlen($short) > 40 ? mb_substr($short, 0, 39) . '…' : $short;
    }

    /**
     * Joins core.js and app.js into one classic script for the static export.
     * core.js exports plain functions; app.js imports them with one import line.
     */
    public static function bundleJs(string $core, string $app): string
    {
        $core = (string) preg_replace('/^export\s+(?=(?:async\s+)?function|const|let|class)/m', '', $core);
        $app = (string) preg_replace('/^import\s[^;]+;\s*$/m', '', $app);
        $bundle = "(() => {\n'use strict';\n" . $core . "\n" . $app . "\n})();\n";
        if (stripos($bundle, '</script') !== false || preg_match('/^\s*(?:import|export)\s/m', $bundle)) {
            throw new ShowcaseException('JS assets cannot be inlined safely (unexpected import/export or </script>).');
        }

        return $bundle;
    }

    public static function hash(string $content): string
    {
        return 'sha256-' . base64_encode(hash('sha256', $content, true));
    }

    /**
     * CSS custom properties for the accent colour, adjusted for contrast.
     */
    public function themeCss(string $accent): string
    {
        $light = Color::readableOn($accent, self::LIGHT_SURFACE);
        $dark = Color::readableOn($accent, self::DARK_SURFACE);
        $lightVars = sprintf('--accent:%s;--on-accent:%s;', $light, Color::textOn($light));
        $darkVars = sprintf('--accent:%s;--on-accent:%s;', $dark, Color::textOn($dark));

        return ':root{' . $lightVars . '}'
            . '[data-theme=dark]{' . $darkVars . '}'
            . '@media (prefers-color-scheme:dark){[data-theme=auto]{' . $darkVars . '}}';
    }

    private function asset(string $name): string
    {
        $path = $this->assetsDir . '/' . $name;
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new ShowcaseException('Missing asset: ' . $name);
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function template(string $file, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $t) {
            ob_end_clean();
            throw $t;
        }

        return (string) ob_get_clean();
    }
}
