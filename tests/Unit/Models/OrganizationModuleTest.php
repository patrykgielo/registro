<?php

namespace Tests\Unit\Models;

use App\Enums\Industry;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_has_module_returns_false_for_unknown_module(): void
    {
        $org = Organization::factory()->create(['booking_type' => 'time_slot']);

        $this->assertFalse($org->hasModule('nonexistent'));
    }

    public function test_has_module_uses_booking_type_defaults_for_time_slot(): void
    {
        $org = Organization::factory()->create([
            'booking_type' => 'time_slot',
            'industry' => null,
        ]);

        $this->assertTrue($org->hasModule('services'));
        $this->assertTrue($org->hasModule('bookings'));
        $this->assertFalse($org->hasModule('rentals'));
        $this->assertFalse($org->hasModule('staff'));
    }

    public function test_has_module_uses_booking_type_defaults_for_item_rental(): void
    {
        $org = Organization::factory()->create([
            'booking_type' => 'item_rental',
            'industry' => null,
        ]);

        $this->assertFalse($org->hasModule('services'));
        $this->assertFalse($org->hasModule('bookings'));
        $this->assertTrue($org->hasModule('rentals'));
    }

    public function test_has_module_uses_booking_type_defaults_for_both(): void
    {
        $org = Organization::factory()->create([
            'booking_type' => 'both',
            'industry' => null,
        ]);

        $this->assertTrue($org->hasModule('services'));
        $this->assertTrue($org->hasModule('bookings'));
        $this->assertTrue($org->hasModule('rentals'));
    }

    public function test_has_module_industry_overrides_booking_type(): void
    {
        $org = Organization::factory()->create([
            'booking_type' => 'time_slot',
            'industry' => Industry::EquipmentRental,
        ]);

        // EquipmentRental defaults: ['services', 'rentals']
        $this->assertTrue($org->hasModule('rentals'));
        $this->assertTrue($org->hasModule('services'));
        $this->assertFalse($org->hasModule('bookings'));
    }

    public function test_has_module_explicit_override_takes_priority(): void
    {
        $org = Organization::factory()->create([
            'booking_type' => 'time_slot',
            'industry' => Industry::EquipmentRental,
            'settings' => ['modules' => ['staff' => true, 'rentals' => false]],
        ]);

        // Explicit override: staff ON, rentals OFF (even though industry default is ON)
        $this->assertTrue($org->hasModule('staff'));
        $this->assertFalse($org->hasModule('rentals'));
    }

    public function test_enable_module_persists_override(): void
    {
        $org = Organization::factory()->create([
            'booking_type' => 'time_slot',
            'industry' => null,
        ]);

        $this->assertFalse($org->hasModule('staff'));

        $org->enableModule('staff');
        $org->refresh();

        $this->assertTrue($org->hasModule('staff'));
        $this->assertTrue(data_get($org->settings, 'modules.staff'));
    }

    public function test_disable_module_persists_override(): void
    {
        $org = Organization::factory()->create([
            'booking_type' => 'time_slot',
            'industry' => null,
        ]);

        $this->assertTrue($org->hasModule('services'));

        $org->disableModule('services');
        $org->refresh();

        $this->assertFalse($org->hasModule('services'));
        $this->assertFalse(data_get($org->settings, 'modules.services'));
    }

    public function test_industry_default_modules_method(): void
    {
        $this->assertEquals(['services', 'rentals', 'website'], Industry::EquipmentRental->defaultModules());
        $this->assertEquals(['services', 'bookings', 'website'], Industry::AutoDetailing->defaultModules());
        $this->assertEquals(['services', 'bookings', 'website'], Industry::GeneralServices->defaultModules());
    }

    public function test_non_default_modules_are_off_by_default(): void
    {
        $org = Organization::factory()->create([
            'booking_type' => 'time_slot',
            'industry' => null,
        ]);

        // Modules not in MODULE_DEFAULTS['time_slot'] should be OFF
        $this->assertFalse($org->hasModule('staff'));
        $this->assertFalse($org->hasModule('customers'));
        $this->assertFalse($org->hasModule('vehicles'));
        $this->assertFalse($org->hasModule('communication'));
        $this->assertTrue($org->hasModule('website'));  // website is ON by default for all tenants
        $this->assertFalse($org->hasModule('service_area'));
    }

    public function test_industry_completely_replaces_booking_type_lookup(): void
    {
        // booking_type='both' would give [services, bookings, rentals]
        // but EquipmentRental industry should give ['services', 'rentals']
        $org = Organization::factory()->create([
            'booking_type' => 'both',
            'industry' => Industry::EquipmentRental,
        ]);

        $this->assertTrue($org->hasModule('rentals'));
        $this->assertTrue($org->hasModule('services'));
        $this->assertFalse($org->hasModule('bookings'));
    }

    public function test_industry_not_in_defaults_falls_to_false(): void
    {
        $org = Organization::factory()->create([
            'booking_type' => 'time_slot',
            'industry' => Industry::AutoDetailing,
        ]);

        // AutoDetailing defaults: [services, bookings] — staff is NOT in there
        $this->assertFalse($org->hasModule('staff'));
        $this->assertFalse($org->hasModule('rentals'));
        $this->assertFalse($org->hasModule('communication'));
    }

    public function test_enable_module_preserves_existing_settings(): void
    {
        $org = Organization::factory()->create([
            'booking_type' => 'time_slot',
            'settings' => ['features' => ['vehicles' => true]],
        ]);

        $org->enableModule('staff');
        $org->refresh();

        // Existing features should be preserved
        $this->assertTrue(data_get($org->settings, 'features.vehicles'));
        $this->assertTrue(data_get($org->settings, 'modules.staff'));
    }
}
