<?php

namespace Tests\Unit\Actions;

use App\Actions\Onboarding\Seeders\SeedAutoDetailing;
use App\Actions\Onboarding\Seeders\SeedEquipmentRental;
use App\Actions\Onboarding\Seeders\SeedGeneralServices;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\RentalItem;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerticalSeederTest extends TestCase
{
    use RefreshDatabase;

    private function createOrganization(string $bookingType = 'time_slot'): Organization
    {
        $user = \App\Models\User::factory()->create();

        return Organization::create([
            'name' => 'Test Org',
            'slug' => 'test-org-'.rand(1000, 9999),
            'booking_type' => $bookingType,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
    }

    public function test_seed_equipment_rental_creates_categories(): void
    {
        $org = $this->createOrganization('item_rental');
        $seeder = new SeedEquipmentRental;

        $seeder->seed($org);

        $categories = RentalCategory::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->get();

        $this->assertEquals(7, $categories->count());
    }

    public function test_seed_equipment_rental_creates_items(): void
    {
        $org = $this->createOrganization('item_rental');
        $seeder = new SeedEquipmentRental;

        $seeder->seed($org);

        $items = RentalItem::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->get();

        $this->assertEquals(13, $items->count());
    }

    public function test_seed_equipment_rental_items_have_tiered_pricing(): void
    {
        $org = $this->createOrganization('item_rental');
        $seeder = new SeedEquipmentRental;

        $seeder->seed($org);

        $item = RentalItem::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->first();

        $this->assertNotNull($item->price_per_day);
        $this->assertNotNull($item->price_per_day_long);
        $this->assertNotNull($item->price_threshold_days);
        $this->assertNotNull($item->deposit_amount);
        $this->assertGreaterThan(0, (float) $item->price_per_day);
        $this->assertLessThan((float) $item->price_per_day, (float) $item->price_per_day_long);
    }

    public function test_seed_equipment_rental_items_have_specifications(): void
    {
        $org = $this->createOrganization('item_rental');
        $seeder = new SeedEquipmentRental;

        $seeder->seed($org);

        $item = RentalItem::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->first();

        $this->assertIsArray($item->specifications);
        $this->assertArrayHasKey('specs', $item->specifications);
    }

    public function test_seed_auto_detailing_creates_services(): void
    {
        $org = $this->createOrganization('time_slot');
        $seeder = new SeedAutoDetailing;

        $seeder->seed($org);

        $services = Service::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->get();

        $this->assertEquals(8, $services->count());
    }

    public function test_seed_auto_detailing_services_have_metadata(): void
    {
        $org = $this->createOrganization('time_slot');
        $seeder = new SeedAutoDetailing;

        $seeder->seed($org);

        $service = Service::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->first();

        $this->assertIsArray($service->metadata);
        $this->assertArrayHasKey('prices_by_size', $service->metadata);
        $this->assertArrayHasKey('durations_by_size', $service->metadata);
        $this->assertArrayHasKey('A', $service->metadata['prices_by_size']);
        $this->assertArrayHasKey('D', $service->metadata['prices_by_size']);
    }

    public function test_seed_general_services_creates_placeholder(): void
    {
        $org = $this->createOrganization('time_slot');
        $seeder = new SeedGeneralServices;

        $seeder->seed($org);

        $services = Service::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->get();

        $this->assertEquals(1, $services->count());
        $this->assertEquals('Przykładowa usługa', $services->first()->name);
    }
}
