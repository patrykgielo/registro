<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ServiceResource\Pages\EditService;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pins the MySQL-only failure found by `PanelWalkthroughTest` on the RC26
 * release gate: a service with a `metadata.specs` parameter that has NO
 * unit (e.g. "Rodzaj paliwa: benzyna") got mutated by a save with zero user
 * changes. Root cause: Filament core's `HasState::getRawState()` (vendor/
 * filament/schemas/.../Concerns/HasState.php) unconditionally coerces any
 * blank, non-array field state to `null` on every read of the form's state
 * — including a `TextInput` nested inside a `Repeater` item. `''` was never
 * a value Filament would round-trip unchanged.
 *
 * Deliberately does NOT rely on "whichever service happens to be first in
 * the DB" (the exact reason this was invisible on SQLite locally per the
 * MySQL-gate report) — this test builds its own fixture, mirroring
 * SeedEquipmentRental's actual shape for a unit-less parameter (`unit`:
 * `null`, the canonical empty value per NormalizesSpecsShape's docblock),
 * and targets it directly by route key.
 */
class ServiceResourceSpecsUnitNoopSaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_unchanged_service_does_not_mutate_a_spec_with_no_unit(): void
    {
        foreach (['super-admin', 'admin', 'staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $tenant = Organization::factory()->equipmentRental()->create();
        session(['tenant_id' => $tenant->id]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($tenant->id, ['role' => 'admin']);
        $this->actingAs($admin);

        $category = RentalCategory::factory()->for($tenant, 'organization')->create();
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $tenant->id,
            'rental_category_id' => $category->id,
            'metadata' => [
                'specs' => [
                    ['label' => 'Moc', 'value' => 3000, 'unit' => 'W'],
                    ['label' => 'Rodzaj paliwa', 'value' => 'benzyna', 'unit' => null],
                ],
            ],
        ]);

        $before = $service->fresh()->getAttributes()['metadata'];

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $after = $service->fresh()->getAttributes()['metadata'];

        $this->assertSame($before, $after, 'saving an existing service with no changes must not mutate metadata->specs');
    }
}
