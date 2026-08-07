<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Positive control for the task's explicit "don't break staff" requirement.
 * AppointmentResource is the one resource in this fix that needed create/
 * edit/delete/deleteAny/view overrides added deliberately WIDER than
 * BaseResource's admin/super-admin default, because staff run the calendar
 * day to day and previously had unrestricted access to every action on this
 * table (nothing enforced canDelete()/canEdit() before this fix, so staff's
 * real usage was, functionally, "everything"). Confirms staff kept exactly
 * that after authorization actually started being enforced, and that a role
 * with no legitimate business here (customer can't reach /admin at all, but
 * a bare authenticated user with no role is the same shape) is still denied.
 */
class AppointmentResourceStaffAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super-admin', 'admin', 'staff', 'customer'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    public function test_staff_retains_full_appointment_management(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->actingAs($staff);

        $appointment = new Appointment;

        $this->assertTrue(AppointmentResource::canViewAny());
        $this->assertTrue(AppointmentResource::canCreate());
        $this->assertTrue(AppointmentResource::canEdit($appointment));
        $this->assertTrue(AppointmentResource::canDelete($appointment));
        $this->assertTrue(AppointmentResource::canDeleteAny());
        $this->assertTrue(AppointmentResource::canView($appointment));

        // The methods Filament's actions actually call — not just the can*()
        // read-side — must agree, or the buttons would render while the
        // action itself still gets refused.
        $this->assertTrue(AppointmentResource::getCreateAuthorizationResponse()->allowed());
        $this->assertTrue(AppointmentResource::getEditAuthorizationResponse($appointment)->allowed());
        $this->assertTrue(AppointmentResource::getDeleteAuthorizationResponse($appointment)->allowed());
        $this->assertTrue(AppointmentResource::getDeleteAnyAuthorizationResponse()->allowed());
    }

    public function test_a_role_with_no_business_here_is_denied(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $this->actingAs($customer);

        $appointment = new Appointment;

        $this->assertFalse(AppointmentResource::canViewAny());
        $this->assertFalse(AppointmentResource::getDeleteAuthorizationResponse($appointment)->allowed());
    }
}
