<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Actions\Onboarding\Seeders\VerticalSeeder;
use App\Models\Organization;
use App\Models\Setting;

class SeedOrganizationDefaults
{
    /**
     * Seed default settings for a newly created organization.
     */
    public function execute(Organization $org): void
    {
        $this->seedSettings($org);
        $this->seedIndustryFeatures($org);
        $this->seedVerticalData($org);
    }

    private function seedSettings(Organization $org): void
    {
        $defaults = [
            'booking' => [
                'booking_enabled' => $org->supportsAppointments(),
                'business_hours_start' => '09:00',
                'business_hours_end' => '18:00',
                'advance_booking_hours' => 24,
                'cancellation_hours' => 24,
                'slot_interval_minutes' => 30,
            ],
            'auth' => [
                'registration_enabled' => true,
            ],
            'general' => [
                'app_name' => $org->name,
                'vat_rate' => 23,
            ],
            'checkout' => [
                'inquiry_email' => '',
            ],
        ];

        foreach ($defaults as $group => $settings) {
            foreach ($settings as $key => $value) {
                Setting::withoutGlobalScope('organization')->updateOrCreate(
                    [
                        'organization_id' => $org->id,
                        'group' => $group,
                        'key' => $key,
                    ],
                    [
                        'value' => is_array($value) ? $value : [$value],
                    ]
                );
            }
        }
    }

    private function seedIndustryFeatures(Organization $org): void
    {
        if ($org->industry === null) {
            return;
        }

        $features = $org->industry->defaultFeatures();
        $settings = $org->settings ?? [];

        foreach ($features as $feature => $enabled) {
            if ($enabled) {
                data_set($settings, "features.{$feature}", true);
            }
        }

        if (! empty($settings)) {
            $org->update(['settings' => $settings]);
        }
    }

    private function seedVerticalData(Organization $org): void
    {
        if ($org->industry === null) {
            return;
        }

        $seederClass = $org->industry->seederClass();
        $seeder = app($seederClass);

        if ($seeder instanceof VerticalSeeder) {
            $seeder->seed($org);
        }
    }
}
