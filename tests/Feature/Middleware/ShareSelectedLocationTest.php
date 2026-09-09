<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Http\Middleware\ResolveTenant;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faza 5.1 (86cbahqg3) foundation. Unlike LocationContextTest (which drives
 * LocationContext directly), this class proves the middleware actually runs
 * for a REAL request through the real 'web' group — registered in
 * bootstrap/app.php, not just callable in isolation. Uses the project's
 * established actingAsTenant() pattern (bind a fake ResolveTenant — see
 * BookingConfirmationSecurityTest/TenantFeatureTest) so the real
 * ShareSelectedLocation, appended right after ResolveTenant/
 * SubstituteBindings/CheckMaintenanceMode, still runs unmodified.
 *
 * `GET /` is used as the carrier route: it's the one route explicitly proven
 * to work with NO tenant at all (home-fallback), which this suite also needs
 * to exercise, and it sits directly in the base 'web' group so no route-level
 * wiring is required for ShareSelectedLocation to run — exactly the "don't
 * touch any view" constraint this step is scoped to (home-fallback's own
 * view is untouched; only the response status/session are asserted).
 */
class ShareSelectedLocationTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_a_location_belonging_to_another_tenant_is_cleared_and_the_page_still_loads(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();
        $foreignLocation = Location::factory()->for($orgB, 'organization')->create(['is_active' => true]);

        $response = $this->actingAsTenant($orgA)
            ->withSession(['selected_location_id' => $foreignLocation->id])
            ->get('/');

        $response->assertOk();
        $response->assertSessionMissing('selected_location_id');
    }

    public function test_a_deleted_location_id_is_cleared_and_the_page_still_loads(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $response = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => 999999])
            ->get('/');

        $response->assertOk();
        $response->assertSessionMissing('selected_location_id');
    }

    public function test_an_inactive_locations_id_is_cleared_and_the_page_still_loads(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create(['is_active' => false]);

        $response = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $location->id])
            ->get('/');

        $response->assertOk();
        $response->assertSessionMissing('selected_location_id');
    }

    public function test_a_valid_selection_for_the_current_tenant_survives_the_request(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        [$locationA, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);

        $response = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $locationB->id])
            ->get('/');

        $response->assertOk();
        $response->assertSessionHas('selected_location_id', $locationB->id);
    }

    /**
     * Kryterium akceptacji z 86cbahqg3 literally: a stale cross-tenant value
     * must be cleared silently, never a 500 — this asserts BOTH halves for
     * the root-domain (no tenant at all) case, the one where a naive
     * implementation would most plausibly throw trying to resolve "the
     * current tenant" for validation.
     */
    public function test_root_domain_with_a_stale_session_value_is_cleared_without_a_500(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create(['is_active' => true]);

        $response = $this->withSession(['selected_location_id' => $location->id])->get('/');

        $response->assertOk();
        $response->assertSessionMissing('selected_location_id');
    }

    public function test_a_guest_with_no_session_value_at_all_does_not_error(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create(['is_active' => true]);

        $response = $this->actingAsTenant($org)->get('/');

        $response->assertOk();
        $response->assertSessionMissing('selected_location_id');
    }
}
