<?php

declare(strict_types=1);

namespace SheetShowcase;

/**
 * Minimal, dependency-free reader for the first worksheet of an .xlsx file.
 *
 * Uses only ZipArchive + XMLReader (streaming), so memory stays low and no
 * external entities are ever loaded. Formulas are NEVER evaluated: for a
 * formula cell we use the cached result Excel stored in the file.
 *
 * Values are returned as strings shaped like Excel's own "Save as CSV":
 * numbers in plain notation, booleans as TRUE/FALSE, and date-formatted
 * serial numbers as ISO `YYYY-MM-DD` (or `YYYY-MM-DD HH:MM` with a time).
 */
final class XlsxReader
{
    /** Refuse to inflate any single XML part beyond this (zip-bomb guard). */
    private const MAX_PART_BYTES = 64 * 1024 * 1024;

    /** Built-in Excel number formats that are dates/times. */
    private const BUILTIN_DATE_FORMATS = [
        14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36,
        45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58,
    ];

    private const BUILTIN_TIME_ONLY = [18, 19, 20, 21, 45, 46, 47];

    /**
     * @return list<list<string>> All rows of the first sheet (header row first).
     */
    public function read(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new ShowcaseException('The PHP zip extension is required to read .xlsx files.');
        }
        $zip = new \ZipArchive();
        if (!is_file($path) || $zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new ShowcaseException('Not a readable .xlsx file: ' . basename($path));
        }

        // Collect libxml errors instead of emitting PHP warnings.
        $previousLibxml = libxml_use_internal_errors(true);
        try {
            $workbook = $this->part($zip, 'xl/workbook.xml');
            if ($workbook === null) {
                throw new ShowcaseException('Not a valid .xlsx file (no workbook): ' . basename($path));
            }
            $date1904 = (bool) preg_match('/<(?:\w+:)?workbookPr\b[^>]*\bdate1904="(?:1|true)"/', $workbook);
            $sheetPath = $this->firstSheetPath($zip, $workbook);
            $sharedStrings = $this->sharedStrings($this->part($zip, 'xl/sharedStrings.xml'));
            $styles = $this->dateStyles($this->part($zip, 'xl/styles.xml'));
            $sheetXml = $this->part($zip, $sheetPath);
            if ($sheetXml === null) {
                throw new ShowcaseException('The first worksheet is missing in ' . basename($path));
            }

            $rows = $this->sheetRows($sheetXml, $sharedStrings, $styles, $date1904);
        } finally {
            $zip->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxml);
        }

        return CsvReader::normaliseRows($rows);
    }

    private function part(\ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ($stat['size'] > self::MAX_PART_BYTES) {
            throw new ShowcaseException('Workbook part too large: ' . $name);
        }
        $data = $zip->getFromName($name);

        return $data === false ? null : $data;
    }

    private static function reader(string $xml): \XMLReader
    {
        $reader = new \XMLReader();
        // No LIBXML_NOENT / LIBXML_DTDLOAD: entities and DTDs stay unresolved.
        if (!@$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new ShowcaseException('Malformed XML inside the workbook.');
        }

        return $reader;
    }

    private function firstSheetPath(\ZipArchive $zip, string $workbookXml): string
    {
        $fallback = 'xl/worksheets/sheet1.xml';
        if (!preg_match('/<(?:\w+:)?sheet\b[^>]*\br:id="([^"]+)"/', $workbookXml, $m)) {
            return $fallback;
        }
        $relId = $m[1];
        $rels = $this->part($zip, 'xl/_rels/workbook.xml.rels');
        if ($rels === null) {
            return $fallback;
        }
        $reader = self::reader($rels);
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'Relationship'
                && $reader->getAttribute('Id') === $relId) {
                $target = (string) $reader->getAttribute('Target');
                $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                // Collapse "a/../b" segments and refuse anything escaping the archive root.
                $parts = [];
                foreach (explode('/', $path) as $seg) {
                    if ($seg === '..') {
                        array_pop($parts);
                    } elseif ($seg !== '.' && $seg !== '') {
                        $parts[] = $seg;
                    }
                }

                return implode('/', $parts);
            }
        }

        return $fallback;
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(?string $xml): array
    {
        if ($xml === null) {
            return [];
        }
        $strings = [];
        $reader = self::reader($xml);
        $current = null;
        $phoneticDepth = 0;
        while ($reader->read()) {
            $name = $reader->localName;
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                if ($name === 'si') {
                    $current = '';
                    if ($reader->isEmptyElement) {
                        $strings[] = '';
                        $current = null;
                    }
                } elseif ($name === 'rPh' && !$reader->isEmptyElement) {
                    $phoneticDepth++;
                } elseif ($name === 't' && $current !== null && $phoneticDepth === 0 && !$reader->isEmptyElement) {
                    $current .= $reader->readString();
                }
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT) {
                if ($name === 'rPh') {
                    $phoneticDepth--;
                } elseif ($name === 'si' && $current !== null) {
                    $strings[] = $current;
                    $current = null;
                }
            }
        }

        return $strings;
    }

    /**
     * Maps style index => 'date' | 'datetime' | 'time' for date-formatted styles.
     *
     * @return array<int, string>
     */
    private function dateStyles(?string $xml): array
    {
        if ($xml === null) {
            return [];
        }
        $custom = [];
        $styles = [];
        $reader = self::reader($xml);
        $inCellXfs = false;
        $index = 0;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                if ($reader->localName === 'numFmt') {
                    $custom[(int) $reader->getAttribute('numFmtId')] = (string) $reader->getAttribute('formatCode');
                } elseif ($reader->localName === 'cellXfs') {
                    $inCellXfs = !$reader->isEmptyElement;
                } elseif ($inCellXfs && $reader->localName === 'xf') {
                    $kind = self::formatKind((int) $reader->getAttribute('numFmtId'), $custom);
                    if ($kind !== null) {
                        $styles[$index] = $kind;
                    }
                    $index++;
                }
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'cellXfs') {
                $inCellXfs = false;
            }
        }

        return $styles;
    }

    /**
     * @param array<int, string> $custom
     */
    public static function formatKind(int $id, array $custom): ?string
    {
        if (isset($custom[$id])) {
            return self::customFormatKind($custom[$id]);
        }
        if (in_array($id, self::BUILTIN_TIME_ONLY, true)) {
            return 'time';
        }
        if ($id === 22) {
            return 'datetime';
        }

        return in_array($id, self::BUILTIN_DATE_FORMATS, true) ? 'date' : null;
    }

    public static function customFormatKind(string $code): ?string
    {
        // Only the first section (positive numbers) matters.
        $code = explode(';', $code)[0];
        // Drop quoted literals, [colors]/[$-locale], escaped chars and padding/fill.
        $code = (string) preg_replace(['/"[^"]*"/', '/\[[^\]]*\]/', '/\\\\./', '/[_*]./'], '', $code);
        if (stripos($code, 'general') !== false) {
            return null;
        }
        $hasDate = (bool) preg_match('/[dy]/i', $code) || (bool) preg_match('/m/i', $code) && !preg_match('/[hs]/i', $code);
        $hasTime = (bool) preg_match('/[hs]/i', $code);
        if ($hasDate && $hasTime) {
            return 'datetime';
        }
        if ($hasDate) {
            return 'date';
        }

        return $hasTime ? 'time' : null;
    }

    /**
     * Converts an Excel serial number to an ISO string.
     */
    public static function serialToIso(float $serial, string $kind = 'date', bool $date1904 = false): string
    {
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);
        if ($seconds >= 86400) {
            $days++;
            $seconds -= 86400;
        }
        if ($date1904) {
            $base = new \DateTimeImmutable('1904-01-01', new \DateTimeZone('UTC'));
        } else {
            // Excel pretends 1900 was a leap year; serials before 61 are off by one.
            $base = new \DateTimeImmutable($days < 61 ? '1899-12-31' : '1899-12-30', new \DateTimeZone('UTC'));
        }
        $date = $base->modify(sprintf('%+d days', $days))->modify(sprintf('+%d seconds', $seconds));
        $time = $seconds % 60 === 0 ? $date->format('H:i') : $date->format('H:i:s');

        return match ($kind) {
            'time' => $time,
            'datetime' => $seconds === 0 ? $date->format('Y-m-d') : $date->format('Y-m-d') . ' ' . $time,
            default => $date->format('Y-m-d'),
        };
    }

    /**
     * Excel stores doubles; print them the way a CSV export would.
     */
    public static function formatNumber(string $raw): string
    {
        if (!is_numeric($raw)) {
            return $raw;
        }
        $f = (float) $raw;
        if (floor($f) === $f && abs($f) < 1e15) {
            return (string) (int) $f;
        }
        $s = sprintf('%.15g', $f);

        return str_contains($s, 'e') ? $s : rtrim(rtrim($s, '0'), '.');
    }

    public static function columnIndex(string $ref): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $ref));
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    /**
     * @param list<string> $shared
     * @param array<int, string> $dateStyles
     * @return list<list<string>>
     */
    private function sheetRows(string $xml, array $shared, array $dateStyles, bool $date1904): array
    {
        $rows = [];
        $row = null;
        $reader = self::reader($xml);

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'row') {
                if ($row !== null) {
                    $rows[] = self::denseRow($row);
                }
                $row = null;
                continue;
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'row') {
                $row = [];
                if ($reader->isEmptyElement) {
                    $row = null;
                }
                continue;
            }
            if ($reader->localName !== 'c' || $row === null) {
                continue;
            }

            $ref = (string) $reader->getAttribute('r');
            $col = $ref !== '' ? self::columnIndex($ref) : ($row === [] ? 0 : max(array_keys($row)) + 1);
            $type = $reader->getAttribute('t') ?? 'n';
            $style = (int) ($reader->getAttribute('s') ?? 0);
            [$value, $inline] = $reader->isEmptyElement ? [null, ''] : self::cellContent($reader);

            $row[$col] = match ($type) {
                's' => $shared[(int) $value] ?? '',
                'inlineStr' => $inline,
                'b' => $value === '1' ? 'TRUE' : 'FALSE',
                'str', 'e' => (string) $value,
                'd' => self::isoFromDateType((string) $value),
                default => self::numericCell($value, $dateStyles[$style] ?? null, $date1904),
            };
        }

        return $rows;
    }

    /**
     * Reads the children of a <c> element: returns [<v> text, <is> text].
     * The <f> formula text is deliberately ignored.
     *
     * @return array{0: ?string, 1: string}
     */
    private static function cellContent(\XMLReader $reader): array
    {
        $value = null;
        $inline = '';
        $phonetic = 0;
        while ($reader->read()) {
            $name = $reader->localName;
            if ($reader->nodeType === \XMLReader::END_ELEMENT) {
                if ($name === 'c') {
                    break;
                }
                if ($name === 'rPh') {
                    $phonetic--;
                }
                continue;
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->isEmptyElement) {
                continue;
            }
            if ($name === 'v') {
                $value = $reader->readString();
            } elseif ($name === 'rPh') {
                $phonetic++;
            } elseif ($name === 't' && $phonetic === 0) {
                $inline .= $reader->readString();
            }
        }

        return [$value, $inline];
    }

    private static function numericCell(?string $value, ?string $dateKind, bool $date1904): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($dateKind !== null && is_numeric($value)) {
            return self::serialToIso((float) $value, $dateKind, $date1904);
        }

        return self::formatNumber($value);
    }

    private static function isoFromDateType(string $value): string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:T00:00(?::00(?:\.0+)?)?Z?)?$/', $value, $m)) {
            return $m[1];
        }

        return str_replace('T', ' ', $value);
    }

    /**
     * @param array<int, string> $sparse
     * @return list<string>
     */
    private static function denseRow(array $sparse): array
    {
        if ($sparse === []) {
            return [];
        }
        $dense = array_fill(0, max(array_keys($sparse)) + 1, '');
        foreach ($sparse as $i => $v) {
            $dense[$i] = $v;
        }

        return $dense;
    }
}
