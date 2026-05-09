<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Generates a 10-shade color palette from a single HEX color.
 *
 * Algorithm:
 * - Converts HEX → HSL
 * - Preserves Hue (H) and Saturation (S) from input
 * - Generates lightness values: shade 50 = ~97%, shade 900 = ~15%
 * - Shade 500 = exact input color
 * - Returns array and CSS custom properties string
 *
 * Security: validates hex input strictly — prevents CSS injection.
 */
class ColorScaleGenerator
{
    /**
     * Lightness values per shade stop (string keys to avoid PHP integer cast).
     * Shade 500 marker: skip generation and use exact input instead.
     *
     * @var array<string, float|null>
     */
    private const SHADE_LIGHTNESS = [
        's50' => 97.0,
        's100' => 93.0,
        's200' => 86.0,
        's300' => 74.0,
        's400' => 61.0,
        's500' => null, // null = use exact input hex
        's600' => 40.0,
        's700' => 31.0,
        's800' => 22.0,
        's900' => 15.0,
    ];

    /**
     * Generate a 10-shade palette from a HEX color.
     *
     * @param  string  $hex  Input color (#RRGGBB or #RGB)
     * @return array<string, string> Shade map: ['50' => '#...', ..., '900' => '#...']
     *
     * @throws \InvalidArgumentException on invalid hex
     */
    public static function generate(string $hex): array
    {
        $hex = self::normalizeHex($hex);
        [$h, $s, $l] = self::hexToHsl($hex);

        $shades = [];

        foreach (self::SHADE_LIGHTNESS as $key => $targetL) {
            $shade = substr($key, 1); // strip leading 's' prefix: 's500' → '500'

            if ($targetL === null) {
                // Shade 500 = exact input color (no rounding/conversion)
                $shades[$shade] = $hex;
            } else {
                $shades[$shade] = self::hslToHex($h, $s, $targetL);
            }
        }

        return $shades;
    }

    /**
     * Generate CSS custom properties string from a HEX color.
     *
     * @param  string  $hex  Input color (#RRGGBB or #RGB)
     * @param  string  $prefix  CSS variable prefix (default: 'primary')
     * @return string CSS variables e.g. "--primary-50: #f5f5f5;\n--primary-100: #e5e5e5;\n..."
     *
     * @throws \InvalidArgumentException on invalid hex
     */
    public static function toCssVariables(string $hex, string $prefix = 'primary'): string
    {
        $shades = self::generate($hex);
        $lines = [];

        foreach ($shades as $shade => $color) {
            $lines[] = "--{$prefix}-{$shade}: {$color};";
        }

        return implode("\n    ", $lines);
    }

    /**
     * Normalize and validate a hex color string.
     *
     * Accepts: #RRGGBB or #RGB
     * Rejects: everything else (rgba, hsl, named colors, etc.)
     *
     * @throws \InvalidArgumentException
     */
    private static function normalizeHex(string $hex): string
    {
        $hex = trim($hex);

        // Must start with #
        if (! str_starts_with($hex, '#')) {
            throw new \InvalidArgumentException("Invalid hex color: must start with '#'. Got: {$hex}");
        }

        $stripped = substr($hex, 1);

        // Expand shorthand #RGB → #RRGGBB
        if (preg_match('/^[0-9a-fA-F]{3}$/', $stripped)) {
            $r = $stripped[0].$stripped[0];
            $g = $stripped[1].$stripped[1];
            $b = $stripped[2].$stripped[2];
            $stripped = $r.$g.$b;
        }

        // Must be exactly 6 hex characters
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $stripped)) {
            throw new \InvalidArgumentException("Invalid hex color format. Expected #RRGGBB or #RGB. Got: {$hex}");
        }

        return '#'.strtolower($stripped);
    }

    /**
     * Convert HEX color to HSL.
     *
     * Handles achromatic colors (grayscale) without division by zero.
     *
     * @param  string  $hex  Normalized #RRGGBB hex color
     * @return array{0: float, 1: float, 2: float} [H (0-360), S (0-100), L (0-100)]
     */
    private static function hexToHsl(string $hex): array
    {
        $r = hexdec(substr($hex, 1, 2)) / 255;
        $g = hexdec(substr($hex, 3, 2)) / 255;
        $b = hexdec(substr($hex, 5, 2)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;

        // Lightness
        $l = ($max + $min) / 2;

        // Achromatic: no hue or saturation
        if ($delta < 0.0001) {
            return [0.0, 0.0, $l * 100];
        }

        // Saturation
        $s = $delta / (1 - abs(2 * $l - 1));

        // Hue
        if ($max === $r) {
            $h = 60 * fmod(($g - $b) / $delta, 6);
        } elseif ($max === $g) {
            $h = 60 * (($b - $r) / $delta + 2);
        } else {
            $h = 60 * (($r - $g) / $delta + 4);
        }

        if ($h < 0) {
            $h += 360;
        }

        return [$h, $s * 100, $l * 100];
    }

    /**
     * Convert HSL values to HEX color.
     *
     * @param  float  $h  Hue (0-360)
     * @param  float  $s  Saturation (0-100)
     * @param  float  $l  Lightness (0-100)
     */
    private static function hslToHex(float $h, float $s, float $l): string
    {
        $s /= 100;
        $l /= 100;

        $a = $s * min($l, 1 - $l);

        $f = function (int $n) use ($h, $l, $a): int {
            $k = fmod($n + $h / 30, 12);
            $color = $l - $a * max(-1.0, min($k - 3, 9 - $k, 1.0));

            return (int) round(max(0.0, min(255.0, $color * 255)));
        };

        $r = $f(0);
        $g = $f(8);
        $b = $f(4);

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
