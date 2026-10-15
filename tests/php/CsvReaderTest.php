<?php

declare(strict_types=1);

namespace SheetShowcase\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SheetShowcase\CsvReader;

/** Acceptance criterion 3. */
final class CsvReaderTest extends TestCase
{
    private const EXPECTED = [
        ['Name', 'City', 'Price'],
        ['Crème brûlée', 'Paris', '4.50'],
        ['Pierogi', 'Kraków', '6'],
        ['Tapas', 'Sevilla', '12.25'],
    ];

    private static function fixture(string $name): string
    {
        return __DIR__ . '/fixtures/' . $name;
    }

    /** @return array<string, array{string, string}> */
    public static function delimiters(): array
    {
        return [
            'comma' => ['delim-comma.csv', ','],
            'semicolon' => ['delim-semicolon.csv', ';'],
            'tab' => ['delim-tab.csv', "\t"],
            'pipe' => ['delim-pipe.csv', '|'],
        ];
    }

    #[DataProvider('delimiters')]
    public function testDetectsEachDelimiter(string $file, string $delimiter): void
    {
        $text = (string) file_get_contents(self::fixture($file));
        self::assertSame($delimiter, CsvReader::detectDelimiter($text));
        self::assertSame(self::EXPECTED, (new CsvReader())->read(self::fixture($file)));
    }

    public function testUtf8WithBom(): void
    {
        $rows = (new CsvReader())->read(self::fixture('utf8-bom.csv'));
        self::assertSame(self::EXPECTED, $rows);
        self::assertSame('Name', $rows[0][0], 'BOM must not leak into the first header');
    }

    public function testUtf8WithoutBom(): void
    {
        self::assertSame(self::EXPECTED, (new CsvReader())->read(self::fixture('utf8-nobom.csv')));
    }

    public function testWindows1252FallbackConvertsAccentsAndEuro(): void
    {
        $rows = (new CsvReader())->read(self::fixture('cp1252.csv'));
        self::assertSame([
            ['Name', 'Price'],
            ['Café crème', '€ 3,50'],
            ['Thé', '€ 2,00'],
        ], $rows);
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                self::assertTrue(mb_check_encoding($cell, 'UTF-8'));
            }
        }
    }

    public function testQuotedFieldsWithCommasQuotesAndNewlines(): void
    {
        $rows = (new CsvReader())->read(self::fixture('quoted.csv'));
        self::assertSame([
            ['Name', 'Address', 'Note'],
            ['Smith, Jane', '12 High St, Springfield', "Line one\r\nLine two"],
            ['Plain', 'He said "hi"', ''],
            ["Multi\nline\nname", 'Somewhere', 'last'],
        ], $rows);
    }

    public function testRaggedRowsArePaddedAndBlankLinesSkipped(): void
    {
        $rows = (new CsvReader())->read(self::fixture('ragged.csv'));
        self::assertSame([
            ['A', 'B', 'C', ''],
            ['1', '2', '', ''],
            ['3', '', '', ''],
            ['4', '5', '6', ''],
            ['7', '8', '9', '10'],
        ], $rows);
    }

    public function testUtf16WithBomIsDecoded(): void
    {
        $utf16 = "\xFF\xFE" . mb_convert_encoding("Name\tCity\nZoë\tMünchen\n", 'UTF-16LE', 'UTF-8');
        self::assertSame([['Name', 'City'], ['Zoë', 'München']], (new CsvReader())->parseString($utf16));
    }

    public function testFormulaLikeCellsStayText(): void
    {
        $rows = (new CsvReader())->parseString("A,B\n=1+2,=HYPERLINK(\"x\")\n");
        self::assertSame(['=1+2', '=HYPERLINK("x")'], $rows[1]);
    }

    public function testSemicolonFileWithDecimalCommasPrefersSemicolon(): void
    {
        $text = "Item;Price;Qty\nTea;2,50;1\nCake;3,75;2\nBun;1,20;3\n";
        self::assertSame(';', CsvReader::detectDelimiter($text));
    }
}
