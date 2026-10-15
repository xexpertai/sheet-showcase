<?php

declare(strict_types=1);

namespace SheetShowcase;

/**
 * Guesses a column type from its values, and parses typed values.
 *
 * Types: text | number | currency | date | url | email | image | tag
 */
final class TypeInferer
{
    public const TYPES = ['text', 'number', 'currency', 'date', 'url', 'email', 'image', 'tag'];

    /** Rows examined per column. */
    public const SAMPLE_ROWS = 200;

    /** At most this many distinct values for a text column to become a tag. */
    public const TAG_MAX_DISTINCT = 12;

    private const SYMBOLS = ['€' => 'EUR', '$' => 'USD', '£' => 'GBP', '₹' => 'INR', '¥' => 'JPY'];

    private const CURRENCY_RE = '/^(?:(?<pre>[€$£₹¥]|EUR|USD|GBP|INR|JPY)\s?)?(?<num>[-+]?[\d.,\x{00A0}\x{202F} ]*\d)(?:\s?(?<post>[€$£₹¥]|EUR|USD|GBP|INR|JPY))?$/u';

    private const ISO_DATE_RE = '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/';

    private const IMAGE_RE = '/\.(?:png|jpe?g|gif|webp|avif|svg)(?:[?#].*)?$/i';

    private const PRICE_HEADER_RE = '/\b(price|cost|fee|amount|total|preis|prix|precio|rate)\b/i';

    /**
     * @param list<string> $values Column values (in sheet order).
     */
    public static function infer(array $values, string $header = ''): string
    {
        $sample = array_values(array_filter(
            array_slice($values, 0, self::SAMPLE_ROWS),
            static fn (string $v): bool => trim($v) !== ''
        ));
        if ($sample === []) {
            return 'text';
        }

        if (self::all($sample, static fn (string $v): bool => self::isEmail($v))) {
            return 'email';
        }
        if (self::all($sample, static fn (string $v): bool => self::isHttpUrl($v))) {
            return self::all($sample, static fn (string $v): bool => (bool) preg_match(self::IMAGE_RE, $v))
                ? 'image' : 'url';
        }
        if (self::all($sample, static fn (string $v): bool => self::parseDate($v) !== null)) {
            return 'date';
        }
        if (self::all($sample, static fn (string $v): bool => self::parseNumber($v) !== null)) {
            return preg_match(self::PRICE_HEADER_RE, $header) ? 'currency' : 'number';
        }
        if (self::all($sample, static fn (string $v): bool => self::parseCurrency($v) !== null)) {
            return 'currency';
        }

        $distinct = count(array_unique($sample));
        $maxLen = max(array_map('mb_strlen', $sample));
        if ($distinct <= self::TAG_MAX_DISTINCT && $distinct * 2 <= count($sample) && $maxLen <= 40) {
            return 'tag';
        }

        return 'text';
    }

    /**
     * ISO-4217 code implied by currency symbols in the values, if any.
     *
     * @param list<string> $values
     */
    public static function detectCurrency(array $values): ?string
    {
        foreach (array_slice($values, 0, self::SAMPLE_ROWS) as $v) {
            $parsed = self::parseCurrency($v);
            if ($parsed !== null && $parsed[1] !== null) {
                return $parsed[1];
            }
        }

        return null;
    }

    public static function parseNumber(string $v): ?float
    {
        $s = (string) preg_replace('/(?<=\d)[\x{00A0}\x{202F} ](?=\d{3})/u', '', trim($v));
        if (preg_match('/^[-+]?(\d+(\.\d*)?|\.\d+)([eE][-+]?\d+)?$/', $s)) {
            return (float) $s;
        }
        if (preg_match('/^[-+]?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) {
            return (float) str_replace(',', '', $s);
        }
        if (preg_match('/^[-+]?\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
            return (float) str_replace(['.', ','], ['', '.'], $s);
        }
        if (preg_match('/^[-+]?\d+,\d+$/', $s)) {
            return (float) str_replace(',', '.', $s);
        }

        return null;
    }

    /**
     * @return array{0: float, 1: ?string}|null [amount, ISO code or null]
     */
    public static function parseCurrency(string $v): ?array
    {
        $v = trim($v);
        if (!preg_match(self::CURRENCY_RE, $v, $m)) {
            return null;
        }
        $symbol = ($m['pre'] ?? '') !== '' ? $m['pre'] : ($m['post'] ?? '');
        $amount = self::parseNumber($m['num']);
        if ($amount === null) {
            return null;
        }
        $code = $symbol === '' ? null : (self::SYMBOLS[$symbol] ?? $symbol);

        return [$amount, $code];
    }

    /**
     * Returns the canonical ISO form of a date string, or null.
     */
    public static function parseDate(string $v): ?string
    {
        $v = trim($v);
        if (!preg_match(self::ISO_DATE_RE, $v)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', substr($v, 0, 10)));
        if (!checkdate($m, $d, $y)) {
            return null;
        }

        return str_replace('T', ' ', $v);
    }

    public static function isEmail(string $v): bool
    {
        return filter_var(trim($v), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * True only for absolute http(s) URLs with a host. Anything else
     * (javascript:, data:, relative paths, ...) is rejected.
     */
    public static function isHttpUrl(string $v): bool
    {
        $v = trim($v);
        if (!preg_match('~^https?://[^\s/?#]+[^\s]*$~i', $v)) {
            return false;
        }
        $parts = parse_url($v);

        return is_array($parts) && isset($parts['host']) && $parts['host'] !== ''
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true);
    }

    /**
     * @param list<string> $values
     */
    private static function all(array $values, callable $test): bool
    {
        foreach ($values as $v) {
            if (!$test($v)) {
                return false;
            }
        }

        return true;
    }
}
