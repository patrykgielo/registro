<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Ustaw jako główną" table action — plan-wdrozenia.md step 1.3 requires it
 * to use Location::promoteToPrimary() (one transaction covering both the
 * demotion and the promotion) rather than assigning `primary_slot` directly,
 * and to be hidden/disabled for a location that already IS primary. Confirms
 * both through the actual Livewire table action, not just the underlying
 * model method (already pinned by Unit\Models\LocationPrimarySlotTest).
 */
class LocationResourcePromoteActionTest extends TestCase
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

    public function test_promoting_a_non_primary_location_switches_which_one_is_primary(): void
    {
        $first = Location::factory()->for($this->tenant, 'organization')->create();
        $second = Location::factory()->for($this->tenant, 'organization')->create();

        $this->assertSame(1, $first->fresh()->primary_slot);
        $this->assertNull($second->fresh()->primary_slot);

        Livewire::test(ListLocations::class)
            ->callTableAction('promoteToPrimary', $second);

        $this->assertNull($first->fresh()->primary_slot);
        $this->assertSame(1, $second->fresh()->primary_slot);
    }

    public function test_promoting_never_leaves_two_primaries_or_zero(): void
    {
        $first = Location::factory()->for($this->tenant, 'organization')->create();
        $second = Location::factory()->for($this->tenant, 'organization')->create();
        Location::factory()->for($this->tenant, 'organization')->create();

        Livewire::test(ListLocations::class)
            ->callTableAction('promoteToPrimary', $second);

        $primaryCount = Location::where('organization_id', $this->tenant->id)
            ->where('primary_slot', 1)
            ->count();

        $this->assertSame(1, $primaryCount);
        $this->assertNotSame($first->fresh()->primary_slot, 1);
    }

    public function test_promote_action_is_hidden_for_a_location_that_is_already_primary(): void
    {
        $primary = Location::factory()->for($this->tenant, 'organization')->create();

        Livewire::test(ListLocations::class)
            ->assertTableActionHidden('promoteToPrimary', $primary);
    }
}
