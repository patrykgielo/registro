<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Only seed when the test uses RefreshDatabase (has a database)
        if (in_array(\Illuminate\Foundation\Testing\RefreshDatabase::class, class_uses_recursive($this))) {
            $this->artisan('db:seed', ['--class' => \Database\Seeders\RolePermissionSeeder::class]);
            $this->artisan('db:seed', ['--class' => \Database\Seeders\EmailTemplateSeeder::class]);
            $this->artisan('db:seed', ['--class' => \Database\Seeders\VehicleTypeSeeder::class]);
        }
    }
}
