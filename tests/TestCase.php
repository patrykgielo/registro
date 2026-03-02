<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed required reference data for all tests
        // This runs AFTER RefreshDatabase trait migrations
        $this->artisan('db:seed', ['--class' => \Database\Seeders\RolePermissionSeeder::class]);
        $this->artisan('db:seed', ['--class' => \Database\Seeders\EmailTemplateSeeder::class]);
        $this->artisan('db:seed', ['--class' => \Database\Seeders\VehicleTypeSeeder::class]);
    }
}
