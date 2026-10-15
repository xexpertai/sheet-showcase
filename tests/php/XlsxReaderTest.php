<?php

declare(strict_types=1);

namespace SheetShowcase\Tests;

use PHPUnit\Framework\TestCase;
use SheetShowcase\CsvReader;
use SheetShowcase\ShowcaseException;
use SheetShowcase\Tools\XlsxWriter;
use SheetShowcase\XlsxReader;

/** Acceptance criterion 4. */
final class XlsxReaderTest extends TestCase
{
    private const FX = __DIR__ . '/fixtures/';

    public function testMatchesCsvExportOfTheSameSheet(): void
    {
        $xlsx = (new XlsxReader())->read(self::FX . 'roundtrip.xlsx');
        $csv = (new CsvReader())->read(self::FX . 'roundtrip.csv');
        self::assertSame($csv, $xlsx);
    }

    public function testCoversEachCellKind(): void
    {
        $rows = (new XlsxReader())->read(self::FX . 'roundtrip.xlsx');
        self::assertSame('Widget', $rows[1][0], 'shared string');
        self::assertSame('Gizmo', $rows[3][0], 'inline string');
        self::assertSame('4.5', $rows[1][2], 'decimal number');
        self::assertSame('12', $rows[2][2], 'whole float printed without .0');
        self::assertSame('TRUE', $rows[1][3], 'boolean true');
        self::assertSame('FALSE', $rows[2][3], 'boolean false');
        self::assertSame('', $rows[2][4], 'empty cell in the middle of a row');
        self::assertSame('2024-02-29', $rows[1][5], 'date-formatted serial -> ISO');
        self::assertSame('13', $rows[4][1], 'formula: cached value, never evaluated');
        self::assertSame('ünïcödé ✓', $rows[4][4]);
    }

    public function testSerialConversion(): void
    {
        self::assertSame('1900-01-01', XlsxReader::serialToIso(1));
        self::assertSame('1900-02-28', XlsxReader::serialToIso(59));
        self::assertSame('1900-03-01', XlsxReader::serialToIso(61));
        self::assertSame('2026-10-15', XlsxReader::serialToIso(46310));
        self::assertSame('2026-10-15 13:30', XlsxReader::serialToIso(46310.5625, 'datetime'));
        self::assertSame('2026-10-15', XlsxReader::serialToIso(46310, 'datetime'));
        self::assertSame('18:00', XlsxReader::serialToIso(0.75, 'time'));
        self::assertSame('1904-01-02', XlsxReader::serialToIso(1, 'date', true));
    }

    public function testDateFormatDetection(): void
    {
        self::assertSame('date', XlsxReader::formatKind(14, []));
        self::assertSame('datetime', XlsxReader::formatKind(22, []));
        self::assertSame('time', XlsxReader::formatKind(20, []));
        self::assertNull(XlsxReader::formatKind(2, []));
        self::assertSame('date', XlsxReader::customFormatKind('dd/mm/yyyy'));
        self::assertSame('date', XlsxReader::customFormatKind('[$-409]mmmm d, yyyy;@'));
        self::assertSame('datetime', XlsxReader::customFormatKind('yyyy-mm-dd hh:mm'));
        self::assertSame('time', XlsxReader::customFormatKind('h:mm AM/PM'));
        self::assertNull(XlsxReader::customFormatKind('#,##0.00 "days"'));
        self::assertNull(XlsxReader::customFormatKind('[Red]0.00'));
        self::assertNull(XlsxReader::customFormatKind('General'));
    }

    public function testNumberFormatting(): void
    {
        self::assertSame('0.3', XlsxReader::formatNumber('0.30000000000000004'));
        self::assertSame('1234567', XlsxReader::formatNumber('1234567'));
        self::assertSame('-2.75', XlsxReader::formatNumber('-2.75'));
    }

    public function testOnlyTheFirstSheetIsRead(): void
    {
        $path = self::tmp('two-sheets.xlsx');
        XlsxWriter::write($path, [['Only'], ['first']]);
        // Add a second sheet listed after the first one in workbook.xml.
        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->addFromString('xl/worksheets/sheet2.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>second</t></is></c></row></sheetData></worksheet>');
        $wb = (string) $zip->getFromName('xl/workbook.xml');
        $zip->addFromString('xl/workbook.xml', str_replace('</sheets>', '<sheet name="B" sheetId="2" r:id="rId9"/></sheets>', $wb));
        $zip->close();

        self::assertSame([['Only'], ['first']], (new XlsxReader())->read($path));
    }

    public function testExternalEntitiesAreNotResolved(): void
    {
        $secret = self::tmp('xxe-secret.txt');
        file_put_contents($secret, 'LEAKED');
        $path = self::tmp('xxe.xlsx');
        XlsxWriter::write($path, [['A'], ['placeholder']]);
        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file://' . $secret . '">]>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>&e;</t></si><si><t>placeholder</t></si></sst>');
        $zip->close();

        $rows = (new XlsxReader())->read($path);
        self::assertStringNotContainsString('LEAKED', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function testRejectsNonXlsx(): void
    {
        $this->expectException(ShowcaseException::class);
        (new XlsxReader())->read(self::FX . 'delim-comma.csv');
    }

    private static function tmp(string $name): string
    {
        $dir = sys_get_temp_dir() . '/sheet-showcase-tests';
        @mkdir($dir, 0775, true);

        return $dir . '/' . $name;
    }
}
