<?php

namespace Tests\Unit\Models;

use App\Enums\ServiceType;
use App\Models\Rental;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_service_type_is_immutable_after_creation(): void
    {
        $service = Service::factory()->itemRental()->create();

        $service->update(['service_type' => ServiceType::TimeSlot]);
        $service->refresh();

        $this->assertEquals(ServiceType::ItemRental, $service->service_type);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function polishDurations(): array
    {
        // Days carry a no-break space so a line break cannot split "3 / dni"; hours and minutes keep the plain space they always had.
        return [
            '1440 min is one day, singular (it printed "1 dni" while $days was a float)' => [1440, "1\u{00A0}dzień"],
            '1500 min is a day and an hour' => [1500, "1\u{00A0}dzień, 1 godz"],
            '2880 min is two days, 2-4 form' => [2880, "2\u{00A0}dni"],
            '34560 min is 24 days, the form follows the last digit' => [34560, "24\u{00A0}dni"],
            '0 min' => [0, '0 min'],
        ];
    }

    #[DataProvider('polishDurations')]
    public function test_formatted_duration_in_polish(int $minutes, string $expected): void
    {
        app()->setLocale('pl');
        $service = new Service;
        $service->duration_minutes = $minutes;

        $this->assertSame($expected, $service->formatted_duration);
    }

    public function test_formatted_duration_in_english(): void
    {
        app()->setLocale('en');
        $service = new Service;

        $service->duration_minutes = 1440;
        $this->assertSame("1\u{00A0}day", $service->formatted_duration);

        $service->duration_minutes = 2910;
        $this->assertSame("2\u{00A0}days, 30 min", $service->formatted_duration);

        $service->duration_minutes = 90;
        $this->assertSame('1 h, 30 min', $service->formatted_duration);
    }

    public function test_formatted_duration_returns_null_when_duration_is_null(): void
    {
        $service = new Service;
        $service->duration_minutes = null;

        $this->assertNull($service->formatted_duration);
    }

    public function test_formatted_duration_returns_zero_min_for_rental(): void
    {
        $service = Service::factory()->itemRental()->create();

        $this->assertEquals('0 min', $service->formatted_duration);
    }

    public function test_formatted_rental_price_returns_null_when_no_price(): void
    {
        $service = Service::factory()->itemRental()->create([
            'price_per_day' => null,
        ]);

        $this->assertNull($service->formatted_rental_price);
    }

    public function test_service_type_immutability_does_not_block_other_updates(): void
    {
        $service = Service::factory()->itemRental()->create([
            'name' => 'Original Name',
        ]);

        $service->update(['name' => 'Updated Name', 'service_type' => ServiceType::TimeSlot]);
        $service->refresh();

        $this->assertEquals('Updated Name', $service->name);
        $this->assertEquals(ServiceType::ItemRental, $service->service_type);
    }

    /**
     * ClickUp 123k99ct3j1 — sedno zgłoszenia: a save with NO changes must
     * not mutate metadata.specs when it is already in the canonical list
     * shape. Pins the exact invariant PanelWalkthroughTest checks end-to-end
     * for every resource — this test is the narrow, Service-specific proof.
     */
    public function test_no_op_save_does_not_mutate_a_list_shaped_specs_field(): void
    {
        $specs = [['label' => 'Moc', 'value' => 800, 'unit' => 'W']];
        $service = Service::factory()->itemRental()->create(['metadata' => ['specs' => $specs]]);

        $service->refresh();
        $service->save();
        $service->refresh();

        $this->assertSame($specs, $service->metadata['specs']);
        $this->assertFalse($service->wasChanged());
    }

    /**
     * App\Models\Concerns\NormalizesSpecsShape — the standing defense
     * against a dict-shaped specs field reaching the database from ANY
     * write path (not only Filament's Repeater, which is what the
     * migration + fixed seeder handle for existing/seeded data).
     */
    public function test_saving_normalizes_a_dict_shaped_specs_field_into_list_shape(): void
    {
        $service = Service::factory()->itemRental()->create();

        $service->metadata = ['specs' => ['power_w' => 800, 'weight_kg' => 4.2]];
        $service->save();
        $service->refresh();

        $this->assertSame([
            ['label' => 'Moc', 'value' => 800, 'unit' => 'W'],
            ['label' => 'Waga', 'value' => 4.2, 'unit' => 'kg'],
        ], $service->metadata['specs']);
    }

    public function test_saving_leaves_an_empty_specs_field_untouched(): void
    {
        $service = Service::factory()->itemRental()->create(['metadata' => ['specs' => []]]);

        $service->refresh();
        $service->save();
        $service->refresh();

        $this->assertSame([], $service->metadata['specs']);
    }
}
