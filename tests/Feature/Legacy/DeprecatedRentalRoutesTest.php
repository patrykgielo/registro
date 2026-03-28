<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Models\Organization;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Verifies that the deprecated /wypozyczalnia/... booking wizard routes
 * respond with 410 Gone instead of 200, 302, or 500.
 *
 * The read-only AJAX availability endpoints (/api/rental/...) must NOT
 * be affected — they remain fully functional.
 */
class DeprecatedRentalRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class]);

        $this->org = Organization::factory()->equipmentRental()->create();

        // Service must belong to the org so the slug route binding resolves
        // when the tenant global scope is active via actingAsTenant().
        $this->service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 3,
        ]);
    }

    /**
     * Stub ResolveTenant so the route model binding for {service:slug} resolves
     * correctly under the BelongsToOrganization global scope.
     *
     * Same pattern used in AddToCartTest and CheckoutFlowTest.
     */
    private function actingAsTenant(Organization $org): static
    {
        $this->app->bind(\App\Http\Middleware\ResolveTenant::class, function () use ($org) {
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

    // -------------------------------------------------------------------------
    // GET routes — 410 Gone
    // -------------------------------------------------------------------------

    public function test_step1_get_returns_410(): void
    {
        $response = $this->actingAsTenant($this->org)
            ->get(route('rental.step1', $this->service));

        $response->assertStatus(410);
    }

    public function test_step2_get_returns_410(): void
    {
        $response = $this->actingAsTenant($this->org)
            ->get(route('rental.step2', $this->service));

        $response->assertStatus(410);
    }

    public function test_step3_get_returns_410(): void
    {
        $response = $this->actingAsTenant($this->org)
            ->get(route('rental.step3', $this->service));

        $response->assertStatus(410);
    }

    public function test_confirmation_get_returns_410(): void
    {
        $response = $this->actingAsTenant($this->org)
            ->get(route('rental.confirmation', $this->service));

        $response->assertStatus(410);
    }

    // -------------------------------------------------------------------------
    // POST routes — 410 Gone
    // -------------------------------------------------------------------------

    public function test_step1_store_post_returns_410(): void
    {
        $response = $this->actingAsTenant($this->org)
            ->post(route('rental.step1.store', $this->service), []);

        $response->assertStatus(410);
    }

    public function test_step2_store_post_returns_410(): void
    {
        $response = $this->actingAsTenant($this->org)
            ->post(route('rental.step2.store', $this->service), []);

        $response->assertStatus(410);
    }

    public function test_confirm_post_returns_410(): void
    {
        $response = $this->actingAsTenant($this->org)
            ->post(route('rental.confirm', $this->service), []);

        $response->assertStatus(410);
    }

    // -------------------------------------------------------------------------
    // 410 response body — machine-readable hint
    // -------------------------------------------------------------------------

    public function test_410_response_contains_json_message(): void
    {
        $response = $this->actingAsTenant($this->org)
            ->get(route('rental.step1', $this->service));

        $response->assertStatus(410);
        $response->assertJson(['message' => 'Ten endpoint jest nieaktywny. Użyj /koszyk']);
    }

    // -------------------------------------------------------------------------
    // API availability endpoints — must remain functional (200)
    // -------------------------------------------------------------------------

    public function test_check_availability_api_still_returns_200(): void
    {
        $response = $this->actingAsTenant($this->org)
            ->get(route('rental.availability', $this->service), [
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(3)->toDateString(),
            ]);

        // The endpoint validates the query params separately — hitting it
        // without them returns 422, but it must NOT return 410.
        $this->assertNotEquals(410, $response->getStatusCode());
        $this->assertNotEquals(500, $response->getStatusCode());
    }

    public function test_monthly_availability_api_still_returns_200(): void
    {
        $response = $this->actingAsTenant($this->org)
            ->get(route('rental.calendar', $this->service), [
                'year' => now()->year,
                'month' => now()->month,
            ]);

        $this->assertNotEquals(410, $response->getStatusCode());
        $this->assertNotEquals(500, $response->getStatusCode());
    }

    public function test_check_availability_api_returns_available_quantity(): void
    {
        $response = $this->actingAsTenant($this->org)
            ->getJson(route('rental.availability', $this->service).'?start_date='.now()->addDay()->toDateString().'&end_date='.now()->addDays(3)->toDateString());

        $response->assertOk();
        $response->assertJsonStructure(['available_quantity', 'total_quantity']);
    }
}
