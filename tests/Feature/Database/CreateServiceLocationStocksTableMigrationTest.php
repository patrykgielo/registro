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
 * 2026_08_28_090000_create_service_location_stocks_table.php — the
 * wycofywalność requirement in plan-wdrozenia.md. Runs on SQLite locally
 * (.env.testing); the MySQL 8.0 release gate is what actually exercises the
 * FK onDelete behaviour below with real InnoDB semantics — see
 * CreateLocationsTableMigrationTest's docblock for the same caveat.
 */
class CreateServiceLocationStocksTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_28_090000_create_service_location_stocks_table.php';

    public function test_up_creates_the_table_with_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('service_location_stocks'));
        $this->assertTrue(Schema::hasColumns('service_location_stocks', [
            'id', 'organization_id', 'service_id', 'location_id',
            'quantity', 'is_active', 'created_at', 'updated_at',
        ]));
    }

    public function test_service_id_and_location_id_pair_must_be_unique(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);

        DB::table('service_location_stocks')->insert($this->rowFor($org->id, $service->id, $location->id));

        $this->expectException(QueryException::class);

        DB::table('service_location_stocks')->insert($this->rowFor($org->id, $service->id, $location->id));
    }

    public function test_the_same_service_can_have_a_row_for_two_different_locations(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($org, 'organization')->create();
        $locationB = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);

        DB::table('service_location_stocks')->insert($this->rowFor($org->id, $service->id, $locationA->id));
        DB::table('service_location_stocks')->insert($this->rowFor($org->id, $service->id, $locationB->id));

        $this->assertSame(2, DB::table('service_location_stocks')->where('service_id', $service->id)->count());
    }

    /**
     * code-reviewer BLOKER 2 (Faza 2): service_id was originally
     * restrictOnDelete, modelled on rentals.service_id/order_items.service_id
     * — but those two protect LEGAL RECORDS (migrations.md's FK table), and
     * a stock row is not one. restrictOnDelete here meant almost every real
     * item_rental service (any one that had EVER had this field routed —
     * i.e. every single-active-location tenant, 8/8 of them today) silently
     * stopped being deletable from the panel the moment it got its first
     * stock anchor row. See ServiceResourceDeletionTest for the panel-level
     * proof (DeleteAction actually succeeds, not just this raw DB delete).
     */
    public function test_deleting_a_service_with_a_stock_row_cascades(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        DB::table('service_location_stocks')->insert($this->rowFor($org->id, $service->id, $location->id));

        DB::table('services')->where('id', $service->id)->delete();

        $this->assertDatabaseMissing('service_location_stocks', ['service_id' => $service->id]);
        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    /**
     * The onDelete choice the migration's own docblock argues for: deleting
     * a Location cascades to its stock rows rather than being blocked by
     * them — the mechanism that, combined with locations.organization_id's
     * own cascadeOnDelete, is what lets an organization hard-delete succeed
     * even with existing stock rows (proven end-to-end, through the real
     * organizations table, by
     * tests/Feature/Organizations/ServiceLocationStockCascadeDeletionTest.php).
     */
    public function test_deleting_a_location_cascades_to_its_stock_rows(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        DB::table('service_location_stocks')->insert($this->rowFor($org->id, $service->id, $location->id));

        // Location's own delete guard (LocationObserver::deleting()) blocks
        // removing a tenant's only location — this location is the
        // organization's only one, so delete at the DB level directly,
        // bypassing the model layer, exactly like the cascade path this
        // test is measuring.
        DB::table('locations')->where('id', $location->id)->delete();

        $this->assertDatabaseMissing('service_location_stocks', ['location_id' => $location->id]);
    }

    public function test_rollback_drops_the_table_and_migrating_again_recreates_it_empty(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        DB::table('service_location_stocks')->insert($this->rowFor($org->id, $service->id, $location->id));
        $this->assertTrue(Schema::hasTable('service_location_stocks'));

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertFalse(Schema::hasTable('service_location_stocks'));

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertTrue(Schema::hasTable('service_location_stocks'));
        $this->assertSame(0, DB::table('service_location_stocks')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(int $organizationId, int $serviceId, int $locationId): array
    {
        return [
            'organization_id' => $organizationId,
            'service_id' => $serviceId,
            'location_id' => $locationId,
            'quantity' => 5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
