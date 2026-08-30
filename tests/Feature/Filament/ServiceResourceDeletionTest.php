<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ServiceResource\Pages\EditService;
use App\Filament\Resources\ServiceResource\Pages\ListServices;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * code-reviewer BLOKER 2 (Faza 2): 2026_08_28_090000_create_service_location_stocks_table.php
 * originally made `service_id` restrictOnDelete — modelled on
 * rentals.service_id/order_items.service_id, but those two protect LEGAL
 * RECORDS, and a stock row is not one (see the migration's own updated
 * docblock). Since RouteQuantityFieldToPrimaryLocationStock::handle() runs
 * on every save of an item_rental service for a single-active-location
 * tenant — the shape of every one of the 8 real tenants today — almost any
 * such service silently stopped being deletable from the panel the moment
 * it got its first stock anchor row, and ServiceResource's DeleteAction
 * (which does not catch QueryException) surfaced a raw 500 instead.
 * No test in this repo exercised ServiceResource deletion at all before
 * this file.
 */
class ServiceResourceDeletionTest extends TestCase
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

    public function test_deleting_an_item_rental_service_with_a_stock_row_succeeds_and_cascades_the_stock_row(): void
    {
        $primary = Location::factory()->for($this->tenant, 'organization')->create();
        $category = RentalCategory::factory()->for($this->tenant, 'organization')->create();
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->tenant->id,
            'rental_category_id' => $category->id,
            'quantity_total' => 5,
        ]);
        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $service->id,
            'location_id' => $primary->id,
            'quantity' => 5,
        ]);

        Livewire::test(ListServices::class)
            ->callTableAction('delete', $service);

        $this->assertDatabaseMissing('services', ['id' => $service->id]);
        $this->assertDatabaseMissing('service_location_stocks', ['service_id' => $service->id]);
    }

    public function test_edit_page_header_delete_action_also_succeeds_with_a_stock_row_present(): void
    {
        $primary = Location::factory()->for($this->tenant, 'organization')->create();
        $category = RentalCategory::factory()->for($this->tenant, 'organization')->create();
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->tenant->id,
            'rental_category_id' => $category->id,
            'quantity_total' => 3,
        ]);
        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $service->id,
            'location_id' => $primary->id,
            'quantity' => 3,
        ]);

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('services', ['id' => $service->id]);
        $this->assertDatabaseMissing('service_location_stocks', ['service_id' => $service->id]);
    }

    public function test_deleting_a_service_with_no_stock_row_still_works(): void
    {
        Location::factory()->for($this->tenant, 'organization')->create();
        $category = RentalCategory::factory()->for($this->tenant, 'organization')->create();
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->tenant->id,
            'rental_category_id' => $category->id,
        ]);

        Livewire::test(ListServices::class)
            ->callTableAction('delete', $service);

        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }
}
