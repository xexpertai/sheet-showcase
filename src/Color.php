<?php

declare(strict_types=1);

namespace SheetShowcase;

/**
 * Small colour helpers that keep any user-chosen accent colour accessible:
 * we derive variants with a WCAG contrast ratio of at least 4.5:1 against
 * the light and dark page backgrounds.
 */
final class Color
{
    public const LIGHT_BG = '#ffffff';
    public const DARK_BG = '#111827';
    public const MIN_CONTRAST = 4.5;

    public static function normalise(string $hex): string
    {
        $hex = ltrim(strtolower($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/', $hex)) {
            throw new ShowcaseException('Invalid colour: ' . $hex);
        }

        return '#' . $hex;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rgb(string $hex): array
    {
        $hex = substr(self::normalise($hex), 1);

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    public static function luminance(string $hex): float
    {
        $c = array_map(static function (int $v): float {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function mix(string $a, string $b, float $weightOfB): string
    {
        $ca = self::rgb($a);
        $cb = self::rgb($b);
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $out .= sprintf('%02x', (int) round($ca[$i] + ($cb[$i] - $ca[$i]) * $weightOfB));
        }

        return $out;
    }

    /**
     * Moves $color towards black or white until it reaches 4.5:1 on $background.
     */
    public static function readableOn(string $color, string $background): string
    {
        $color = self::normalise($color);
        $target = self::luminance($background) > 0.5 ? '#000000' : '#ffffff';
        for ($w = 0.0; $w <= 1.0; $w += 0.05) {
            $candidate = self::mix($color, $target, $w);
            if (self::contrast($candidate, $background) >= self::MIN_CONTRAST) {
                return $candidate;
            }
        }

        return $target;
    }

    /** Text colour (black or white) with the best contrast on $background. */
    public static function textOn(string $background): string
    {
        return self::contrast('#ffffff', $background) >= self::contrast('#000000', $background) ? '#ffffff' : '#000000';
    }
}
