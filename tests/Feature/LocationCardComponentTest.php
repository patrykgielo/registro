<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Location;
use App\Support\ContentGridResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Faza 1, step 1.7 (plan-wdrozenia.md): locations displayed on CMS pages via
 * the existing "Siatka treści" block. Every field on Location is optional
 * (backfill can create a location with an empty address, see LocationFactory
 * and the migration in step 1.6) — this pins that x-ios.location-card never
 * throws and never leaves a dangling empty affordance (no img tag with no
 * src, no tel: link with nothing to call, no map link with nothing to map)
 * when a field is absent.
 */
class LocationCardComponentTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_with_every_optional_field_present(): void
    {
        $location = Location::factory()->create([
            'name' => 'Warszawa Centrala',
            'street' => 'Marszałkowska',
            'building' => '1',
            'postal_code' => '00-001',
            'city' => 'Warszawa',
            'phone' => '+48 22 123 45 67',
            'latitude' => 52.2297,
            'longitude' => 21.0122,
            'photo' => 'locations/1/photo.jpg',
            'opening_hours' => [
                ['label' => 'Pon–Pt', 'hours' => '7:00–17:00'],
                ['label' => 'Sob', 'hours' => 'Zamknięte'],
            ],
        ]);

        $html = Blade::render('<x-ios.location-card :location="$location" />', ['location' => $location]);

        $this->assertStringContainsString('Warszawa Centrala', $html);
        $this->assertStringContainsString('Marszałkowska 1', $html);
        $this->assertStringContainsString('00-001 Warszawa', $html);
        $this->assertStringContainsString('Pon–Pt', $html);
        $this->assertStringContainsString('7:00–17:00', $html);
        $this->assertStringContainsString('Sob', $html);
        $this->assertStringContainsString('Zamknięte', $html);
        $this->assertStringContainsString('tel:+48221234567', $html);
        // Coordinates present → map link must use them, not a text address query.
        $this->assertStringContainsString('query=52.2297,21.0122', $html);
        $this->assertStringContainsString('photo.jpg', $html);
        // The dead Tailwind class found in x-cms.card (project_dead_primary_scale
        // memory) must not be repeated here.
        $this->assertStringNotContainsString('text-primary-', $html);
    }

    public function test_renders_without_throwing_when_every_optional_field_is_empty(): void
    {
        $location = Location::factory()->create([
            'name' => 'Nowy Oddział',
            'street' => null,
            'building' => null,
            'postal_code' => null,
            'city' => null,
            'phone' => null,
            'latitude' => null,
            'longitude' => null,
            'photo' => null,
            'opening_hours' => null,
        ]);

        $html = Blade::render('<x-ios.location-card :location="$location" />', ['location' => $location]);

        $this->assertStringContainsString('Nowy Oddział', $html);
        // No image tag at all — not an <img> with an empty/placeholder src.
        $this->assertStringNotContainsString('<img', $html);
        // No dangling tel:/maps link when there is nothing to call or map.
        $this->assertStringNotContainsString('tel:', $html);
        $this->assertStringNotContainsString('google.com/maps', $html);
    }

    public function test_falls_back_to_address_based_map_link_when_coordinates_are_missing(): void
    {
        $location = Location::factory()->create([
            'name' => 'Kraków Filia',
            'street' => 'Floriańska',
            'building' => '5',
            'postal_code' => '31-019',
            'city' => 'Kraków',
            'phone' => null,
            'latitude' => null,
            'longitude' => null,
            'photo' => null,
            'opening_hours' => null,
        ]);

        $html = Blade::render('<x-ios.location-card :location="$location" />', ['location' => $location]);

        $this->assertStringContainsString('google.com/maps/search', $html);
        $this->assertStringContainsString(urlencode('Floriańska 5'), $html);
        $this->assertStringNotContainsString('tel:', $html);
    }

    /**
     * A Repeater field mid-edit in the panel (or data created before a shape
     * change) can hand back rows where one of the two sub-fields is blank —
     * dark-theme.md/filament-resources.md both call this out as a recurring
     * class of bug for Repeater-backed JSON. Must not render a bare em-dash
     * or throw an "Undefined array key" warning.
     */
    public function test_opening_hours_entry_with_a_blank_sub_field_does_not_crash(): void
    {
        $location = Location::factory()->create([
            'name' => 'Wrocław Oddział',
            'opening_hours' => [
                ['label' => 'Pon–Pt', 'hours' => ''],
                ['label' => '', 'hours' => ''],
            ],
        ]);

        $html = Blade::render('<x-ios.location-card :location="$location" />', ['location' => $location]);

        $this->assertStringContainsString('Pon–Pt', $html);
    }

    public function test_dark_variant_uses_client_dark_theme_colors(): void
    {
        $location = Location::factory()->create(['phone' => '500 100 200']);

        $html = Blade::render(
            '<x-ios.location-card :location="$location" :dark="true" />',
            ['location' => $location]
        );

        $this->assertStringContainsString('#0AB1EA', $html);
        $this->assertStringContainsString('service-card-dark', $html);
    }

    /**
     * Closes the loop end-to-end: the resolver registers 'locations', and
     * the content-grid block actually dispatches it to x-ios.location-card
     * rather than falling through to x-cms.card (which cannot render an
     * address/phone/hours at all — see card.blade.php's $item->title ??
     * $item->name / $item->excerpt ?? $item->body only).
     */
    public function test_content_grid_block_dispatches_locations_to_the_location_card(): void
    {
        $location = Location::factory()->create(['name' => 'Poznań Magazyn', 'phone' => null, 'photo' => null]);

        $data = [
            'content_type' => 'locations',
            'content_items' => [(string) $location->id],
            'columns' => '3',
        ];

        $html = Blade::render('<x-content-blocks.content-grid :data="$data" />', ['data' => $data]);

        $this->assertStringContainsString('Poznań Magazyn', $html);
        $this->assertStringContainsString('cms-content-card', $html);
    }

    public function test_resolver_and_card_agree_on_what_a_tenant_without_website_module_cannot_pick(): void
    {
        // Sanity check that the registry entry this component depends on
        // is wired the way plan-wdrozenia.md step 1.7 specifies (module
        // gate on 'website', not left ungated).
        $types = ContentGridResolver::availableContentTypes(
            \App\Models\Organization::factory()->create([
                'booking_type' => 'time_slot',
                'industry' => null,
                'settings' => ['modules' => ['website' => false]],
            ])
        );

        $this->assertArrayNotHasKey('locations', $types);
    }
}
