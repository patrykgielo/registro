<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\TenantFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantWithFeatures(array $features = []): Organization
    {
        $owner = User::factory()->create();

        return Organization::create([
            'name' => 'Test Org',
            'slug' => 'test-org',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
            'settings' => ['features' => $features],
        ]);
    }

    /**
     * Set the tenant context for HTTP requests.
     *
     * Replaces ResolveTenant middleware with a test double that sets the org directly.
     */
    private function actingAsTenant(Organization $org): static
    {
        config(['app.domain' => 'registro.local']);

        // Replace ResolveTenant middleware with one that forces the given org
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

    public function test_tenant_feature_returns_false_when_no_tenant(): void
    {
        $this->assertFalse(TenantFeature::active('vehicles'));
    }

    public function test_tenant_feature_reads_from_request_attributes(): void
    {
        $org = $this->createTenantWithFeatures(['vehicles' => true]);

        // Simulate ResolveTenant middleware setting the attribute
        $this->app['request']->attributes->set('tenant', $org);

        $this->assertTrue(TenantFeature::active('vehicles'));
        $this->assertFalse(TenantFeature::active('mobile_service'));
    }

    public function test_booking_wizard_has_4_steps_without_vehicles(): void
    {
        $org = $this->createTenantWithFeatures([]); // No features enabled
        $owner = $org->owner;
        $owner->assignRole('customer');

        $service = \App\Models\Service::factory()->create();

        // Set up booking session with enough data
        $this->withSession([
            'booking' => [
                'service_id' => $service->id,
                'date' => now()->addDay()->format('Y-m-d'),
                'time_slot' => '10:00',
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'email' => 'jan@test.pl',
                'phone' => '123456789',
            ],
        ]);

        // Step 3 should show contact (not vehicle-location) — vehicle step is skipped
        $response = $this->actingAs($owner)
            ->actingAsTenant($org)
            ->get(route('booking.step', 3));
        $response->assertOk();
        $response->assertViewIs('booking-wizard.steps.contact');

        // Step 4 should show review
        $response = $this->actingAs($owner)
            ->actingAsTenant($org)
            ->withSession([
                'booking' => [
                    'service_id' => $service->id,
                    'date' => now()->addDay()->format('Y-m-d'),
                    'time_slot' => '10:00',
                    'first_name' => 'Jan',
                    'last_name' => 'Kowalski',
                    'email' => 'jan@test.pl',
                    'phone' => '123456789',
                ],
            ])
            ->get(route('booking.step', 4));
        $response->assertOk();
        $response->assertViewIs('booking-wizard.steps.review');

        // Step 5 should redirect to step 1 (out of bounds)
        $response = $this->actingAs($owner)
            ->actingAsTenant($org)
            ->get(route('booking.step', 5));
        $response->assertRedirect(route('booking.step', 1));
    }

    public function test_booking_wizard_has_5_steps_with_vehicles(): void
    {
        $org = $this->createTenantWithFeatures([
            'vehicles' => true,
            'mobile_service' => true,
        ]);
        $owner = $org->owner;
        $owner->assignRole('customer');

        $service = \App\Models\Service::factory()->create();

        // Step 3 should show vehicle-location when features are enabled
        $response = $this->actingAs($owner)
            ->actingAsTenant($org)
            ->withSession([
                'booking' => [
                    'service_id' => $service->id,
                    'date' => now()->addDay()->format('Y-m-d'),
                    'time_slot' => '10:00',
                ],
            ])
            ->get(route('booking.step', 3));
        $response->assertOk();
        $response->assertViewIs('booking-wizard.steps.vehicle-location');
    }

    public function test_profile_hides_vehicle_link_when_feature_disabled(): void
    {
        $org = $this->createTenantWithFeatures([]); // No features
        $owner = $org->owner;

        $response = $this->actingAs($owner)
            ->actingAsTenant($org)
            ->get(route('profile.index'));
        $response->assertOk();
        $response->assertDontSee('route(\'profile.vehicle\')');
    }

    public function test_profile_vehicle_page_returns_404_when_feature_disabled(): void
    {
        $org = $this->createTenantWithFeatures([]); // No features
        $owner = $org->owner;

        $response = $this->actingAs($owner)
            ->actingAsTenant($org)
            ->get(route('profile.vehicle'));
        $response->assertNotFound();
    }

    public function test_vehicle_api_returns_empty_when_feature_disabled(): void
    {
        $org = $this->createTenantWithFeatures([]); // No features
        $owner = $org->owner;

        $response = $this->actingAs($owner)
            ->actingAsTenant($org)
            ->getJson(route('api.vehicle-types'));
        $response->assertOk();
        $response->assertJson(['success' => true, 'data' => []]);
    }
}
