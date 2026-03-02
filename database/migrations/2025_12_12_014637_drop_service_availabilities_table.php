<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop service_availabilities table - Dead Code Cleanup
 *
 * CONTEXT:
 * On 2025-11-19, the system was migrated from per-service availability (OLD)
 * to calendar-based availability (NEW) via StaffSchedule system.
 *
 * OLD System (ServiceAvailability):
 * - Required configuration per service, per day, per staff member
 * - Combinatorial explosion: 10 services × 7 days × 5 staff = 350 configs
 * - Admin UI: Modal-based forms (poor UX)
 * - Never used by booking logic (dead code)
 *
 * NEW System (StaffSchedule):
 * - Staff-centric base schedules (applies to ALL services)
 * - Date exceptions for overrides (single days)
 * - Vacation periods for multi-day absences
 * - Priority logic: Vacation → Exception → Base Schedule
 * - 91% configuration reduction: 350 clicks → 30 clicks
 *
 * VERIFICATION:
 * - Database query confirmed 0 records in service_availabilities table
 * - AppointmentService uses StaffScheduleService (not ServiceAvailability)
 * - No code references ServiceAvailability model
 * - All UI components removed from EmployeeResource
 *
 * BACKUP:
 * To restore, run: php artisan migrate:rollback --step=1
 * Table structure will be recreated (no data to restore - table was empty)
 *
 * RELATED:
 * - ADR-015: Staff Availability System Redesign
 * - Migration 2025_11_19_162910: Migrate to new schema
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Table was verified empty before deletion (0 records)
        Schema::dropIfExists('service_availabilities');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate table structure for rollback
        Schema::create('service_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('day_of_week'); // 0 = Sunday, 6 = Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            // Ensure no overlapping availability for same user/service/day
            $table->unique(['user_id', 'service_id', 'day_of_week', 'start_time'], 'sa_user_service_day_time_unique');
        });
    }
};
