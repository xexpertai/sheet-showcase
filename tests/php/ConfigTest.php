<?php

declare(strict_types=1);

namespace SheetShowcase\Tests;

use PHPUnit\Framework\TestCase;
use SheetShowcase\Color;
use SheetShowcase\Config;
use SheetShowcase\Library;
use SheetShowcase\Showcase;
use SheetShowcase\ShowcaseException;

final class ConfigTest extends TestCase
{
    public function testSharedConfigWithPerSheetOverrides(): void
    {
        $c = Config::forDataFile(__DIR__ . '/../../data/cafe-menu.xlsx');
        self::assertSame('Café Lumen — Menu', $c->title);
        self::assertSame('en-GB', $c->locale, 'shared default');
        self::assertSame('cards', $c->layout);
        self::assertSame('EUR', $c->currency);
    }

    public function testSheetSpecificFileWins(): void
    {
        $c = Config::forDataFile(__DIR__ . '/fixtures/xss.csv');
        self::assertSame(['Category'], $c->filters);
    }

    public function testDefaultsWithoutConfig(): void
    {
        $s = Showcase::fromRows('price-list_2026', [['Product', 'Price'], ['A', '1'], ['B', '2']]);
        self::assertSame('Price list 2026', $s->title);
        self::assertSame('auto', $s->theme);
        self::assertSame('cards', $s->layout);
        self::assertSame('en', $s->locale);
        self::assertSame(Showcase::DEFAULT_ACCENT, $s->accent);
    }

    public function testColumnsOrderLabelsAndTypes(): void
    {
        $config = Config::fromArray([
            'columns' => ['b', ['name' => 'A', 'label' => 'First', 'type' => 'tag']],
            'sort_default' => ['column' => 'b', 'direction' => 'desc'],
        ]);
        $s = Showcase::fromRows('x', [['A', 'B', 'C'], ['1', '2', '3']], $config);
        self::assertSame(['B', 'First', 'C'], array_map(static fn ($c) => $c->label, $s->columns));
        self::assertSame('tag', $s->columns[1]->type);
        self::assertSame('b', $s->sortKey);
        self::assertSame('desc', $s->sortDir);
    }

    public function testInvalidValuesAreRejectedWithClearMessages(): void
    {
        $cases = [
            [['theme' => 'neon'], '"theme" must be one of'],
            [['layout' => 'grid'], '"layout" must be one of'],
            [['accent_color' => 'red;}body{display:none'], 'accent_color'],
            [['currency' => 'EURO'], 'currency'],
            [['locale' => '"><script>'], 'locale'],
            [['columns' => [['name' => 'A', 'type' => 'html']]], 'Unknown type'],
        ];
        foreach ($cases as [$data, $message]) {
            try {
                Config::fromArray($data);
                self::fail('Expected exception for ' . json_encode($data));
            } catch (ShowcaseException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testUnknownColumnInConfigIsReported(): void
    {
        $this->expectException(ShowcaseException::class);
        $this->expectExceptionMessage('Column "Nope"');
        Showcase::fromRows('x', [['A'], ['1']], Config::fromArray(['columns' => ['Nope']]));
    }

    public function testDuplicateAndEmptyHeaders(): void
    {
        $s = Showcase::fromRows('x', [['Name', 'Name', ''], ['a', 'b', 'c']]);
        self::assertSame(['Name', 'Name (2)', 'Column 3'], array_map(static fn ($c) => $c->header, $s->columns));
        self::assertSame(['name', 'name-2', 'column-3'], array_map(static fn ($c) => $c->key, $s->columns));
    }

    public function testAccentIsAdjustedForContrast(): void
    {
        foreach (['#ffff00', '#1d4ed8', '#9a3412', '#000000', '#22c55e'] as $accent) {
            self::assertGreaterThanOrEqual(4.5, Color::contrast(Color::readableOn($accent, '#ffffff'), '#ffffff'));
            self::assertGreaterThanOrEqual(4.5, Color::contrast(Color::readableOn($accent, '#1f2937'), '#1f2937'));
        }
        self::assertEqualsWithDelta(21.0, Color::contrast('#000000', '#ffffff'), 0.01);
    }

    public function testLibraryWhitelist(): void
    {
        $lib = new Library(__DIR__ . '/../../data');
        self::assertSame(['cafe-menu', 'club-members', 'event-schedule'], $lib->names());
        self::assertNotNull($lib->resolve('cafe-menu'));
        foreach (['../secret', '..', '.', 'a/b', 'CAFE-MENU', "cafe-menu\0", '', null, ['x'], 'cafe-menu.xlsx'] as $bad) {
            self::assertNull($lib->resolve($bad));
        }
    }
}
