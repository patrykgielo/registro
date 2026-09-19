<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Http\Middleware\ResolveTenant;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ClickUp 123k99cvcc3 — SeedEquipmentRental (and, before it runs at all, the
 * onboarding:seed-vertical console command that wraps it) created Service
 * rows directly via Eloquent, never touching service_location_stocks. A
 * seeded catalogue showed 0 available everywhere until an admin happened to
 * open one product's "Stany magazynowe" tab. Also covers the REVERSE
 * ordering bug: a location created AFTER the catalogue already exists must
 * inherit quantity_total, not silently reset every product's opening
 * quantity to 0.
 */
class SeedVerticalDataMaterializesLocationStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function actingAsTenant(Organization $org): static
    {
        $this->app->bind(ResolveTenant::class, function () use ($org) {
            return new class($org)
            {
                public function __construct(private Organization $org) {}

                public function handle($request, $next)
                {
                    $request->attributes->set('tenant', $this->org);

                    return $next($request);
                }
            };
        });

        return $this;
    }

    public function test_seeding_a_tenant_that_already_has_a_location_makes_products_immediately_available_via_the_real_cart_route(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create(['is_active' => true]);

        $this->artisan('onboarding:seed-vertical', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $service = Service::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('name', 'Wiertarka udarowa')
            ->firstOrFail();

        $this->assertGreaterThan(0, $service->quantity_total);

        $customer = User::factory()->create();

        $response = $this->actingAs($customer)
            ->actingAsTenant($org)
            ->post(route('cart.add'), [
                'service_id' => $service->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(2)->toDateString(),
                'quantity' => 1,
            ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('cart_items', [
            'service_id' => $service->id,
            'quantity' => 1,
        ]);
    }

    public function test_seeding_materializes_a_stock_row_at_the_primary_location_matching_quantity_total(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create(['is_active' => true]);

        $this->artisan('onboarding:seed-vertical', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $service = Service::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('name', 'Wiertarka udarowa')
            ->firstOrFail();

        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id,
            'location_id' => $location->id,
            'quantity' => $service->quantity_total,
        ]);
    }

    /**
     * Reverse order: the catalogue exists FIRST (via the seeder, no location
     * to anchor to yet -> no rows at all), the organization's first-ever
     * location is created AFTER. It must inherit each service's
     * quantity_total, not reset every product to 0.
     */
    public function test_a_location_created_after_the_catalogue_already_exists_inherits_quantity_total_instead_of_zero(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $this->artisan('onboarding:seed-vertical', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $service = Service::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('name', 'Wiertarka udarowa')
            ->firstOrFail();

        $this->assertSame(
            0,
            ServiceLocationStock::withoutGlobalScope('organization')->where('service_id', $service->id)->count(),
            'sanity check: no location existed at seed time, so no stock row could have been created yet'
        );

        $firstLocation = Location::factory()->for($org, 'organization')->create(['is_active' => true]);

        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id,
            'location_id' => $firstLocation->id,
            'quantity' => $service->quantity_total,
        ]);
    }

    /**
     * A SECOND location added after the catalogue already has stock
     * elsewhere must still zero-fill, same as before this fix — only the
     * genuinely FIRST location for an organization inherits quantity_total.
     */
    public function test_a_second_location_still_zero_fills_even_when_the_catalogue_predates_it(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $firstLocation = Location::factory()->for($org, 'organization')->create(['is_active' => true]);

        $this->artisan('onboarding:seed-vertical', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $service = Service::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('name', 'Wiertarka udarowa')
            ->firstOrFail();

        $secondLocation = Location::factory()->for($org, 'organization')->create(['is_active' => true]);

        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id,
            'location_id' => $secondLocation->id,
            'quantity' => 0,
        ]);
        // The first location must be unaffected by the second one's arrival.
        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id,
            'location_id' => $firstLocation->id,
            'quantity' => $service->quantity_total,
        ]);
    }
}
