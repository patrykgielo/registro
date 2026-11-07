<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Organization;
use App\Models\ServiceAreaWaitlist;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Executes migrate:rollback + migrate for
 * 2026_11_07_110001_backfill_and_enforce_organization_id_on_service_area_waitlist.php.
 *
 * The table has NO service_area_id (and nothing else that names a tenant), so
 * existing rows cannot be derived from a relation. The only unambiguous case is
 * "exactly one organization exists". On a multi-organization database the rows
 * stay NULL (never guessed) and the column stays nullable.
 */
class BackfillOrganizationIdForServiceAreaWaitlistMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_11_07_110001_backfill_and_enforce_organization_id_on_service_area_waitlist.php';

    /**
     * RefreshDatabase's initial migrate ran against an empty table, so every
     * test rolls back first (column becomes nullable again), creates its
     * fixtures, then re-runs the migration against real data.
     */
    private function rollbackAndRerun(callable $fixtures): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $fixtures();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }

    private function insertRow(?int $organizationId, string $email): int
    {
        return DB::table('service_area_waitlist')->insertGetId([
            'organization_id' => $organizationId,
            'email' => $email,
            'requested_address' => 'Stary Rynek 1, Poznań',
            'requested_latitude' => 52.4064,
            'requested_longitude' => 16.9252,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function organizationIdOf(int $rowId): ?int
    {
        $value = DB::table('service_area_waitlist')->where('id', $rowId)->value('organization_id');

        return $value === null ? null : (int) $value;
    }

    private function organizationIdIsNullable(): bool
    {
        $column = collect(Schema::getColumns('service_area_waitlist'))->firstWhere('name', 'organization_id');

        return (bool) $column['nullable'];
    }

    public function test_on_a_single_organization_database_orphan_rows_go_to_that_organization(): void
    {
        $org = Organization::factory()->create();
        $ids = [];

        $this->rollbackAndRerun(function () use (&$ids) {
            $ids[] = $this->insertRow(null, 'a@gmail.com');
            $ids[] = $this->insertRow(null, 'b@gmail.com');
        });

        foreach ($ids as $id) {
            $this->assertSame($org->id, $this->organizationIdOf($id));
        }
        $this->assertFalse($this->organizationIdIsNullable());
    }

    public function test_on_a_multi_organization_database_orphan_rows_are_never_guessed(): void
    {
        [$orgA] = Organization::factory()->count(2)->create();
        $orphan = 0;
        $owned = 0;

        $this->rollbackAndRerun(function () use ($orgA, &$orphan, &$owned) {
            $orphan = $this->insertRow(null, 'orphan@gmail.com');
            $owned = $this->insertRow($orgA->id, 'owned@gmail.com');
        });

        $this->assertNull($this->organizationIdOf($orphan));
        $this->assertSame($orgA->id, $this->organizationIdOf($owned));
        $this->assertTrue($this->organizationIdIsNullable(), 'NOT NULL must not be applied while orphan rows exist.');
    }

    public function test_a_soft_deleted_organization_still_counts_so_the_single_org_shortcut_is_not_taken(): void
    {
        Organization::factory()->create();
        // Soft-deleted tenant — it may own some of these rows. Raw update: the model's
        // delete() is guarded by OrganizationObserver.
        $deleted = Organization::factory()->create();
        DB::table('organizations')->where('id', $deleted->id)->update(['deleted_at' => now()]);
        $orphan = 0;

        $this->rollbackAndRerun(function () use (&$orphan) {
            $orphan = $this->insertRow(null, 'orphan@gmail.com');
        });

        $this->assertNull($this->organizationIdOf($orphan));
    }

    public function test_orphan_rows_on_a_multi_organization_database_are_logged_not_silent(): void
    {
        Organization::factory()->count(2)->create();
        Log::spy();

        $this->rollbackAndRerun(fn () => $this->insertRow(null, 'orphan@gmail.com'));

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, 'service_area_waitlist')
                && ($context['orphan_rows'] ?? null) === 1
        )->once();
    }

    public function test_orphan_rows_are_invisible_to_every_tenant(): void
    {
        [$orgA, $orgB] = Organization::factory()->count(2)->create();

        $this->rollbackAndRerun(fn () => $this->insertRow(null, 'orphan@gmail.com'));

        foreach ([$orgA, $orgB] as $org) {
            session(['tenant_id' => $org->id]);
            $this->assertSame(0, ServiceAreaWaitlist::count());
        }
        $this->assertSame(1, ServiceAreaWaitlist::withoutGlobalScopes()->count());
    }

    public function test_without_orphans_not_null_is_enforced_even_on_a_multi_organization_database(): void
    {
        [$orgA] = Organization::factory()->count(2)->create();

        $this->rollbackAndRerun(fn () => $this->insertRow($orgA->id, 'owned@gmail.com'));

        $this->assertFalse($this->organizationIdIsNullable());

        $this->expectException(QueryException::class);
        $this->insertRow(null, 'sneaky@gmail.com');
    }

    public function test_an_empty_table_ends_up_not_null(): void
    {
        $this->assertFalse($this->organizationIdIsNullable());
    }

    public function test_rollback_makes_the_column_nullable_again_and_keeps_the_data(): void
    {
        $org = Organization::factory()->create();
        $row = $this->insertRow($org->id, 'owned@gmail.com');

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertTrue($this->organizationIdIsNullable());
        $this->assertSame($org->id, $this->organizationIdOf($row));

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertFalse($this->organizationIdIsNullable());
    }
}
