<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Location;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins the "exactly one primary location per organization" mechanism
 * (app/docs/features/lokalizacje/tryb-jednooddzialowy.md) —
 * LocationObserver + the UNIQUE(organization_id, primary_slot) constraint it
 * backstops. See LocationObserver's own docblocks for why promotion is a
 * two-commit sequence for a plain save() but a single transaction via
 * Location::promoteToPrimary().
 */
class LocationPrimarySlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_location_for_a_tenant_becomes_primary_automatically(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $location = Location::factory()->for($org, 'organization')->create();

        $this->assertSame(1, $location->fresh()->primary_slot);
    }

    public function test_second_location_for_the_same_tenant_is_not_primary_by_default(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        Location::factory()->for($org, 'organization')->create();
        $second = Location::factory()->for($org, 'organization')->create();

        $this->assertNull($second->fresh()->primary_slot);
    }

    public function test_a_second_tenants_first_location_becomes_primary_independently(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        $locationA = Location::factory()->for($orgA, 'organization')->create();
        $locationB = Location::factory()->for($orgB, 'organization')->create();

        $this->assertSame(1, $locationA->fresh()->primary_slot);
        $this->assertSame(1, $locationB->fresh()->primary_slot);
    }

    public function test_creating_a_second_location_explicitly_marked_primary_demotes_the_first(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $first = Location::factory()->for($org, 'organization')->create();
        $this->assertSame(1, $first->fresh()->primary_slot);

        $second = Location::factory()->for($org, 'organization')->primary()->create();

        $this->assertNull($first->fresh()->primary_slot);
        $this->assertSame(1, $second->fresh()->primary_slot);
    }

    public function test_promoting_via_a_plain_save_demotes_the_previous_primary(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $first = Location::factory()->for($org, 'organization')->create();
        $second = Location::factory()->for($org, 'organization')->create();

        $second->primary_slot = 1;
        $second->save();

        $this->assertNull($first->fresh()->primary_slot);
        $this->assertSame(1, $second->fresh()->primary_slot);
    }

    public function test_promote_to_primary_atomically_switches_which_location_is_primary(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $first = Location::factory()->for($org, 'organization')->create();
        $second = Location::factory()->for($org, 'organization')->create();

        Location::promoteToPrimary($second);

        $this->assertNull($first->fresh()->primary_slot);
        $this->assertSame(1, $second->fresh()->primary_slot);
    }

    /**
     * Proves promoteToPrimary() is genuinely one COMMIT, not two — the
     * distinction its own docblock (and LocationObserver::updating()'s)
     * draws against a plain `$location->primary_slot = 1; $location->save();`.
     * Injects a failure on the SECOND of its two writes (the promoting
     * save()) and asserts the FIRST write (the demote) did not survive
     * either. If they were two separate commits, the demote would already be
     * durable by the time the second write fails, and $first would come back
     * demoted despite $second never having been promoted — a "nobody is
     * primary" state the UNIQUE constraint would still technically allow
     * (NULL is never unique-constrained) but the product invariant forbids.
     *
     * The temporary `saving` listener is registered on Location's *own*
     * event class (`'eloquent.saving: '.Location::class`) and removed in a
     * `finally` block — nothing else in this codebase listens on Location's
     * `saving` event today (LocationObserver only hooks `creating`/
     * `updating`/`deleting`), so this cannot leak into or mask any other
     * test.
     */
    public function test_promote_to_primary_is_atomic_a_failure_on_the_second_write_rolls_back_the_first_too(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $first = Location::factory()->for($org, 'organization')->create();
        $second = Location::factory()->for($org, 'organization')->create();

        Location::saving(function (Location $location) use ($second): void {
            if ($location->is($second)) {
                throw new \RuntimeException('simulated failure between the demote and the promote');
            }
        });

        try {
            try {
                Location::promoteToPrimary($second);
                $this->fail('Expected the simulated failure to propagate out of promoteToPrimary()');
            } catch (\RuntimeException) {
                // expected — the assertions below are the actual point of this test
            }
        } finally {
            Location::getEventDispatcher()->forget('eloquent.saving: '.Location::class);
        }

        $this->assertSame(
            1,
            $first->fresh()->primary_slot,
            'the demote must have rolled back together with the failed promote — one transaction, not two commits'
        );
        $this->assertNull($second->fresh()->primary_slot);
    }

    /**
     * Proves the DB constraint is the actual backstop, not just application
     * discipline — a raw insert that bypasses Location/LocationObserver
     * entirely still cannot create two primary rows for the same
     * organization.
     */
    public function test_database_rejects_two_primary_rows_for_the_same_organization_even_bypassing_the_model(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $existing = Location::factory()->for($org, 'organization')->create();
        $this->assertSame(1, $existing->fresh()->primary_slot);

        $this->expectException(QueryException::class);

        DB::table('locations')->insert([
            'organization_id' => $org->id,
            'name' => 'Duplicate primary',
            'slug' => 'duplicate-primary',
            'primary_slot' => 1,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
