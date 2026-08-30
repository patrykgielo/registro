<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ServiceType;
use App\Filament\Resources\ServiceResource\Pages\CreateService;
use App\Filament\Resources\ServiceResource\Pages\EditService;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * plan-wdrozenia.md Krok 2.5, panel end of the "zero regression for a
 * single-location tenant" contract: the "Ilość w magazynie" field itself
 * (enabled/disabled, whether editing it actually reaches
 * service_location_stocks) — the underlying routing rules are unit-tested in
 * tests/Unit/Actions/RouteQuantityFieldToPrimaryLocationStockTest.php, this
 * is the thin layer proving ServiceResource's form actually wires them up.
 */
class ServiceResourceQuantityFieldRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenant;

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
    }

    public function test_field_is_enabled_for_a_single_location_tenant_and_saving_routes_the_value(): void
    {
        $primary = Location::factory()->for($this->tenant, 'organization')->create();
        $category = \App\Models\RentalCategory::factory()->for($this->tenant, 'organization')->create();
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->tenant->id,
            'rental_category_id' => $category->id,
            'quantity_total' => 1,
        ]);

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertFormFieldIsEnabled('quantity_total')
            ->fillForm(['quantity_total' => 9])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(9, $service->fresh()->quantity_total);
        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id,
            'location_id' => $primary->id,
            'quantity' => 9,
        ]);
    }

    public function test_field_is_disabled_for_a_multi_location_tenant_and_saving_does_not_touch_the_stock_split(): void
    {
        $primary = Location::factory()->for($this->tenant, 'organization')->create();
        Location::factory()->for($this->tenant, 'organization')->create();
        $category = \App\Models\RentalCategory::factory()->for($this->tenant, 'organization')->create();
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->tenant->id,
            'rental_category_id' => $category->id,
            'quantity_total' => 5,
        ]);
        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $service->id,
            'location_id' => $primary->id,
            'quantity' => 2,
        ]);

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertFormFieldIsDisabled('quantity_total')
            ->call('save')
            ->assertHasNoFormErrors();

        $row = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $service->id)->where('location_id', $primary->id)->first();

        $this->assertSame(2, $row->quantity, 'must not have been clobbered with quantity_total (5)');
    }

    /**
     * code-reviewer BLOKER 1 (Faza 2): org used to have two active
     * locations, this service's stock was split across both, then the
     * SECOND location got deactivated — tenantHasExactlyOneActiveLocation()
     * alone would say "single-location" again, but this service's stock
     * row at the now-inactive location still exists. The field must stay
     * disabled (same as the genuinely multi-location case above), NOT
     * silently accept a value that handle() then refuses to route.
     */
    public function test_field_is_disabled_when_this_services_stock_is_orphaned_at_a_deactivated_location(): void
    {
        $primary = Location::factory()->for($this->tenant, 'organization')->create();
        $secondary = Location::factory()->for($this->tenant, 'organization')->create();
        $category = \App\Models\RentalCategory::factory()->for($this->tenant, 'organization')->create();
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->tenant->id,
            'rental_category_id' => $category->id,
            'quantity_total' => 8,
        ]);
        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $service->id,
            'location_id' => $primary->id,
            'quantity' => 5,
        ]);
        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $service->id,
            'location_id' => $secondary->id,
            'quantity' => 3,
        ]);
        $secondary->update(['is_active' => false]);

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertFormFieldIsDisabled('quantity_total')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(8, $service->fresh()->quantity_total, 'a no-op save must be idempotent, not inflate the mirror');
        $row = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $service->id)->where('location_id', $primary->id)->first();
        $this->assertSame(5, $row->quantity, 'must not have absorbed the orphaned row');
    }

    public function test_creating_a_new_item_rental_service_for_a_single_location_tenant_routes_the_typed_quantity(): void
    {
        $primary = Location::factory()->for($this->tenant, 'organization')->create();
        $category = \App\Models\RentalCategory::factory()->for($this->tenant, 'organization')->create();

        Livewire::test(CreateService::class)
            ->fillForm([
                'service_type' => ServiceType::ItemRental->value,
                'name' => 'Wiertarka testowa',
                'slug' => 'wiertarka-testowa',
                'rental_category_id' => $category->id,
                'price_per_day' => 50,
                'quantity_total' => 4,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::withoutGlobalScope('organization')
            ->where('organization_id', $this->tenant->id)
            ->where('name', 'Wiertarka testowa')
            ->firstOrFail();

        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id,
            'location_id' => $primary->id,
            'quantity' => 4,
        ]);
    }
}
