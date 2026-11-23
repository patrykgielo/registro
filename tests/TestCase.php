<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;

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

    protected function setUp(): void
    {
        parent::setUp();

        // `artisan test --parallel` workers share the real filesystem (the DB is per worker, storage/ is not).
        // The catalog importer's scratch root is a real directory that tests assert on and plant stale dirs in,
        // so each worker gets its own. No token (sequential run) = the production path, untouched.
        if (($token = ParallelTesting::token()) !== false) {
            config(['catalog_import.scratch_root' => storage_path('app/tmp/catalog-import-'.$token)]);
        }
    }
}
