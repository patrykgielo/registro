<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enable vehicle-related features for the existing Demo organization.
     * This preserves the current behavior for the original tenant.
     *
     * Uses DB::table() instead of the Organization Eloquent model to avoid
     * coupling this migration to model behavior that may change over time
     * (e.g. SoftDeletes adding WHERE deleted_at IS NULL before the column exists).
     */
    public function up(): void
    {
        $org = DB::table('organizations')->where('slug', 'demo')->first();

        if ($org) {
            $settings = json_decode($org->settings ?? '{}', true) ?? [];
            data_set($settings, 'features.vehicles', true);
            data_set($settings, 'features.mobile_service', true);
            data_set($settings, 'features.service_area', true);
            DB::table('organizations')->where('id', $org->id)->update([
                'settings' => json_encode($settings),
            ]);
        }
    }

    /**
     * Remove feature flags from Demo organization.
     */
    public function down(): void
    {
        $org = DB::table('organizations')->where('slug', 'demo')->first();

        if ($org) {
            $settings = json_decode($org->settings ?? '{}', true) ?? [];
            unset($settings['features']);
            DB::table('organizations')->where('id', $org->id)->update([
                'settings' => json_encode($settings),
            ]);
        }
    }
};
