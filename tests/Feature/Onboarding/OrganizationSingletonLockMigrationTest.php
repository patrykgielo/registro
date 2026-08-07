<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The DB-level singleton lock (2026_08_06_100002_add_singleton_lock_to_organizations_table)
 * is guarded to MySQL + TENANT_SLUG set. The test suite runs on SQLite with
 * TENANT_SLUG unset (.env.testing never sets it), so it must be a clean no-op
 * here -- this test is what guarantees that, rather than assuming it.
 */
class OrganizationSingletonLockMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_singleton_column_is_not_added_on_sqlite(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertNull(config('app.tenant_slug'));

        $this->assertFalse(Schema::hasColumn('organizations', 'singleton'));
    }

    public function test_multiple_organizations_can_still_be_created_in_dev_and_tests(): void
    {
        Organization::factory()->count(2)->create();

        $this->assertSame(2, Organization::count());
    }
}
