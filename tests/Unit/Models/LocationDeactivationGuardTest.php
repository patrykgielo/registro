<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Exceptions\LocationCannotBeDeactivatedException;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faza 6 code review (2026-09-10) — the deactivation-side twin of
 * LocationDeletionGuardTest. Before this, HARD deletion of the last/primary
 * location was guarded (App\Observers\LocationObserver::deleting(), since
 * Faza 1) but nothing guarded DEACTIVATING it — a one-click dead end for
 * every customer with an existing cart, reproduced end-to-end in
 * LocationDeactivationCheckoutGuardTest.
 *
 * Same "call the model directly, no Livewire/LocationResource/HTTP" shape as
 * LocationDeletionGuardTest — proves App\Observers\LocationObserver is the
 * real backstop, not just the Filament UI layer (own coverage in
 * Tests\Feature\Filament\LocationResourceDeactivationGuardTest).
 */
class LocationDeactivationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivating_the_only_active_location_of_a_tenant_throws_even_outside_filament(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $only = Location::factory()->for($org, 'organization')->create(['is_active' => true]);

        $this->expectException(LocationCannotBeDeactivatedException::class);
        $this->expectExceptionMessageMatches('/only active location/');

        $only->update(['is_active' => false]);
    }

    public function test_deactivating_one_of_two_active_locations_succeeds(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $secondary = Location::factory()->for($org, 'organization')->create(['is_active' => true]);

        $secondary->update(['is_active' => false]);

        $this->assertFalse($secondary->fresh()->is_active);
    }

    /**
     * A no-op save (already active, saved again) or an update to an
     * unrelated field must not trip the guard — it only fires when
     * `is_active` is actually DIRTY and turning false.
     */
    public function test_updating_an_unrelated_field_on_the_only_active_location_succeeds(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $only = Location::factory()->for($org, 'organization')->create(['is_active' => true]);

        $only->update(['name' => 'Nowa nazwa']);

        $this->assertSame('Nowa nazwa', $only->fresh()->name);
        $this->assertTrue($only->fresh()->is_active);
    }

    /**
     * Reactivating (false → true) is never blocked — the guard only cares
     * about the direction that would strand customers.
     */
    public function test_reactivating_a_location_always_succeeds(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $only = Location::factory()->for($org, 'organization')->create(['is_active' => false]);

        $only->update(['is_active' => true]);

        $this->assertTrue($only->fresh()->is_active);
    }

    /**
     * Create-side is DELIBERATELY unguarded (tried and reverted — see
     * LocationObserver::creating()'s own note): a not-yet-existing Location
     * cannot strand an EXISTING customer cart, and blocking this would break
     * the legitimate "create a branch as a draft, activate it once it's
     * ready" admin workflow — including creating a tenant's very FIRST
     * location inactive, before anything else exists for that org yet.
     */
    public function test_creating_a_tenants_first_location_as_inactive_succeeds(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $only = Location::factory()->for($org, 'organization')->create(['is_active' => false]);

        $this->assertFalse($only->fresh()->is_active);
    }

    /**
     * A blocked deactivation leaves the row exactly as it was — no partial
     * write, no primary_slot side effect.
     */
    public function test_a_blocked_deactivation_leaves_the_location_untouched(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $only = Location::factory()->for($org, 'organization')->create(['is_active' => true]);

        try {
            $only->update(['is_active' => false]);
            $this->fail('Expected LocationCannotBeDeactivatedException');
        } catch (LocationCannotBeDeactivatedException) {
            // expected
        }

        $this->assertTrue($only->fresh()->is_active);
    }
}
