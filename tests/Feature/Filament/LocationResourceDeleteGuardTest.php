<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * LocationResource::guardDeletion() — plan-wdrozenia.md step 1.3 asked to
 * "przemyśl, co ma się stać przy próbie usunięcia głównej lokalizacji albo
 * ostatniej lokalizacji tenanta". Decision made here: BOTH are blocked
 * outright (never silently auto-promoted/auto-demoted) — see the method's
 * own docblock for why. Covers the row DeleteAction, the EditRecord header
 * DeleteAction (a second path to the same guard), and the bulk action.
 */
class LocationResourceDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->tenant = Organization::factory()->equipmentRental()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->organizations()->attach($this->tenant->id, ['role' => 'admin']);

        session(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->admin);
    }

    public function test_cannot_delete_the_only_location_of_a_tenant(): void
    {
        $only = Location::factory()->for($this->tenant, 'organization')->create();

        Livewire::test(ListLocations::class)
            ->callTableAction('delete', $only);

        $this->assertDatabaseHas('locations', ['id' => $only->id]);
    }

    public function test_cannot_delete_the_primary_location_while_siblings_exist(): void
    {
        $primary = Location::factory()->for($this->tenant, 'organization')->create();
        Location::factory()->for($this->tenant, 'organization')->create();

        $this->assertSame(1, $primary->fresh()->primary_slot);

        Livewire::test(ListLocations::class)
            ->callTableAction('delete', $primary);

        $this->assertDatabaseHas('locations', ['id' => $primary->id]);
    }

    public function test_can_delete_a_non_primary_location_when_others_remain(): void
    {
        Location::factory()->for($this->tenant, 'organization')->create();
        $secondary = Location::factory()->for($this->tenant, 'organization')->create();

        $this->assertNull($secondary->fresh()->primary_slot);

        Livewire::test(ListLocations::class)
            ->callTableAction('delete', $secondary);

        $this->assertDatabaseMissing('locations', ['id' => $secondary->id]);
    }

    public function test_edit_page_header_delete_action_is_guarded_the_same_way(): void
    {
        $only = Location::factory()->for($this->tenant, 'organization')->create();

        // Location's route key is `slug`, not `id` (see Location::getRouteKeyName()).
        Livewire::test(EditLocation::class, ['record' => $only->slug])
            ->callAction('delete');

        $this->assertDatabaseHas('locations', ['id' => $only->id]);
    }

    public function test_bulk_delete_of_every_location_is_blocked(): void
    {
        $first = Location::factory()->for($this->tenant, 'organization')->create();
        $second = Location::factory()->for($this->tenant, 'organization')->create();

        Livewire::test(ListLocations::class)
            ->callTableBulkAction('delete', [$first, $second]);

        $this->assertDatabaseHas('locations', ['id' => $first->id]);
        $this->assertDatabaseHas('locations', ['id' => $second->id]);
    }

    public function test_bulk_delete_including_the_primary_is_blocked_even_with_siblings_left_over(): void
    {
        $primary = Location::factory()->for($this->tenant, 'organization')->create();
        Location::factory()->for($this->tenant, 'organization')->create();
        $third = Location::factory()->for($this->tenant, 'organization')->create();

        Livewire::test(ListLocations::class)
            ->callTableBulkAction('delete', [$primary, $third]);

        $this->assertDatabaseHas('locations', ['id' => $primary->id]);
        $this->assertDatabaseHas('locations', ['id' => $third->id]);
    }

    public function test_bulk_delete_of_non_primary_locations_leaving_at_least_one_behind_succeeds(): void
    {
        Location::factory()->for($this->tenant, 'organization')->create();
        $second = Location::factory()->for($this->tenant, 'organization')->create();
        $third = Location::factory()->for($this->tenant, 'organization')->create();

        Livewire::test(ListLocations::class)
            ->callTableBulkAction('delete', [$second, $third]);

        $this->assertDatabaseMissing('locations', ['id' => $second->id]);
        $this->assertDatabaseMissing('locations', ['id' => $third->id]);
    }
}
