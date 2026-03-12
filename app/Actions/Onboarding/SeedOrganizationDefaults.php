<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\Organization;
use App\Models\Setting;

class SeedOrganizationDefaults
{
    /**
     * Seed default settings for a newly created organization.
     */
    public function execute(Organization $org): void
    {
        $defaults = [
            'booking' => [
                'booking_enabled' => true,
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
            ],
        ];

        foreach ($defaults as $group => $settings) {
            foreach ($settings as $key => $value) {
                Setting::withoutGlobalScope('organization')->create([
                    'organization_id' => $org->id,
                    'group' => $group,
                    'key' => $key,
                    'value' => is_array($value) ? $value : [$value],
                ]);
            }
        }
    }
}
