<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ColorScaleGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ColorScaleGeneratorTest extends TestCase
{
    // ── generate() ────────────────────────────────────────────────────────────

    public function test_generates_ten_shades(): void
    {
        $shades = ColorScaleGenerator::generate('#2563eb');

        $this->assertCount(10, $shades);
        $this->assertArrayHasKey('50', $shades);
        $this->assertArrayHasKey('900', $shades);
    }

    public function test_shade_500_equals_exact_input(): void
    {
        $shades = ColorScaleGenerator::generate('#2563eb');

        $this->assertSame('#2563eb', $shades['500']);
    }

    public function test_shade_500_equals_exact_input_uppercase_normalized(): void
    {
        $shades = ColorScaleGenerator::generate('#2563EB');

        $this->assertSame('#2563eb', $shades['500']);
    }

    public function test_lightness_decreases_from_50_to_900(): void
    {
        $shades = ColorScaleGenerator::generate('#2563eb');
        $order = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900'];

        $lightnesses = array_map(function (string $hex): float {
            return $this->hexToLightness($hex);
        }, array_map(fn ($k) => $shades[$k], $order));

        for ($i = 0; $i < count($lightnesses) - 1; $i++) {
            $this->assertGreaterThan(
                $lightnesses[$i + 1],
                $lightnesses[$i],
                "Shade {$order[$i]} should be lighter than shade {$order[$i + 1]}"
            );
        }
    }

    public function test_shorthand_hex_expanded_correctly(): void
    {
        // #fff = #ffffff — shade 500 should be white
        $shades = ColorScaleGenerator::generate('#fff');

        $this->assertSame('#ffffff', $shades['500']);
    }

    public function test_shorthand_hex_produces_ten_shades(): void
    {
        $shades = ColorScaleGenerator::generate('#abc');

        $this->assertCount(10, $shades);
    }

    public function test_all_shades_are_valid_hex_colors(): void
    {
        $shades = ColorScaleGenerator::generate('#6366f1');

        foreach ($shades as $shade => $hex) {
            $this->assertMatchesRegularExpression(
                '/^#[0-9a-f]{6}$/',
                $hex,
                "Shade {$shade} value '{$hex}' is not a valid lowercase hex color"
            );
        }
    }

    public function test_achromatic_white_does_not_throw(): void
    {
        $shades = ColorScaleGenerator::generate('#ffffff');

        $this->assertCount(10, $shades);
        $this->assertSame('#ffffff', $shades['500']);
    }

    public function test_achromatic_black_does_not_throw(): void
    {
        $shades = ColorScaleGenerator::generate('#000000');

        $this->assertCount(10, $shades);
        $this->assertSame('#000000', $shades['500']);
    }

    public function test_achromatic_gray_does_not_throw(): void
    {
        $shades = ColorScaleGenerator::generate('#808080');

        $this->assertCount(10, $shades);
        $this->assertSame('#808080', $shades['500']);
    }

    // ── Invalid inputs ─────────────────────────────────────────────────────────

    public function test_throws_on_missing_hash_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/must start with '#'/");

        ColorScaleGenerator::generate('2563eb');
    }

    public function test_throws_on_rgba_format(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ColorScaleGenerator::generate('rgba(37, 99, 235, 1)');
    }

    public function test_throws_on_hsl_format(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ColorScaleGenerator::generate('hsl(221, 83%, 53%)');
    }

    public function test_throws_on_named_color(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ColorScaleGenerator::generate('blue');
    }

    public function test_throws_on_invalid_hex_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ColorScaleGenerator::generate('#GGGGGG');
    }

    public function test_throws_on_too_short_hex(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ColorScaleGenerator::generate('#12');
    }

    public function test_throws_on_css_injection_attempt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ColorScaleGenerator::generate('#ff0000; background: red');
    }

    // ── toCssVariables() ──────────────────────────────────────────────────────

    public function test_css_variables_contain_all_shades(): void
    {
        $css = ColorScaleGenerator::toCssVariables('#2563eb');

        foreach (['50', '100', '200', '300', '400', '500', '600', '700', '800', '900'] as $shade) {
            $this->assertStringContainsString("--primary-{$shade}:", $css);
        }
    }

    public function test_css_variables_use_custom_prefix(): void
    {
        $css = ColorScaleGenerator::toCssVariables('#2563eb', 'brand');

        $this->assertStringContainsString('--brand-500:', $css);
        $this->assertStringNotContainsString('--primary-', $css);
    }

    public function test_css_variables_shade_500_contains_exact_input(): void
    {
        $css = ColorScaleGenerator::toCssVariables('#2563eb');

        $this->assertStringContainsString('--primary-500: #2563eb;', $css);
    }

    public function test_css_variables_are_valid_css_syntax(): void
    {
        $css = ColorScaleGenerator::toCssVariables('#6366f1');
        $lines = array_filter(explode("\n", str_replace('    ', '', $css)));

        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression(
                '/^--[a-z]+-\d+: #[0-9a-f]{6};$/',
                trim($line),
                "Line '{$line}' is not valid CSS variable syntax"
            );
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function hexToLightness(string $hex): float
    {
        $r = hexdec(substr($hex, 1, 2)) / 255;
        $g = hexdec(substr($hex, 3, 2)) / 255;
        $b = hexdec(substr($hex, 5, 2)) / 255;

        return (max($r, $g, $b) + min($r, $g, $b)) / 2 * 100;
    }
}
