<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VULN: double-booking race condition (no locking, no unique constraint).
 *
 * `appointments` only had non-unique performance indexes on
 * (staff_id, appointment_date) and (appointment_date, start_time) — nothing
 * stopped two concurrent requests for the same staff/slot from both passing
 * the SELECT-based conflict check and both successfully inserting a row.
 *
 * Fix: a genuine UNIQUE constraint as the authoritative backstop (app-level
 * locking in AppointmentController::store() / BookingController::confirm()
 * is best-effort only — see AppointmentService::lockStaffAppointmentsForDate()).
 *
 * Design choice — why not a plain unique(staff_id, appointment_date, start_time):
 * Cancelled appointments must free the slot for rebooking, and MySQL has no
 * native partial/filtered unique index (unlike Postgres' `WHERE` clause on a
 * unique index). Using the raw `status` column in the composite key doesn't
 * work either — two ACTIVE appointments with different statuses (e.g. one
 * 'pending', one 'confirmed') would still be treated as distinct keys and
 * the collision would slip through.
 *
 * Instead: a dedicated nullable `active_slot` column, maintained exclusively
 * by Appointment's model event (see App\Models\Appointment::booted()) —
 * `true` for any non-cancelled appointment, `null` the instant it's
 * cancelled. Both MySQL and SQLite treat every NULL in a unique index as
 * distinct from every other NULL, so:
 *   - two ACTIVE appointments for the same staff/date/start_time collide
 *     (active_slot = true = true) → constraint violation, exactly what we want.
 *   - any number of CANCELLED appointments (or historical rows before this
 *     migration ran) for the same slot never collide with each other or with
 *     an active one (active_slot = null on each side).
 * This keeps the fix a plain composite unique index — no generated/virtual
 * columns, no per-driver SQL — portable across the project's MySQL
 * (production) and SQLite (test suite) targets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->boolean('active_slot')->nullable()->after('status');
        });

        // Backfill in bounded batches (chunkById) rather than two unbounded
        // UPDATE ... WHERE statements — safe to run against a production-sized
        // table later, not just the current empty dev table.
        DB::table('appointments')->select(['id', 'status'])->chunkById(500, function ($appointments) {
            $activeIds = $appointments->where('status', '!=', 'cancelled')->pluck('id');
            $cancelledIds = $appointments->where('status', 'cancelled')->pluck('id');

            if ($activeIds->isNotEmpty()) {
                DB::table('appointments')->whereIn('id', $activeIds)->update(['active_slot' => true]);
            }
            if ($cancelledIds->isNotEmpty()) {
                DB::table('appointments')->whereIn('id', $cancelledIds)->update(['active_slot' => null]);
            }
        });

        // Pre-flight: abort with a clear, actionable error instead of letting
        // Schema::table()->unique() below fail with an opaque DB duplicate-key
        // error. TIME(start_time) normalizes the comparison so pre-existing
        // rows saved with inconsistent precision ('10:00' vs '10:00:00' — the
        // exact gap Appointment::booted()'s new saving-hook normalization
        // fixes going forward, see App\Models\Appointment) are still correctly
        // recognized as the same slot. Low real-world exposure today (no
        // populated staging/prod DB yet, per CLAUDE.md), but this must not
        // silently hard-fail a future deploy with no diagnostic.
        $duplicates = DB::table('appointments')
            ->select('staff_id', 'appointment_date', DB::raw('TIME(start_time) as normalized_start_time'), DB::raw('COUNT(*) as conflict_count'))
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('staff_id')
            ->groupBy('staff_id', 'appointment_date', DB::raw('TIME(start_time)'))
            ->having('conflict_count', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $details = $duplicates->map(fn ($row) => sprintf(
                'staff_id=%s date=%s time=%s (%d conflicting rows)',
                $row->staff_id,
                $row->appointment_date,
                $row->normalized_start_time,
                $row->conflict_count
            ))->implode('; ');

            throw new \RuntimeException(sprintf(
                'Cannot add appointments_staff_slot_unique: %d existing double-booked slot(s) found in the '.
                'appointments table. Resolve these manually (cancel or reschedule one side of each conflict) '.
                'before re-running this migration. Conflicts: %s',
                $duplicates->count(),
                $details
            ));
        }

        Schema::table('appointments', function (Blueprint $table) {
            // organization_id deliberately EXCLUDED here — unlike the general
            // .claude/rules/migrations.md convention for tenant-scoped tables.
            // staff_id references the global `users` table (not scoped per
            // org); a staff member shared across tenants genuinely cannot be
            // double-booked at the same instant regardless of which org's
            // calendar the appointment lives under, so adding organization_id
            // would silently reopen cross-tenant double-booking for shared staff.
            $table->unique(
                ['staff_id', 'appointment_date', 'start_time', 'active_slot'],
                'appointments_staff_slot_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique('appointments_staff_slot_unique');
            $table->dropColumn('active_slot');
        });
    }
};
