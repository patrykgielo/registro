<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tests for Filament Settings Repeater data format handling.
 *
 * These tests prevent the [object Object] bug from recurring.
 * Incident: 2026-02-05 - Simple Repeater data was saved in wrong format.
 *
 * @see app/Filament/Traits/HasGroupedSettings.php
 * @see .claude/rules/filament-settings-pages.md
 */
class FilamentSettingsRepeaterTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin user
        $this->admin = User::factory()->create();
        $adminRole = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->admin->assignRole($adminRole);
    }

    /**
     * Test that Simple Repeater data is stored as flat array.
     *
     * Expected format: ['text1', 'text2', 'text3']
     * NOT: [['item' => 'text1'], ['item' => 'text2']]
     */
    public function test_simple_repeater_saves_as_flat_array(): void
    {
        // Arrange: Create setting with correct format
        $data = ['Item 1', 'Item 2', 'Item 3'];

        Setting::create([
            'group' => 'booking_wizard',
            'key' => 'before_visit_items',
            'value' => $data,
        ]);

        // Act: Retrieve setting
        $setting = Setting::where('group', 'booking_wizard')
            ->where('key', 'before_visit_items')
            ->first();

        // Assert: Must be flat array of strings
        $this->assertIsArray($setting->value);
        $this->assertCount(3, $setting->value);
        $this->assertEquals('Item 1', $setting->value[0]);
        $this->assertEquals('Item 2', $setting->value[1]);
        $this->assertEquals('Item 3', $setting->value[2]);

        // CRITICAL: Each element must be string, NOT array
        foreach ($setting->value as $item) {
            $this->assertIsString($item, 'Simple Repeater item must be string, not array');
        }
    }

    /**
     * Test that Complex Repeater data is stored as array of objects.
     *
     * Expected format: [['name' => 'X', 'icon' => 'Y'], ...]
     */
    public function test_complex_repeater_saves_as_array_of_objects(): void
    {
        // Arrange
        $data = [
            ['name' => 'Type 1', 'icon' => 'sun', 'description' => 'Description 1'],
            ['name' => 'Type 2', 'icon' => 'moon', 'description' => 'Description 2'],
        ];

        Setting::create([
            'group' => 'booking_wizard',
            'key' => 'service_location_types',
            'value' => $data,
        ]);

        // Act
        $setting = Setting::where('group', 'booking_wizard')
            ->where('key', 'service_location_types')
            ->first();

        // Assert
        $this->assertIsArray($setting->value);
        $this->assertCount(2, $setting->value);
        $this->assertArrayHasKey('name', $setting->value[0]);
        $this->assertArrayHasKey('icon', $setting->value[0]);
        $this->assertArrayHasKey('description', $setting->value[0]);
        $this->assertEquals('Type 1', $setting->value[0]['name']);
    }

    /**
     * Test migration fixes doubly nested array format.
     *
     * Corrupt format: [['item1', 'item2']]
     * Fixed format: ['item1', 'item2']
     */
    public function test_migration_fixes_doubly_nested_array(): void
    {
        // Arrange: Create corrupted data
        Setting::create([
            'group' => 'booking_wizard',
            'key' => 'before_visit_items',
            'value' => [['Item 1', 'Item 2']], // Doubly nested (corrupt)
        ]);

        // Act: Run migration
        $migration = include database_path('migrations/2026_02_05_220000_fix_repeater_settings_data_format.php');
        $migration->up();

        // Assert: Data is now flat
        $setting = Setting::where('group', 'booking_wizard')
            ->where('key', 'before_visit_items')
            ->first();

        $this->assertEquals(['Item 1', 'Item 2'], $setting->value);
        $this->assertIsString($setting->value[0]);
    }

    /**
     * Test migration fixes Simple Repeater object format.
     *
     * Corrupt format: [['item' => 'text1'], ['item' => 'text2']]
     * Fixed format: ['text1', 'text2']
     */
    public function test_migration_fixes_simple_repeater_object_format(): void
    {
        // Arrange: Create corrupted data (as Filament would save incorrectly)
        Setting::create([
            'group' => 'booking_wizard',
            'key' => 'before_visit_items',
            'value' => [
                ['item' => 'Item 1'],
                ['item' => 'Item 2'],
            ],
        ]);

        // Act: Run migration
        $migration = include database_path('migrations/2026_02_05_220000_fix_repeater_settings_data_format.php');
        $migration->up();

        // Assert: Data is flattened
        $setting = Setting::where('group', 'booking_wizard')
            ->where('key', 'before_visit_items')
            ->first();

        $this->assertEquals(['Item 1', 'Item 2'], $setting->value);
    }

    /**
     * Test migration leaves correct format unchanged.
     */
    public function test_migration_preserves_correct_format(): void
    {
        // Arrange: Create already-correct data
        $correctData = ['Item 1', 'Item 2', 'Item 3'];

        Setting::create([
            'group' => 'booking_wizard',
            'key' => 'before_visit_items',
            'value' => $correctData,
        ]);

        // Act: Run migration
        $migration = include database_path('migrations/2026_02_05_220000_fix_repeater_settings_data_format.php');
        $migration->up();

        // Assert: Data unchanged
        $setting = Setting::where('group', 'booking_wizard')
            ->where('key', 'before_visit_items')
            ->first();

        $this->assertEquals($correctData, $setting->value);
    }

    /**
     * Test that null/empty settings don't cause errors.
     */
    public function test_migration_handles_empty_settings(): void
    {
        // Arrange: Create empty setting
        Setting::create([
            'group' => 'booking_wizard',
            'key' => 'before_visit_items',
            'value' => [],
        ]);

        // Act: Run migration (should not throw)
        $migration = include database_path('migrations/2026_02_05_220000_fix_repeater_settings_data_format.php');

        $this->expectNotToPerformAssertions();
        $migration->up();
    }

    /**
     * Test that Complex Repeater with service_location_types is handled correctly.
     */
    public function test_complex_repeater_migration_preserves_structure(): void
    {
        // Arrange: Correct Complex Repeater data
        $data = [
            ['name' => 'Parking', 'icon' => 'sun', 'description' => 'Open parking'],
            ['name' => 'Garage', 'icon' => 'home', 'description' => 'Underground'],
        ];

        Setting::create([
            'group' => 'booking_wizard',
            'key' => 'service_location_types',
            'value' => $data,
        ]);

        // Act: Run migration
        $migration = include database_path('migrations/2026_02_05_220000_fix_repeater_settings_data_format.php');
        $migration->up();

        // Assert: Structure preserved
        $setting = Setting::where('group', 'booking_wizard')
            ->where('key', 'service_location_types')
            ->first();

        $this->assertEquals($data, $setting->value);
        $this->assertArrayHasKey('name', $setting->value[0]);
        $this->assertArrayHasKey('icon', $setting->value[0]);
    }
}
