<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\RentalStatus;
use App\Filament\Resources\RentalResource\Pages\CreateRental;
use App\Filament\Resources\RentalResource\Pages\EditRental;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Faza 4 krok 4.4 (kontrakt-dostepnosci.md) — CreateRental/EditRental were
 * the two of the nine getAvailableQuantity() call sites with NO existing
 * form field to source $locationId from at all; RentalResource::form() now
 * has one (see that file's own docblock). Both tests below are deliberately
 * constructed so the two outcomes only differ depending on whether
 * $locationId is actually forwarded — not just present in the signature:
 *
 * - test_creating_a_rental_at_a_location_with_free_stock_succeeds_even_when_
 *   the_global_total_is_zero: quantity_total = 0 (the null-branch capacity)
 *   but the SELECTED location has a free unit. Reverting the fix (dropping
 *   `locationId: ...` from CreateRental) makes this FAIL, because the call
 *   would fall back to the null branch's quantity_total = 0.
 * - test_creating_a_rental_at_a_fully_booked_location_fails_even_when_the_
 *   global_total_is_high: quantity_total = 5 but the SELECTED location has
 *   zero free capacity. Reverting the fix makes this WRONGLY SUCCEED,
 *   because the null branch would see quantity_total = 5 and ignore the
 *   per-location reservation entirely.
 *
 * No existing test file covered CreateRental/EditRental before this change —
 * confirmed by grep (zero hits for the two class names anywhere in tests/).
 */
class RentalResourceLocationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Location $locationA;

    private Location $locationB;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super-admin', 'admin', 'staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->org = Organization::factory()->equipmentRental()->create();
        session(['tenant_id' => $this->org->id]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($this->org->id, ['role' => 'admin']);
        $this->actingAs($admin);

        $this->service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'price_per_day' => 100,
        ]);

        // Locations created AFTER the service so ServiceLocationStockObserver
        // auto-materializes the anchor rows (same precedent as
        // RentalAvailabilityServiceLocationTest::setUp()).
        $this->locationA = Location::factory()->for($this->org, 'organization')->create();
        $this->locationB = Location::factory()->for($this->org, 'organization')->create();
    }

    private function start(): Carbon
    {
        return Carbon::today()->addDays(10);
    }

    private function end(): Carbon
    {
        return Carbon::today()->addDays(12);
    }

    private function baseFormData(array $overrides = []): array
    {
        return array_merge([
            'service_id' => $this->service->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 1,
            'start_date' => $this->start()->toDateString(),
            'end_date' => $this->end()->toDateString(),
            'pricing_unit' => 'daily',
            'status' => RentalStatus::Pending->value,
            'unit_price_at_booking' => 100,
            'total_price' => 200,
        ], $overrides);
    }

    public function test_creating_a_rental_at_a_location_with_free_stock_succeeds_even_when_the_global_total_is_zero(): void
    {
        $this->service->update(['quantity_total' => 0]);

        ServiceLocationStock::where('service_id', $this->service->id)
            ->where('location_id', $this->locationA->id)
            ->update(['quantity' => 1]);

        Livewire::test(CreateRental::class)
            ->fillForm($this->baseFormData(['location_id' => $this->locationA->id]))
            ->call('create');

        $this->assertDatabaseCount('rentals', 1);
        $this->assertDatabaseHas('rentals', [
            'service_id' => $this->service->id,
            'location_id' => $this->locationA->id,
        ]);
    }

    public function test_creating_a_rental_at_a_fully_booked_location_fails_even_when_the_global_total_is_high(): void
    {
        $this->service->update(['quantity_total' => 5]);

        ServiceLocationStock::where('service_id', $this->service->id)
            ->where('location_id', $this->locationA->id)
            ->update(['quantity' => 1]);

        // Already fully reserved at location A, for the exact same window.
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->service->id,
            'location_id' => $this->locationA->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 1,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Confirmed,
        ]);

        Livewire::test(CreateRental::class)
            ->fillForm($this->baseFormData(['location_id' => $this->locationA->id]))
            ->call('create');

        // No second rental was created — the location-scoped check rejected it.
        $this->assertDatabaseCount('rentals', 1);
    }

    /**
     * Team lead review, item 3: the defensive `modifyQueryUsing` on
     * `location_id` (same pattern as UnitsRelationManager, "Select
     * pojedynczy broni się sam") had no test of its own for THIS resource —
     * it only had precedent from a different one. Mirrors
     * UnitsRelationManagerTest::test_submitting_another_tenants_location_id_is_rejected_server_side().
     */
    public function test_creating_a_rental_with_another_tenants_location_id_is_rejected_server_side(): void
    {
        $otherTenant = Organization::factory()->equipmentRental()->create();
        $otherLocation = Location::factory()->for($otherTenant, 'organization')->create();

        Livewire::test(CreateRental::class)
            ->fillForm($this->baseFormData(['location_id' => $otherLocation->id]))
            ->call('create')
            ->assertHasFormErrors(['location_id' => 'in']);

        $this->assertDatabaseMissing('rentals', [
            'service_id' => $this->service->id,
            'location_id' => $otherLocation->id,
        ]);
    }

    public function test_creating_a_rental_without_a_location_falls_back_to_the_global_total_unchanged(): void
    {
        $this->service->update(['quantity_total' => 1]);

        Livewire::test(CreateRental::class)
            ->fillForm($this->baseFormData(['location_id' => null]))
            ->call('create');

        $this->assertDatabaseCount('rentals', 1);
        $this->assertDatabaseHas('rentals', [
            'service_id' => $this->service->id,
            'location_id' => null,
        ]);
    }

    public function test_editing_a_rental_excludes_its_own_reservation_from_its_own_locations_capacity(): void
    {
        ServiceLocationStock::where('service_id', $this->service->id)
            ->where('location_id', $this->locationA->id)
            ->update(['quantity' => 1]);

        $rental = Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->service->id,
            'location_id' => $this->locationA->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 1,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Pending,
            'unit_price_at_booking' => 100,
            'total_price' => 200,
            'pricing_unit' => 'daily',
        ]);

        // Editing a field that isn't quantity/dates/location must not be
        // rejected by the rental's own (already-counted) reservation at
        // location A — this is what excludeRentalId is for.
        Livewire::test(EditRental::class, ['record' => $rental->getKey()])
            ->fillForm($this->baseFormData([
                'location_id' => $this->locationA->id,
                'notes' => 'Zaktualizowano.',
            ]))
            ->call('save');

        $rental->refresh();
        $this->assertSame('Zaktualizowano.', $rental->notes);
    }

    /**
     * Team lead review, item 4 (cheap insurance): the `location_id` field's
     * `->default(primary_slot)` only seeds a CREATE form's initial state —
     * it must not silently assign a location to an existing rental that was
     * deliberately left location-less, just because it's being edited.
     */
    public function test_editing_a_rental_does_not_overwrite_a_null_location_with_the_default_primary(): void
    {
        $rental = Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->service->id,
            'location_id' => null,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 1,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Pending,
            'unit_price_at_booking' => 100,
            'total_price' => 200,
            'pricing_unit' => 'daily',
        ]);

        Livewire::test(EditRental::class, ['record' => $rental->getKey()])
            ->fillForm($this->baseFormData([
                'location_id' => null,
                'notes' => 'Bez oddziału.',
            ]))
            ->call('save');

        $rental->refresh();
        $this->assertNull($rental->location_id);
        $this->assertSame('Bez oddziału.', $rental->notes);
    }

    public function test_editing_a_rental_into_a_fully_booked_other_location_fails(): void
    {
        ServiceLocationStock::where('service_id', $this->service->id)
            ->where('location_id', $this->locationA->id)
            ->update(['quantity' => 1]);
        ServiceLocationStock::where('service_id', $this->service->id)
            ->where('location_id', $this->locationB->id)
            ->update(['quantity' => 1]);

        $rental = Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->service->id,
            'location_id' => $this->locationA->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 1,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Pending,
            'unit_price_at_booking' => 100,
            'total_price' => 200,
            'pricing_unit' => 'daily',
        ]);

        // Location B is fully booked by a DIFFERENT rental.
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->service->id,
            'location_id' => $this->locationB->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 1,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Confirmed,
        ]);

        // Moving the FIRST rental to location B must fail — its own
        // excludeRentalId only excludes ITS OWN row, not the other one.
        Livewire::test(EditRental::class, ['record' => $rental->getKey()])
            ->fillForm($this->baseFormData(['location_id' => $this->locationB->id]))
            ->call('save');

        $rental->refresh();
        $this->assertEquals($this->locationA->id, $rental->location_id, 'The rental must not have moved to the fully booked location.');
    }
}
