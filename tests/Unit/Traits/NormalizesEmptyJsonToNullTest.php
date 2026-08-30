<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use App\Enums\TemplateKey;
use App\Models\Location;
use App\Models\Organization;
use App\Models\ReminderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins App\Traits\NormalizesEmptyJsonToNull against its two current
 * consumers. Filament's Repeater/FileUpload/KeyValue dehydrate an untouched
 * empty field to `[]`, never `null` — without this trait, a no-op "Zapisz"
 * on a record whose JSON column was genuinely NULL silently rewrites it to
 * `[]`, which Location's Auditable trait then logs as a real change that
 * never happened (PanelWalkthroughTest, 2026-08-30).
 */
class NormalizesEmptyJsonToNullTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_empty_array_on_location_opening_hours_and_gallery_stores_null(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create([
            'opening_hours' => null,
            'gallery' => null,
        ]);

        $location->opening_hours = [];
        $location->gallery = [];
        $location->save();

        $this->assertNull($location->fresh()->getRawOriginal('opening_hours'));
        $this->assertNull($location->fresh()->getRawOriginal('gallery'));
    }

    public function test_saving_non_empty_array_on_location_opening_hours_is_preserved(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create(['opening_hours' => null]);

        $location->opening_hours = ['monday' => ['09:00', '17:00']];
        $location->save();

        $this->assertSame(['monday' => ['09:00', '17:00']], $location->fresh()->opening_hours);
    }

    public function test_saving_empty_array_on_reminder_config_settings_stores_null(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $config = ReminderConfig::create([
            'organization_id' => $org->id,
            'name' => 'Test',
            'channel' => 'sms',
            'template_key' => TemplateKey::APPOINTMENT_REMINDER_24H->value,
            'settings' => null,
        ]);

        $config->settings = [];
        $config->save();

        $this->assertNull($config->fresh()->getRawOriginal('settings'));
    }
}
