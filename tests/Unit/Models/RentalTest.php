<?php

namespace Tests\Unit\Models;

use App\Enums\RentalStatus;
use App\Models\Rental;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RentalTest extends TestCase
{
    use RefreshDatabase;

    public function test_rental_can_be_created(): void
    {
        $rental = Rental::factory()->create();

        $this->assertDatabaseHas('rentals', [
            'id' => $rental->id,
            'status' => 'pending',
        ]);
    }

    public function test_rental_belongs_to_service(): void
    {
        $rental = Rental::factory()->create();

        $this->assertInstanceOf(Service::class, $rental->service);
    }

    public function test_rental_belongs_to_customer(): void
    {
        $rental = Rental::factory()->create();

        $this->assertInstanceOf(User::class, $rental->customer);
    }

    public function test_duration_days_accessor(): void
    {
        $rental = Rental::factory()->create([
            'start_date' => Carbon::parse('2026-03-01'),
            'end_date' => Carbon::parse('2026-03-05'),
        ]);

        // 5 days inclusive (March 1-5)
        $this->assertEquals(5, $rental->duration_days);
    }

    public function test_is_overdue_accessor(): void
    {
        $rental = Rental::factory()->create([
            'status' => RentalStatus::Active,
            'start_date' => Carbon::today()->subDays(5),
            'end_date' => Carbon::today()->subDay(),
        ]);

        $this->assertTrue($rental->is_overdue);
    }

    public function test_is_not_overdue_when_end_date_future(): void
    {
        $rental = Rental::factory()->create([
            'status' => RentalStatus::Active,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today()->addDays(3),
        ]);

        $this->assertFalse($rental->is_overdue);
    }

    public function test_is_not_overdue_when_returned(): void
    {
        $rental = Rental::factory()->create([
            'status' => RentalStatus::Returned,
            'start_date' => Carbon::today()->subDays(5),
            'end_date' => Carbon::today()->subDay(),
        ]);

        $this->assertFalse($rental->is_overdue);
    }

    public function test_status_transition_sets_confirmed_at(): void
    {
        $rental = Rental::factory()->create(['status' => RentalStatus::Pending]);

        $rental->update(['status' => RentalStatus::Confirmed]);
        $rental->refresh();

        $this->assertNotNull($rental->confirmed_at);
    }

    public function test_status_transition_sets_picked_up_at(): void
    {
        $rental = Rental::factory()->confirmed()->create();

        $rental->update(['status' => RentalStatus::Active]);
        $rental->refresh();

        $this->assertNotNull($rental->picked_up_at);
    }

    public function test_status_transition_sets_returned_at(): void
    {
        $rental = Rental::factory()->active()->create();

        $rental->update(['status' => RentalStatus::Returned]);
        $rental->refresh();

        $this->assertNotNull($rental->returned_at);
    }

    public function test_status_transition_sets_cancelled_at(): void
    {
        $rental = Rental::factory()->create(['status' => RentalStatus::Pending]);

        $rental->update(['status' => RentalStatus::Cancelled]);
        $rental->refresh();

        $this->assertNotNull($rental->cancelled_at);
    }

    public function test_customer_name_accessor(): void
    {
        $rental = Rental::factory()->create([
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
        ]);

        $this->assertEquals('Jan Kowalski', $rental->customer_name);
    }

    public function test_scopes(): void
    {
        Rental::factory()->create(['status' => RentalStatus::Pending]);
        Rental::factory()->create(['status' => RentalStatus::Confirmed]);
        Rental::factory()->create(['status' => RentalStatus::Active]);
        Rental::factory()->create(['status' => RentalStatus::Returned]);
        Rental::factory()->create(['status' => RentalStatus::Cancelled]);

        $this->assertCount(1, Rental::pending()->get());
        $this->assertCount(1, Rental::confirmed()->get());
        $this->assertCount(1, Rental::active()->get());
        $this->assertCount(1, Rental::returned()->get());
        $this->assertCount(1, Rental::cancelled()->get());
    }

    public function test_status_timestamps_not_mass_assignable(): void
    {
        $rental = Rental::factory()->create();

        $rental->fill([
            'confirmed_at' => '2026-01-01 00:00:00',
            'picked_up_at' => '2026-01-02 00:00:00',
            'returned_at' => '2026-01-03 00:00:00',
            'cancelled_at' => '2026-01-04 00:00:00',
        ]);

        $this->assertNull($rental->confirmed_at);
        $this->assertNull($rental->picked_up_at);
        $this->assertNull($rental->returned_at);
        $this->assertNull($rental->cancelled_at);
    }

    public function test_status_timestamps_still_set_by_transition(): void
    {
        $rental = Rental::factory()->create(['status' => RentalStatus::Pending]);

        $rental->update(['status' => RentalStatus::Confirmed]);
        $rental->refresh();

        $this->assertNotNull($rental->confirmed_at);
    }
}
