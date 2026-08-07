<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\StaffVacationPeriodResource;
use App\Filament\Resources\StaffVacationPeriodResource\Pages\ListStaffVacationPeriods;
use App\Models\StaffVacationPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * StaffVacationPeriodResource already had correct per-record canDelete() logic
 * (staff may only delete their own PENDING vacation, never an approved one)
 * before this fix — but, same as UserResource, it was never actually
 * enforced: DeleteAction calls getDeleteAuthorizationResponse(), not
 * canDelete(), and with no policy that resolved to allow() for everyone.
 *
 * The row-level DeleteAction on ListStaffVacationPeriods (not
 * EditStaffVacationPeriod's header action) is the exploitable path here:
 * EditRecord::authorizeAccess() has always called canEdit() directly and
 * StaffVacationPeriodResource::getEloquentQuery() already scopes staff to
 * their own records — both correctly deny/hide a colleague's vacation
 * regardless of this fix, so testing through them would prove nothing new.
 * What getEloquentQuery() does NOT protect against is a staff member's OWN
 * *approved* vacation, which is still in their scoped query and reachable —
 * only canDelete()'s `! $record->is_approved` check stands between that row
 * and the DeleteAction button, which is exactly what was broken.
 */
class StaffVacationPeriodResourceDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super-admin', 'admin', 'staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function vacation(User $owner, bool $approved): StaffVacationPeriod
    {
        return StaffVacationPeriod::create([
            'user_id' => $owner->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeeks(2)->toDateString(),
            'is_approved' => $approved,
        ]);
    }

    public function test_staff_cannot_delete_a_colleagues_pending_vacation(): void
    {
        $staffA = User::factory()->create();
        $staffA->assignRole('staff');

        $staffB = User::factory()->create();
        $staffB->assignRole('staff');

        $vacation = $this->vacation($staffB, approved: false);

        $this->actingAs($staffA);

        $this->assertFalse(StaffVacationPeriodResource::canDelete($vacation));
        $this->assertFalse(
            StaffVacationPeriodResource::getDeleteAuthorizationResponse($vacation)->allowed()
        );
    }

    public function test_staff_cannot_actually_delete_their_own_approved_vacation(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $vacation = $this->vacation($staff, approved: true);

        $this->actingAs($staff);

        try {
            Livewire::test(ListStaffVacationPeriods::class)
                ->callTableAction('delete', $vacation);
        } catch (\Throwable) {
            // Refusal is the expected outcome; the assertion below is the contract.
        }

        $this->assertDatabaseHas('staff_vacation_periods', ['id' => $vacation->id]);
    }

    /**
     * The row action was guarded; this path was not, and review proved it by
     * deleting the record. Filament only re-checks each selected row when the
     * action carries ->authorizeIndividualRecords(), which is opt-in — without
     * it a single canDeleteAny() pass deletes the whole selection, and
     * canDeleteAny() allows staff.
     */
    public function test_staff_cannot_bulk_delete_their_own_approved_vacation(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $approved = $this->vacation($staff, approved: true);
        $pending = $this->vacation($staff, approved: false);

        $this->actingAs($staff);

        try {
            Livewire::test(ListStaffVacationPeriods::class)
                ->callTableBulkAction('delete', [$approved, $pending]);
        } catch (\Throwable) {
            // Partial refusal is fine; the assertions below are the contract.
        }

        $this->assertDatabaseHas('staff_vacation_periods', ['id' => $approved->id]);
        $this->assertDatabaseMissing('staff_vacation_periods', ['id' => $pending->id]);
    }

    public function test_staff_can_still_delete_their_own_pending_vacation(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $vacation = $this->vacation($staff, approved: false);

        $this->actingAs($staff);

        $this->assertTrue(StaffVacationPeriodResource::canDelete($vacation));

        Livewire::test(ListStaffVacationPeriods::class)
            ->callTableAction('delete', $vacation);

        $this->assertDatabaseMissing('staff_vacation_periods', ['id' => $vacation->id]);
    }

    public function test_admin_can_delete_any_staff_vacation_including_approved(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $vacation = $this->vacation($staff, approved: true);

        $this->actingAs($admin);

        Livewire::test(ListStaffVacationPeriods::class)
            ->callTableAction('delete', $vacation);

        $this->assertDatabaseMissing('staff_vacation_periods', ['id' => $vacation->id]);
    }
}
