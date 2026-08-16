<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Pins the fix for feature/tenant-branding-fixes: a rental Service with no
 * featured_image renders an icon badge (ServiceType::ItemRental defaults to
 * the 'cube' heroicon) instead of a reserved blank area — but the badge's
 * background used `bg-primary-500`, a Tailwind class this project's theme
 * never defines (design-tokens.css only registers a `brand` color scale,
 * not `primary` — see tailwind.config.js). Tailwind v4 only emits utilities
 * for values it can resolve, so that class compiled to nothing: a
 * transparent icon container behind a white heroicon is invisible against
 * the card's white background — the "empty white rectangle" every
 * no-image product card showed. `hover:border-primary-300` on the card
 * itself was the same dead class, just without a visible symptom.
 */
class ServiceCardNoImagePlaceholderTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_variant_icon_badge_uses_a_real_theme_color_class(): void
    {
        $service = Service::factory()->itemRental()->create([
            'icon' => null,
            'featured_image' => null,
        ]);

        $html = Blade::render('<x-ios.service-card :service="$service" />', ['service' => $service]);

        // The regression: this class does not exist in this project's
        // Tailwind theme (no `primary` scale, only `brand`) and silently
        // compiles to nothing, leaving the icon container transparent.
        $this->assertStringNotContainsString('bg-primary-500', $html);
        $this->assertStringNotContainsString('border-primary-300', $html);

        // The fix: a color class this theme actually defines.
        $this->assertStringContainsString('bg-brand', $html);
    }

    public function test_dark_variant_icon_badge_is_unaffected(): void
    {
        $service = Service::factory()->itemRental()->create([
            'icon' => null,
            'featured_image' => null,
        ]);

        $html = Blade::render(
            '<x-ios.service-card :service="$service" variant="dark" />',
            ['service' => $service]
        );

        $this->assertStringContainsString('#0AB1EA', $html);
        $this->assertStringNotContainsString('bg-primary-500', $html);
    }
}
