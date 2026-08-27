<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Models\Location;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Measures — rather than assumes — whether
 * App\Observers\LocationObserver::deleting() fires when a Location is
 * removed as a side effect of `locations.organization_id`'s
 * cascadeOnDelete() FK, as opposed to a direct Location::delete() call.
 *
 * It does not: MySQL (and, here, SQLite with `foreign_key_constraints`
 * enabled — config/database.php, on by default) performs the child DELETE
 * directly inside the database engine when the parent row is removed. It
 * never instantiates a Location model or dispatches any of its events. The
 * delete guard added to LocationObserver::deleting() (blocks removing a
 * tenant's only location, or its primary location while siblings exist) is
 * therefore correctly bypassed here — hard-deleting an organization is never
 * blocked by an invariant that belongs to a different model.
 *
 * Uses forceDelete() rather than the normal SoftDeletes delete(): only a
 * real DB-level DELETE on `organizations` fires the FK's cascadeOnDelete() —
 * a soft-delete is just an UPDATE and never touches `locations` at all.
 * `bypassDeleteGuard = true` skips OrganizationObserver::deleting()'s own,
 * unrelated guards (lifecycle_state, active obligations, legal records),
 * which are not what this test is about — the same pattern
 * OrganizationPurgeTest already uses for its own delete-related tests.
 *
 * Direct proof that the guard genuinely fires for an ordinary
 * Location::delete() call (the contrast this test's absence of an exception
 * depends on) lives in Tests\Unit\Models\LocationDeletionGuardTest.
 */
class LocationCascadeDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_hard_deleting_an_organization_cascades_to_its_only_primary_location_without_the_guard_blocking_it(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();

        // Sanity: this is exactly the shape LocationObserver::deleting()
        // blocks on a direct delete() — the only, primary location of its
        // organization.
        $this->assertSame(1, $location->fresh()->primary_slot);
        $this->assertDatabaseHas('locations', ['id' => $location->id]);

        $org->bypassDeleteGuard = true;
        $org->forceDelete();

        // No LocationCannotBeDeletedException was thrown above (the test
        // would have failed with an uncaught exception if it had been), AND
        // the row is actually gone — the cascade did the deletion, silently,
        // at the DB level.
        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }

    public function test_hard_deleting_an_organization_cascades_to_every_one_of_its_locations(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $primary = Location::factory()->for($org, 'organization')->create();
        $secondary = Location::factory()->for($org, 'organization')->create();

        $org->bypassDeleteGuard = true;
        $org->forceDelete();

        $this->assertDatabaseMissing('locations', ['id' => $primary->id]);
        $this->assertDatabaseMissing('locations', ['id' => $secondary->id]);
    }
}
