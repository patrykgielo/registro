<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\RentalResource\Pages\CreateRental;
use App\Filament\Resources\RentalResource\Pages\EditRental;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Service;
use App\Models\User;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * HIGH finding (coordinator review, 2026-07-05): the Filament admin Rental
 * create/edit pages wrote to the same `rentals` table that
 * CartService::convertToOrder() protects via Service-row locking + a locking
 * availability recheck, with zero locking of its own — an independent,
 * unlocked entry point into the same availability pool. These tests exercise
 * the fix (CreateRental::handleRecordCreation() / EditRental::handleRecordUpdate())
 * directly, bypassing Livewire's mount lifecycle — same lightweight pattern
 * already used in this codebase for testing Filament Page classes (see
 * Tests\Feature\Analytics\AnalyticsOverviewPageTest: `new AnalyticsOverview` +
 * binding `tenant` onto the request, no Livewire::test()). handleRecordCreation()/
 * handleRecordUpdate() only depend on static resource resolution + the DB, not
 * on Livewire component state, so this is safe.
 */
class RentalAvailabilityGuardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Service $service;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->equipmentRental()->create();
        $this->service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 1,
        ]);
        $this->customer = User::factory()->create();

        // Bind tenant context so BelongsToOrganization / TenantFeature::currentTenant()
        // resolve correctly — same pattern as AnalyticsOverviewPageTest.
        $this->app['request']->attributes->set('tenant', $this->org);
    }

    /**
     * @return array<string, mixed>
     */
    private function rentalData(array $overrides = []): array
    {
        return array_merge([
            'service_id' => $this->service->id,
            'customer_id' => $this->customer->id,
            'quantity' => 1,
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-12',
            'pricing_unit' => 'daily',
            'unit_price_at_booking' => 100,
            'total_price' => 300,
            'status' => 'pending',
        ], $overrides);
    }

    private function invokeCreate(array $data): Rental
    {
        $page = new CreateRental;
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);

        return $method->invoke($page, $data);
    }

    private function invokeUpdate(Rental $record, array $data): Rental
    {
        $page = new EditRental;
        $method = new ReflectionMethod($page, 'handleRecordUpdate');
        $method->setAccessible(true);

        return $method->invoke($page, $record, $data);
    }

    public function test_create_rental_succeeds_when_stock_available(): void
    {
        $record = $this->invokeCreate($this->rentalData());

        $this->assertInstanceOf(Rental::class, $record);
        $this->assertDatabaseHas('rentals', ['id' => $record->id, 'quantity' => 1]);
    }

    public function test_create_rental_is_blocked_when_stock_unavailable(): void
    {
        // First rental consumes the service's only unit (quantity_total = 1).
        $this->invokeCreate($this->rentalData());

        $this->expectException(Halt::class);

        try {
            $this->invokeCreate($this->rentalData());
        } finally {
            $this->assertDatabaseCount('rentals', 1);
        }
    }

    public function test_create_rental_skips_check_for_non_blocking_status(): void
    {
        $this->invokeCreate($this->rentalData());

        // 'cancelled' does not consume capacity (RentalStatus::blocksAvailability()
        // === false) — allowed even though the only unit is already reserved by
        // the first (blocking, 'pending') rental above.
        $record = $this->invokeCreate($this->rentalData(['status' => 'cancelled']));

        $this->assertInstanceOf(Rental::class, $record);
        $this->assertDatabaseCount('rentals', 2);
    }

    public function test_edit_rental_excludes_its_own_reservation_from_the_check(): void
    {
        $record = $this->invokeCreate($this->rentalData());

        // Re-saving with the SAME quantity must not be blocked by the record's
        // own prior reservation (which would happen without $excludeRentalId:
        // available = 1 total - 1 (self, double-counted) = 0 < requested 1).
        $updated = $this->invokeUpdate($record->fresh(), $this->rentalData(['quantity' => 1, 'status' => 'confirmed']));

        $this->assertSame('confirmed', $updated->fresh()->status->value);
    }

    public function test_edit_rental_is_blocked_when_increasing_beyond_available_stock(): void
    {
        $record = $this->invokeCreate($this->rentalData(['quantity' => 1]));

        $this->expectException(Halt::class);

        try {
            $this->invokeUpdate($record->fresh(), $this->rentalData(['quantity' => 2]));
        } finally {
            $this->assertDatabaseHas('rentals', ['id' => $record->id, 'quantity' => 1]);
        }
    }
}
