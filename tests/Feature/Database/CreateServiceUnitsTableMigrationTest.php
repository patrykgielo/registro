<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Executes migrate:rollback (not just a static `down()` regex) for
 * 2026_09_08_090000_create_service_units_table.php — the wycofywalność
 * requirement in plan-wdrozenia.md. Runs on SQLite locally (.env.testing);
 * the MySQL 8.0 release gate is what actually exercises the FK onDelete
 * behaviour below with real InnoDB semantics — see
 * CreateServiceLocationStocksTableMigrationTest's docblock for the same
 * caveat.
 */
class CreateServiceUnitsTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_09_08_090000_create_service_units_table.php';

    public function test_up_creates_the_table_with_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('service_units'));
        $this->assertTrue(Schema::hasColumns('service_units', [
            'id', 'organization_id', 'service_id', 'location_id',
            'identifier', 'inventory_number', 'status', 'acquired_at', 'notes',
            'created_at', 'updated_at',
        ]));
    }

    /**
     * The exact behaviour the schema migration's docblock asks to be
     * verified, not assumed: NULL never collides with NULL under
     * UNIQUE(organization_id, identifier) — standard SQL semantics shared by
     * SQLite and MySQL, unlike the divergences tests.md's MySQL-gate section
     * warns about (ENUM enforcement, JSON key ordering, etc.), so this
     * result generalizes to the real gate.
     */
    public function test_multiple_units_without_an_identifier_do_not_collide(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);

        DB::table('service_units')->insert($this->rowFor($org->id, $service->id, $location->id, null));
        DB::table('service_units')->insert($this->rowFor($org->id, $service->id, $location->id, null));

        $this->assertSame(2, DB::table('service_units')->where('service_id', $service->id)->count());
    }

    public function test_a_duplicate_identifier_within_the_same_organization_is_rejected(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);

        DB::table('service_units')->insert($this->rowFor($org->id, $service->id, $location->id, 'KOP-04'));

        $this->expectException(QueryException::class);

        DB::table('service_units')->insert($this->rowFor($org->id, $service->id, $location->id, 'KOP-04'));
    }

    public function test_two_different_organizations_can_use_the_identical_identifier(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($orgA, 'organization')->create();
        $serviceA = Service::factory()->itemRental()->create(['organization_id' => $orgA->id]);

        $orgB = Organization::factory()->equipmentRental()->create();
        $locationB = Location::factory()->for($orgB, 'organization')->create();
        $serviceB = Service::factory()->itemRental()->create(['organization_id' => $orgB->id]);

        DB::table('service_units')->insert($this->rowFor($orgA->id, $serviceA->id, $locationA->id, 'KOP-04'));
        DB::table('service_units')->insert($this->rowFor($orgB->id, $serviceB->id, $locationB->id, 'KOP-04'));

        $this->assertSame(1, DB::table('service_units')->where('organization_id', $orgA->id)->count());
        $this->assertSame(1, DB::table('service_units')->where('organization_id', $orgB->id)->count());
    }

    public function test_deleting_a_service_with_units_cascades(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        DB::table('service_units')->insert($this->rowFor($org->id, $service->id, $location->id, null));

        DB::table('services')->where('id', $service->id)->delete();

        $this->assertDatabaseMissing('service_units', ['service_id' => $service->id]);
        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    public function test_deleting_a_location_cascades_to_its_units(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        DB::table('service_units')->insert($this->rowFor($org->id, $service->id, $location->id, null));

        // Bypass the model-layer delete guard (LocationObserver blocks
        // removing a tenant's only location) — same technique as
        // CreateServiceLocationStocksTableMigrationTest, measuring the raw
        // FK behaviour, not the panel-level guard.
        DB::table('locations')->where('id', $location->id)->delete();

        $this->assertDatabaseMissing('service_units', ['location_id' => $location->id]);
    }

    public function test_rollback_drops_the_table_and_migrating_again_recreates_it_empty(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        DB::table('service_units')->insert($this->rowFor($org->id, $service->id, $location->id, null));
        $this->assertTrue(Schema::hasTable('service_units'));

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertFalse(Schema::hasTable('service_units'));

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertTrue(Schema::hasTable('service_units'));
        $this->assertSame(0, DB::table('service_units')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(int $organizationId, int $serviceId, int $locationId, ?string $identifier): array
    {
        return [
            'organization_id' => $organizationId,
            'service_id' => $serviceId,
            'location_id' => $locationId,
            'identifier' => $identifier,
            'inventory_number' => null,
            'status' => 'available',
            'acquired_at' => null,
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
