<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Pins the WCAG contrast fix for the status badge (123k99cu9u0). The four
 * status variants of x-ui.badge (success/warning/error/info) render
 * `bg-{color}/10 text-{color}` — flattened over its real card background
 * (dark-theme.md's alpha rule), the shared --color-success/warning/error/info
 * tokens clear 4.5:1 WCAG contrast on at most ONE of the two surfaces this
 * component actually renders on (see design-tokens.css's "Status badge"
 * comment for the measured numbers, oklch→linear sRGB→alpha-composite→WCAG).
 * Without the `dark` prop switching to the badge-*-dark token set, a future
 * "simplification" back to one class list would silently reintroduce
 * whichever surface it deletes.
 */
class BadgeSurfaceContrastTest extends TestCase
{
    use RefreshDatabase;

    public function test_light_surface_badge_uses_the_light_optimized_tokens(): void
    {
        $html = Blade::render('<x-ui.badge variant="success" dot>Dostępne</x-ui.badge>');

        $this->assertStringContainsString('text-badge-success', $html);
        $this->assertStringContainsString('bg-badge-success/10', $html);
        $this->assertStringNotContainsString('badge-success-dark', $html);
    }

    public function test_dark_prop_switches_every_status_variant_to_the_dark_optimized_tokens(): void
    {
        $variants = ['success', 'warning', 'error', 'info'];

        foreach ($variants as $variant) {
            $html = Blade::render(
                '<x-ui.badge :variant="$variant" dark dot>Test</x-ui.badge>',
                ['variant' => $variant]
            );

            $this->assertStringContainsString(
                "text-badge-{$variant}-dark",
                $html,
                "variant={$variant} must use the dark-optimized text token when dark=true"
            );
            $this->assertStringContainsString(
                "bg-badge-{$variant}-dark/10",
                $html,
                "variant={$variant} must use the dark-optimized background token when dark=true"
            );
        }
    }

    /**
     * The one caller that renders on both surfaces — proves the wiring at
     * ios/service-card.blade.php, not just the component in isolation.
     */
    public function test_service_card_availability_badge_uses_dark_tokens_only_when_the_card_itself_is_dark(): void
    {
        $available = Service::factory()->itemRental()->create(['featured_image' => null]);
        $unavailable = Service::factory()->itemRental()->create(['featured_image' => null]);

        $lightHtml = Blade::render(
            '<x-ios.service-card :service="$service" :available-quantity="3" />',
            ['service' => $available]
        );
        $this->assertStringContainsString('text-badge-success', $lightHtml);
        $this->assertStringNotContainsString('badge-success-dark', $lightHtml);

        $darkHtml = Blade::render(
            '<x-ios.service-card :service="$service" variant="dark" :available-quantity="0" />',
            ['service' => $unavailable]
        );
        $this->assertStringContainsString('text-badge-error-dark', $darkHtml);
    }
}
