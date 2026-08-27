<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Exceptions\LocationCannotBeDeletedException;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Before this, "every tenant keeps at least one location, and its primary
 * location is never deleted while siblings exist"
 * (tryb-jednooddzialowy.md) lived ONLY in LocationResource::guardDeletion(),
 * called from three Filament call sites. Nothing stopped tinker, a future
 * console command, a future API endpoint, or a seeder from deleting the
 * last/primary location directly.
 *
 * These tests call Location::delete() directly — no Livewire, no
 * LocationResource, no HTTP request at all — the same shape a `php artisan
 * tinker` session or a console command would use, proving
 * App\Observers\LocationObserver::deleting() is the real backstop now, not
 * just the Filament UI layer (which still has its own coverage in
 * Tests\Feature\Filament\LocationResourceDeleteGuardTest, for the friendly
 * notification path).
 */
class LocationDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_the_only_location_of_a_tenant_throws_even_outside_filament(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $only = Location::factory()->for($org, 'organization')->create();

        $this->expectException(LocationCannotBeDeletedException::class);
        $this->expectExceptionMessageMatches('/only location/');

        $only->delete();
    }

    public function test_deleting_the_primary_location_while_siblings_exist_throws_even_outside_filament(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $primary = Location::factory()->for($org, 'organization')->create();
        Location::factory()->for($org, 'organization')->create();

        $this->assertSame(1, $primary->fresh()->primary_slot);

        $this->expectException(LocationCannotBeDeletedException::class);
        $this->expectExceptionMessageMatches('/primary location/');

        $primary->delete();
    }

    public function test_deleting_a_non_primary_location_with_a_sibling_remaining_succeeds(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create();
        $secondary = Location::factory()->for($org, 'organization')->create();

        $this->assertNull($secondary->fresh()->primary_slot);

        $secondary->delete();

        $this->assertDatabaseMissing('locations', ['id' => $secondary->id]);
    }

    /**
     * Neither guarded row survives the failed delete attempt in an
     * inconsistent state — the row that threw is still exactly what it was
     * before.
     */
    public function test_a_blocked_deletion_leaves_the_location_untouched(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $only = Location::factory()->for($org, 'organization')->create();

        try {
            $only->delete();
            $this->fail('Expected LocationCannotBeDeletedException');
        } catch (LocationCannotBeDeletedException) {
            // expected
        }

        $this->assertDatabaseHas('locations', ['id' => $only->id]);
    }
}
