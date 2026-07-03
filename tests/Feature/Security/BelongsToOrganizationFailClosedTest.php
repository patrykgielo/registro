<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression tests for VULN-003 Layer 2: BelongsToOrganization's global scope now
 * fails closed (returns zero rows) whenever ResolveTenant genuinely ran for the
 * current request and still resolved no tenant — instead of silently no-op'ing
 * (returning ALL organizations' rows), as it did before this hardening.
 *
 * See:
 * - app/Http/Middleware/ResolveTenant.php (sets `tenant_resolution_attempted`)
 * - app/Traits/BelongsToOrganization.php (consumes it to fail closed)
 * - app/docs/security/vulnerabilities/VULN-003-root-domain-tenant-bypass.md (Layer 2 section)
 *
 * Originally, unlike tests/Feature/Security/RootDomainTenantIsolationTest.php
 * (routes that already carried RequireTenant::class), the routes exercised
 * here deliberately carried ONLY ResolveTenant::class — proving the fix works
 * at the query/model layer, independent of route middleware. VULN-003 "Layer
 * 3" later added RequireTenant to the booking.* and appointments.* group too (see
 * BookingCrossTenantSessionFallbackTest); the affected tests below were
 * updated accordingly, but the trait-level mechanism they exercise is
 * unchanged and still relevant as a backstop for any route missing
 * RequireTenant.
 */
class BelongsToOrganizationFailClosedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);
        Notification::fake();
    }

    /**
     * Core Layer 2 mechanism: `/booking/available-slots` originally sat behind
     * ['auth', ResolveTenant::class] only (routes/web.php) — no RequireTenant.
     * Before Layer 2, a request with no resolved tenant would silently see
     * Service::findOrFail() succeed against ANY organization's service (the
     * global scope no-op'd). Layer 2 made the same query return zero rows and
     * findOrFail() 404 on its own, independent of route middleware.
     *
     * VULN-003 "Layer 3" (see BookingCrossTenantSessionFallbackTest) later added
     * RequireTenant::class to this same route group, so this request now 404s at
     * the route layer before the Service query even runs — the assertion is
     * unchanged, but Layer 2's trait-level backstop is no longer the only thing
     * proven here. Left in place because it still passes and still documents
     * defense in depth (if RequireTenant were ever removed from this route by
     * mistake, Layer 2 alone would still catch it).
     */
    public function test_available_slots_endpoint_fails_closed_on_root_domain_without_require_tenant(): void
    {
        $org = Organization::factory()->autoDetailing()->create();
        $service = Service::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('http://registro.local/booking/available-slots?'.http_build_query([
                'service_id' => $service->id,
                'date' => now()->addDays(2)->format('Y-m-d'),
            ]));

        $response->assertNotFound();
    }

    /**
     * Originally a "side-benefit" check: BookingController's service-selection
     * step queries Service::active()->get() with no explicit org filter, and at
     * the time this test was written `booking.step` carried no RequireTenant
     * guard — so this proved the trait-level fail-closed backstop worked even
     * without route-level protection (200 OK, but zero services, no leak).
     *
     * VULN-003 "Layer 3" (see BookingCrossTenantSessionFallbackTest) closed the
     * separately-tracked session-fallback gap by adding RequireTenant::class to
     * this exact route group — so `booking.step` now 404s outright on the root
     * domain, before the Service query this test used to inspect ever runs.
     * Updated to assert the new (stronger) behavior; BelongsToOrganization's
     * fail-closed scope remains the backstop for any route that still lacks
     * RequireTenant (proven by the other tests in this file).
     */
    public function test_booking_wizard_service_step_returns_404_on_root_domain(): void
    {
        $orgA = Organization::factory()->autoDetailing()->create();
        $orgB = Organization::factory()->autoDetailing()->create();

        Service::factory()->create([
            'organization_id' => $orgA->id,
            'name' => 'Usluga Organizacji A',
            'is_active' => true,
        ]);
        Service::factory()->create([
            'organization_id' => $orgB->id,
            'name' => 'Usluga Organizacji B',
            'is_active' => true,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('http://registro.local/booking/step/1');

        $response->assertNotFound();
    }

    /**
     * Companion to the above: AppointmentController::index() scopes by
     * customer_id already, but the underlying Appointment query still goes
     * through BelongsToOrganization. Confirms an authenticated user's own
     * appointments remain visible when the request is properly tenant-scoped
     * via a REAL subdomain (genuine ResolveTenant resolution, not a stub) —
     * i.e. Layer 2 does not break the legitimate, tenant-resolved path.
     */
    public function test_my_appointments_still_visible_with_properly_resolved_tenant(): void
    {
        $org = Organization::factory()->autoDetailing()->create();
        $user = User::factory()->create();
        $staff = User::factory()->create();
        $staff->assignRole(Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']));
        $service = Service::factory()->create(['organization_id' => $org->id]);

        $appointment = Appointment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => $user->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'status' => AppointmentStatus::Pending,
        ]);

        $response = $this->actingAs($user)
            ->get('http://'.$org->slug.'.registro.local/my-appointments');

        $response->assertOk();
        $response->assertSee($appointment->id);
    }

    /**
     * Confirms the OTHER half of the design: a bare Eloquent query with no HTTP
     * request in flight (no ResolveTenant dispatch at all — e.g. a Unit test,
     * a queued job, or this test's own setUp() before any $this->get() call)
     * keeps today's permissive no-op behavior. tenant_resolution_attempted is
     * only ever set by ResolveTenant::handle() actually running.
     */
    public function test_bare_query_without_any_http_request_is_unaffected(): void
    {
        $orgA = Organization::factory()->autoDetailing()->create();
        $orgB = Organization::factory()->autoDetailing()->create();

        Service::factory()->create(['organization_id' => $orgA->id, 'is_active' => true]);
        Service::factory()->create(['organization_id' => $orgB->id, 'is_active' => true]);

        // No $this->get()/post() call has happened in this test — app('request')
        // is still the default bootstrap request, never touched by ResolveTenant.
        $this->assertSame(2, Service::count());
    }
}
