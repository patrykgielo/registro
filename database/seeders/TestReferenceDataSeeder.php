<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Root seeder for the test suite's reference/lookup data: RBAC roles and
 * permissions, transactional email templates, and vehicle types.
 *
 * Wired as `Tests\TestCase::$seeder`. `RefreshDatabase::migrateFreshUsing()`
 * reads that property and passes `--seeder=TestReferenceDataSeeder` to
 * `migrate:fresh`, which runs it exactly ONCE per test process — inside
 * `RefreshDatabase::migrateDatabases()`, which itself only executes while
 * `RefreshDatabaseState::$migrated` is still false (i.e. for the very first
 * RefreshDatabase test in the process), and strictly BEFORE that first
 * test's `beginDatabaseTransaction()`. Every later test's per-test
 * transaction therefore starts from — and rolls back to — an already-seeded
 * baseline instead of re-inserting ~185 rows on every single test.
 *
 * Each of the three seeders below is idempotent (`firstOrCreate`/
 * `updateOrCreate`) and has no dependency on tenant context, so running them
 * once at process bootstrap (before any HTTP request, Filament tenant, or
 * session exists) produces identical rows to running them per-test. See
 * `.claude/rules/tests.md` -> "Reference data seeding" for the full
 * reasoning and the pitfalls that were checked before relying on this.
 */
class TestReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            EmailTemplateSeeder::class,
            VehicleTypeSeeder::class,
        ]);
    }
}
