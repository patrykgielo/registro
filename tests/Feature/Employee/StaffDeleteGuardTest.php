<?php

namespace Tests\Feature\Employee;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\EmployeeResource;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private VehicleType $vehicleType;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $this->vehicleType = VehicleType::factory()->create();
    }

    private function createStaff(): User
    {
        $user = User::factory()->create();
        $user->assignRole('staff');

        return $user;
    }

    private function makeAppointment(array $attributes): Appointment
    {
        return Appointment::factory()->create(
            array_merge(['vehicle_type_id' => $this->vehicleType->id], $attributes)
        );
    }

    /**
     * Tests the hasFutureActiveAppointments helper directly through reflection,
     * since it is a private static method on EmployeeResource.
     */
    private function hasFuture(User $staff): bool
    {
        $method = new \ReflectionMethod(EmployeeResource::class, 'hasFutureActiveAppointments');

        return $method->invoke(null, $staff);
    }

    public function test_returns_true_when_staff_has_future_pending_appointment(): void
    {
        $org = Organization::factory()->create();
        $staff = $this->createStaff();

        $this->makeAppointment([
            'organization_id' => $org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Pending,
            'appointment_date' => now()->addDay()->toDateString(),
        ]);

        $this->assertTrue($this->hasFuture($staff));
    }

    public function test_returns_true_when_staff_has_future_confirmed_appointment(): void
    {
        $org = Organization::factory()->create();
        $staff = $this->createStaff();

        $this->makeAppointment([
            'organization_id' => $org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Confirmed,
            'appointment_date' => now()->addWeek()->toDateString(),
        ]);

        $this->assertTrue($this->hasFuture($staff));
    }

    public function test_returns_false_when_all_appointments_are_in_the_past(): void
    {
        $org = Organization::factory()->create();
        $staff = $this->createStaff();

        $this->makeAppointment([
            'organization_id' => $org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Pending,
            'appointment_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertFalse($this->hasFuture($staff));
    }

    public function test_returns_false_when_future_appointment_is_cancelled(): void
    {
        $org = Organization::factory()->create();
        $staff = $this->createStaff();

        $this->makeAppointment([
            'organization_id' => $org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Cancelled,
            'appointment_date' => now()->addDay()->toDateString(),
        ]);

        $this->assertFalse($this->hasFuture($staff));
    }

    public function test_returns_false_when_future_appointment_is_completed(): void
    {
        $org = Organization::factory()->create();
        $staff = $this->createStaff();

        $this->makeAppointment([
            'organization_id' => $org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Completed,
            'appointment_date' => now()->addDay()->toDateString(),
        ]);

        $this->assertFalse($this->hasFuture($staff));
    }

    public function test_returns_false_when_staff_has_no_appointments(): void
    {
        $staff = $this->createStaff();

        $this->assertFalse($this->hasFuture($staff));
    }

    public function test_does_not_count_appointments_of_other_staff(): void
    {
        $org = Organization::factory()->create();
        $staff = $this->createStaff();
        $otherStaff = $this->createStaff();

        $this->makeAppointment([
            'organization_id' => $org->id,
            'staff_id' => $otherStaff->id,
            'status' => AppointmentStatus::Pending,
            'appointment_date' => now()->addDay()->toDateString(),
        ]);

        $this->assertFalse($this->hasFuture($staff));
    }
}
