<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleDataRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Set the tenant context for HTTP requests.
     *
     * Replaces ResolveTenant middleware with a test double that sets the org directly,
     * same pattern used throughout the project's test suite.
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

    public function test_vehicle_types_endpoint_is_rate_limited_at_60_per_minute(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->actingAsTenant($org);

        for ($i = 0; $i < 60; $i++) {
            $response = $this->getJson(route('api.vehicle-types'));
            $response->assertOk();
        }

        $response = $this->getJson(route('api.vehicle-types'));
        $response->assertStatus(429);
    }
}
