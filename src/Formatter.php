<?php

declare(strict_types=1);

namespace SheetShowcase;

/**
 * Server-side display formatting (used for the no-JavaScript first paint).
 * The browser re-formats with Intl.NumberFormat / Intl.DateTimeFormat.
 * Deliberately intl-free so it works on bare shared hosting.
 */
final class Formatter
{
    private const SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'INR' => '₹', 'JPY' => '¥'];

    /** Locales that use a decimal comma => thousands separator. */
    private const COMMA_LOCALES = [
        'de' => '.', 'es' => '.', 'it' => '.', 'nl' => '.', 'pt' => '.', 'da' => '.', 'id' => '.', 'tr' => '.',
        'fr' => "\u{202F}", 'ru' => "\u{00A0}", 'pl' => "\u{00A0}", 'sv' => "\u{00A0}", 'fi' => "\u{00A0}",
        'nb' => "\u{00A0}", 'cs' => "\u{00A0}",
    ];

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public function __construct(private readonly string $locale = 'en', private readonly string $currency = 'USD')
    {
    }

    public function format(string|int|float|null $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_string($value) && in_array($type, ['number', 'currency'], true)) {
            return $value; // unparseable text such as "on request"
        }

        return match ($type) {
            'number' => $this->number((float) $value, is_int($value) ? 0 : null),
            'currency' => $this->money((float) $value),
            'date' => $this->date((string) $value),
            default => (string) $value,
        };
    }

    public function number(float $n, ?int $decimals = null): string
    {
        $lang = strtolower(substr($this->locale, 0, 2));
        $commaLocale = array_key_exists($lang, self::COMMA_LOCALES);
        if ($decimals === null) {
            $decimals = 0;
            $frac = rtrim(substr(sprintf('%.3F', abs($n) - floor(abs($n))), 2), '0');
            $decimals = strlen($frac);
        }

        return number_format(
            $n,
            $decimals,
            $commaLocale ? ',' : '.',
            $commaLocale ? self::COMMA_LOCALES[$lang] : ','
        );
    }

    public function money(float $n): string
    {
        $decimals = $this->currency === 'JPY' ? 0 : 2;
        $amount = $this->number($n, $decimals);
        $symbol = self::SYMBOLS[$this->currency] ?? $this->currency;
        $lang = strtolower(substr($this->locale, 0, 2));

        return array_key_exists($lang, self::COMMA_LOCALES) ? $amount . "\u{00A0}" . $symbol : $symbol . $amount;
    }

    public function date(string $iso): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}:\d{2}))?/', $iso, $m)) {
            return $iso;
        }
        $out = (int) $m[3] . ' ' . self::MONTHS[(int) $m[2] - 1] . ' ' . $m[1];

        return isset($m[4]) ? $out . ', ' . $m[4] : $out;
    }
}
