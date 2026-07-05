<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SECURITY FIX 001: Booking Confirmation ID Exposure
 *
 * This test verifies the session-based confirmation flow prevents ID enumeration attacks.
 *
 * VULN-003 Layer 2: `showConfirmation()` sits behind `['auth', ResolveTenant::class]`
 * only (no `RequireTenant`) — on the root domain (no tenant resolved), the real
 * ResolveTenant now marks `tenant_resolution_attempted`, so BelongsToOrganization
 * fails closed on Appointment queries unless a tenant is simulated for the request.
 * Tests below create an Organization and bind it via actingAsTenant() — same
 * pattern used throughout the project (see TenantFeatureTest, CustomerOrdersTest).
 */
class BookingConfirmationSecurityMinimalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->autoDetailing()->create();

        // Set the tenant on the currently-bound request too, so model creation
        // below (seeders, Appointment::create) auto-assigns organization_id via
        // BelongsToOrganization's creating hook — actingAsTenant() below only
        // affects requests dispatched through the HTTP kernel via $this->get()/post().
        $this->app['request']->attributes->set('tenant', $this->org);

        // email_templates is intentionally a GLOBAL, NULL-organization_id system
        // table (see migration 2026_06_29_120000_fix_tenant_scoped_unique_constraints
        // — composite tenant-scoped uniques were deliberately skipped for it) — but
        // EmailTemplate still uses BelongsToOrganization, so with a real tenant now
        // resolved for this request, lookups get tenant-filtered and miss the global
        // rows (same pre-existing, orthogonal gap behind CustomerOrdersTest's known
        // 'order-cancelled' template failure — unrelated to VULN-003 Layer 2, just
        // newly reachable here now that a tenant is properly simulated). Fake
        // notifications so these tests don't depend on that unrelated gap.
        \Illuminate\Support\Facades\Notification::fake();
    }

    /**
     * Bind a test double for ResolveTenant — same pattern used throughout the project.
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

    /**
     * Test 1: Confirmation page redirects without session token
     * CRITICAL: Prevents unauthorized access
     */
    public function test_confirmation_requires_session_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('booking.confirmation'));

        $response->assertRedirect(route('appointments.index'));
        $response->assertSessionHas('error', 'Link potwierdzenia wygasł. Zobacz swoje wizyty poniżej.');
    }

    /**
     * Test 2: Session token is single-use (consumed after first view)
     * CRITICAL: Prevents token reuse
     */
    public function test_session_token_is_single_use(): void
    {
        // Seed required data
        $this->artisan('db:seed', ['--class' => 'ServiceSeeder']);
        $this->artisan('db:seed', ['--class' => 'VehicleTypeSeeder']);

        $user = User::factory()->create();
        $staff = User::factory()->create();
        $staffRole = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $staff->assignRole($staffRole);

        // Create appointment with all required fields
        $appointment = Appointment::create([
            'organization_id' => $this->org->id,
            'customer_id' => $user->id,
            'service_id' => Service::first()->id,
            'staff_id' => $staff->id,
            'appointment_date' => now()->addDays(2)->format('Y-m-d'),
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'status' => AppointmentStatus::Pending,
            'vehicle_type_id' => VehicleType::first()->id,
            'location_address' => 'Test Address 123',
            'location_latitude' => 52.2297,
            'location_longitude' => 21.0122,
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => 'jan@example.com',
            'phone' => '+48123456789',
        ]);

        // Set session token
        session(['booking_confirmed_id' => $appointment->id]);

        // First request: Success
        $response1 = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('booking.confirmation'));

        $response1->assertOk();

        // Session token should be consumed (pulled)
        $this->assertNull(session('booking_confirmed_id'));

        // Second request: Redirect (token already used)
        $response2 = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('booking.confirmation'));

        $response2->assertRedirect(route('appointments.index'));
        $response2->assertSessionHas('error');
    }

    /**
     * Test 3: Confirmation route does NOT accept ID parameter in URL
     * CRITICAL: Prevents ID enumeration attack
     */
    public function test_confirmation_route_rejects_id_parameter(): void
    {
        $user = User::factory()->create();

        // Try to access confirmation with ID in URL (old vulnerable pattern)
        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get('/booking/confirmation/123');

        // Should return 404 (route not found)
        $response->assertNotFound();
    }

    /**
     * Test 4: Appointment ID is NOT exposed in confirmation URL
     * CRITICAL: Verifies security fix
     *
     * @group skip
     */
    public function skip_test_appointment_id_not_in_url(): void
    {
        $this->markTestSkipped('getRequest() not available in Laravel test - route validation covered by other tests');

        return;

        $this->artisan('db:seed', ['--class' => 'ServiceSeeder']);
        $this->artisan('db:seed', ['--class' => 'VehicleTypeSeeder']);

        $user = User::factory()->create();
        $staff = User::factory()->create();
        $staffRole = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $staff->assignRole($staffRole);

        $appointment = Appointment::create([
            'customer_id' => $user->id,
            'service_id' => Service::first()->id,
            'staff_id' => $staff->id,
            'appointment_date' => now()->addDays(2)->format('Y-m-d'),
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'status' => AppointmentStatus::Pending,
            'vehicle_type_id' => VehicleType::first()->id,
            'location_address' => 'Test Address 123',
            'location_latitude' => 52.2297,
            'location_longitude' => 21.0122,
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => 'jan@example.com',
            'phone' => '+48123456789',
        ]);

        // Set session token
        session(['booking_confirmed_id' => $appointment->id]);

        $response = $this->actingAs($user)
            ->get(route('booking.confirmation'));

        $response->assertOk();

        // Verify route name is correct (no ID parameter in URL)
        $this->assertEquals(
            route('booking.confirmation'),
            url('/booking/confirmation')
        );
    }

    /**
     * Test 5: Ownership check prevents access to other users' appointments
     * CRITICAL: Defense in depth
     */
    public function test_ownership_check_prevents_unauthorized_access(): void
    {
        $this->artisan('db:seed', ['--class' => 'ServiceSeeder']);
        $this->artisan('db:seed', ['--class' => 'VehicleTypeSeeder']);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $staff = User::factory()->create();
        $staffRole = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $staff->assignRole($staffRole);

        // User 2's appointment
        $appointment = Appointment::create([
            'organization_id' => $this->org->id,
            'customer_id' => $user2->id,
            'service_id' => Service::first()->id,
            'staff_id' => $staff->id,
            'appointment_date' => now()->addDays(2)->format('Y-m-d'),
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'status' => AppointmentStatus::Pending,
            'vehicle_type_id' => VehicleType::first()->id,
            'location_address' => 'Test Address 123',
            'location_latitude' => 52.2297,
            'location_longitude' => 21.0122,
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => 'jan@example.com',
            'phone' => '+48123456789',
        ]);

        // Attacker (user1) tries to access user2's appointment via session tampering
        session(['booking_confirmed_id' => $appointment->id]);

        $response = $this->actingAs($user1)  // Logged in as different user
            ->actingAsTenant($this->org)
            ->get(route('booking.confirmation'));

        $response->assertForbidden();
    }
}
