<?php

declare(strict_types=1);

namespace SheetShowcase;

/**
 * Reads CSV files exported by Excel, LibreOffice, Google Sheets and friends.
 *
 * - Delimiter auto-detected among `,` `;` tab and `|`.
 * - UTF-8 with or without BOM, UTF-16 (BOM) and a Windows-1252 fallback.
 * - RFC 4180 quoting: quoted fields may contain delimiters, `""` and newlines.
 * - Ragged rows are padded so every row has the same number of cells.
 *
 * Cell values are returned as trimmed strings. Nothing is ever evaluated:
 * a cell that starts with `=` is just text.
 */
final class CsvReader
{
    public const DELIMITERS = [',', ';', "\t", '|'];

    /** Lines sampled when guessing the delimiter. */
    private const SNIFF_RECORDS = 20;

    /**
     * @return list<list<string>> All rows (the first one is the header row).
     */
    public function read(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ShowcaseException("CSV file not readable: " . basename($path));
        }
        $bytes = (string) file_get_contents($path);

        return $this->parseString($bytes);
    }

    /**
     * @return list<list<string>>
     */
    public function parseString(string $bytes, ?string $delimiter = null): array
    {
        $text = self::toUtf8($bytes);
        $delimiter ??= self::detectDelimiter($text);
        $rows = self::parse($text, $delimiter);

        return self::normaliseRows($rows);
    }

    /**
     * Converts raw bytes to UTF-8 without BOM.
     */
    public static function toUtf8(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return substr($bytes, 3);
        }
        if (str_starts_with($bytes, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        // Not valid UTF-8: assume the classic Excel-on-Windows encoding.
        return (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    /**
     * Picks the delimiter that yields the most consistent multi-column rows.
     */
    public static function detectDelimiter(string $text): string
    {
        $best = ',';
        $bestScore = -1;
        foreach (self::DELIMITERS as $candidate) {
            $records = self::parse($text, $candidate, self::SNIFF_RECORDS);
            $counts = array_map('count', $records);
            if ($counts === []) {
                continue;
            }
            $freq = array_count_values($counts);
            arsort($freq);
            $mode = (int) array_key_first($freq);
            if ($mode < 2) {
                continue;
            }
            // Consistency first, then the number of columns as tie-breaker.
            $score = $freq[$mode] * 1000 + $mode;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * RFC 4180 state machine. Accepts LF, CRLF and CR line endings.
     *
     * @return list<list<string>>
     */
    public static function parse(string $text, string $delimiter, int $maxRecords = PHP_INT_MAX): array
    {
        $rows = [];
        $row = [];
        $field = '';
        $inQuotes = false;
        $len = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($inQuotes) {
                if ($ch === '"') {
                    if ($i + 1 < $len && $text[$i + 1] === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $inQuotes = false;
                    }
                } else {
                    $field .= $ch;
                }
                continue;
            }
            if ($ch === '"' && trim($field) === '') {
                $inQuotes = true;
                $field = '';
            } elseif ($ch === $delimiter) {
                $row[] = $field;
                $field = '';
            } elseif ($ch === "\n" || $ch === "\r") {
                if ($ch === "\r" && $i + 1 < $len && $text[$i + 1] === "\n") {
                    $i++;
                }
                $row[] = $field;
                $rows[] = $row;
                $row = [];
                $field = '';
                if (count($rows) >= $maxRecords) {
                    return $rows;
                }
            } else {
                $field .= $ch;
            }
        }
        if ($field !== '' || $row !== []) {
            $row[] = $field;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Trims cells, drops blank lines and pads ragged rows to equal width.
     *
     * @param list<list<string>> $rows
     * @return list<list<string>>
     */
    public static function normaliseRows(array $rows): array
    {
        $out = [];
        $width = 0;
        foreach ($rows as $row) {
            $row = array_map(static fn (string $v): string => trim($v), $row);
            if (implode('', $row) === '') {
                continue;
            }
            $out[] = $row;
            $width = max($width, count($row));
        }
        foreach ($out as $i => $row) {
            $out[$i] = array_pad($row, $width, '');
        }

        return $out;
    }
}
