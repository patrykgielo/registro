<?php

namespace Tests\Unit\Models;

use App\Enums\RentalStatus;
use App\Enums\ServiceType;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_service_type_is_time_slot(): void
    {
        $service = Service::factory()->create();

        $this->assertEquals(ServiceType::TimeSlot, $service->service_type);
    }

    public function test_item_rental_factory_state(): void
    {
        $service = Service::factory()->itemRental()->create();

        $this->assertEquals(ServiceType::ItemRental, $service->service_type);
        $this->assertNotNull($service->price_per_day);
        $this->assertNotNull($service->quantity_total);
        $this->assertEquals(0, $service->duration_minutes);
    }

    public function test_scope_rentable_filters_item_rental(): void
    {
        Service::factory()->create(['service_type' => ServiceType::TimeSlot]);
        Service::factory()->itemRental()->create();
        Service::factory()->itemRental()->create();

        $this->assertCount(2, Service::rentable()->get());
    }

    public function test_scope_bookable_filters_time_slot(): void
    {
        Service::factory()->create(['service_type' => ServiceType::TimeSlot]);
        Service::factory()->create(['service_type' => ServiceType::TimeSlot]);
        Service::factory()->itemRental()->create();

        $this->assertCount(2, Service::bookable()->get());
    }

    public function test_category_relationship(): void
    {
        $category = RentalCategory::factory()->create();
        $service = Service::factory()->itemRental()->create([
            'rental_category_id' => $category->id,
        ]);

        $this->assertInstanceOf(RentalCategory::class, $service->category);
        $this->assertEquals($category->id, $service->category->id);
    }

    public function test_rentals_relationship(): void
    {
        $service = Service::factory()->itemRental()->create();
        $customer = User::factory()->create();

        Rental::factory()->create([
            'service_id' => $service->id,
            'customer_id' => $customer->id,
        ]);

        $this->assertCount(1, $service->rentals);
    }

    public function test_formatted_rental_price_day_only(): void
    {
        $service = Service::factory()->itemRental()->create([
            'price_per_day' => 100.00,
            'price_per_hour' => null,
            'price_per_week' => null,
        ]);

        $this->assertEquals('100,00 zł/dzień', $service->formatted_rental_price);
    }

    public function test_formatted_rental_price_all_units(): void
    {
        $service = Service::factory()->itemRental()->create([
            'price_per_day' => 100.00,
            'price_per_hour' => 15.00,
            'price_per_week' => 600.00,
        ]);

        $this->assertEquals('100,00 zł/dzień | 15,00 zł/godz | 600,00 zł/tydz', $service->formatted_rental_price);
    }

    public function test_available_quantity_with_no_rentals(): void
    {
        $service = Service::factory()->itemRental()->create([
            'quantity_total' => 5,
        ]);

        $available = $service->availableQuantity(
            Carbon::today(),
            Carbon::today()->addDays(3)
        );

        $this->assertEquals(5, $available);
    }

    public function test_is_available_checks_quantity(): void
    {
        $org = Organization::factory()->itemRental()->create();
        $customer = User::factory()->create();
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $org->id,
            'quantity_total' => 2,
        ]);

        Rental::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'quantity' => 2,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today()->addDays(5),
            'status' => RentalStatus::Confirmed,
        ]);

        $this->assertFalse($service->isAvailable(Carbon::today(), Carbon::today()->addDays(3)));
        $this->assertTrue($service->isAvailable(Carbon::today()->addDays(10), Carbon::today()->addDays(15)));
    }

    public function test_time_slot_service_has_duration(): void
    {
        $service = Service::factory()->create([
            'service_type' => ServiceType::TimeSlot,
            'duration_minutes' => 120,
        ]);

        $this->assertEquals('2 godz', $service->formatted_duration);
    }

    public function test_auto_generates_slug_from_name(): void
    {
        $service = Service::factory()->create([
            'name' => 'Wiertarka udarowa BOSCH',
            'slug' => null,
        ]);

        $this->assertEquals('wiertarka-udarowa-bosch', $service->slug);
    }

    public function test_route_key_name_is_slug(): void
    {
        $service = new Service;

        $this->assertEquals('slug', $service->getRouteKeyName());
    }
}
