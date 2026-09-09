<?php

declare(strict_types=1);

namespace Tests\Feature\Rental;

use App\Http\Middleware\ResolveTenant;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Faza 5.3/5.4 (86cbahqgb/86cbahqgh, plan-wdrozenia.md) — the catalog tile
 * (`x-ios.service-card` on `/wypozyczalnia` + `/wypozyczalnia/{category}`,
 * inline markup on `/uslugi`) and the product page
 * (`/uslugi/{service:slug}`) both read
 * `RentalAvailabilityService::availabilityForServices()` +
 * `availableQuantityFor()` through `RentalController`/`ServiceController`'s
 * own `locationAvailabilityFor()`/`rentalAvailabilityFor()` helpers.
 *
 * Same `actingAsTenant()` bind-a-fake-ResolveTenant pattern as
 * `RentalCatalogueTest`/`LocationSwitcherTest` — a real request through the
 * real 'web' group, so ShareSelectedLocation/RequireTenant/the header still
 * run; only tenant RESOLUTION is stubbed.
 */
class RentalCatalogueLocationAvailabilityTest extends TestCase
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

    private function stock(Organization $org, Service $service, Location $location, int $quantity): ServiceLocationStock
    {
        return ServiceLocationStock::create([
            'organization_id' => $org->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'is_active' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // 1. Query count — the acceptance criterion is explicit: "assert na
    //    liczbie zapytań", not on the rendered result.
    // -------------------------------------------------------------------------

    public function test_category_page_query_count_is_constant_regardless_of_item_count(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create(['is_active' => true]);

        $fewCategory = RentalCategory::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        foreach (range(1, 3) as $i) {
            $service = Service::factory()->itemRental()->create([
                'organization_id' => $org->id,
                'rental_category_id' => $fewCategory->id,
            ]);
            $this->stock($org, $service, $location, 5);
        }

        $manyCategory = RentalCategory::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        foreach (range(1, 20) as $i) {
            $service = Service::factory()->itemRental()->create([
                'organization_id' => $org->id,
                'rental_category_id' => $manyCategory->id,
            ]);
            $this->stock($org, $service, $location, 5);
        }

        // Warm request-independent caches (nav menu etc., keyed by tenant —
        // services.md) BEFORE measuring, so a cold-vs-warm cache difference
        // between the two categories can't masquerade as an item-count
        // effect — the actual failure mode this warm-up ruled out while
        // writing this test (33 vs 15 queries, fewer items costing MORE).
        $this->actingAsTenant($org)->get("/wypozyczalnia/{$fewCategory->slug}");
        $this->actingAsTenant($org)->get("/wypozyczalnia/{$manyCategory->slug}");

        DB::enableQueryLog();
        $this->actingAsTenant($org)->get("/wypozyczalnia/{$fewCategory->slug}")->assertOk();
        $fewCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->actingAsTenant($org)->get("/wypozyczalnia/{$manyCategory->slug}")->assertOk();
        $manyCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $fewCount,
            $manyCount,
            "Query count must be constant: 3 items issued {$fewCount} queries, 20 items issued {$manyCount}."
        );
    }

    /**
     * Deliberately ONE tenant throughout, measured before/after adding more
     * services, rather than two separate tenants — `SettingsManager::get()`
     * issues extra fallback queries (tenant-scoped row miss → global row) the
     * FIRST time a given tenant is ever touched, independent of item count.
     * Comparing two fresh tenants directly made this test measure THAT
     * effect instead of the one it's meant to catch (measured while writing
     * this test: 14 vs 28 queries for 2 vs 18 items on two separate tenants,
     * collapsing to identical counts once both measurements share one
     * already-warmed tenant).
     */
    public function test_uslugi_listing_query_count_is_constant_regardless_of_item_count(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $category = RentalCategory::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        foreach (range(1, 2) as $i) {
            $service = Service::factory()->itemRental()->create([
                'organization_id' => $org->id,
                'rental_category_id' => $category->id,
            ]);
            $this->stock($org, $service, $location, 5);
        }

        // Warm this tenant's caches (nav menu, settings — services.md) BEFORE
        // measuring either count.
        $this->actingAsTenant($org)->get('/uslugi');

        DB::enableQueryLog();
        $this->actingAsTenant($org)->get('/uslugi')->assertOk();
        $fewCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(1, 16) as $i) {
            $service = Service::factory()->itemRental()->create([
                'organization_id' => $org->id,
                'rental_category_id' => $category->id,
            ]);
            $this->stock($org, $service, $location, 5);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAsTenant($org)->get('/uslugi')->assertOk();
        $manyCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $fewCount,
            $manyCount,
            "Query count must be constant: 2 rentable items issued {$fewCount} queries, 18 issued {$manyCount}."
        );
    }

    // -------------------------------------------------------------------------
    // 2. Location-scoped number on the tile, and the "missing anchor row
    //    means ZERO" contract (kontrakt-dostepnosci.md).
    // -------------------------------------------------------------------------

    public function test_category_tile_shows_the_selected_locations_own_quantity(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        [$locationA, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        $category = RentalCategory::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $org->id,
            'rental_category_id' => $category->id,
        ]);
        $this->stock($org, $service, $locationA, 5);
        $this->stock($org, $service, $locationB, 2);

        $response = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $locationA->id])
            ->get("/wypozyczalnia/{$category->slug}")
            ->assertOk();

        $response->assertSee('Dostępne: 5 szt.');
        $response->assertDontSee('Dostępne: 2 szt.');
    }

    public function test_a_location_with_no_anchor_row_shows_unavailable_not_unlimited(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        [$locationA, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        $category = RentalCategory::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $org->id,
            'rental_category_id' => $category->id,
            'quantity_total' => 9,
        ]);
        // Only location A has a service_location_stocks row — B has none.
        $this->stock($org, $service, $locationA, 5);

        $response = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $locationB->id])
            ->get("/wypozyczalnia/{$category->slug}")
            ->assertOk();

        $response->assertSee('Obecnie niedostępne');
        $response->assertDontSee('Dostępne:');
    }

    /**
     * Code review (2026-09-09): the category-page test above proves the
     * "missing anchor row means ZERO, not unlimited" contract for the tile,
     * but `rentalAvailabilityFor()` reads that SAME contract through its own
     * call site in `ServiceController::show()` — an untested second caller
     * of a shared method is exactly the coverage gap this phase already saw
     * once with unit numbering. Same fixture shape as the tile test on
     * purpose, so a future reader can see at a glance these are the same
     * mechanism proven twice, not two different behaviours.
     */
    public function test_product_page_shows_unavailable_not_unlimited_for_a_location_with_no_anchor_row(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        [$locationA, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        $category = RentalCategory::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $org->id,
            'rental_category_id' => $category->id,
            'quantity_total' => 9,
        ]);
        // Only location A has a service_location_stocks row — B has none.
        $this->stock($org, $service, $locationA, 5);

        $response = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $locationB->id])
            ->get(route('service.show', $service))
            ->assertOk();

        $response->assertSee('Obecnie niedostępne');
        $response->assertDontSee('szt. dostępnych');
        $response->assertDontSee('Dostępny (');
    }

    public function test_single_location_tenant_gets_that_locations_quantity_with_no_explicit_selection(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $onlyLocation = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $category = RentalCategory::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $org->id,
            'rental_category_id' => $category->id,
            'quantity_total' => 99,
        ]);
        $this->stock($org, $service, $onlyLocation, 4);

        // No session key set — LocationContext::selected() auto-resolves the
        // single active location for free (see its own docblock).
        $response = $this->actingAsTenant($org)
            ->get("/wypozyczalnia/{$category->slug}")
            ->assertOk();

        $response->assertSee('Dostępne: 4 szt.');
    }

    public function test_multi_location_tenant_with_no_selection_falls_back_to_the_combined_total(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        $category = RentalCategory::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $org->id,
            'rental_category_id' => $category->id,
            'quantity_total' => 7,
        ]);
        // No reservations, no session selection — availableQuantityFor()
        // must resolve to $entry['total'] (== getAvailableQuantity(locationId: null),
        // today's pre-Faza-5 global behaviour).

        $response = $this->actingAsTenant($org)
            ->get("/wypozyczalnia/{$category->slug}")
            ->assertOk();

        $response->assertSee('Dostępne: 7 szt.');
    }

    // -------------------------------------------------------------------------
    // 3. Parity — tile and product page must agree for the same selection.
    // -------------------------------------------------------------------------

    public function test_product_page_agrees_with_the_tile_for_the_same_selected_location(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        [$locationA, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        $category = RentalCategory::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $org->id,
            'rental_category_id' => $category->id,
        ]);
        $this->stock($org, $service, $locationA, 4);
        $this->stock($org, $service, $locationB, 9);

        $tile = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $locationA->id])
            ->get("/wypozyczalnia/{$category->slug}")
            ->assertOk();
        $tile->assertSee('Dostępne: 4 szt.');

        $product = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $locationA->id])
            ->get(route('service.show', $service))
            ->assertOk();

        $product->assertSee('4 szt. dostępnych');
        $product->assertSee('Dostępny (4 szt.)');
        $product->assertDontSee('9 szt. dostępnych');
        // The calendar's own AJAX calls must carry the same branch, or the
        // number above and what the calendar fetches would disagree.
        $product->assertSee("selectedLocationId: {$locationA->id}", false);
    }

    public function test_time_slot_service_page_renders_no_quantity_badge(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $service = Service::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);

        $response = $this->actingAsTenant($org)
            ->get(route('service.show', $service))
            ->assertOk();

        $response->assertDontSee('szt. dostępnych');
        $response->assertDontSee('Obecnie niedostępne');
    }
}
