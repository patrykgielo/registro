<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The DB-level singleton lock (2026_08_06_100002_add_singleton_lock_to_organizations_table)
 * is guarded to MySQL + TENANT_SLUG set. The test suite runs with TENANT_SLUG
 * unset (.env.testing never sets it) on EITHER driver -- SQLite locally, MySQL
 * on the release gate (deploy-production.yml) -- so it must be a clean no-op on
 * both; this test is what guarantees that, rather than assuming it.
 *
 * Deliberately does NOT assert a specific driver: this suite has run on MySQL
 * since the release gate started exercising the Feature suite there (see
 * .claude/rules/tests.md), and the real invariant under test is "the column is
 * absent when TENANT_SLUG is unset", which holds on both drivers -- an
 * assertSame('sqlite', ...) here would fail on MySQL for a reason that has
 * nothing to do with the migration's own guard.
 */
class OrganizationSingletonLockMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_singleton_column_is_not_added_when_tenant_slug_is_unset(): void
    {
        $this->assertNull(config('app.tenant_slug'));

        $this->assertFalse(Schema::hasColumn('organizations', 'singleton'));
    }

    public function test_multiple_organizations_can_still_be_created_in_dev_and_tests(): void
    {
        Organization::factory()->count(2)->create();

        $this->assertSame(2, Organization::count());
    }
}
