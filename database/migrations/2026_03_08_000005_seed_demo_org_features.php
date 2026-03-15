<?php

use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Enable vehicle-related features for the existing Demo organization.
     * This preserves the current behavior for the original tenant.
     */
    public function up(): void
    {
        $org = Organization::where('slug', 'demo')->first();

        if ($org) {
            $settings = $org->settings ?? [];
            data_set($settings, 'features.vehicles', true);
            data_set($settings, 'features.mobile_service', true);
            data_set($settings, 'features.service_area', true);
            $org->update(['settings' => $settings]);
        }
    }

    /**
     * Remove feature flags from Demo organization.
     */
    public function down(): void
    {
        $org = Organization::where('slug', 'demo')->first();

        if ($org) {
            $settings = $org->settings ?? [];
            unset($settings['features']);
            $org->update(['settings' => $settings]);
        }
    }
};
