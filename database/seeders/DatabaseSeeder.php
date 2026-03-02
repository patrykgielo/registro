<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with development lookup data.
     *
     * This seeder orchestrates development/testing seeders ONLY.
     * For production deployments, reference data is seeded via DATA MIGRATIONS.
     *
     * IMPORTANT: This seeder does NOT create users!
     * For first admin user, use: php artisan make:filament-user
     *
     * What gets seeded (v0.3.1+):
     * - Application settings (booking, map, contact, marketing)
     * - Roles and permissions (super-admin, admin, staff, customer)
     * - Vehicle types (5 categories for booking wizard)
     * - Services (8 car detailing services)
     *
     * What's now seeded via DATA MIGRATIONS (v0.3.1+):
     * - Email templates (30 templates: 15 types × 2 languages) → database/migrations/2025_12_02_224732_seed_email_templates.php
     * - SMS templates (14 templates: 7 types × 2 languages) → database/migrations/2025_12_02_225216_seed_sms_templates.php
     *
     * See: docs/guides/data-migrations.md for pattern explanation
     *
     * All seeders are idempotent - safe to run multiple times.
     */
    public function run(): void
    {
        $this->call([
            SettingSeeder::class,           // ✅ Dev settings
            RolePermissionSeeder::class,    // ✅ Dev roles
            VehicleTypeSeeder::class,       // ✅ Dev vehicle types
            ServiceSeeder::class,           // ✅ Dev services
            ServiceAreaSeeder::class,       // ✅ Dev service areas (geographic restrictions)
            // EmailTemplateSeeder removed - now data migration (2025_12_02_224732_seed_email_templates.php)
            // SmsTemplateSeeder removed - now data migration (2025_12_02_225216_seed_sms_templates.php)
        ]);

        // NOTE: Test users are created by individual tests via factories
        // For manual testing, use: php artisan make:filament-user
        // Or create via Tinker: User::factory()->create([...])
    }
}
