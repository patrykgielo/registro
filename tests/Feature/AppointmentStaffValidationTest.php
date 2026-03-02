<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppointmentStaffValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles are seeded by RolePermissionSeeder in TestCase
        // No need to create manually
    }

    /**
     * Helper method to find next valid working day (Mon-Fri)
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

    /** @test */
    public function it_allows_appointment_with_staff_role()
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $service = Service::factory()->create();

        $appointment = Appointment::create([
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'appointment_date' => $this->getNextWorkingDay(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'staff_id' => $staff->id,
        ]);
    }

    /** @test */
    public function it_rejects_appointment_with_admin_role()
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Tylko użytkownicy z rolą "staff"');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $service = Service::factory()->create();

        Appointment::create([
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'staff_id' => $admin->id,
            'appointment_date' => $this->getNextWorkingDay(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function it_rejects_appointment_with_super_admin_role()
    {
        $this->expectException(ValidationException::class);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $service = Service::factory()->create();

        Appointment::create([
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'staff_id' => $superAdmin->id,
            'appointment_date' => $this->getNextWorkingDay(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function it_rejects_updating_to_non_staff_user()
    {
        $this->expectException(ValidationException::class);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $service = Service::factory()->create();

        $appointment = Appointment::create([
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'appointment_date' => $this->getNextWorkingDay(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => 'pending',
        ]);

        // Try to update to admin - should fail
        $appointment->staff_id = $admin->id;
        $appointment->save();
    }
}
