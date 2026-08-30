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
        $longDescription = 'Nasz oddział w centrum miasta obsługuje wynajem sprzętu budowlanego '
            .'oraz ogrodniczego dla klientów indywidualnych i firm z całego regionu na miejscu.';
        $this->assertGreaterThan(120, strlen($longDescription), 'fixture must exceed the Str::limit(120) truncation point');

        $location = Location::factory()->create([
            'name' => 'Warszawa Centrala',
            'code' => 'WAW',
            'email' => 'warszawa@example.test',
            'description' => $longDescription,
            'street' => 'Marszałkowska',
            'building' => '1',
            'postal_code' => '00-001',
            'city' => 'Warszawa',
            'phone' => '+48 22 123 45 67',
            'latitude' => 52.2297,
            'longitude' => 21.0122,
            'photo' => 'locations/1/photo.jpg',
            'gallery' => [
                'locations/1/gallery/1.jpg',
                'locations/1/gallery/2.jpg',
                'locations/1/gallery/3.jpg',
                'locations/1/gallery/4.jpg',
                'locations/1/gallery/5.jpg',
            ],
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

        // code → badge next to the name.
        $this->assertStringContainsString('WAW', $html);

        // email → mailto: action alongside phone/map.
        $this->assertStringContainsString('mailto:warszawa@example.test', $html);

        // description → truncated to Str::limit(..., 120), not the full sentence.
        $this->assertStringContainsString(\Illuminate\Support\Str::limit($longDescription, 120), $html);
        $this->assertStringNotContainsString($longDescription, $html);

        // gallery → 4-thumbnail preview strip (not all 5) plus a "+1" remainder badge.
        $this->assertSame(4, substr_count($html, 'gallery/'), 'only the first 4 gallery images should render as thumbnails');
        $this->assertStringContainsString('+1', $html);
        $this->assertStringNotContainsString('gallery/5.jpg', $html);
    }

    public function test_renders_without_throwing_when_every_optional_field_is_empty(): void
    {
        $location = Location::factory()->create([
            'name' => 'Nowy Oddział',
            'code' => null,
            'email' => null,
            'description' => '',
            'gallery' => [],
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
        // No dangling mailto: link, no empty code badge, no empty description
        // paragraph, no empty gallery grid — only-name-and-address must not
        // leave any of the four new sections as a hollow shell.
        $this->assertStringNotContainsString('mailto:', $html);
        $this->assertStringNotContainsString('rounded-full px-2 py-0.5', $html);
        $this->assertStringNotContainsString('role="group"', $html);
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

    /**
     * Requirement: visible h4 "Godziny otwarcia" heading, programmatically
     * tied to the hours list via aria-labelledby, unique id per location.
     */
    public function test_renders_hours_heading_wired_to_the_list_via_aria_labelledby(): void
    {
        $location = Location::factory()->create([
            'opening_hours' => [
                ['label' => 'Pon–Pt', 'hours' => '7:00–17:00'],
            ],
        ]);

        $html = Blade::render('<x-ios.location-card :location="$location" />', ['location' => $location]);

        $expectedId = 'location-hours-heading-'.$location->id;

        $this->assertStringContainsString('Godziny otwarcia', $html);
        $this->assertStringContainsString('<h4 id="'.$expectedId.'"', $html);
        $this->assertStringContainsString('aria-labelledby="'.$expectedId.'"', $html);
    }

    /**
     * Two cards on the same CMS page (content-grid loop) must not collide on
     * the same id — heading ids are keyed by the real location id.
     */
    public function test_hours_heading_ids_are_unique_across_multiple_cards_on_one_page(): void
    {
        $first = Location::factory()->create(['opening_hours' => [['label' => 'Pon', 'hours' => '7:00-17:00']]]);
        $second = Location::factory()->create(['opening_hours' => [['label' => 'Wt', 'hours' => '7:00-17:00']]]);

        $html = Blade::render(
            '<x-ios.location-card :location="$first" /><x-ios.location-card :location="$second" />',
            ['first' => $first, 'second' => $second]
        );

        $this->assertStringContainsString('location-hours-heading-'.$first->id, $html);
        $this->assertStringContainsString('location-hours-heading-'.$second->id, $html);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_no_hours_heading_or_empty_section_when_location_has_no_hours(): void
    {
        $location = Location::factory()->create(['opening_hours' => null]);

        $html = Blade::render('<x-ios.location-card :location="$location" />', ['location' => $location]);

        $this->assertStringNotContainsString('Godziny otwarcia', $html);
        $this->assertStringNotContainsString('<h4', $html);
        $this->assertStringNotContainsString('aria-labelledby', $html);
    }

    /**
     * <time> is only used for an hours value that parses as an unambiguous
     * range (OpeningHoursParserTest pins the grammar) — "Zamknięte" stays
     * plain text, never wrapped or reformatted.
     */
    public function test_time_element_wraps_only_unambiguous_hour_ranges(): void
    {
        $location = Location::factory()->create([
            'opening_hours' => [
                ['label' => 'Pon–Pt', 'hours' => '7:00–17:00'],
                ['label' => 'Sob', 'hours' => 'Zamknięte'],
            ],
        ]);

        $html = Blade::render('<x-ios.location-card :location="$location" />', ['location' => $location]);

        $this->assertStringContainsString('<time>7:00–17:00</time>', $html);
        $this->assertStringNotContainsString('<time>Zamknięte</time>', $html);
        $this->assertStringContainsString('Zamknięte', $html);
    }

    /**
     * Structured data: unambiguous label+hours pair becomes an
     * openingHoursSpecification entry, address/phone/email/geo/image are
     * always emitted when present regardless of the hours outcome.
     */
    public function test_structured_data_emits_opening_hours_specification_for_unambiguous_entry(): void
    {
        $location = Location::factory()->create([
            'name' => 'Gdańsk Filia',
            'street' => 'Długa',
            'building' => '10',
            'postal_code' => '80-001',
            'city' => 'Gdańsk',
            'phone' => '+48 58 123 45 67',
            'email' => 'gdansk@example.test',
            'latitude' => 54.3520,
            'longitude' => 18.6466,
            'photo' => 'locations/2/photo.jpg',
            'opening_hours' => [
                ['label' => 'Pon–Pt', 'hours' => '7:00–17:00'],
            ],
        ]);

        $html = Blade::render('<x-ios.location-card :location="$location" />', ['location' => $location]);

        $this->assertStringContainsString('"@type":"LocalBusiness"', $html);
        $this->assertStringContainsString('"name":"Gdańsk Filia"', $html);
        $this->assertStringContainsString('"streetAddress":"Długa 10"', $html);
        $this->assertStringContainsString('"telephone":"+48 58 123 45 67"', $html);
        $this->assertStringContainsString('"email":"gdansk@example.test"', $html);
        $this->assertStringContainsString('"latitude":54.352', $html);
        $this->assertStringContainsString('"openingHoursSpecification"', $html);
        // JSON_UNESCAPED_SLASHES is deliberate here (see location-card.blade.php) — slashes
        // stay literal, not "\/", unlike ServiceController's schema which doesn't set that flag.
        $this->assertStringContainsString('"dayOfWeek":"https://schema.org/Monday"', $html);
        $this->assertStringContainsString('"opens":"07:00"', $html);
        $this->assertStringContainsString('"closes":"17:00"', $html);
    }

    /**
     * Ambiguous label ("Dni robocze") or hours ("na telefon") must NOT
     * fabricate an openingHoursSpecification, but the rest of the
     * LocalBusiness data (name, address, phone) still comes through.
     */
    public function test_structured_data_omits_opening_hours_specification_for_ambiguous_entries(): void
    {
        $location = Location::factory()->create([
            'name' => 'Łódź Magazyn',
            'street' => 'Piotrkowska',
            'building' => '50',
            'postal_code' => '90-001',
            'city' => 'Łódź',
            'phone' => '+48 42 999 88 77',
            'opening_hours' => [
                ['label' => 'Dni robocze', 'hours' => '7:00-17:00'],
                ['label' => 'Pon', 'hours' => 'na telefon'],
            ],
        ]);

        $html = Blade::render('<x-ios.location-card :location="$location" />', ['location' => $location]);

        $this->assertStringNotContainsString('openingHoursSpecification', $html);
        $this->assertStringContainsString('"name":"Łódź Magazyn"', $html);
        $this->assertStringContainsString('"streetAddress":"Piotrkowska 50"', $html);
        $this->assertStringContainsString('"telephone":"+48 42 999 88 77"', $html);
    }

    /**
     * A location without any of the optional structured-data fields still
     * emits a minimal, valid LocalBusiness block (name only) — never an
     * empty/malformed script tag.
     */
    public function test_structured_data_still_emits_minimal_schema_when_every_optional_field_is_absent(): void
    {
        $location = Location::factory()->create([
            'name' => 'Bez Danych',
            'street' => null,
            'building' => null,
            'postal_code' => null,
            'city' => null,
            'phone' => null,
            'email' => null,
            'latitude' => null,
            'longitude' => null,
            'photo' => null,
            'opening_hours' => null,
        ]);

        $html = Blade::render('<x-ios.location-card :location="$location" />', ['location' => $location]);

        $this->assertStringContainsString('<script type="application/ld+json">', $html);
        $this->assertStringContainsString('"name":"Bez Danych"', $html);
        $this->assertStringNotContainsString('"address"', $html);
        $this->assertStringNotContainsString('"telephone"', $html);
        $this->assertStringNotContainsString('"openingHoursSpecification"', $html);
    }

    /**
     * A tenant-controlled field containing a literal "</script>" sequence
     * must not be able to terminate the JSON-LD script block early — the
     * safe-raw-echo escaping (str_replace('</', '<\/', ...)) is the only
     * thing standing between free tenant text and script injection here.
     */
    public function test_structured_data_neutralises_a_closing_script_tag_in_tenant_text(): void
    {
        $location = Location::factory()->create([
            'name' => 'Wrocław</script><script>alert(1)</script>',
        ]);

        $html = Blade::render('<x-ios.location-card :location="$location" />', ['location' => $location]);

        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $html);
        $this->assertStringContainsString('<\/script><script>alert(1)<\/script>', $html);
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
