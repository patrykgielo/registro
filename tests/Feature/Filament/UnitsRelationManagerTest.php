<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ServiceType;
use App\Enums\ServiceUnitStatus;
use App\Filament\Resources\ServiceResource\Pages\EditService;
use App\Filament\Resources\ServiceResource\RelationManagers\UnitsRelationManager;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\ServiceUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * plan-wdrozenia.md Faza 3 kroki 3.4/3.5 — the "Egzemplarze" tab on
 * ServiceResource. Mirrors ServiceResourceQuantityFieldRoutingTest's setUp()
 * shape (same tenant/session/role bootstrap) since both exercise the same
 * EditService page.
 */
class UnitsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenant;

    private Organization $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super-admin', 'admin', 'staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->tenant = Organization::factory()->equipmentRental()->create();
        $this->otherTenant = Organization::factory()->equipmentRental()->create();
        session(['tenant_id' => $this->tenant->id]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($this->tenant->id, ['role' => 'admin']);
        $this->actingAs($admin);
    }

    private function itemRentalService(Organization $organization, array $overrides = []): Service
    {
        $category = RentalCategory::factory()->for($organization, 'organization')->create();

        return Service::factory()->itemRental()->create(array_merge([
            'organization_id' => $organization->id,
            'rental_category_id' => $category->id,
            'quantity_total' => 0,
        ], $overrides));
    }

    public function test_tab_lists_only_units_belonging_to_the_current_tenant(): void
    {
        $location = Location::factory()->for($this->tenant, 'organization')->create();
        $service = $this->itemRentalService($this->tenant);
        $ownUnit = ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'identifier' => 'OWN-1',
        ]);

        // A unit belonging to another tenant's own service — must never
        // surface here even though the relation manager scopes by service_id,
        // not organization_id.
        $otherLocation = Location::factory()->for($this->otherTenant, 'organization')->create();
        $otherService = $this->itemRentalService($this->otherTenant);
        ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $this->otherTenant->id,
            'service_id' => $otherService->id,
            'location_id' => $otherLocation->id,
            'identifier' => 'OTHER-1',
        ]);

        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $service,
            'pageClass' => EditService::class,
        ])
            ->assertCanSeeTableRecords([$ownUnit])
            ->assertCountTableRecords(1);
    }

    /**
     * The relationship() query never RENDERS another tenant's location as an
     * option. Submission-side rejection (a crafted request naming that
     * location's real ID) is a SEPARATE finding, covered by the two tests
     * below — see UnitsRelationManager::form()'s own docblock for why no
     * extra ->rule() was needed to close it.
     */
    public function test_the_location_select_never_offers_another_tenants_location(): void
    {
        $location = Location::factory()->for($this->tenant, 'organization')->create();
        $service = $this->itemRentalService($this->tenant);
        $otherLocation = Location::factory()->for($this->otherTenant, 'organization')->create();

        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $service,
            'pageClass' => EditService::class,
        ])
            ->mountTableAction('create')
            ->assertFormFieldExists(
                'location_id',
                fn (\Filament\Forms\Components\Select $field): bool => array_key_exists($location->id, $field->getOptions())
                    && ! array_key_exists($otherLocation->id, $field->getOptions())
            );
    }

    public function test_submitting_another_tenants_location_id_is_rejected_server_side(): void
    {
        $service = $this->itemRentalService($this->tenant);
        $otherLocation = Location::factory()->for($this->otherTenant, 'organization')->create();

        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $service,
            'pageClass' => EditService::class,
        ])
            ->callTableAction('create', data: [
                'location_id' => $otherLocation->id,
                'status' => ServiceUnitStatus::Available->value,
            ])
            ->assertHasTableActionErrors(['location_id' => 'in']);

        $this->assertDatabaseMissing('service_units', [
            'service_id' => $service->id,
            'location_id' => $otherLocation->id,
        ]);
    }

    public function test_submitting_an_inactive_locations_id_is_rejected_server_side(): void
    {
        $service = $this->itemRentalService($this->tenant);
        $inactiveLocation = Location::factory()->for($this->tenant, 'organization')->create(['is_active' => false]);

        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $service,
            'pageClass' => EditService::class,
        ])
            ->callTableAction('create', data: [
                'location_id' => $inactiveLocation->id,
                'status' => ServiceUnitStatus::Available->value,
            ])
            ->assertHasTableActionErrors(['location_id' => 'in']);
    }

    public function test_creating_a_unit_raises_the_anchor_and_quantity_total(): void
    {
        $location = Location::factory()->for($this->tenant, 'organization')->create();
        $service = $this->itemRentalService($this->tenant);

        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $service,
            'pageClass' => EditService::class,
        ])
            ->callTableAction('create', data: [
                'location_id' => $location->id,
                'identifier' => 'KOP-04',
                'status' => ServiceUnitStatus::Available->value,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('service_units', [
            'service_id' => $service->id,
            'location_id' => $location->id,
            'identifier' => 'KOP-04',
        ]);
        $this->assertSame(1, $service->fresh()->quantity_total);
        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id,
            'location_id' => $location->id,
            'quantity' => 1,
        ]);
    }

    public function test_switching_a_unit_to_maintenance_lowers_quantity_total_by_one(): void
    {
        $location = Location::factory()->for($this->tenant, 'organization')->create();
        $service = $this->itemRentalService($this->tenant);
        $unitA = ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
        ]);
        ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
        ]);
        $this->assertSame(2, $service->fresh()->quantity_total);

        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $service,
            'pageClass' => EditService::class,
        ])
            ->callTableAction('edit', $unitA, data: [
                'location_id' => $location->id,
                'status' => ServiceUnitStatus::Maintenance->value,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, $service->fresh()->quantity_total);
        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id,
            'location_id' => $location->id,
            'quantity' => 1,
        ]);
    }

    /**
     * The lockout the code reviewer found: once a unit's OWN location is
     * deactivated, editing anything else about that unit — here, only
     * `status` — must still succeed. Before the fix (modifyQueryUsing's
     * `is_active = true` alone), Select::getSelectedRecordUsing() re-ran the
     * SAME closure to re-resolve the current value on every save, found no
     * match, and getInValidationRuleValues() turned that into `Rule::in([])`
     * — rejecting the ENTIRE form regardless of which field actually
     * changed. Falsified by temporarily reverting the `orWhere` half of the
     * fix (`modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true)`,
     * the code exactly as this file had it before this task): this exact
     * test failed with `location_id => in` — the field never even mentioned
     * in `data` below.
     */
    public function test_editing_a_unit_on_a_deactivated_location_still_succeeds(): void
    {
        // A second active location is required before this deactivation:
        // LocationObserver::updating() (Faza 6 code review, 2026-09-10) now
        // blocks deactivating a tenant's ONLY active location — this test's
        // own subject is unrelated to that guard (unit editing UI, not
        // deactivation itself), so the fixture just needs a realistic
        // "closing ONE of several branches" shape instead of tripping it.
        Location::factory()->for($this->tenant, 'organization')->create(['is_active' => true]);
        $location = Location::factory()->for($this->tenant, 'organization')->create();
        $service = $this->itemRentalService($this->tenant);
        $unit = ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
        ]);

        $location->update(['is_active' => false]);

        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $service,
            'pageClass' => EditService::class,
        ])
            ->callTableAction('edit', $unit, data: [
                'status' => ServiceUnitStatus::Maintenance->value,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(ServiceUnitStatus::Maintenance, $unit->fresh()->status);
        $this->assertSame($location->id, $unit->fresh()->location_id, 'location must stay unchanged, not silently cleared');
    }

    /**
     * Confirms server-side rejection, not just an absent form option (that
     * half is already covered by test_in_transit_is_not_offered_as_a_selectable_status_in_the_form()
     * above). Same `Select::getInValidationRule()` → `Rule::in($options)`
     * mechanism as location_id, but for a plain `->options()` array rather
     * than a relationship() — confirmed here rather than left as inference.
     */
    public function test_submitting_in_transit_status_directly_is_rejected_server_side(): void
    {
        $location = Location::factory()->for($this->tenant, 'organization')->create();
        $service = $this->itemRentalService($this->tenant);

        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $service,
            'pageClass' => EditService::class,
        ])
            ->callTableAction('create', data: [
                'location_id' => $location->id,
                'status' => ServiceUnitStatus::InTransit->value,
            ])
            ->assertHasTableActionErrors(['status' => 'in']);

        $this->assertDatabaseMissing('service_units', [
            'service_id' => $service->id,
            'status' => ServiceUnitStatus::InTransit->value,
        ]);
    }

    /**
     * Requirement #6 in the task: the parent EditService form's "Ilość w
     * magazynie" field must reflect the drop WITHOUT a full page reload —
     * proven here through the actual event contract (dispatch from the
     * relation manager, #[On(...)] listener on the page), not by asserting
     * on the DB alone (that part is already covered by the test above).
     */
    public function test_editing_a_unit_dispatches_the_event_the_parent_page_listens_for(): void
    {
        $location = Location::factory()->for($this->tenant, 'organization')->create();
        $service = $this->itemRentalService($this->tenant);
        $unit = ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
        ]);

        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $service,
            'pageClass' => EditService::class,
        ])
            ->callTableAction('edit', $unit, data: [
                'location_id' => $location->id,
                'status' => ServiceUnitStatus::Maintenance->value,
            ])
            ->assertDispatched('service-unit-stock-changed');

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->call('refreshQuantityTotalField')
            ->assertSet('data.quantity_total', 0);
    }

    public function test_identifier_must_be_unique_within_the_tenant_but_not_across_tenants(): void
    {
        $location = Location::factory()->for($this->tenant, 'organization')->create();
        $service = $this->itemRentalService($this->tenant);
        ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'identifier' => 'KOP-04',
        ]);

        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $service,
            'pageClass' => EditService::class,
        ])
            ->callTableAction('create', data: [
                'location_id' => $location->id,
                'identifier' => 'KOP-04',
                'status' => ServiceUnitStatus::Available->value,
            ])
            ->assertHasTableActionErrors(['identifier' => 'unique']);

        // Same identifier, different tenant — must be allowed (composite
        // UNIQUE(organization_id, identifier) in the migration).
        $otherLocation = Location::factory()->for($this->otherTenant, 'organization')->create();
        $otherService = $this->itemRentalService($this->otherTenant);
        ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $this->otherTenant->id,
            'service_id' => $otherService->id,
            'location_id' => $otherLocation->id,
            'identifier' => 'KOP-04',
        ]);

        $this->assertDatabaseHas('service_units', [
            'organization_id' => $this->otherTenant->id,
            'identifier' => 'KOP-04',
        ]);
    }

    public function test_identifier_is_optional_and_multiple_units_without_one_can_coexist(): void
    {
        $location = Location::factory()->for($this->tenant, 'organization')->create();
        $service = $this->itemRentalService($this->tenant);

        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $service,
            'pageClass' => EditService::class,
        ])
            ->callTableAction('create', data: [
                'location_id' => $location->id,
                'identifier' => null,
                'status' => ServiceUnitStatus::Available->value,
            ])
            ->assertHasNoTableActionErrors()
            ->callTableAction('create', data: [
                'location_id' => $location->id,
                'identifier' => null,
                'status' => ServiceUnitStatus::Available->value,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(2, ServiceUnit::withoutGlobalScope('organization')
            ->where('service_id', $service->id)->whereNull('identifier')->count());
    }

    /**
     * `in_transit` is a UI-only omission, not a server-side block (see the
     * field's own docblock — nothing today reads/writes this status, so
     * there is no invariant to enforce with a validation rule, only a
     * misleading option to hide). Proven the same way as the cross-tenant
     * location option, not via a rejected submission.
     */
    public function test_in_transit_is_not_offered_as_a_selectable_status_in_the_form(): void
    {
        $service = $this->itemRentalService($this->tenant);

        Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $service,
            'pageClass' => EditService::class,
        ])
            ->mountTableAction('create')
            ->assertFormFieldExists(
                'status',
                fn (\Filament\Forms\Components\Select $field): bool => ! array_key_exists(
                    ServiceUnitStatus::InTransit->value,
                    $field->getOptions()
                )
            );
    }

    public function test_tab_is_hidden_for_a_time_slot_service(): void
    {
        $service = Service::factory()->create([
            'organization_id' => $this->tenant->id,
            'service_type' => ServiceType::TimeSlot,
        ]);

        $this->assertFalse(UnitsRelationManager::canViewForRecord($service, 'admin'));
    }

    public function test_tab_is_visible_for_an_item_rental_service(): void
    {
        $service = $this->itemRentalService($this->tenant);

        $this->assertTrue(UnitsRelationManager::canViewForRecord($service, 'admin'));
    }
}
