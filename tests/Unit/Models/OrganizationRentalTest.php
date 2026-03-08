<?php

namespace Tests\Unit\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationRentalTest extends TestCase
{
    use RefreshDatabase;

    public function test_time_slot_org_does_not_support_rentals(): void
    {
        $org = Organization::factory()->create(['booking_type' => 'time_slot']);

        $this->assertFalse($org->supportsRentals());
        $this->assertTrue($org->supportsAppointments());
    }

    public function test_item_rental_org_supports_rentals(): void
    {
        $org = Organization::factory()->itemRental()->create();

        $this->assertTrue($org->supportsRentals());
        $this->assertFalse($org->supportsAppointments());
    }

    public function test_both_org_supports_both(): void
    {
        $owner = User::factory()->create();
        $org = Organization::create([
            'name' => 'Both Org',
            'slug' => 'both-org',
            'booking_type' => 'both',
            'owner_id' => $owner->id,
        ]);

        $this->assertTrue($org->supportsRentals());
        $this->assertTrue($org->supportsAppointments());
    }

    public function test_organization_has_rental_categories(): void
    {
        $org = Organization::factory()->itemRental()->create();

        $this->assertCount(0, $org->rentalCategories);
    }

    public function test_organization_has_rental_items(): void
    {
        $org = Organization::factory()->itemRental()->create();

        $this->assertCount(0, $org->rentalItems);
    }

    public function test_organization_has_rentals(): void
    {
        $org = Organization::factory()->itemRental()->create();

        $this->assertCount(0, $org->rentals);
    }
}
