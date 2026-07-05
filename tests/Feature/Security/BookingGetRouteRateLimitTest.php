<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\ResolveTenant;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VULN-001 follow-up: GET booking endpoints were left unthrottled while the
 * POST endpoints already had `throttle` middleware. See
 * app/docs/security/vulnerabilities/VULN-001-missing-rate-limiting.md.
 */
class BookingGetRouteRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->autoDetailing()->create();
        $this->app['request']->attributes->set('tenant', $this->org);
        $this->user = User::factory()->create();
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

    public function test_restore_progress_get_route_is_rate_limited(): void
    {
        $this->actingAs($this->user)->actingAsTenant($this->org);

        for ($i = 1; $i <= 60; $i++) {
            $response = $this->get(route('booking.restore-progress'));
            $response->assertStatus(200);
        }

        // 61st request within the same minute must be throttled (throttle:60,1).
        $response = $this->get(route('booking.restore-progress'));
        $response->assertStatus(429);
    }

    public function test_unavailable_dates_get_route_has_stricter_rate_limit(): void
    {
        $service = Service::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)->actingAsTenant($this->org);

        for ($i = 1; $i <= 20; $i++) {
            $response = $this->get(route('booking.unavailable-dates', ['service_id' => $service->id]));
            $response->assertStatus(200);
        }

        // 21st request within the same minute must be throttled (throttle:20,1).
        $response = $this->get(route('booking.unavailable-dates', ['service_id' => $service->id]));
        $response->assertStatus(429);
    }

    public function test_booking_step_get_route_is_rate_limited(): void
    {
        $this->actingAs($this->user)->actingAsTenant($this->org);

        for ($i = 1; $i <= 60; $i++) {
            $response = $this->get(route('booking.step', 1));
            $response->assertStatus(200);
        }

        $response = $this->get(route('booking.step', 1));
        $response->assertStatus(429);
    }

    public function test_booking_create_get_route_is_rate_limited(): void
    {
        $service = Service::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)->actingAsTenant($this->org);

        for ($i = 1; $i <= 60; $i++) {
            $response = $this->get(route('booking.create', $service));
            $response->assertStatus(302);
        }

        // 61st request within the same minute must be throttled (throttle:60,1).
        $response = $this->get(route('booking.create', $service));
        $response->assertStatus(429);
    }

    public function test_available_slots_get_route_has_stricter_rate_limit(): void
    {
        $service = Service::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)->actingAsTenant($this->org);

        $params = ['service_id' => $service->id, 'date' => now()->addDay()->format('Y-m-d')];

        for ($i = 1; $i <= 20; $i++) {
            $response = $this->get(route('booking.slots', $params));
            $response->assertStatus(200);
        }

        // 21st request within the same minute must be throttled (throttle:20,1).
        $response = $this->get(route('booking.slots', $params));
        $response->assertStatus(429);
    }
}
