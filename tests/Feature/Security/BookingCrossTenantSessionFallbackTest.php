<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\ResolveTenant;
use App\Models\Organization;
use App\Models\Service;
use App\Models\StaffSchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * VULN-003 "Layer 3": booking.* and appointments.* routes sat behind
 * ['auth', ResolveTenant::class] only (no RequireTenant). ResolveTenant writes
 * session()->put('tenant_id', ...) on EVERY successful subdomain visit — even
 * an anonymous, unauthenticated one — with no authorization check running
 * first. An authenticated customer of Org A could visit orgB.<domain>/ (any
 * public page) to poison their own session with tenant_id = orgB.id, then hit
 * the root-domain booking flow while authenticated. TenantFeature::currentTenant()
 * resolves Org B via its 3rd fallback branch (session), which is non-null — so
 * BelongsToOrganization's Layer 2 fail-closed check (gated on the `tenant`
 * REQUEST ATTRIBUTE, only set by ResolveTenant's subdomain-success branch) is
 * never reached. Net effect: cross-tenant READ + WRITE (a customer could plant
 * a bogus appointment into a completely different tenant's calendar).
 *
 * Fix: routes/web.php's outer ['auth', ResolveTenant::class] group (covering
 * all booking.*, appointments.* and profile.* routes) now also carries
 * RequireTenant::class, which gates on the `tenant` request attribute directly
 * — never on the session fallback — closing this regardless of stale session
 * content. See app/docs/security/vulnerabilities/VULN-003-root-domain-tenant-bypass.md.
 */
class BookingCrossTenantSessionFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    /**
     * Bind a test double for ResolveTenant — same pattern used throughout the
     * project (e.g. BookingConfirmationSecurityTest::actingAsTenant()).
     */
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

    public function test_booking_step_returns_404_on_root_domain_with_poisoned_session(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($orgA->id);

        // Simulate the session state left behind by ResolveTenant after the
        // customer merely visited orgB's subdomain (no auth on orgB required).
        $response = $this->actingAs($customer)
            ->withSession(['tenant_id' => $orgB->id])
            ->get('http://registro.local/booking/step/1');

        $response->assertNotFound();
    }

    /**
     * Get next working day (Monday-Friday), at least 2 days out — same helper
     * used throughout the project's booking tests (24h+ advance booking rule).
     */
    private function getNextWorkingDay(): Carbon
    {
        $date = Carbon::now()->addDays(2);

        while ($date->dayOfWeek === Carbon::SATURDAY || $date->dayOfWeek === Carbon::SUNDAY) {
            $date->addDay();
        }

        return $date;
    }

    public function test_booking_confirm_post_returns_404_on_root_domain_with_poisoned_session(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        // Build a REAL, fully bookable service under Org B — the tenant the
        // poisoned session resolves to via TenantFeature::currentTenant()'s
        // 3rd fallback branch. Without this, Service::findOrFail() in
        // BookingController::confirm() would 404 on its own (unknown ID),
        // proving nothing about RequireTenant. Setting the `tenant` request
        // attribute here (not via the actual HTTP call below) only affects
        // organization_id auto-assignment for these setup-time factory
        // creates — same pattern as BookingConfirmationSecurityTest.
        $this->app['request']->attributes->set('tenant', $orgB);

        $service = Service::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);

        $staff = User::factory()->create();
        $staff->assignRole(Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']));
        for ($day = Carbon::MONDAY; $day <= Carbon::FRIDAY; $day++) {
            StaffSchedule::create([
                'user_id' => $staff->id,
                'day_of_week' => $day,
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'is_active' => true,
            ]);
        }
        $staff->services()->attach($service->id);

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($orgA->id);

        Notification::fake();

        // Attack scenario from the report: a poisoned session must NOT let
        // Appointment::create() (BookingController::confirm()) execute at all
        // — RequireTenant must reject the request before the controller runs.
        // (Verified manually: with RequireTenant::class temporarily removed
        // from this route group, this exact request DOES reach and execute
        // Appointment::create(), scoped to Org B — this test then fails.)
        $response = $this->actingAs($customer)
            ->withSession([
                'tenant_id' => $orgB->id,
                'booking' => [
                    'service_id' => $service->id,
                    'date' => $this->getNextWorkingDay()->format('Y-m-d'),
                    'time_slot' => '10:00',
                    'first_name' => 'Jan',
                    'last_name' => 'Kowalski',
                    'email' => 'jan@example.com',
                    'phone' => '+48123456789',
                ],
            ])
            ->post('http://registro.local/booking/confirm');

        $response->assertNotFound();

        $this->assertDatabaseMissing('appointments', [
            'email' => 'jan@example.com',
        ]);
    }

    public function test_appointments_index_returns_404_on_root_domain_with_poisoned_session(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($orgA->id);

        $response = $this->actingAs($customer)
            ->withSession(['tenant_id' => $orgB->id])
            ->get('http://registro.local/my-appointments');

        $response->assertNotFound();
    }

    /**
     * Positive control: RequireTenant must NOT break the legitimate case — a
     * customer booking on their own tenant's real subdomain, with ResolveTenant
     * setting the `tenant` request attribute normally.
     */
    public function test_booking_step_works_normally_on_real_tenant_subdomain(): void
    {
        $org = Organization::factory()->autoDetailing()->create();
        $this->app['request']->attributes->set('tenant', $org);

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($org->id);

        $response = $this->actingAs($customer)
            ->actingAsTenant($org)
            ->get(route('booking.step', 1));

        $response->assertOk();
        $response->assertViewIs('booking-wizard.steps.service');
    }

    /**
     * Positive control: appointments.index must still work normally for a
     * customer on their own tenant's real subdomain.
     */
    public function test_appointments_index_works_normally_on_real_tenant_subdomain(): void
    {
        $org = Organization::factory()->autoDetailing()->create();
        $this->app['request']->attributes->set('tenant', $org);

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($org->id);

        $response = $this->actingAs($customer)
            ->actingAsTenant($org)
            ->get(route('appointments.index'));

        $response->assertOk();
        $response->assertViewIs('appointments.index');
    }
}
