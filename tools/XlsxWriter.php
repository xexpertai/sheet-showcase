<?php

declare(strict_types=1);

namespace SheetShowcase\Tools;

/**
 * Dev-only helper that writes small .xlsx files for samples and test fixtures.
 * Not used at runtime.
 *
 * Cell values:
 *   string            -> shared string
 *   int|float         -> number
 *   bool              -> boolean
 *   null              -> no cell at all (a real gap in the row)
 *   ['date', 'Y-m-d'] -> serial number with a date number format
 *   ['inline', 's']   -> inline string
 *   ['formula', 'A1+1', cached] -> formula with a cached value
 */
final class XlsxWriter
{
    /**
     * @param list<list<mixed>> $rows
     */
    public static function write(string $path, array $rows, string $sheetName = 'Sheet1'): void
    {
        $shared = [];
        $sharedIndex = [];
        $sheetRows = '';
        foreach ($rows as $r => $row) {
            $cells = '';
            foreach ($row as $c => $value) {
                if ($value === null) {
                    continue;
                }
                $ref = self::col($c) . ($r + 1);
                if (is_bool($value)) {
                    $cells .= sprintf('<c r="%s" t="b"><v>%d</v></c>', $ref, $value ? 1 : 0);
                } elseif (is_int($value) || is_float($value)) {
                    $cells .= sprintf('<c r="%s"><v>%s</v></c>', $ref, var_export($value, true));
                } elseif (is_array($value) && $value[0] === 'date') {
                    $serial = self::serial($value[1]);
                    $cells .= sprintf('<c r="%s" s="1"><v>%s</v></c>', $ref, $serial);
                } elseif (is_array($value) && $value[0] === 'inline') {
                    $cells .= sprintf('<c r="%s" t="inlineStr"><is><t>%s</t></is></c>', $ref, self::x($value[1]));
                } elseif (is_array($value) && $value[0] === 'formula') {
                    $cells .= sprintf('<c r="%s"><f>%s</f><v>%s</v></c>', $ref, self::x($value[1]), self::x((string) $value[2]));
                } else {
                    $s = (string) $value;
                    if (!isset($sharedIndex[$s])) {
                        $sharedIndex[$s] = count($shared);
                        $shared[] = $s;
                    }
                    $cells .= sprintf('<c r="%s" t="s"><v>%d</v></c>', $ref, $sharedIndex[$s]);
                }
            }
            $sheetRows .= sprintf('<row r="%d">%s</row>', $r + 1, $cells);
        }

        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rns = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $sst = '';
        foreach ($shared as $s) {
            $sst .= '<si><t xml:space="preserve">' . self::x($s) . '</t></si>';
        }

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . "<workbook xmlns=\"$ns\" xmlns:r=\"$rns\"><sheets>"
                . '<sheet name="' . self::x($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
                . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . "<styleSheet xmlns=\"$ns\">"
                . '<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/></numFmts>'
                . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
                . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
                . '<borders count="1"><border/></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
                . '</styleSheet>',
            'xl/sharedStrings.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . "<sst xmlns=\"$ns\" count=\"" . count($shared) . '" uniqueCount="' . count($shared) . "\">$sst</sst>",
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . "<worksheet xmlns=\"$ns\" xmlns:r=\"$rns\"><sheetData>$sheetRows</sheetData></worksheet>",
        ];

        if (is_file($path)) {
            unlink($path);
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create $path");
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
            // Fixed timestamps keep the generated files reproducible.
            $zip->setMtimeName($name, 1700000000);
        }
        $zip->close();
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }

        return $s;
    }

    private static function serial(string $ymd): int
    {
        $base = new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC'));
        $d = new \DateTimeImmutable($ymd, new \DateTimeZone('UTC'));

        return (int) $base->diff($d)->days;
    }

    private static function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
