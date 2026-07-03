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
 * Unlike tests/Feature/Security/RootDomainTenantIsolationTest.php (which covers
 * routes that already carry the explicit RequireTenant::class middleware), the
 * routes exercised here deliberately carry ONLY ResolveTenant::class (no
 * RequireTenant) — proving the fix works at the query/model layer, independent
 * of any per-route middleware being remembered.
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
     * Core Layer 2 mechanism: `/booking/available-slots` sits behind
     * ['auth', ResolveTenant::class] only (routes/web.php) — no RequireTenant.
     * Before Layer 2, a request with no resolved tenant would silently see
     * Service::findOrFail() succeed against ANY organization's service (the
     * global scope no-op'd). After Layer 2, the same query returns zero rows
     * and findOrFail() 404s — even though no RequireTenant guards this route.
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
     * Side-benefit check (per task spec): BookingController's service-selection
     * step queries Service::active()->get() with no explicit org filter and no
     * RequireTenant guard. Prior to Layer 2, this leaked every tenant's active
     * services to anonymous/root-domain visitors. After Layer 2, the query
     * itself returns nothing for a root-domain request — the wizard step
     * renders (200, no RequireTenant to block it), but with zero services,
     * proving no cross-tenant data reaches the response.
     */
    public function test_booking_wizard_service_step_shows_no_cross_tenant_services_on_root_domain(): void
    {
        $orgA = Organization::factory()->autoDetailing()->create();
        $orgB = Organization::factory()->autoDetailing()->create();

        $serviceA = Service::factory()->create([
            'organization_id' => $orgA->id,
            'name' => 'Usluga Organizacji A',
            'is_active' => true,
        ]);
        $serviceB = Service::factory()->create([
            'organization_id' => $orgB->id,
            'name' => 'Usluga Organizacji B',
            'is_active' => true,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('http://registro.local/booking/step/1');

        $response->assertOk();
        $response->assertViewHas('services', function ($services) {
            return $services->isEmpty();
        });
        $response->assertDontSee($serviceA->name);
        $response->assertDontSee($serviceB->name);
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
