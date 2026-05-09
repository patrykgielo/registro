<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Seed default design group settings (global defaults, organization_id = NULL).
     *
     * These values serve as fallback for tenants who have not customized their
     * brand appearance yet. The `design` module must be explicitly enabled per
     * tenant in the Platform panel before the Design Hub is visible.
     */
    public function up(): void
    {
        $defaults = [
            ['group' => 'design', 'key' => 'brand_color',         'value' => ['#6366f1']],
            ['group' => 'design', 'key' => 'font_family',         'value' => ['inter']],
            ['group' => 'design', 'key' => 'brand_name_override', 'value' => [null]],
            ['group' => 'design', 'key' => 'use_logo_in_emails',  'value' => [true]],
            ['group' => 'design', 'key' => 'use_color_in_emails', 'value' => [true]],
        ];

        foreach ($defaults as $setting) {
            Setting::withoutGlobalScope('organization')->updateOrCreate(
                [
                    'organization_id' => null,
                    'group' => $setting['group'],
                    'key' => $setting['key'],
                ],
                ['value' => $setting['value']]
            );
        }
    }

    /**
     * Remove default design settings.
     */
    public function down(): void
    {
        Setting::withoutGlobalScope('organization')
            ->whereNull('organization_id')
            ->where('group', 'design')
            ->delete();
    }
};
