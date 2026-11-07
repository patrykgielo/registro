<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Executes migrate:rollback for
 * 2026_11_07_110000_add_organization_id_to_service_area_waitlist_table.php.
 *
 * Background: 2026_03_08_000003 listed `service_area_waitlists` (plural), a
 * table that never existed, and its Schema::hasTable() guard skipped it
 * silently — the real `service_area_waitlist` stayed global to every tenant.
 */
class AddOrganizationIdToServiceAreaWaitlistTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_11_07_110000_add_organization_id_to_service_area_waitlist_table.php';

    /**
     * @return array<string, mixed>
     */
    private function row(?int $organizationId, string $email = 'jan@gmail.com'): array
    {
        $row = [
            'email' => $email,
            'requested_address' => 'Stary Rynek 1, Poznań',
            'requested_latitude' => 52.4064,
            'requested_longitude' => 16.9252,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Absent (not NULL) once the column has been rolled back.
        return $organizationId === null ? $row : ['organization_id' => $organizationId] + $row;
    }

    public function test_up_adds_the_organization_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('service_area_waitlist', 'organization_id'));
    }

    public function test_the_signup_unique_is_scoped_per_organization(): void
    {
        [$orgA, $orgB] = Organization::factory()->count(2)->create();

        DB::table('service_area_waitlist')->insert($this->row($orgA->id));
        DB::table('service_area_waitlist')->insert($this->row($orgB->id));

        $this->assertSame(2, DB::table('service_area_waitlist')->count());

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('service_area_waitlist')->insert($this->row($orgA->id));
    }

    public function test_deleting_an_organization_removes_its_waitlist_entries_but_not_other_tenants(): void
    {
        [$orgA, $orgB] = Organization::factory()->count(2)->create();
        DB::table('service_area_waitlist')->insert($this->row($orgA->id));
        DB::table('service_area_waitlist')->insert($this->row($orgB->id, 'inna@gmail.com'));

        // Hard delete (offboarding) — PII must not outlive the tenant. Raw delete:
        // OrganizationObserver refuses unless the lifecycle was closed properly, and
        // what is under test here is the FK, not the lifecycle guard.
        DB::table('organizations')->where('id', $orgA->id)->delete();

        $this->assertSame(
            [$orgB->id],
            DB::table('service_area_waitlist')->pluck('organization_id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function test_rollback_drops_the_column_keeps_the_rows_and_restores_the_global_unique(): void
    {
        $org = Organization::factory()->create();
        DB::table('service_area_waitlist')->insert($this->row($org->id));

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertFalse(Schema::hasColumn('service_area_waitlist', 'organization_id'));
        $this->assertSame(1, DB::table('service_area_waitlist')->count());

        // Original `unique_waitlist_entry (email, lat, lng)` is back.
        try {
            DB::table('service_area_waitlist')->insert($this->row(null));
            $this->fail('The global unique_waitlist_entry was not restored.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertTrue(Schema::hasColumn('service_area_waitlist', 'organization_id'));
        $this->assertSame(1, DB::table('service_area_waitlist')->count());
    }

    /**
     * Two tenants may legitimately hold the same (email, location) entry once
     * the unique is per-organization. The pre-migration schema cannot represent
     * that — a rollback must refuse loudly rather than silently delete PII.
     */
    public function test_rollback_refuses_when_cross_tenant_duplicates_would_violate_the_old_unique(): void
    {
        [$orgA, $orgB] = Organization::factory()->count(2)->create();
        DB::table('service_area_waitlist')->insert($this->row($orgA->id));
        DB::table('service_area_waitlist')->insert($this->row($orgB->id));

        try {
            $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
            $this->fail('Rollback should have refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('service_area_waitlist', $e->getMessage());
        }

        // Nothing was touched.
        $this->assertTrue(Schema::hasColumn('service_area_waitlist', 'organization_id'));
        $this->assertSame(2, DB::table('service_area_waitlist')->count());
    }
}
