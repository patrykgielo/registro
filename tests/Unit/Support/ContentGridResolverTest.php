<?php

namespace Tests\Unit\Support;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Support\ContentGridResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentGridResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_content_types_without_tenant(): void
    {
        $types = ContentGridResolver::availableContentTypes(null);

        $this->assertArrayHasKey('services', $types);
        $this->assertArrayHasKey('posts', $types);
        $this->assertArrayHasKey('promotions', $types);
        $this->assertArrayHasKey('portfolio', $types);
        $this->assertArrayNotHasKey('rental_items', $types);
    }

    public function test_available_content_types_filtered_by_module(): void
    {
        $org = Organization::factory()->create([
            'booking_type' => 'time_slot',
            'industry' => null,
            'settings' => ['modules' => ['services' => true, 'website' => false]],
        ]);

        $types = ContentGridResolver::availableContentTypes($org);

        $this->assertArrayHasKey('services', $types);
        $this->assertArrayNotHasKey('posts', $types);
    }

    public function test_rental_items_not_a_content_type(): void
    {
        $types = ContentGridResolver::availableContentTypes(null);

        $this->assertArrayNotHasKey('rental_items', $types);
    }

    public function test_options_for_services_type(): void
    {
        Service::factory()->create(['name' => 'Test Service', 'is_active' => true]);
        Service::factory()->create(['name' => 'Inactive Service', 'is_active' => false]);

        $options = ContentGridResolver::optionsForType('services');

        $this->assertCount(1, $options);
        $this->assertContains('Test Service', $options);
    }

    public function test_options_for_unknown_type_returns_empty(): void
    {
        $options = ContentGridResolver::optionsForType('rental_items');

        $this->assertEmpty($options);
    }

    public function test_resolve_items_preserves_order(): void
    {
        $s1 = Service::factory()->create(['name' => 'First', 'is_active' => true]);
        $s2 = Service::factory()->create(['name' => 'Second', 'is_active' => true]);
        $s3 = Service::factory()->create(['name' => 'Third', 'is_active' => true]);

        $items = ContentGridResolver::resolveItems('services', [$s3->id, $s1->id, $s2->id]);

        $this->assertCount(3, $items);
        $this->assertEquals($s3->id, $items[0]->id);
        $this->assertEquals($s1->id, $items[1]->id);
        $this->assertEquals($s2->id, $items[2]->id);
    }

    public function test_resolve_items_for_unknown_type_returns_empty(): void
    {
        $items = ContentGridResolver::resolveItems('rental_items', [1, 2, 3]);

        $this->assertTrue($items->isEmpty());
    }

    public function test_locations_available_only_when_website_module_active(): void
    {
        $withWebsite = Organization::factory()->create([
            'booking_type' => 'time_slot',
            'industry' => null,
            'settings' => ['modules' => ['website' => true]],
        ]);
        $withoutWebsite = Organization::factory()->create([
            'booking_type' => 'time_slot',
            'industry' => null,
            'settings' => ['modules' => ['website' => false]],
        ]);

        $this->assertArrayHasKey('locations', ContentGridResolver::availableContentTypes($withWebsite));
        $this->assertArrayNotHasKey('locations', ContentGridResolver::availableContentTypes($withoutWebsite));
        // Platform panel / super-admin context (null tenant) sees every type unfiltered.
        $this->assertArrayHasKey('locations', ContentGridResolver::availableContentTypes(null));
    }

    public function test_options_for_locations_type_excludes_inactive_and_labels_with_city(): void
    {
        Location::factory()->create(['name' => 'Magazyn Główny', 'city' => 'Warszawa', 'is_active' => true]);
        Location::factory()->inactive()->create(['name' => 'Zamknięty Oddział', 'city' => 'Łódź']);

        $options = ContentGridResolver::optionsForType('locations');

        $this->assertCount(1, $options);
        $this->assertContains('Magazyn Główny (Warszawa)', $options);
    }

    public function test_options_for_locations_type_is_scoped_to_current_tenant(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        $visible = Location::factory()->for($orgA, 'organization')->create(['name' => 'Warszawa', 'city' => null]);
        Location::factory()->for($orgB, 'organization')->create(['name' => 'Gdańsk', 'city' => null]);

        $this->app['request']->attributes->set('tenant', $orgA);

        $options = ContentGridResolver::optionsForType('locations');

        $this->assertSame([$visible->id => 'Warszawa'], $options);
    }

    public function test_resolve_items_for_locations_preserves_order(): void
    {
        $l1 = Location::factory()->create(['name' => 'First']);
        $l2 = Location::factory()->create(['name' => 'Second']);
        $l3 = Location::factory()->create(['name' => 'Third']);

        $items = ContentGridResolver::resolveItems('locations', [$l3->id, $l1->id, $l2->id]);

        $this->assertCount(3, $items);
        $this->assertEquals($l3->id, $items[0]->id);
        $this->assertEquals($l1->id, $items[1]->id);
        $this->assertEquals($l2->id, $items[2]->id);
    }
}
