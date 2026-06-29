<?php

namespace Tests\Feature\Organizations;

use App\Enums\AppointmentStatus;
use App\Enums\OrganizationLifecycleState;
use App\Exceptions\InvalidLifecycleTransitionException;
use App\Exceptions\OrganizationHasActiveObligationsException;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrganizationObserverTest extends TestCase
{
    use RefreshDatabase;

    private VehicleType $vehicleType;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure the "staff" role exists so AppointmentObserver passes validation
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

    private function setLifecycleState(Organization $org, OrganizationLifecycleState $state): void
    {
        // lifecycle_state is not mass-assignable — must set directly to trigger observer
        $org->lifecycle_state = $state;
        $org->save();
    }

    // ─── Lifecycle state transition guards ────────────────────────────────

    // ─── Creating hook (F003 on INSERT) ──────────────────────────────────────

    public function test_creating_suspended_org_derives_is_active_false(): void
    {
        $org = Organization::factory()->create([
            'lifecycle_state' => OrganizationLifecycleState::Suspended,
        ]);

        $this->assertFalse($org->is_active, 'in-memory is_active after create');
        $this->assertFalse($org->fresh()->is_active, 'DB is_active after create');
        $this->assertSame(OrganizationLifecycleState::Suspended, $org->fresh()->lifecycle_state);
    }

    public function test_creating_active_org_derives_is_active_true(): void
    {
        $org = Organization::factory()->create();  // default lifecycle_state = null → Active

        $this->assertTrue($org->is_active, 'in-memory is_active after create');
        $this->assertTrue($org->fresh()->is_active, 'DB is_active after create');
    }

    // ─── Updating hook (transitions) ─────────────────────────────────────────

    public function test_illegal_transition_throws_exception(): void
    {
        $org = Organization::factory()->create(['lifecycle_state' => 'active']);

        $this->expectException(InvalidLifecycleTransitionException::class);

        // Active → Closed is not a valid transition (must go through Closing first)
        $this->setLifecycleState($org, OrganizationLifecycleState::Closed);
    }

    public function test_legal_transition_without_obligations_succeeds(): void
    {
        $org = Organization::factory()->create(['lifecycle_state' => 'active']);

        $this->setLifecycleState($org, OrganizationLifecycleState::Closing);

        $fresh = $org->fresh();
        $this->assertSame(OrganizationLifecycleState::Closing, $fresh->lifecycle_state);
        // F003: is_active derived from lifecycle_state
        $this->assertFalse($fresh->is_active);
        // W8: closing_initiated_at set on transition to Closing
        $this->assertNotNull($fresh->closing_initiated_at);
    }

    public function test_transition_to_active_sets_is_active_true_and_clears_closing_timestamps(): void
    {
        $org = Organization::factory()->create([
            'lifecycle_state' => 'closing',
            'closing_initiated_at' => now()->subDay(),
        ]);

        $this->setLifecycleState($org, OrganizationLifecycleState::Active);

        $fresh = $org->fresh();
        $this->assertSame(OrganizationLifecycleState::Active, $fresh->lifecycle_state);
        // F003: is_active = true for Active state
        $this->assertTrue($fresh->is_active);
        // W8: closing timestamps cleared when restoring to Active from Closing
        $this->assertNull($fresh->closing_initiated_at);
        $this->assertNull($fresh->purge_after);
    }

    public function test_transition_to_suspended_sets_is_active_false(): void
    {
        $org = Organization::factory()->create(['lifecycle_state' => 'active']);

        $this->setLifecycleState($org, OrganizationLifecycleState::Suspended);

        $fresh = $org->fresh();
        $this->assertSame(OrganizationLifecycleState::Suspended, $fresh->lifecycle_state);
        // F003: is_active = false for Suspended state
        $this->assertFalse($fresh->is_active);
    }

    public function test_transition_to_closing_with_active_obligations_throws(): void
    {
        $org = Organization::factory()->create(['lifecycle_state' => 'active']);
        $staff = $this->createStaff();

        $this->makeAppointment([
            'organization_id' => $org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Pending,
        ]);

        $this->expectException(OrganizationHasActiveObligationsException::class);

        $this->setLifecycleState($org, OrganizationLifecycleState::Closing);
    }

    public function test_transition_to_closing_with_force_flag_bypasses_obligation_check(): void
    {
        $org = Organization::factory()->create(['lifecycle_state' => 'active']);
        $staff = $this->createStaff();

        $this->makeAppointment([
            'organization_id' => $org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Pending,
        ]);

        $org->forceLifecycleTransition = true;
        $this->setLifecycleState($org, OrganizationLifecycleState::Closing);

        $this->assertSame(OrganizationLifecycleState::Closing, $org->fresh()->lifecycle_state);
    }

    public function test_transition_to_closed_with_active_obligations_throws(): void
    {
        $org = Organization::factory()->create(['lifecycle_state' => 'closing']);
        $staff = $this->createStaff();

        $this->makeAppointment([
            'organization_id' => $org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->expectException(OrganizationHasActiveObligationsException::class);

        $this->setLifecycleState($org, OrganizationLifecycleState::Closed);
    }

    public function test_updating_other_fields_does_not_trigger_lifecycle_validation(): void
    {
        $org = Organization::factory()->create(['lifecycle_state' => 'active']);

        // Should not throw even though lifecycle_state remains Active
        $org->update(['name' => 'Updated Name']);

        $this->assertSame('Updated Name', $org->fresh()->name);
    }

    // ─── Delete guards ────────────────────────────────────────────────────

    public function test_delete_with_active_obligations_throws(): void
    {
        $org = Organization::factory()->create(['lifecycle_state' => 'active']);
        $staff = $this->createStaff();

        $this->makeAppointment([
            'organization_id' => $org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Pending,
        ]);

        $this->expectException(OrganizationHasActiveObligationsException::class);

        $org->delete();
    }

    public function test_delete_when_not_closed_throws_even_without_obligations(): void
    {
        $org = Organization::factory()->create(['lifecycle_state' => 'active']);

        $this->expectException(OrganizationHasActiveObligationsException::class);

        $org->delete();
    }

    public function test_delete_blocked_when_lifecycle_is_closing_no_obligations(): void
    {
        $org = Organization::factory()->create(['lifecycle_state' => 'closing']);

        $this->expectException(OrganizationHasActiveObligationsException::class);

        $org->delete();
    }

    public function test_delete_with_bypass_flag_skips_all_guards(): void
    {
        // Even with Active lifecycle and obligations, bypass = true allows deletion
        $org = Organization::factory()->create(['lifecycle_state' => 'active']);
        $staff = $this->createStaff();

        $this->makeAppointment([
            'organization_id' => $org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Pending,
        ]);

        $org->bypassDeleteGuard = true;
        $org->delete();

        $this->assertDatabaseMissing('organizations', ['id' => $org->id]);
    }

    public function test_delete_of_closed_org_with_no_obligations_succeeds(): void
    {
        $org = Organization::factory()->create(['lifecycle_state' => 'closed']);

        $org->delete();

        $this->assertDatabaseMissing('organizations', ['id' => $org->id]);
    }
}
