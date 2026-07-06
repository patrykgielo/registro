<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ServiceResource;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ServiceResource serves every tenant regardless of booking_type — a
 * booking_type = 'both' organization stores real time-slot services AND
 * rental items in the same `services` table / same resource. Only a PURE
 * rental tenant (booking_type = 'item_rental') should see this resource
 * relabeled as a product catalogue under the 'rentals' navigation group;
 * 'time_slot' and 'both' tenants must keep the original "Usługa(i)" /
 * 'content' presentation, otherwise a mixed tenant's time-slot services
 * would be mislabeled as rental products.
 */
class ServiceResourceTenantLabelTest extends TestCase
{
    use RefreshDatabase;

    private function bindTenant(Organization $org): void
    {
        $this->app['request']->attributes->set('tenant', $org);
    }

    public function test_pure_rental_tenant_sees_product_labels_and_rentals_group(): void
    {
        // equipmentRental() (not the bare itemRental() state) — it also sets
        // industry = EquipmentRental, whose defaultModules() includes
        // 'services'. A tenant with booking_type = item_rental but industry
        // left null (reachable only via a manual super-admin edit in
        // /platform, never through normal onboarding) would have the
        // 'services' module disabled by MODULE_DEFAULTS['item_rental'] and
        // this resource would correctly show "Produkt" if visited directly,
        // but never register in the sidebar at all — asserting
        // shouldRegisterNavigation() here pins that this realistic fixture
        // doesn't hit that gap.
        $org = Organization::factory()->equipmentRental()->create();
        $this->bindTenant($org);

        $this->assertTrue(ServiceResource::shouldRegisterNavigation());
        $this->assertSame('Produkt', ServiceResource::getModelLabel());
        $this->assertSame('Produkty', ServiceResource::getPluralModelLabel());
        $this->assertSame('rentals', ServiceResource::getNavigationGroup());
        $this->assertSame(2, ServiceResource::getNavigationSort());
    }

    public function test_time_slot_tenant_keeps_default_service_labels(): void
    {
        $org = Organization::factory()->create(['booking_type' => 'time_slot']);
        $this->bindTenant($org);

        $this->assertSame('Usługa', ServiceResource::getModelLabel());
        $this->assertSame('Usługi', ServiceResource::getPluralModelLabel());
        $this->assertSame('content', ServiceResource::getNavigationGroup());
        $this->assertSame(1, ServiceResource::getNavigationSort());
    }

    public function test_mixed_booking_type_tenant_keeps_default_service_labels(): void
    {
        // Important edge case: a 'both' tenant stores real time-slot services
        // in this same resource — it must NOT lose "Usługi" visibility under
        // a rental-flavored "Produkty" label.
        $org = Organization::factory()->create(['booking_type' => 'both']);
        $this->bindTenant($org);

        $this->assertSame('Usługa', ServiceResource::getModelLabel());
        $this->assertSame('Usługi', ServiceResource::getPluralModelLabel());
        $this->assertSame('content', ServiceResource::getNavigationGroup());
        $this->assertSame(1, ServiceResource::getNavigationSort());
    }

    public function test_no_tenant_resolved_keeps_default_service_labels(): void
    {
        // Console/CLI context — TenantFeature::currentTenant() resolves to null.
        $this->assertSame('Usługa', ServiceResource::getModelLabel());
        $this->assertSame('Usługi', ServiceResource::getPluralModelLabel());
        $this->assertSame('content', ServiceResource::getNavigationGroup());
        $this->assertSame(1, ServiceResource::getNavigationSort());
    }
}
