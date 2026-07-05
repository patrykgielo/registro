<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Http\Middleware\ResolveTenant;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\Service;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Models\VehicleType;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Regression tests for the double-booking race condition fix (2026-07 booking
 * integrity review). `appointments` now has a unique index
 * (staff_id, appointment_date, start_time, active_slot) — see migration
 * 2026_07_05_000001_add_double_booking_guard_to_appointments_table — as the
 * authoritative backstop behind the app-level lockForUpdate() serialization in
 * AppointmentController::store() / BookingController::confirm().
 *
 * Laravel test transactions run on a single DB connection, so a true
 * concurrent-request race can't be reproduced here. Instead:
 *  - test_unique_constraint_rejects_direct_duplicate_insert proves the DB
 *    constraint itself works.
 *  - test_cancelled_appointment_does_not_block_the_slot proves the
 *    active_slot design choice (cancelled appointments free the slot).
 *  - the two controller tests reproduce a REAL single-threaded gap: a
 *    'completed' appointment is invisible to validateAppointment()'s
 *    conflict SELECT (which only checks pending/confirmed), so the
 *    SELECT-based check reports "valid" while the DB constraint still
 *    correctly rejects the resulting duplicate slot — exactly the scenario
 *    the unique index exists to catch, and deterministically reproducible
 *    without real concurrency.
 */
class AppointmentDoubleBookingTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected User $staff;

    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        // generalServices: no vehicles/mobile_service/service_area features —
        // keeps the booking wizard step numbering simple (service=1, datetime=2,
        // contact=3, review=4) for the confirm() test below. The legacy
        // AppointmentController::store() endpoint requires vehicle/location
        // fields unconditionally regardless of tenant features, so it's
        // unaffected and still supplies them explicitly.
        $this->org = Organization::factory()->generalServices()->create();
        $this->app['request']->attributes->set('tenant', $this->org);
        $this->actingAsTenant($this->org);

        Notification::fake();

        $this->service = Service::factory()->create([
            'organization_id' => $this->org->id,
            'duration_minutes' => 60,
        ]);

        $this->staff = User::factory()->create();
        $this->staff->assignRole('staff');
        $this->staff->organizations()->attach($this->org->id);
        for ($day = Carbon::MONDAY; $day <= Carbon::FRIDAY; $day++) {
            StaffSchedule::create([
                'user_id' => $this->staff->id,
                'day_of_week' => $day,
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'is_active' => true,
            ]);
        }
        $this->staff->services()->attach($this->service->id);
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

    protected function getNextWorkingDay(): Carbon
    {
        $date = Carbon::now()->addDays(2);

        while ($date->dayOfWeek === Carbon::SATURDAY || $date->dayOfWeek === Carbon::SUNDAY) {
            $date->addDay();
        }

        return $date;
    }

    public function test_unique_constraint_rejects_direct_duplicate_insert(): void
    {
        $date = $this->getNextWorkingDay()->format('Y-m-d');

        Appointment::create([
            'organization_id' => $this->org->id,
            'service_id' => $this->service->id,
            'customer_id' => User::factory()->create()->id,
            'staff_id' => $this->staff->id,
            'appointment_date' => $date,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => AppointmentStatus::Pending,
        ]);

        $this->expectException(QueryException::class);

        Appointment::create([
            'organization_id' => $this->org->id,
            'service_id' => $this->service->id,
            'customer_id' => User::factory()->create()->id,
            'staff_id' => $this->staff->id,
            'appointment_date' => $date,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);
    }

    public function test_cancelled_appointment_does_not_block_the_slot(): void
    {
        $date = $this->getNextWorkingDay()->format('Y-m-d');

        $cancelled = Appointment::create([
            'organization_id' => $this->org->id,
            'service_id' => $this->service->id,
            'customer_id' => User::factory()->create()->id,
            'staff_id' => $this->staff->id,
            'appointment_date' => $date,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => AppointmentStatus::Cancelled,
        ]);

        $rebooked = Appointment::create([
            'organization_id' => $this->org->id,
            'service_id' => $this->service->id,
            'customer_id' => User::factory()->create()->id,
            'staff_id' => $this->staff->id,
            'appointment_date' => $date,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => AppointmentStatus::Pending,
        ]);

        $this->assertNull($cancelled->fresh()->active_slot);
        $this->assertTrue($rebooked->fresh()->active_slot);
        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_appointment_store_returns_friendly_error_when_db_constraint_catches_a_conflict_missed_by_the_select_check(): void
    {
        $date = $this->getNextWorkingDay();
        $vehicleTypeId = VehicleType::factory()->create()->id;

        // A 'completed' appointment for this exact staff/date/start_time is
        // invisible to validateAppointment()'s conflict check (only checks
        // pending/confirmed) — so the controller proceeds to Appointment::create(),
        // which must now fail on the unique index instead of throwing a raw 500.
        Appointment::create([
            'organization_id' => $this->org->id,
            'service_id' => $this->service->id,
            'customer_id' => User::factory()->create()->id,
            'staff_id' => $this->staff->id,
            'appointment_date' => $date->format('Y-m-d'),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => AppointmentStatus::Completed,
        ]);

        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $response = $this->actingAs($customer)->post(route('appointments.store'), [
            'service_id' => $this->service->id,
            'staff_id' => $this->staff->id,
            'appointment_date' => $date->format('Y-m-d'),
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

        $response->assertSessionHasErrors('appointment');
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_booking_confirm_returns_friendly_error_when_db_constraint_catches_a_conflict_missed_by_the_select_check(): void
    {
        $date = $this->getNextWorkingDay();

        Appointment::create([
            'organization_id' => $this->org->id,
            'service_id' => $this->service->id,
            'customer_id' => User::factory()->create()->id,
            'staff_id' => $this->staff->id,
            'appointment_date' => $date->format('Y-m-d'),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => AppointmentStatus::Completed,
        ]);

        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $response = $this->actingAs($customer)
            ->withSession([
                'booking' => [
                    'service_id' => $this->service->id,
                    'date' => $date->format('Y-m-d'),
                    'time_slot' => '10:00',
                    'first_name' => 'Jan',
                    'last_name' => 'Kowalski',
                    'email' => 'jan@example.com',
                    'phone' => '+48123456789',
                ],
            ])
            ->post(route('booking.confirm'));

        $response->assertRedirect(route('booking.step', 2));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('appointments', 1);
    }
}
