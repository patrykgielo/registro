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

    // -------------------------------------------------------------------------
    // 4. "Dostępne też w" — Faza 5.5 (86cbahqgn), product page only.
    // -------------------------------------------------------------------------

    /**
     * The rescue case the ticket exists for: selected branch has ZERO, a
     * sibling branch has stock. Falsifiability of "the section only appears
     * because of this code": ServiceController::availableElsewhere() returning
     * a hardcoded `[]` makes this test fail (checked manually — see report).
     */
    public function test_section_appears_when_the_selected_location_has_none_but_another_does(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($org, 'organization')->create(['is_active' => true, 'name' => 'Poznań']);
        $locationB = Location::factory()->for($org, 'organization')->create(['is_active' => true, 'name' => 'Gdańsk']);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $this->stock($org, $service, $locationA, 0);
        $this->stock($org, $service, $locationB, 2);

        $response = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $locationA->id])
            ->get(route('service.show', $service))
            ->assertOk();

        $response->assertSee('Dostępne też w');
        $response->assertSee('Gdańsk');
        $response->assertSee('2 szt.');
    }

    public function test_section_does_not_appear_when_no_other_location_has_stock(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $locationB = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $this->stock($org, $service, $locationA, 0);
        $this->stock($org, $service, $locationB, 0);

        $response = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $locationA->id])
            ->get(route('service.show', $service))
            ->assertOk();

        $response->assertDontSee('Dostępne też w');
    }

    /**
     * Decision (see report): the section is agnostic to the SELECTED branch's
     * own stock level — it fires on "free elsewhere", not "free elsewhere AND
     * empty here". A customer seeing 1 unit here may still want to know more
     * are waiting at another branch.
     */
    public function test_section_appears_even_when_the_selected_location_itself_has_stock(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $locationB = Location::factory()->for($org, 'organization')->create(['is_active' => true, 'name' => 'Wrocław']);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $this->stock($org, $service, $locationA, 1);
        $this->stock($org, $service, $locationB, 9);

        $response = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $locationA->id])
            ->get(route('service.show', $service))
            ->assertOk();

        $response->assertSee('Dostępne też w');
        $response->assertSee('Wrocław');
        $response->assertSee('9 szt.');
    }

    /**
     * A location with a stock row of 0 (not "no row at all") must never be
     * listed — `filter(quantity > 0)` in availableElsewhere(), distinct from
     * the "no anchor row" contract already covered above.
     */
    public function test_section_never_lists_a_location_with_zero_stock(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $locationB = Location::factory()->for($org, 'organization')->create(['is_active' => true, 'name' => 'Kraków']);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $this->stock($org, $service, $locationA, 5);
        $this->stock($org, $service, $locationB, 0);

        $response = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $locationA->id])
            ->get(route('service.show', $service))
            ->assertOk();

        // "Dostępne też w" is the literal heading this feature's own markup
        // renders (services/show.blade.php) ONLY when $availableElsewhere is
        // non-empty — its absence is the precise claim. Checking the
        // location NAME's absence page-wide is unreliable here: header.
        // blade.php's own switcher (Faza 5.2, unrelated to this feature)
        // lists every ACTIVE location regardless of stock, TWICE (desktop
        // dropdown + mobile drawer, per that file's own top-of-block
        // comment) — "Kraków" legitimately appears on this page already.
        $response->assertDontSee('Dostępne też w');
    }

    /**
     * An inactive location with stock must never appear, even though the
     * anchor row exists — activeLocations() already filters this at the
     * source (LocationContext::activeLocations()'s own ->active() scope).
     */
    public function test_section_never_lists_an_inactive_location(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $inactive = Location::factory()->for($org, 'organization')->create(['is_active' => false, 'name' => 'Zamknięty Oddział']);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $this->stock($org, $service, $locationA, 0);
        $this->stock($org, $service, $inactive, 3);

        $response = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $locationA->id])
            ->get(route('service.show', $service))
            ->assertOk();

        $response->assertDontSee('Dostępne też w');
        $response->assertDontSee('Zamknięty Oddział');
    }

    /**
     * A DIFFERENT tenant's location must never leak into this list, even if
     * it happens to have stock for a same-slug/same-id coincidence — proves
     * LocationContext::activeLocations()'s own tenant filter is what this
     * feature relies on, not a filter this feature adds itself.
     */
    public function test_section_never_lists_another_tenants_location(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $otherOrg = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $otherOrgLocation = Location::factory()->for($otherOrg, 'organization')->create(['is_active' => true, 'name' => 'Cudzy Oddział']);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $this->stock($org, $service, $locationA, 0);

        $response = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $locationA->id])
            ->get(route('service.show', $service))
            ->assertOk();

        $response->assertDontSee('Dostępne też w');
        $response->assertDontSee('Cudzy Oddział');
    }

    /**
     * No selection at all (multi-location tenant, nothing chosen yet) → the
     * badge already shows the combined total (existing behaviour), so there
     * is no "here" to contrast against — the section must not render.
     */
    public function test_section_does_not_appear_without_a_selected_location(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $locationB = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $this->stock($org, $service, $locationA, 3);
        $this->stock($org, $service, $locationB, 4);

        $response = $this->actingAsTenant($org)
            ->get(route('service.show', $service))
            ->assertOk();

        $response->assertDontSee('Dostępne też w');
    }

    /**
     * Single-location tenant: LocationContext::selected() auto-resolves the
     * one location for free (its own docblock), so $locationId is never
     * null here — but activeLocations() reject()ed down to the selected one
     * leaves an empty list, so the section still correctly never appears.
     */
    public function test_section_does_not_appear_for_a_single_location_tenant(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $onlyLocation = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $this->stock($org, $service, $onlyLocation, 5);

        $response = $this->actingAsTenant($org)
            ->get(route('service.show', $service))
            ->assertOk();

        $response->assertDontSee('Dostępne też w');
    }

    /**
     * Clicking a row must switch the branch AND land back on this exact
     * product page (location.select's own redirect_to contract, header.
     * blade.php's switcher already relies on the same mechanism).
     */
    public function test_clicking_a_row_switches_location_and_returns_to_the_same_product_page(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $locationB = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $this->stock($org, $service, $locationA, 0);
        $this->stock($org, $service, $locationB, 2);

        $productUrl = route('service.show', $service);

        $response = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $locationA->id])
            ->from($productUrl)
            ->post(route('location.select'), [
                'location_id' => $locationB->id,
                'redirect_to' => $productUrl,
            ]);

        $response->assertRedirect($productUrl);

        $followUp = $this->actingAsTenant($org)->get($productUrl)->assertOk();
        $followUp->assertSee('2 szt. dostępnych');
    }

    /**
     * Faza 5.5's core query-count constraint, proven at the SQL level rather
     * than by comparing two page loads (a before/after page-load comparison
     * is unsound here — see the note below). Two independent consumers need
     * the tenant's active-location list in the SAME request:
     * header.blade.php's switcher (`selectionRequired()`/`activeLocations()`,
     * Faza 5.2, pre-existing) and THIS phase's
     * `ServiceController::availableElsewhere()`. Before this phase they were
     * two separate `LocationContext` instances (default container
     * resolution, a fresh instance per `app(LocationContext::class)`
     * call — see the class's own docblock) with two separate
     * `$activeLocationsCache` fields, so adding this section would have
     * meant a genuinely NEW `locations` query the header did not already
     * pay for. `AppServiceProvider::register()`'s new `$this->app->
     * scoped(LocationContext::class)` (this phase) makes both consumers
     * share ONE instance/cache for the lifetime of the request, so the
     * query search for the section is the SAME query the header's own
     * switcher fires.
     *
     * Why not measure via two `->get()` calls and diff the counts (the
     * pattern the OTHER query-count tests in this file use): Laravel's HTTP
     * test harness does not tear down `$this->app` between simulated
     * `->get()` calls within one test method, so a `scoped()` instance
     * (and its cache) SURVIVES across them — unlike a real php-fpm request,
     * where the whole container dies at the end of every request. A
     * "warm-up" `->get()` followed by a "measured" `->get()` would silently
     * reuse the warm-up's already-populated `$activeLocationsCache`,
     * making the measured call show ZERO `locations` queries regardless of
     * whether sharing works — a false positive (measured directly: the
     * count-diff version of this test passed identically whether the
     * `scoped()` binding was present or removed entirely). Asserting the
     * exact count within a SINGLE request's query log has no such blind
     * spot and was verified to catch the regression it exists for — see
     * the report for the manual falsification (temporarily reverting to
     * default container resolution reproduces exactly 2 matching queries
     * instead of 1, confirmed by an ad-hoc run, not committed here).
     */
    public function test_the_active_locations_query_the_header_switcher_needs_is_shared_with_the_new_section_not_duplicated(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $locationB = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $this->stock($org, $service, $locationA, 1);
        $this->stock($org, $service, $locationB, 2);

        $productUrl = route('service.show', $service);

        DB::enableQueryLog();
        $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $locationA->id])
            ->get($productUrl)
            ->assertOk();
        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        // Matches activeLocations()'s own query shape
        // (`->active()->ordered()->get()`, no `id =` filter, no LIMIT) —
        // deliberately distinct from LocationContext::find()'s single-row
        // lookup (`... and "locations"."id" = ? limit 1`, no ORDER BY),
        // which legitimately fires more than once per request already
        // (ShareSelectedLocation's pruneStaleSelection() + the controller's
        // own selectedId() + the header's own selected() call for the
        // dropdown's checkmark — three pre-existing, UNRELATED call sites,
        // none of them touched by this phase).
        $activeLocationsQueries = collect($queryLog)->filter(
            fn (array $q) => str_contains($q['query'], 'from "locations"') && str_contains($q['query'], 'order by')
        );

        $this->assertCount(
            1,
            $activeLocationsQueries,
            'activeLocations() must run exactly once per request — got: '.$activeLocationsQueries->pluck('query')->implode(' | ')
        );
    }
}
