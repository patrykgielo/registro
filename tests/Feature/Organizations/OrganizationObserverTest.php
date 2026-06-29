<?php

namespace Tests\Feature\Organizations;

use App\Enums\AppointmentStatus;
use App\Enums\OrganizationLifecycleState;
use App\Exceptions\InvalidLifecycleTransitionException;
use App\Exceptions\OrganizationHasActiveObligationsException;
use App\Exceptions\OrganizationHasLegalRecordsException;
use App\Exceptions\OrganizationNotClosedException;
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
        $org = Organization::factory()->inactive()->create();

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
        $org = Organization::factory()->create();

        $this->expectException(InvalidLifecycleTransitionException::class);

        // Active → Closed is not a valid transition (must go through Closing first)
        $this->setLifecycleState($org, OrganizationLifecycleState::Closed);
    }

    public function test_legal_transition_without_obligations_succeeds(): void
    {
        $org = Organization::factory()->create();

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
        $org = Organization::factory()->closing()->create();
        $org->update(['closing_initiated_at' => now()->subDay()]);

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
        $org = Organization::factory()->create();

        $this->setLifecycleState($org, OrganizationLifecycleState::Suspended);

        $fresh = $org->fresh();
        $this->assertSame(OrganizationLifecycleState::Suspended, $fresh->lifecycle_state);
        // F003: is_active = false for Suspended state
        $this->assertFalse($fresh->is_active);
    }

    public function test_transition_to_closing_with_active_obligations_throws(): void
    {
        $org = Organization::factory()->create();
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
        $org = Organization::factory()->create();
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

    public function test_force_flag_resets_after_successful_save(): void
    {
        $org = Organization::factory()->create();
        $org->forceLifecycleTransition = true;
        $this->setLifecycleState($org, OrganizationLifecycleState::Suspended);

        $this->assertFalse($org->forceLifecycleTransition, 'flag must be reset by updated() hook');
    }

    public function test_transition_to_closed_with_active_obligations_throws(): void
    {
        $org = Organization::factory()->closing()->create();
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
        $org = Organization::factory()->create();

        // Should not throw even though lifecycle_state remains Active
        $org->update(['name' => 'Updated Name']);

        $this->assertSame('Updated Name', $org->fresh()->name);
    }

    // ─── Delete guards ────────────────────────────────────────────────────

    public function test_delete_of_non_closed_org_throws_not_closed_exception(): void
    {
        $org = Organization::factory()->create();
        $staff = $this->createStaff();

        $this->makeAppointment([
            'organization_id' => $org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Pending,
        ]);

        $this->expectException(OrganizationNotClosedException::class);

        $org->delete();
    }

    public function test_delete_when_not_closed_throws_even_without_obligations(): void
    {
        $org = Organization::factory()->create();

        $this->expectException(OrganizationNotClosedException::class);

        $org->delete();
    }

    public function test_delete_blocked_when_lifecycle_is_closing_no_obligations(): void
    {
        $org = Organization::factory()->closing()->create();

        $this->expectException(OrganizationNotClosedException::class);

        $org->delete();
    }

    public function test_delete_of_closed_org_with_obligations_throws_obligations_exception(): void
    {
        $org = Organization::factory()->closed()->create();
        $staff = $this->createStaff();

        $this->makeAppointment([
            'organization_id' => $org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Pending,
        ]);

        $this->expectException(OrganizationHasActiveObligationsException::class);

        $org->delete();
    }

    public function test_delete_with_bypass_flag_skips_all_guards(): void
    {
        // Even with Active lifecycle and obligations, bypass = true allows deletion
        $org = Organization::factory()->create();
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
        $org = Organization::factory()->closed()->create();

        $org->delete();

        $this->assertDatabaseMissing('organizations', ['id' => $org->id]);
    }

    // ─── Faza 5.2: Legal records guard ───────────────────────────────────────

    public function test_delete_of_closed_org_with_legal_records_throws_exception(): void
    {
        $org = Organization::factory()->closed()->create();

        // A completed order is NOT an active obligation (won't block at step 3),
        // but IS a legal record (must be retained ≥5 years per Art. 112 VAT).
        \Illuminate\Support\Facades\DB::table('tenant_payments')->insert([
            'organization_id' => $org->id,
            'amount' => '599.00',
            'currency' => 'PLN',
            'period_month' => '2026-06',
            'recorded_by' => $org->owner_id,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(OrganizationHasLegalRecordsException::class);

        $org->delete();
    }

    public function test_delete_with_bypass_skips_legal_records_guard(): void
    {
        // bypassDeleteGuard = true skips the application-level check.
        // The DB-level RESTRICT FK is the final backstop (MySQL only, not in SQLite tests).
        $org = Organization::factory()->closed()->create();

        \Illuminate\Support\Facades\DB::table('tenant_payments')->insert([
            'organization_id' => $org->id,
            'amount' => '599.00',
            'currency' => 'PLN',
            'period_month' => '2026-06',
            'recorded_by' => $org->owner_id,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // bypassDeleteGuard skips the application-level OrganizationHasLegalRecordsException,
        // but the DB-level FK is the final backstop: deleting an org that still has legal
        // records throws a QueryException (FK violation) on both SQLite and MySQL. Getting a
        // QueryException here — rather than OrganizationHasLegalRecordsException — proves the
        // app guard was bypassed and the DB constraint caught the deletion.
        $org->bypassDeleteGuard = true;
        $this->expectException(\Illuminate\Database\QueryException::class);
        $org->delete();
    }

    // ─── Faza 5.2: closed_at timestamp ───────────────────────────────────────

    public function test_closed_at_is_set_when_transitioning_to_closed(): void
    {
        $org = Organization::factory()->closing()->create();

        $org->lifecycle_state = OrganizationLifecycleState::Closed;
        $org->save();

        $fresh = $org->fresh();
        $this->assertSame(OrganizationLifecycleState::Closed, $fresh->lifecycle_state);
        $this->assertNotNull($fresh->closed_at, 'closed_at must be set when transitioning to Closed');
    }

    // ─── Faza 5.2: force-flag leak fix ───────────────────────────────────────

    public function test_force_flag_reset_by_saved_even_when_nothing_is_dirty(): void
    {
        // If save() is called on a non-dirty model, Eloquent skips the DB write
        // and never fires updated(). Before Faza 5.2 the flag was only reset in
        // updated() — so a set flag + no-op save() would leave it true, making
        // the NEXT save() (with lifecycle_state change) bypass the obligation guard
        // unintentionally. saved() fires after every save(), including no-ops.
        $org = Organization::factory()->create();
        $org->forceLifecycleTransition = true;

        $org->save(); // nothing is dirty → no DB write → updated() NOT fired → saved() IS fired

        $this->assertFalse($org->forceLifecycleTransition, 'saved() must reset forceLifecycleTransition even on no-op saves');
    }
}
