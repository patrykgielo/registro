<?php

namespace Tests\Unit\Models;

use App\Models\Organization;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RentalItemAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->itemRental()->create();
        $this->customer = User::factory()->create();
    }

    public function test_available_quantity_with_no_rentals(): void
    {
        $item = RentalItem::factory()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $available = $item->availableQuantity(
            Carbon::today(),
            Carbon::today()->addDays(3)
        );

        $this->assertEquals(5, $available);
    }

    public function test_available_quantity_with_overlapping_rental(): void
    {
        $item = RentalItem::factory()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 3,
        ]);

        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'rental_item_id' => $item->id,
            'customer_id' => $this->customer->id,
            'quantity' => 2,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today()->addDays(5),
            'status' => 'confirmed',
        ]);

        $available = $item->availableQuantity(
            Carbon::today()->addDay(),
            Carbon::today()->addDays(3)
        );

        $this->assertEquals(1, $available);
    }

    public function test_available_quantity_ignores_cancelled_rentals(): void
    {
        $item = RentalItem::factory()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 2,
        ]);

        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'rental_item_id' => $item->id,
            'customer_id' => $this->customer->id,
            'quantity' => 2,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today()->addDays(3),
            'status' => 'cancelled',
        ]);

        $available = $item->availableQuantity(
            Carbon::today(),
            Carbon::today()->addDays(3)
        );

        $this->assertEquals(2, $available);
    }

    public function test_available_quantity_ignores_returned_rentals(): void
    {
        $item = RentalItem::factory()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 2,
        ]);

        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'rental_item_id' => $item->id,
            'customer_id' => $this->customer->id,
            'quantity' => 2,
            'start_date' => Carbon::today()->subDays(5),
            'end_date' => Carbon::today()->subDay(),
            'status' => 'returned',
        ]);

        $available = $item->availableQuantity(
            Carbon::today(),
            Carbon::today()->addDays(3)
        );

        $this->assertEquals(2, $available);
    }

    public function test_available_quantity_with_non_overlapping_rental(): void
    {
        $item = RentalItem::factory()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 2,
        ]);

        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'rental_item_id' => $item->id,
            'customer_id' => $this->customer->id,
            'quantity' => 2,
            'start_date' => Carbon::today()->addDays(10),
            'end_date' => Carbon::today()->addDays(15),
            'status' => 'confirmed',
        ]);

        $available = $item->availableQuantity(
            Carbon::today(),
            Carbon::today()->addDays(3)
        );

        $this->assertEquals(2, $available);
    }

    public function test_is_available_returns_true_when_enough_quantity(): void
    {
        $item = RentalItem::factory()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 3,
        ]);

        $this->assertTrue($item->isAvailable(Carbon::today(), Carbon::today()->addDays(3), 2));
    }

    public function test_is_available_returns_false_when_not_enough_quantity(): void
    {
        $item = RentalItem::factory()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 2,
        ]);

        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'rental_item_id' => $item->id,
            'customer_id' => $this->customer->id,
            'quantity' => 2,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today()->addDays(5),
            'status' => 'active',
        ]);

        $this->assertFalse($item->isAvailable(Carbon::today(), Carbon::today()->addDays(3)));
    }

    public function test_multiple_rentals_reduce_availability(): void
    {
        $item = RentalItem::factory()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        // Rental 1: 2 units
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'rental_item_id' => $item->id,
            'customer_id' => $this->customer->id,
            'quantity' => 2,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today()->addDays(5),
            'status' => 'confirmed',
        ]);

        // Rental 2: 1 unit
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'rental_item_id' => $item->id,
            'customer_id' => $this->customer->id,
            'quantity' => 1,
            'start_date' => Carbon::today()->addDay(),
            'end_date' => Carbon::today()->addDays(3),
            'status' => 'active',
        ]);

        $available = $item->availableQuantity(
            Carbon::today()->addDay(),
            Carbon::today()->addDays(3)
        );

        $this->assertEquals(2, $available);
    }
}
