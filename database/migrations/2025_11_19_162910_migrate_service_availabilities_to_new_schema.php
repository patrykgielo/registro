<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration converts the old service_availabilities table structure
     * to the new Option B architecture:
     * - staff_schedules: Base weekly patterns (deduplicated)
     * - service_staff: Service-staff associations (pivot table)
     */
    public function up(): void
    {
        // Check if service_availabilities table exists and has data
        if (! DB::getSchemaBuilder()->hasTable('service_availabilities')) {
            return;
        }

        $oldRecords = DB::table('service_availabilities')->get();

        if ($oldRecords->isEmpty()) {
            return;
        }

        // Step 1: Migrate to staff_schedules (deduplicate by user, day, time)
        $scheduleGroups = $oldRecords->groupBy(function ($item) {
            return $item->user_id.'|'.$item->day_of_week.'|'.$item->start_time.'|'.$item->end_time;
        });

        foreach ($scheduleGroups as $key => $group) {
            $firstRecord = $group->first();

            // Check if this schedule already exists (in case migration is run multiple times)
            $exists = DB::table('staff_schedules')
                ->where('user_id', $firstRecord->user_id)
                ->where('day_of_week', $firstRecord->day_of_week)
                ->where('start_time', $firstRecord->start_time)
                ->where('end_time', $firstRecord->end_time)
                ->exists();

            if (! $exists) {
                DB::table('staff_schedules')->insert([
                    'user_id' => $firstRecord->user_id,
                    'day_of_week' => $firstRecord->day_of_week,
                    'start_time' => $firstRecord->start_time,
                    'end_time' => $firstRecord->end_time,
                    'effective_from' => null,
                    'effective_until' => null,
                    'is_active' => true,
                    'created_at' => $firstRecord->created_at ?? now(),
                    'updated_at' => $firstRecord->updated_at ?? now(),
                ]);
            }
        }

        // Step 2: Migrate to service_staff pivot (unique service-user pairs)
        $serviceStaffPairs = $oldRecords->groupBy(function ($item) {
            return $item->service_id.'|'.$item->user_id;
        });

        foreach ($serviceStaffPairs as $key => $group) {
            $firstRecord = $group->first();

            // Check if this association already exists
            $exists = DB::table('service_staff')
                ->where('service_id', $firstRecord->service_id)
                ->where('user_id', $firstRecord->user_id)
                ->exists();

            if (! $exists) {
                DB::table('service_staff')->insert([
                    'service_id' => $firstRecord->service_id,
                    'user_id' => $firstRecord->user_id,
                    'created_at' => $firstRecord->created_at ?? now(),
                    'updated_at' => $firstRecord->updated_at ?? now(),
                ]);
            }
        }

        // Note: We're keeping service_availabilities table intact for now
        // It can be dropped in a separate migration after verifying the new system works
    }

    /**
     * Reverse the migrations.
     *
     * WARNING: This will delete all data from the new tables!
     * Only run this if you need to rollback the migration.
     */
    public function down(): void
    {
        DB::table('staff_schedules')->truncate();
        DB::table('service_staff')->truncate();

        // Note: service_availabilities table remains unchanged
    }
};
