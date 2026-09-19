<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ServiceUnitStatus;
use App\Filament\Resources\ServiceResource\Pages\EditService;
use App\Filament\Resources\ServiceResource\RelationManagers\LocationStocksRelationManager;
use App\Filament\Resources\ServiceResource\RelationManagers\UnitsRelationManager;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\ServiceUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ClickUp 123k99cvc54 — an admin who typed "5" into "Ilość w magazynie"
 * (routed into service_location_stocks.quantity, no ServiceUnit rows behind
 * it) lost 4 of those 5 the instant the first egzemplarz was created:
 * ServiceUnitObserver::recalculateAnchor() overwrote the anchor with a plain
 * COUNT() of Available units, which was 1. Fixed by materializing the
 * difference as unnumbered placeholder units — App\Observers\
 * ServiceUnitObserver::materializePlaceholdersForFirstUnit().
 */
class UnitCreationPreservesManualStockQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenant;

    private Location $primary;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super-admin', 'admin', 'staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->tenant = Organization::factory()->equipmentRental()->create();
        session(['tenant_id' => $this->tenant->id]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($this->tenant->id, ['role' => 'admin']);
        $this->actingAs($admin);

        $this->primary = Location::factory()->for($this->tenant, 'organization')->create();
        $category = RentalCategory::factory()->for($this->tenant, 'organization')->create();

        $this->service = Service::factory()->itemRental()->create([
            'organization_id' => $this->tenant->id,
            'rental_category_id' => $category->id,
            'quantity_total' => 5,
        ]);

        // Simulates what RouteQuantityFieldToPrimaryLocationStock::handle()
        // would have written when the admin typed "5" into "Ilość w
        // magazynie" — a manual quantity with NO ServiceUnit rows behind it.
        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $this->service->id,
            'location_id' => $this->primary->id,
            'quantity' => 5,
        ]);
    }

    public function test_adding_the_first_unit_backfills_placeholders_so_the_available_count_stays_at_the_previous_stock(): void
    {
        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $this->service,
            'pageClass' => EditService::class,
        ])
            ->callTableAction('create', data: [
                'location_id' => $this->primary->id,
                'identifier' => 'KOP-01',
                'status' => ServiceUnitStatus::Available->value,
            ])
            ->assertHasNoTableActionErrors();

        $units = ServiceUnit::withoutGlobalScope('organization')
            ->where('service_id', $this->service->id)
            ->where('location_id', $this->primary->id)
            ->get();

        $this->assertCount(5, $units, 'the named unit plus 4 backfilled placeholders');
        $this->assertCount(1, $units->whereNotNull('identifier'), 'exactly one unit carries the admin-typed identifier');
        $this->assertCount(4, $units->whereNull('identifier'), 'the rest are unnumbered placeholders (Faza 3: unit numbers are optional)');
        $this->assertTrue($units->every(fn (ServiceUnit $u) => $u->status === ServiceUnitStatus::Available));

        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $this->service->id,
            'location_id' => $this->primary->id,
            'quantity' => 5,
        ]);
        $this->assertSame(5, $this->service->fresh()->quantity_total, 'the mirror must not have dropped from 5 to 1');
    }

    /**
     * The SECOND unit added must not trigger another backfill round — only
     * the genuinely first unit for a (service, location) pair does.
     */
    public function test_adding_a_second_unit_does_not_trigger_another_backfill_round(): void
    {
        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $this->service,
            'pageClass' => EditService::class,
        ])->callTableAction('create', data: [
            'location_id' => $this->primary->id,
            'identifier' => 'KOP-01',
            'status' => ServiceUnitStatus::Available->value,
        ]);

        $this->assertSame(5, $this->service->fresh()->quantity_total);

        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $this->service,
            'pageClass' => EditService::class,
        ])->callTableAction('create', data: [
            'location_id' => $this->primary->id,
            'identifier' => 'KOP-02',
            'status' => ServiceUnitStatus::Available->value,
        ]);

        // Without the "only the first unit" guard, this second create would
        // see previousQuantity=5 again and backfill another batch, inflating
        // the anchor to 9 (5 already-counted + 4 more placeholders).
        $this->assertSame(6, $this->service->fresh()->quantity_total);
        $this->assertSame(
            6,
            ServiceUnit::withoutGlobalScope('organization')->where('service_id', $this->service->id)->count()
        );
    }

    /**
     * A first unit created directly in maintenance does not itself count
     * toward "available" -- the FULL previous quantity (5) must be
     * backfilled as placeholders, none of which is the maintenance unit.
     */
    public function test_first_unit_created_in_maintenance_backfills_the_full_previous_quantity_as_available_placeholders(): void
    {
        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $this->service,
            'pageClass' => EditService::class,
        ])->callTableAction('create', data: [
            'location_id' => $this->primary->id,
            'identifier' => 'KOP-BROKEN',
            'status' => ServiceUnitStatus::Maintenance->value,
        ])->assertHasNoTableActionErrors();

        $this->assertSame(5, $this->service->fresh()->quantity_total, 'the maintenance unit does not count, so all 5 must still be available');

        $availableCount = ServiceUnit::withoutGlobalScope('organization')
            ->where('service_id', $this->service->id)
            ->where('status', ServiceUnitStatus::Available->value)
            ->count();
        $this->assertSame(5, $availableCount);
    }

    public function test_a_service_with_no_pre_existing_stock_row_does_not_backfill_anything(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->tenant->id,
            'quantity_total' => 0,
        ]);

        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $service,
            'pageClass' => EditService::class,
        ])->callTableAction('create', data: [
            'location_id' => $this->primary->id,
            'identifier' => 'FRESH-01',
            'status' => ServiceUnitStatus::Available->value,
        ]);

        $this->assertSame(1, $service->fresh()->quantity_total);
        $this->assertSame(
            1,
            ServiceUnit::withoutGlobalScope('organization')->where('service_id', $service->id)->count()
        );
    }

    public function test_quantity_field_is_disabled_once_the_primary_location_has_units(): void
    {
        ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $this->service->id,
            'location_id' => $this->primary->id,
            'identifier' => 'KOP-01',
        ]);

        Livewire::test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->assertFormFieldIsDisabled('quantity_total');
    }

    /**
     * NOTE on scope: a unit created at a NON-primary location always writes
     * a nonzero row there too (ServiceUnitObserver keeps the two in sync),
     * so it is impossible to have "units outside the primary" without ALSO
     * tripping the pre-existing "stock outside primary" guard
     * (serviceHasStockOutsideItsPrimaryLocation()) -- the field would stay
     * disabled either way, just for that older reason. The distinction this
     * fix actually introduces ("a unit specifically at THIS row") is
     * observable at the per-row level instead -- see
     * test_inline_stock_edit_stays_enabled_for_a_row_at_a_different_location_with_no_units
     * below, which is the real "location A has units, B doesn't" case.
     */
    public function test_inline_stock_edit_is_disabled_for_a_row_that_has_units(): void
    {
        ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $this->service->id,
            'location_id' => $this->primary->id,
            'identifier' => 'KOP-01',
        ]);

        $row = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $this->service->id)
            ->where('location_id', $this->primary->id)
            ->firstOrFail();

        Livewire::test(LocationStocksRelationManager::class, [
            'ownerRecord' => $this->service,
            'pageClass' => EditService::class,
        ])->assertTableColumnExists(
            'quantity',
            fn (\Filament\Tables\Columns\TextInputColumn $column): bool => $column->isDisabled(),
            record: $row,
        );
    }

    /**
     * A stock row at a location with NO units stays inline-editable even
     * though this same service has units elsewhere -- the guard is per
     * (service, location) pair, not per service.
     */
    public function test_inline_stock_edit_stays_enabled_for_a_row_at_a_different_location_with_no_units(): void
    {
        $secondary = Location::factory()->for($this->tenant, 'organization')->create();
        ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $this->service->id,
            'location_id' => $this->primary->id,
            'identifier' => 'KOP-01',
        ]);

        $secondaryRow = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $this->service->id)
            ->where('location_id', $secondary->id)
            ->firstOrFail();

        Livewire::test(LocationStocksRelationManager::class, [
            'ownerRecord' => $this->service,
            'pageClass' => EditService::class,
        ])->assertTableColumnExists(
            'quantity',
            fn (\Filament\Tables\Columns\TextInputColumn $column): bool => ! $column->isDisabled(),
            record: $secondaryRow,
        );
    }
}
