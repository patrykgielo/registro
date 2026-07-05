<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Models\VehicleType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for HIGH finding #2 (2026-07 booking integrity review):
 * AppointmentController::store()'s service_id validation used to be a bare
 * `exists:services,id`, which confirms the row exists ANYWHERE in the DB —
 * bypassing Service's BelongsToOrganization scope entirely, since Eloquent's
 * `exists:` rule queries the raw table. Fixed by scoping the exists check to
 * the current tenant (request attribute), never TenantFeature::currentTenant()
 * (session-fallback risk — VULN-003). staff_id got the same explicit
 * tenant-scoped check (via the organization_user pivot table) for
 * defense-in-depth consistency, even though canPerformService()'s
 * tenant-scoped Service relation already made a cross-tenant staff_id
 * practically unreachable — see App\Rules\StaffRoleRule's docblock.
 */
class AppointmentServiceTenantScopingTest extends TestCase
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

    protected function getNextWorkingDay(): Carbon
    {
        $date = Carbon::now()->addDays(2);

        while ($date->dayOfWeek === Carbon::SATURDAY || $date->dayOfWeek === Carbon::SUNDAY) {
            $date->addDay();
        }

        return $date;
    }

    public function test_cross_tenant_service_id_is_rejected_by_validation(): void
    {
        $orgA = Organization::factory()->autoDetailing()->create();
        $orgB = Organization::factory()->autoDetailing()->create();

        // A service that belongs to Org B — the attacker's own tenant is Org A.
        $this->app['request']->attributes->set('tenant', $orgB);
        $foreignService = Service::factory()->create([
            'organization_id' => $orgB->id,
        ]);

        $vehicleTypeId = VehicleType::factory()->create()->id;

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($orgA->id);

        $this->actingAsTenant($orgA);

        $response = $this->actingAs($customer)->post(route('appointments.store'), [
            'service_id' => $foreignService->id,
            'appointment_date' => $this->getNextWorkingDay()->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'phone_e164' => '+48501234567',
            'location_address' => 'Testowa 1, Warszawa',
            'location_latitude' => 52.2297,
            'location_longitude' => 21.0122,
            'location_place_id' => 'test-place-id',
            'vehicle_type_id' => $vehicleTypeId,
            'vehicle_year' => 2020,
        ]);

        $response->assertSessionHasErrors('service_id');
        $this->assertDatabaseMissing('appointments', [
            'service_id' => $foreignService->id,
        ]);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_cross_tenant_staff_id_is_rejected_by_validation(): void
    {
        $orgA = Organization::factory()->autoDetailing()->create();
        $orgB = Organization::factory()->autoDetailing()->create();

        $this->app['request']->attributes->set('tenant', $orgA);
        $service = Service::factory()->create([
            'organization_id' => $orgA->id,
        ]);

        // Staff member belongs to Org B, not Org A (the current tenant).
        $this->app['request']->attributes->set('tenant', $orgB);
        $foreignStaff = User::factory()->create();
        $foreignStaff->assignRole('staff');
        $foreignStaff->organizations()->attach($orgB->id);

        $vehicleTypeId = VehicleType::factory()->create()->id;

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($orgA->id);

        $this->actingAsTenant($orgA);

        $response = $this->actingAs($customer)->post(route('appointments.store'), [
            'service_id' => $service->id,
            'staff_id' => $foreignStaff->id,
            'appointment_date' => $this->getNextWorkingDay()->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'phone_e164' => '+48501234567',
            'location_address' => 'Testowa 1, Warszawa',
            'location_latitude' => 52.2297,
            'location_longitude' => 21.0122,
            'location_place_id' => 'test-place-id',
            'vehicle_type_id' => $vehicleTypeId,
            'vehicle_year' => 2020,
        ]);

        $response->assertSessionHasErrors('staff_id');
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_same_tenant_service_id_is_accepted_by_validation(): void
    {
        $org = Organization::factory()->autoDetailing()->create();
        $this->app['request']->attributes->set('tenant', $org);
        $this->actingAsTenant($org);

        $service = Service::factory()->create([
            'organization_id' => $org->id,
            'duration_minutes' => 60,
        ]);

        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $staff->organizations()->attach($org->id);
        for ($day = Carbon::MONDAY; $day <= Carbon::FRIDAY; $day++) {
            \App\Models\StaffSchedule::create([
                'user_id' => $staff->id,
                'day_of_week' => $day,
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'is_active' => true,
            ]);
        }
        $staff->services()->attach($service->id);

        $vehicleTypeId = VehicleType::factory()->create()->id;

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($org->id);

        \Illuminate\Support\Facades\Notification::fake();

        $response = $this->actingAs($customer)->post(route('appointments.store'), [
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'appointment_date' => $this->getNextWorkingDay()->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'phone_e164' => '+48501234567',
            'location_address' => 'Testowa 1, Warszawa',
            'location_latitude' => 52.2297,
            'location_longitude' => 21.0122,
            'location_place_id' => 'test-place-id',
            'vehicle_type_id' => $vehicleTypeId,
            'vehicle_year' => 2020,
        ]);

        $response->assertSessionDoesntHaveErrors('service_id');
        $this->assertDatabaseHas('appointments', [
            'service_id' => $service->id,
            'organization_id' => $org->id,
        ]);
    }
}
