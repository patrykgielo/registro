<?php

declare(strict_types=1);

namespace Tests\Feature\Rental;

use App\Enums\RentalStatus;
use App\Exceptions\RentalUnavailableException;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Service;
use App\Services\RentalAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RentalAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private RentalAvailabilityService $service;

    private Organization $org;

    private Service $rentalService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RentalAvailabilityService::class);

        $this->org = Organization::factory()->equipmentRental()->create();

        // Create a rental service with 3 items, owned by this org
        $this->rentalService = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 3,
            'price_per_day' => 100.00,
            'price_per_week' => null,
            'price_per_day_long' => null,
            'price_threshold_days' => null,
            'is_active' => true,
        ]);
    }

    // ─────────────────────────────────────────────
    // getAvailableQuantity
    // ─────────────────────────────────────────────

    public function test_full_quantity_available_when_no_rentals_exist(): void
    {
        $available = $this->service->getAvailableQuantity(
            $this->rentalService,
            Carbon::parse('2027-06-01'),
            Carbon::parse('2027-06-05')
        );

        $this->assertSame(3, $available);
    }

    public function test_quantity_reduced_by_active_rental(): void
    {
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'quantity' => 2,
            'start_date' => '2027-06-01',
            'end_date' => '2027-06-05',
            'status' => RentalStatus::Confirmed,
        ]);

        $available = $this->service->getAvailableQuantity(
            $this->rentalService,
            Carbon::parse('2027-06-01'),
            Carbon::parse('2027-06-05')
        );

        $this->assertSame(1, $available);
    }

    public function test_held_rental_blocks_availability(): void
    {
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'quantity' => 3,
            'start_date' => '2027-06-01',
            'end_date' => '2027-06-05',
            'status' => RentalStatus::Held,
            'held_until' => now()->addMinutes(15),
        ]);

        $available = $this->service->getAvailableQuantity(
            $this->rentalService,
            Carbon::parse('2027-06-01'),
            Carbon::parse('2027-06-05')
        );

        $this->assertSame(0, $available);
    }

    public function test_cancelled_rental_does_not_block_availability(): void
    {
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'quantity' => 3,
            'start_date' => '2027-06-01',
            'end_date' => '2027-06-05',
            'status' => RentalStatus::Cancelled,
        ]);

        $available = $this->service->getAvailableQuantity(
            $this->rentalService,
            Carbon::parse('2027-06-01'),
            Carbon::parse('2027-06-05')
        );

        $this->assertSame(3, $available);
    }

    public function test_expired_rental_does_not_block_availability(): void
    {
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'quantity' => 3,
            'start_date' => '2027-06-01',
            'end_date' => '2027-06-05',
            'status' => RentalStatus::Expired,
        ]);

        $available = $this->service->getAvailableQuantity(
            $this->rentalService,
            Carbon::parse('2027-06-01'),
            Carbon::parse('2027-06-05')
        );

        $this->assertSame(3, $available);
    }

    public function test_non_overlapping_rental_does_not_reduce_availability(): void
    {
        // Rental ends before our query range starts
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'quantity' => 3,
            'start_date' => '2027-05-20',
            'end_date' => '2027-05-31',
            'status' => RentalStatus::Confirmed,
        ]);

        $available = $this->service->getAvailableQuantity(
            $this->rentalService,
            Carbon::parse('2027-06-01'),
            Carbon::parse('2027-06-05')
        );

        $this->assertSame(3, $available);
    }

    public function test_available_quantity_never_goes_below_zero(): void
    {
        // Create rentals totalling more than quantity_total (edge case)
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'quantity' => 5,
            'start_date' => '2027-06-01',
            'end_date' => '2027-06-05',
            'status' => RentalStatus::Confirmed,
        ]);

        $available = $this->service->getAvailableQuantity(
            $this->rentalService,
            Carbon::parse('2027-06-01'),
            Carbon::parse('2027-06-05')
        );

        $this->assertSame(0, $available);
    }

    // ─────────────────────────────────────────────
    // createHold
    // ─────────────────────────────────────────────

    public function test_create_hold_creates_held_rental(): void
    {
        $rental = $this->service->createHold(
            $this->rentalService,
            Carbon::parse('2027-07-01'),
            Carbon::parse('2027-07-03'),
            quantity: 1
        );

        $this->assertSame(RentalStatus::Held, $rental->status);
        $this->assertNotNull($rental->held_until);
        $this->assertTrue($rental->held_until->isFuture());
        $this->assertSame(1, $rental->quantity);
        $this->assertDatabaseHas('rentals', [
            'id' => $rental->id,
            'status' => 'held',
            'service_id' => $this->rentalService->id,
        ]);
    }

    public function test_create_hold_snapshots_pricing(): void
    {
        $rental = $this->service->createHold(
            $this->rentalService,
            Carbon::parse('2027-07-01'),
            Carbon::parse('2027-07-03'),
            quantity: 1
        );

        // 3 days × 100 = 300
        $this->assertSame('300.00', (string) $rental->total_price);
        $this->assertSame('daily', $rental->pricing_unit);
    }

    public function test_create_hold_throws_when_insufficient_quantity(): void
    {
        $this->expectException(RentalUnavailableException::class);

        $this->service->createHold(
            $this->rentalService,
            Carbon::parse('2027-07-01'),
            Carbon::parse('2027-07-03'),
            quantity: 5 // more than quantity_total=3
        );
    }

    public function test_create_hold_throws_when_all_items_already_held(): void
    {
        // Hold all 3 items
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'quantity' => 3,
            'start_date' => '2027-07-01',
            'end_date' => '2027-07-03',
            'status' => RentalStatus::Held,
            'held_until' => now()->addMinutes(15),
        ]);

        $this->expectException(RentalUnavailableException::class);

        $this->service->createHold(
            $this->rentalService,
            Carbon::parse('2027-07-01'),
            Carbon::parse('2027-07-03'),
            quantity: 1
        );
    }

    public function test_create_hold_sets_deposit_from_service(): void
    {
        $this->rentalService->update(['deposit_amount' => 500.00]);

        $rental = $this->service->createHold(
            $this->rentalService,
            Carbon::parse('2027-07-01'),
            Carbon::parse('2027-07-02'),
            quantity: 1
        );

        $this->assertSame('500.00', (string) $rental->deposit_amount);
    }

    // ─────────────────────────────────────────────
    // confirmHold
    // ─────────────────────────────────────────────

    public function test_confirm_hold_transitions_to_pending(): void
    {
        $rental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
        ]);

        $confirmed = $this->service->confirmHold($rental, [
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => 'jan@example.com',
            'phone' => '500123456',
        ]);

        $this->assertSame(RentalStatus::Pending, $confirmed->status);
        $this->assertNull($confirmed->held_until);
        $this->assertSame('Jan', $confirmed->first_name);
        $this->assertSame('jan@example.com', $confirmed->email);
    }

    public function test_confirm_hold_throws_when_not_held(): void
    {
        $this->expectException(\LogicException::class);

        $rental = Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'status' => RentalStatus::Pending,
        ]);

        $this->service->confirmHold($rental, [
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => 'jan@example.com',
            'phone' => '500123456',
        ]);
    }

    public function test_confirm_hold_throws_when_hold_expired(): void
    {
        $this->expectException(RentalUnavailableException::class);

        $rental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'held_until' => now()->subMinute(), // already expired
        ]);

        $this->service->confirmHold($rental, [
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => 'jan@example.com',
            'phone' => '500123456',
        ]);
    }

    public function test_confirm_hold_sets_rental_to_expired_when_ttl_passed(): void
    {
        $rental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'held_until' => now()->subMinute(),
        ]);

        try {
            $this->service->confirmHold($rental, [
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'email' => 'jan@example.com',
                'phone' => '500123456',
            ]);
        } catch (RentalUnavailableException) {
        }

        $this->assertDatabaseHas('rentals', [
            'id' => $rental->id,
            'status' => 'expired',
        ]);
    }

    // ─────────────────────────────────────────────
    // getMonthlyAvailability
    // ─────────────────────────────────────────────

    public function test_monthly_availability_returns_correct_number_of_days(): void
    {
        $result = $this->service->getMonthlyAvailability($this->rentalService, 2027, 6);

        $this->assertCount(30, $result); // June has 30 days
        $this->assertArrayHasKey('2027-06-01', $result);
        $this->assertArrayHasKey('2027-06-30', $result);
    }

    public function test_monthly_availability_shows_available_when_no_rentals(): void
    {
        $result = $this->service->getMonthlyAvailability($this->rentalService, 2027, 7);

        foreach ($result as $day) {
            $this->assertSame('available', $day['status']);
            $this->assertSame(3, $day['available_quantity']);
        }
    }

    public function test_monthly_availability_shows_unavailable_when_fully_booked(): void
    {
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'quantity' => 3,
            'start_date' => '2027-08-01',
            'end_date' => '2027-08-31',
            'status' => RentalStatus::Confirmed,
        ]);

        $result = $this->service->getMonthlyAvailability($this->rentalService, 2027, 8);

        $this->assertSame('unavailable', $result['2027-08-15']['status']);
        $this->assertSame(0, $result['2027-08-15']['available_quantity']);
    }

    public function test_monthly_availability_shows_partial_when_partially_booked(): void
    {
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'quantity' => 2,
            'start_date' => '2027-09-10',
            'end_date' => '2027-09-20',
            'status' => RentalStatus::Confirmed,
        ]);

        $result = $this->service->getMonthlyAvailability($this->rentalService, 2027, 9);

        $this->assertSame('partial', $result['2027-09-15']['status']);
        $this->assertSame(1, $result['2027-09-15']['available_quantity']);
        // Days outside the booking range are fully available
        $this->assertSame('available', $result['2027-09-05']['status']);
    }

    // ─────────────────────────────────────────────
    // holdTtlMinutes
    // ─────────────────────────────────────────────

    public function test_hold_ttl_is_15_minutes(): void
    {
        $this->assertSame(15, RentalAvailabilityService::holdTtlMinutes());
    }
}
