<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * `RefreshDatabase::migrateFreshUsing()` reads this and passes
     * `--seeder=...` to `migrate:fresh`, which runs it exactly ONCE per test
     * process (before the first test's transaction begins) instead of once
     * per test. Tests that use RefreshDatabase get roles/permissions, email
     * templates, and vehicle types seeded automatically; tests that don't
     * use RefreshDatabase never trigger `migrate:fresh` at all, so this
     * property is simply unused for them. See
     * `database/seeders/TestReferenceDataSeeder.php` and
     * `.claude/rules/tests.md` -> "Reference data seeding".
     */
    protected $seeder = \Database\Seeders\TestReferenceDataSeeder::class;
}
