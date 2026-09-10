<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Faza 6 code review (2026-09-10) — LocationResource::guardDeactivation(),
 * the deactivation-side twin of LocationResourceDeleteGuardTest. Same
 * "friendly halt in front of the model-layer exception" split, but through
 * a normal form Save (EditRecord::handleRecordUpdate()/
 * CreateRecord::handleRecordCreation()) rather than a DeleteAction — there
 * is no discrete Action to `->before()`/`->halt()` here, see
 * EditLocation::handleRecordUpdate()'s own docblock.
 */
class LocationResourceDeactivationGuardTest extends TestCase
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

    public function test_cannot_deactivate_the_only_active_location_via_the_edit_form(): void
    {
        $only = Location::factory()->for($this->tenant, 'organization')->create(['is_active' => true]);

        Livewire::test(EditLocation::class, ['record' => $only->slug])
            ->fillForm(['is_active' => false])
            ->call('save');

        $this->assertTrue($only->fresh()->is_active);
    }

    public function test_can_deactivate_one_of_two_active_locations_via_the_edit_form(): void
    {
        Location::factory()->for($this->tenant, 'organization')->create(['is_active' => true]);
        $secondary = Location::factory()->for($this->tenant, 'organization')->create(['is_active' => true]);

        Livewire::test(EditLocation::class, ['record' => $secondary->slug])
            ->fillForm(['is_active' => false])
            ->call('save');

        $this->assertFalse($secondary->fresh()->is_active);
    }

    public function test_saving_the_only_active_location_without_changing_is_active_succeeds(): void
    {
        $only = Location::factory()->for($this->tenant, 'organization')->create(['is_active' => true, 'name' => 'Stara nazwa']);

        Livewire::test(EditLocation::class, ['record' => $only->slug])
            ->fillForm(['is_active' => true, 'name' => 'Nowa nazwa'])
            ->call('save');

        $this->assertSame('Nowa nazwa', $only->fresh()->name);
        $this->assertTrue($only->fresh()->is_active);
    }

    /**
     * Create-side is deliberately unguarded — see
     * App\Observers\LocationObserver::creating()'s own note on why. Covered
     * here (not just LocationDeactivationGuardTest's model-layer version) to
     * prove the Filament create form doesn't add its own restriction either.
     */
    public function test_creating_a_tenants_first_location_as_inactive_succeeds_via_the_create_form(): void
    {
        Livewire::test(CreateLocation::class)
            ->fillForm([
                'name' => 'Nowy oddział',
                'is_active' => false,
            ])
            ->call('create');

        $this->assertDatabaseHas('locations', ['name' => 'Nowy oddział', 'is_active' => false]);
    }
}
