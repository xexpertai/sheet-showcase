<?php

declare(strict_types=1);

namespace SheetShowcase\Tests;

use PHPUnit\Framework\TestCase;
use SheetShowcase\Config;
use SheetShowcase\CsvReader;
use SheetShowcase\Exporter;
use SheetShowcase\Renderer;
use SheetShowcase\Showcase;

/** Acceptance criteria 2 and 6 (server side). */
final class RendererTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private static function xssHtml(bool $inline): string
    {
        return (new Renderer(self::ROOT))->render(Showcase::fromFile(__DIR__ . '/fixtures/xss.csv'), inline: $inline);
    }

    /**
     * Removes the JSON data block and the inlined JS so we only inspect markup.
     */
    private static function markupOnly(string $html): string
    {
        return (string) preg_replace('~<script\b[^>]*>.*?</script>~s', '<script></script>', $html);
    }

    /**
     * Parses HTML with DOMDocument and returns every element.
     *
     * @return list<\DOMElement>
     */
    private static function elements(string $html): array
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $out = [];
        foreach ($doc->getElementsByTagName('*') as $el) {
            $out[] = $el;
        }

        return $out;
    }

    public function testXssPayloadsAreInertInLiveAndExportHtml(): void
    {
        foreach ([false, true] as $inline) {
            $html = self::xssHtml($inline);
            $scripts = 0;
            foreach (self::elements($html) as $el) {
                $tag = strtolower($el->tagName);
                self::assertNotContains($tag, ['svg', 'iframe', 'object', 'embed'], "unexpected <$tag>");
                if ($tag === 'script') {
                    $scripts++;
                }
                foreach ($el->attributes as $attr) {
                    $name = strtolower($attr->name);
                    self::assertStringStartsNotWith('on', $name, "event handler attribute on <$tag>");
                    if (in_array($name, ['href', 'src'], true)) {
                        self::assertMatchesRegularExpression(
                            '~^(https?://|mailto:[^:]+@|assets/|data:,$|#[a-z-]+$)~i',
                            $attr->value,
                            "unsafe $name on <$tag>: {$attr->value}"
                        );
                    }
                }
            }
            // Exactly the scripts we emit: the JSON block plus app.js (linked or inline).
            self::assertSame(2, $scripts, 'no injected <script> elements');

            // The payloads are still visible to the reader, as escaped text.
            $markup = self::markupOnly($html);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $markup);
            self::assertStringContainsString('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $markup);
            self::assertStringContainsString('javascript:alert(1)', $markup, 'unsafe URL shown as plain text');
            self::assertStringContainsString('<title>&lt;script&gt;alert(&quot;title&quot;)&lt;/script&gt;</title>', $markup);
        }
    }

    public function testEmbeddedJsonCannotBreakOutOfItsScriptBlock(): void
    {
        $html = self::xssHtml(true);
        self::assertMatchesRegularExpression('~<script type="application/json" id="showcase-data">(.*?)</script>~s', $html);
        preg_match('~<script type="application/json" id="showcase-data">(.*?)</script>~s', $html, $m);
        $json = $m[1];
        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('>', $json);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('</script><script>alert(3)</script>', $data['rows'][0][4], 'value round-trips intact');
    }

    public function testOnlyHttpUrlsBecomeLinksAndImages(): void
    {
        $markup = self::markupOnly(self::xssHtml(false));
        self::assertStringContainsString('<a href="https://example.org/ok" rel="noopener noreferrer nofollow">example.org/ok</a>', $markup);
        // A URL with a quote-injection attempt is not a valid http URL: never an <img>.
        self::assertStringNotContainsString('<img', $markup);
        self::assertStringNotContainsString('mailto:bad', $markup);
    }

    public function testFormulaCellIsShownAsText(): void
    {
        $markup = self::markupOnly(self::xssHtml(false));
        self::assertStringContainsString('=HYPERLINK(&quot;http://evil.example&quot;,&quot;x&quot;)', $markup);
    }

    public function testExportIsOneSelfContainedFile(): void
    {
        $out = sys_get_temp_dir() . '/sheet-showcase-tests/export/cafe-menu.html';
        @unlink($out);
        $bytes = (new Exporter())->export(self::ROOT . '/data/cafe-menu.xlsx', $out);
        self::assertFileExists($out);
        $html = (string) file_get_contents($out);
        self::assertSame($bytes, strlen($html));

        // Nothing is fetched: no src/href pointing to files or the network.
        self::assertDoesNotMatchRegularExpression('~<(?:script|img|iframe)\b[^>]*\ssrc=~i', $html);
        self::assertDoesNotMatchRegularExpression('~<link\b(?![^>]*href="data:)[^>]*>~i', $html);
        self::assertStringNotContainsString('@import', $html);
        preg_match_all('~<style>(.*?)</style>~s', $html, $css);
        self::assertDoesNotMatchRegularExpression('~url\((?!data:)~i', implode('', $css[1]));
        self::assertStringNotContainsString('type="module"', $html);
        self::assertStringContainsString('Café au lait', $html);

        // CSP hashes cover exactly the inline script and styles.
        preg_match('~<script>(.*?)</script>~s', $html, $m);
        self::assertStringContainsString(Renderer::hash($m[1]), $html);
        preg_match_all('~<style>(.*?)</style>~s', $html, $styles);
        self::assertCount(2, $styles[1]);
        foreach ($styles[1] as $css) {
            self::assertStringContainsString(Renderer::hash($css), $html);
        }
    }

    public function testCafeMenuRendersAsCards(): void
    {
        $html = (new Renderer(self::ROOT))->render(Showcase::fromFile(self::ROOT . '/data/cafe-menu.xlsx'));
        self::assertStringContainsString('<ul class="cards" role="list">', $html);
        self::assertSame(26, substr_count($html, '<li class="card">'));
        self::assertStringContainsString('€3.20', $html);
        self::assertStringContainsString('Showing 26 of 26 items', $html);
    }

    public function testTableUsesScopedHeaders(): void
    {
        $rows = (new CsvReader())->parseString("Name,Qty\nA,1\nB,2\n");
        $s = Showcase::fromRows('t', $rows, Config::fromArray(['layout' => 'table']));
        $html = (new Renderer(self::ROOT))->render($s);
        self::assertStringContainsString('<th scope="col">Name</th>', $html);
        self::assertStringContainsString('<th scope="col" class="num">Qty</th>', $html);
        self::assertStringContainsString('<th scope="row">', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
    }

    public function testOnlyFirstPageIsServerRendered(): void
    {
        $rows = [['Name', 'N']];
        for ($i = 0; $i < 120; $i++) {
            $rows[] = ["Item $i", (string) $i];
        }
        $html = (new Renderer(self::ROOT))->render(Showcase::fromRows('big', $rows));
        self::assertSame(50, substr_count($html, '<li class="card">'));
        self::assertStringContainsString('Showing 50 of 120 items', $html);
        self::assertMatchesRegularExpression('~<button type="button" id="more" class="btn-primary">~', $html);
    }

    public function testHiddenColumnsNeverReachTheOutput(): void
    {
        $rows = [['Name', 'Secret phone'], ['Ann', '+1 555 0100']];
        $config = Config::fromArray(['columns' => [['name' => 'Secret phone', 'hidden' => true]]]);
        $html = (new Renderer(self::ROOT))->render(Showcase::fromRows('h', $rows, $config), inline: true);
        self::assertStringNotContainsString('555', $html);
        self::assertStringNotContainsString('Secret phone', $html);
    }

    public function testDefaultSortIsApplied(): void
    {
        $rows = [['Name', 'Price'], ['B', '2'], ['A', '3'], ['C', '1']];
        $s = Showcase::fromRows('s', $rows, Config::fromArray(['sort_default' => '-Price']));
        self::assertSame(['A', 'B', 'C'], array_column($s->sortedRows(), 0));
    }
}
