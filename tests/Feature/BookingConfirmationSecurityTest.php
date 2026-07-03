<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Organization;
use App\Models\Service;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Models\VehicleType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * VULN-003 Layer 2: booking.* routes sit behind ['auth', ResolveTenant::class]
 * only (no RequireTenant) — on the root domain (no tenant resolved), the real
 * ResolveTenant now marks tenant_resolution_attempted, so BelongsToOrganization
 * fails closed on Appointment/Service/StaffSchedule queries unless a tenant is
 * simulated. See actingAsTenant() below — same pattern used throughout the project.
 */
class BookingConfirmationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected User $user;

    protected Service $service;

    protected User $staff;

    protected VehicleType $vehicleType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->autoDetailing()->create();

        // Set the tenant on the currently-bound request too, so model creation
        // below (seeders, StaffSchedule, Appointment factory) auto-assigns
        // organization_id via BelongsToOrganization's creating hook —
        // actingAsTenant() below only affects requests dispatched through the
        // HTTP kernel via $this->get()/post().
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

        // Seed database with required data
        $this->artisan('db:seed', ['--class' => 'ServiceSeeder']);
        $this->artisan('db:seed', ['--class' => 'VehicleTypeSeeder']);

        $this->user = User::factory()->create();

        // Create staff user with "staff" role
        $this->staff = User::factory()->create();
        $staffRole = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $this->staff->assignRole($staffRole);

        // Create staff schedule for Monday-Friday 09:00-18:00
        for ($day = Carbon::MONDAY; $day <= Carbon::FRIDAY; $day++) {
            StaffSchedule::create([
                'user_id' => $this->staff->id,
                'day_of_week' => $day,
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'is_active' => true,
            ]);
        }

        $this->service = Service::first();
        $this->vehicleType = VehicleType::first();

        // Attach service to staff member (required for availability checks)
        $this->staff->services()->attach($this->service->id);
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
     * Get next working day (Monday-Friday) at least 2 days from now.
     */
    protected function getNextWorkingDay(): Carbon
    {
        $date = Carbon::now()->addDays(2); // Start 2 days from now for 24h advance booking

        // If it's Saturday or Sunday, move to next Monday
        while ($date->dayOfWeek === Carbon::SATURDAY || $date->dayOfWeek === Carbon::SUNDAY) {
            $date->addDay();
        }

        return $date;
    }

    /**
     * Test that confirmation page redirects without session token.
     */
    public function test_confirmation_redirects_without_session_token(): void
    {
        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->get(route('booking.confirmation'));

        $response->assertRedirect(route('appointments.index'));
        $response->assertSessionHas('error', 'Link potwierdzenia wygasł. Zobacz swoje wizyty poniżej.');
    }

    /**
     * Test that confirmation page works with valid session token.
     */
    public function test_confirmation_works_with_valid_session_token(): void
    {
        // Create appointment
        $appointment = Appointment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->user->id,
            'service_id' => $this->service->id,
            'staff_id' => $this->staff->id,
        ]);

        // Simulate session token (created by BookingController::confirm)
        session(['booking_confirmed_id' => $appointment->id]);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->get(route('booking.confirmation'));

        $response->assertOk();
        $response->assertViewIs('booking-wizard.confirmation');
        $response->assertViewHas('appointment');

        // Verify appointment data passed to view
        $viewAppointment = $response->viewData('appointment');
        $this->assertEquals($appointment->id, $viewAppointment->id);
    }

    /**
     * Test that session token is single-use (consumed after first view).
     */
    public function test_session_token_is_single_use(): void
    {
        // Create appointment
        $appointment = Appointment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->user->id,
            'service_id' => $this->service->id,
            'staff_id' => $this->staff->id,
        ]);

        // Set session token
        session(['booking_confirmed_id' => $appointment->id]);

        // First request: Success
        $response1 = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->get(route('booking.confirmation'));

        $response1->assertOk();

        // Session token should be consumed (pulled)
        $this->assertNull(session('booking_confirmed_id'));

        // Second request: Redirect (token already used)
        $response2 = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->get(route('booking.confirmation'));

        $response2->assertRedirect(route('appointments.index'));
        $response2->assertSessionHas('error');
    }

    /**
     * Test that confirmation page blocks access to other users' appointments.
     */
    public function test_confirmation_blocks_other_users_appointments(): void
    {
        // Create another user and their appointment
        $otherUser = User::factory()->create();
        $otherAppointment = Appointment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $otherUser->id,
            'service_id' => $this->service->id,
            'staff_id' => $this->staff->id,
        ]);

        // Attacker tries to access other user's appointment via session tampering
        session(['booking_confirmed_id' => $otherAppointment->id]);

        $response = $this->actingAs($this->user)  // Logged in as different user
            ->actingAsTenant($this->org)
            ->get(route('booking.confirmation'));

        $response->assertForbidden();
    }

    /**
     * Test that confirmation route does NOT accept ID parameter in URL.
     */
    public function test_confirmation_route_has_no_id_parameter(): void
    {
        // Create appointment
        $appointment = Appointment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->user->id,
            'service_id' => $this->service->id,
            'staff_id' => $this->staff->id,
        ]);

        // Try to access confirmation with ID in URL (old vulnerable pattern)
        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->get('/booking/confirmation/'.$appointment->id);

        // Should return 404 (route not found)
        $response->assertNotFound();
    }

    /**
     * Test that appointment ID is NOT exposed in confirmation page URL.
     *
     * @group skip
     */
    public function skip_test_appointment_id_not_in_confirmation_url(): void
    {
        $this->markTestSkipped('getRequest() not available - route validation covered by other tests');

        return;

        // Create appointment
        $appointment = Appointment::factory()->create([
            'customer_id' => $this->user->id,
            'service_id' => $this->service->id,
            'staff_id' => $this->staff->id,
        ]);

        // Set session token
        session(['booking_confirmed_id' => $appointment->id]);

        $response = $this->actingAs($this->user)
            ->get(route('booking.confirmation'));

        $response->assertOk();

        // Verify URL does NOT contain appointment ID
        $this->assertEquals(
            '/booking/confirmation',
            $response->getRequest()->getRequestUri()
        );

        // Ensure no ID parameter in URL
        $this->assertStringNotContainsString(
            (string) $appointment->id,
            $response->getRequest()->getRequestUri()
        );
    }

    /**
     * Test full booking flow creates session token correctly.
     */
    public function test_booking_flow_creates_session_token(): void
    {
        // Simulate completed booking wizard session
        session([
            'booking' => [
                'service_id' => $this->service->id,
                'date' => $this->getNextWorkingDay()->format('Y-m-d'),
                'time_slot' => '10:00',
                'vehicle_type_id' => $this->vehicleType->id,
                'location_address' => 'Test Address 123',
                'location_latitude' => 52.2297,
                'location_longitude' => 21.0122,
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'email' => 'jan@example.com',
                'phone' => '+48123456789',
            ],
        ]);

        // Submit booking confirmation
        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('booking.confirm'));

        // Should redirect to confirmation page (no ID in URL)
        $response->assertRedirect(route('booking.confirmation'));

        // Session should contain appointment ID token
        $this->assertNotNull(session('booking_confirmed_id'));

        // Wizard session should be cleared
        $this->assertNull(session('booking'));

        // Verify appointment created
        $this->assertDatabaseHas('appointments', [
            'customer_id' => $this->user->id,
            'service_id' => $this->service->id,
            'email' => 'jan@example.com',
        ]);
    }
}
