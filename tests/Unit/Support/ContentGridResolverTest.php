<?php

namespace Tests\Unit\Support;

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
}
