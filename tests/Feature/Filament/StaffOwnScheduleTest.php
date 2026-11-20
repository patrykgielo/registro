<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\StaffDateExceptionResource;
use App\Filament\Resources\StaffDateExceptionResource\Pages\CreateStaffDateException;
use App\Filament\Resources\StaffDateExceptionResource\Pages\EditStaffDateException;
use App\Filament\Resources\StaffDateExceptionResource\Pages\ListStaffDateExceptions;
use App\Filament\Resources\StaffScheduleResource;
use App\Filament\Resources\StaffScheduleResource\Pages\CreateStaffSchedule;
use App\Filament\Resources\StaffScheduleResource\Pages\EditStaffSchedule;
use App\Filament\Resources\StaffScheduleResource\Pages\ListStaffSchedules;
use App\Models\StaffDateException;
use App\Models\StaffSchedule;
use App\Models\User;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Staff manage their OWN availability. The seeder grants `staff.manage_availability` and
 * `staff.view_availability`, but both resources hard-coded `admin|super-admin`, so the people
 * the schedule is about could not see it. Pattern: StaffVacationPeriodResource (staff see only
 * their own rows, admins see all).
 */
class StaffOwnScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $staffA;

    private User $staffB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staffA = $this->userWith('staff');
        $this->staffB = $this->userWith('staff');
        $this->admin = $this->userWith('admin');
    }

    private function userWith(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function schedule(User $owner, int $day = 1): StaffSchedule
    {
        return StaffSchedule::create([
            'user_id' => $owner->id,
            'day_of_week' => $day,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'is_active' => true,
        ]);
    }

    private function exception(User $owner, string $date = '2030-06-10'): StaffDateException
    {
        return StaffDateException::create([
            'user_id' => $owner->id,
            'exception_date' => $date,
            'exception_type' => StaffDateException::TYPE_UNAVAILABLE,
        ]);
    }

    // ---------------------------------------------------------------- schedules

    public function test_staff_can_open_the_schedule_list(): void
    {
        $this->actingAs($this->staffA);

        $this->assertTrue(StaffScheduleResource::canViewAny());
        Livewire::test(ListStaffSchedules::class)->assertSuccessful();
    }

    public function test_staff_see_their_own_schedule_and_not_a_colleagues(): void
    {
        $mine = $this->schedule($this->staffA, 1);
        $theirs = $this->schedule($this->staffB, 2);

        $this->actingAs($this->staffA);

        Livewire::test(ListStaffSchedules::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_staff_cannot_edit_or_delete_a_colleagues_schedule(): void
    {
        $theirs = $this->schedule($this->staffB);

        $this->actingAs($this->staffA);

        $this->assertFalse(StaffScheduleResource::canEdit($theirs));
        $this->assertFalse(StaffScheduleResource::canDelete($theirs));
        $this->assertFalse(StaffScheduleResource::getEditAuthorizationResponse($theirs)->allowed());

        // ...and the edit page cannot even resolve it: it is not in the scoped query (404)
        $this->assertThrows(
            fn () => Livewire::test(EditStaffSchedule::class, ['record' => $theirs->getKey()]),
            ModelNotFoundException::class
        );
        $this->assertSame('09:00', substr((string) $theirs->fresh()->start_time, 0, 5));
    }

    public function test_staff_cannot_delete_a_colleagues_schedule_through_the_table(): void
    {
        $theirs = $this->schedule($this->staffB);

        $this->actingAs($this->staffA);

        // the row is not in the scoped table query, so the action cannot be resolved for it
        $this->assertThrows(
            fn () => Livewire::test(ListStaffSchedules::class)->callTableAction('delete', $theirs),
            ActionNotResolvableException::class
        );

        $this->assertDatabaseHas('staff_schedules', ['id' => $theirs->id]);
    }

    public function test_staff_can_edit_and_delete_their_own_schedule(): void
    {
        $mine = $this->schedule($this->staffA);

        $this->actingAs($this->staffA);

        $this->assertTrue(StaffScheduleResource::canEdit($mine));

        Livewire::test(EditStaffSchedule::class, ['record' => $mine->getKey()])
            ->fillForm(['start_time' => '10:00', 'end_time' => '18:00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('10:00', substr((string) $mine->fresh()->start_time, 0, 5));

        Livewire::test(ListStaffSchedules::class)->callTableAction('delete', $mine);
        $this->assertDatabaseMissing('staff_schedules', ['id' => $mine->id]);
    }

    public function test_staff_create_a_schedule_for_themselves_with_the_user_prefilled(): void
    {
        $this->actingAs($this->staffA);

        Livewire::test(CreateStaffSchedule::class)
            ->assertFormSet(['user_id' => $this->staffA->id])
            ->fillForm(['day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '16:00'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('staff_schedules', ['user_id' => $this->staffA->id, 'day_of_week' => 3]);
    }

    public function test_staff_cannot_create_a_schedule_for_a_colleague_by_tampering_with_user_id(): void
    {
        $this->actingAs($this->staffA);

        Livewire::test(CreateStaffSchedule::class)
            ->fillForm([
                'user_id' => $this->staffB->id, // tampered
                'day_of_week' => 3,
                'start_time' => '08:00',
                'end_time' => '16:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['user_id']) // first line of defence: B is not an allowed option
            ->fillForm(['user_id' => $this->staffA->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseMissing('staff_schedules', ['user_id' => $this->staffB->id]);
        $this->assertDatabaseHas('staff_schedules', ['user_id' => $this->staffA->id, 'day_of_week' => 3]);
        $this->assertSame(1, StaffSchedule::count());
    }

    /**
     * Second line of defence: even if a forged user_id got past form validation, the page hook
     * pins it before it reaches the database.
     */
    public function test_the_create_and_edit_page_hooks_pin_user_id_for_staff(): void
    {
        $mine = $this->schedule($this->staffA);
        $this->actingAs($this->staffA);

        $create = Livewire::test(CreateStaffSchedule::class)->instance();
        $edit = Livewire::test(EditStaffSchedule::class, ['record' => $mine->getKey()])->instance();

        $this->assertSame($this->staffA->id, $this->callProtected($create, 'mutateFormDataBeforeCreate', ['user_id' => $this->staffB->id])['user_id']);
        $this->assertSame($this->staffA->id, $this->callProtected($edit, 'mutateFormDataBeforeSave', ['user_id' => $this->staffB->id])['user_id']);

        $this->actingAs($this->admin);
        $this->assertSame($this->staffB->id, $this->callProtected($create, 'mutateFormDataBeforeCreate', ['user_id' => $this->staffB->id])['user_id']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function callProtected(object $page, string $method, array $data): array
    {
        return (new \ReflectionMethod($page, $method))->invoke($page, $data);
    }

    // ------------------------------------------------------------ bulk actions

    public function test_bulk_delete_with_a_forged_colleague_id_only_deletes_the_staff_members_own_rows(): void
    {
        $mine = $this->schedule($this->staffA, 1);
        $theirs = $this->schedule($this->staffB, 2);

        $this->actingAs($this->staffA);

        Livewire::test(ListStaffSchedules::class)->callTableBulkAction('delete', [$mine->id, $theirs->id]);

        $this->assertDatabaseMissing('staff_schedules', ['id' => $mine->id]);
        $this->assertDatabaseHas('staff_schedules', ['id' => $theirs->id]);
    }

    public function test_bulk_activate_and_deactivate_with_a_forged_colleague_id_leave_the_colleague_untouched(): void
    {
        $mine = $this->schedule($this->staffA, 1);
        $theirs = $this->schedule($this->staffB, 2);

        $this->actingAs($this->staffA);

        Livewire::test(ListStaffSchedules::class)->callTableBulkAction('deactivate', [$mine->id, $theirs->id]);

        $this->assertFalse((bool) $mine->fresh()->is_active);
        $this->assertTrue((bool) $theirs->fresh()->is_active, 'a colleague schedule was deactivated through a forged id');

        $theirs->update(['is_active' => false]);

        Livewire::test(ListStaffSchedules::class)->callTableBulkAction('activate', [$mine->id, $theirs->id]);

        $this->assertTrue((bool) $mine->fresh()->is_active);
        $this->assertFalse((bool) $theirs->fresh()->is_active, 'a colleague schedule was activated through a forged id');
    }

    public function test_bulk_delete_of_exceptions_with_a_forged_colleague_id_spares_the_colleague(): void
    {
        $mine = $this->exception($this->staffA, '2030-06-10');
        $theirs = $this->exception($this->staffB, '2030-06-11');

        $this->actingAs($this->staffA);

        Livewire::test(ListStaffDateExceptions::class)->callTableBulkAction('delete', [$mine->id, $theirs->id]);

        $this->assertDatabaseMissing('staff_date_exceptions', ['id' => $mine->id]);
        $this->assertDatabaseHas('staff_date_exceptions', ['id' => $theirs->id]);
    }

    public function test_admin_bulk_delete_still_reaches_every_row(): void
    {
        $a = $this->schedule($this->staffA, 1);
        $b = $this->schedule($this->staffB, 2);

        $this->actingAs($this->admin);

        Livewire::test(ListStaffSchedules::class)->callTableBulkAction('delete', [$a->id, $b->id]);

        $this->assertSame(0, StaffSchedule::count());
    }

    public function test_the_write_path_pins_user_id_for_staff_and_leaves_it_for_admins(): void
    {
        $this->actingAs($this->staffA);
        $this->assertSame($this->staffA->id, StaffScheduleResource::forceOwnUserId(['user_id' => $this->staffB->id])['user_id']);
        $this->assertSame($this->staffA->id, StaffDateExceptionResource::forceOwnUserId([])['user_id']);

        $this->actingAs($this->admin);
        $this->assertSame($this->staffB->id, StaffScheduleResource::forceOwnUserId(['user_id' => $this->staffB->id])['user_id']);
    }

    public function test_staff_cannot_reassign_their_schedule_to_a_colleague_by_editing(): void
    {
        $mine = $this->schedule($this->staffA);

        $this->actingAs($this->staffA);

        Livewire::test(EditStaffSchedule::class, ['record' => $mine->getKey()])
            ->fillForm(['user_id' => $this->staffB->id])
            ->call('save');

        $this->assertSame($this->staffA->id, $mine->fresh()->user_id);
    }

    public function test_admin_sees_every_schedule_and_may_manage_all(): void
    {
        $a = $this->schedule($this->staffA, 1);
        $b = $this->schedule($this->staffB, 2);

        $this->actingAs($this->admin);

        Livewire::test(ListStaffSchedules::class)->assertCanSeeTableRecords([$a, $b]);
        $this->assertTrue(StaffScheduleResource::canEdit($b));
        $this->assertTrue(StaffScheduleResource::canDelete($b));
        $this->assertTrue(StaffScheduleResource::canCreate());
    }

    public function test_admin_can_create_a_schedule_for_any_staff_member(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateStaffSchedule::class)
            ->fillForm([
                'user_id' => $this->staffB->id,
                'day_of_week' => 4,
                'start_time' => '08:00',
                'end_time' => '16:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('staff_schedules', ['user_id' => $this->staffB->id, 'day_of_week' => 4]);
    }

    public function test_customers_get_no_access(): void
    {
        $this->actingAs($this->userWith('customer'));

        $this->assertFalse(StaffScheduleResource::canViewAny());
        $this->assertFalse(StaffDateExceptionResource::canViewAny());
    }

    public function test_the_list_header_counts_do_not_leak_a_colleagues_exceptions(): void
    {
        $this->exception($this->staffB, '2030-06-10');
        $this->exception($this->staffB, '2030-06-11');

        $this->actingAs($this->staffA);

        $component = Livewire::test(ListStaffSchedules::class);
        $action = $component->instance()->getAction('manage_exceptions');

        $this->assertSame(0, (int) $action->getBadge());
    }

    // --------------------------------------------------------------- exceptions

    public function test_staff_see_their_own_exceptions_and_not_a_colleagues(): void
    {
        $mine = $this->exception($this->staffA, '2030-06-10');
        $theirs = $this->exception($this->staffB, '2030-06-11');

        $this->actingAs($this->staffA);

        $this->assertTrue(StaffDateExceptionResource::canViewAny());
        Livewire::test(ListStaffDateExceptions::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_staff_cannot_edit_or_delete_a_colleagues_exception(): void
    {
        $theirs = $this->exception($this->staffB);

        $this->actingAs($this->staffA);

        $this->assertFalse(StaffDateExceptionResource::canEdit($theirs));
        $this->assertFalse(StaffDateExceptionResource::canDelete($theirs));

        $this->assertThrows(
            fn () => Livewire::test(EditStaffDateException::class, ['record' => $theirs->getKey()]),
            ModelNotFoundException::class
        );
        $this->assertThrows(
            fn () => Livewire::test(ListStaffDateExceptions::class)->callTableAction('delete', $theirs),
            ActionNotResolvableException::class
        );

        $this->assertDatabaseHas('staff_date_exceptions', ['id' => $theirs->id, 'reason' => null]);
    }

    public function test_staff_can_manage_their_own_exceptions(): void
    {
        $this->actingAs($this->staffA);

        Livewire::test(CreateStaffDateException::class)
            ->fillForm([
                'exception_date' => '2030-07-01',
                'exception_type' => StaffDateException::TYPE_UNAVAILABLE,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $mine = StaffDateException::where('user_id', $this->staffA->id)->firstOrFail();
        $this->assertSame($this->staffA->id, $mine->user_id);

        Livewire::test(EditStaffDateException::class, ['record' => $mine->getKey()])
            ->fillForm(['reason' => 'Wizyta u lekarza'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Wizyta u lekarza', $mine->fresh()->reason);

        Livewire::test(ListStaffDateExceptions::class)->callTableAction('delete', $mine);
        $this->assertDatabaseMissing('staff_date_exceptions', ['id' => $mine->id]);
    }

    public function test_admin_exceptions_behaviour_is_unchanged(): void
    {
        $a = $this->exception($this->staffA, '2030-06-10');
        $b = $this->exception($this->staffB, '2030-06-11');

        $this->actingAs($this->admin);

        Livewire::test(ListStaffDateExceptions::class)->assertCanSeeTableRecords([$a, $b]);
        $this->assertTrue(StaffDateExceptionResource::canEdit($b));
        $this->assertTrue(StaffDateExceptionResource::canDelete($b));
        $this->assertTrue(StaffDateExceptionResource::canCreate());
    }

    public function test_an_admin_who_also_holds_the_staff_role_is_not_limited(): void
    {
        $both = $this->userWith('admin');
        $both->assignRole('staff');
        $b = $this->schedule($this->staffB);

        $this->actingAs($both);

        Livewire::test(ListStaffSchedules::class)->assertCanSeeTableRecords([$b]);
    }
}
