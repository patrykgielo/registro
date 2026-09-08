<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\ServiceType;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Executes migrate:rollback for
 * 2026_09_08_090001_generate_service_units_from_quantity_total.php — same
 * wycofywalność requirement, same pattern, as
 * BackfillServiceLocationStocksMigrationTest for its Faza 2 sibling.
 *
 * By the time RefreshDatabase's own initial `migrate` runs there are zero
 * item_rental services in the DB, so that first run is a genuine no-op.
 * Every test below rolls back first, creates its own fixtures, then
 * re-runs `migrate --path=...` to actually exercise up() against real data.
 */
class GenerateServiceUnitsFromQuantityTotalMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_09_08_090001_generate_service_units_from_quantity_total.php';

    private const SCHEMA_MIGRATION_PATH = 'database/migrations/2026_09_08_090000_create_service_units_table.php';

    /**
     * `order_items.service_unit_id` FKs to `service_units` (2026_09_08_100000,
     * added later the same day) — must be rolled back before SCHEMA_MIGRATION_PATH's
     * own down() can DROP TABLE service_units. Same rule as
     * CreateServiceUnitsTableMigrationTest's own copy of this constant.
     */
    private const DEPENDENT_ORDER_ITEM_UNIT_MIGRATION_PATH = 'database/migrations/2026_09_08_100000_add_service_unit_id_to_order_items_table.php';

    public function test_up_creates_one_unit_per_quantity_total_in_the_primary_location(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $org = Organization::factory()->equipmentRental()->create();
        $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 4]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame(4, DB::table('service_units')->where('service_id', $service->id)->count());
        $this->assertSame(
            4,
            DB::table('service_units')->where('service_id', $service->id)->where('location_id', $primary->id)->count()
        );
        $this->assertSame(4, DB::table('service_units')->where('service_id', $service->id)->whereNull('identifier')->count());
        $this->assertSame(4, DB::table('service_units')->where('service_id', $service->id)->where('status', 'available')->count());
    }

    /**
     * The manual anchor fix-up documented in the migration's own docblock:
     * without it, a service created after Faza 2's own backfill ran (no
     * pre-existing service_location_stocks row at all) would gain units but
     * the anchor would stay at its stale/never-materialized value.
     */
    public function test_up_materializes_and_corrects_the_primary_location_anchor_row(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $org = Organization::factory()->equipmentRental()->create();
        $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 5]);
        // No service_location_stocks row exists yet for this service —
        // the documented "created after Faza 2's backfill ran" gap.
        $this->assertSame(0, DB::table('service_location_stocks')->where('service_id', $service->id)->count());

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $row = DB::table('service_location_stocks')
            ->where('service_id', $service->id)
            ->where('location_id', $primary->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(5, (int) $row->quantity);
    }

    public function test_up_skips_a_service_that_already_has_units_when_run_again(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 3]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
        $this->assertSame(3, DB::table('service_units')->where('service_id', $service->id)->count());

        // down() is a deliberate no-op (see the migration's own docblock),
        // so the 3 units created above are NOT removed by this rollback —
        // re-running up() genuinely re-executes it against a service that
        // already has units, which is exactly the "ponowne uruchomienie"
        // scenario the idempotency guard exists for. Without the guard this
        // would insert 3 MORE units for the same service.
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame(
            3,
            DB::table('service_units')->where('service_id', $service->id)->count(),
            'a second run must never duplicate units for a service that already has them'
        );
    }

    /**
     * The safety guard: a service whose stock is already split across more
     * than the primary location (a genuine multi-location distribution) is
     * left untouched rather than having its whole quantity_total collapsed
     * into the primary — see the migration's own docblock.
     */
    public function test_up_skips_a_service_whose_stock_is_already_split_across_locations(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $org = Organization::factory()->equipmentRental()->create();
        $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
        $secondary = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 5]);

        DB::table('service_location_stocks')->insert([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $primary->id,
            'quantity' => 3, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('service_location_stocks')->insert([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $secondary->id,
            'quantity' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame(0, DB::table('service_units')->where('service_id', $service->id)->count());
        $this->assertSame(
            3,
            (int) DB::table('service_location_stocks')
                ->where('service_id', $service->id)->where('location_id', $primary->id)->value('quantity'),
            'the pre-existing split must be left untouched, not overwritten with quantity_total'
        );
    }

    public function test_up_skips_time_slot_services(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
        $service = Service::factory()->for($org, 'organization')->create();

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame(0, DB::table('service_units')->where('service_id', $service->id)->count());
    }

    public function test_up_skips_a_service_with_zero_quantity_total(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
        $service = Service::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'service_type' => ServiceType::ItemRental,
            'name' => 'Bez ilości',
            'quantity_total' => 0,
            'duration_minutes' => 0,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame(0, DB::table('service_units')->where('service_id', $service->id)->count());
    }

    public function test_up_skips_an_organization_with_no_primary_location_without_failing_the_batch(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $orgWithoutPrimary = Organization::factory()->equipmentRental()->create();
        $serviceWithoutPrimary = Service::factory()->itemRental()->create(['organization_id' => $orgWithoutPrimary->id, 'quantity_total' => 4]);

        $orgWithPrimary = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($orgWithPrimary, 'organization')->create(['primary_slot' => 1]);
        $serviceWithPrimary = Service::factory()->itemRental()->create(['organization_id' => $orgWithPrimary->id, 'quantity_total' => 6]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame(0, DB::table('service_units')->where('service_id', $serviceWithoutPrimary->id)->count());
        $this->assertSame(6, DB::table('service_units')->where('service_id', $serviceWithPrimary->id)->count());
    }

    /**
     * down() is a deliberate no-op — see the migration file's own docblock.
     */
    public function test_down_preserves_the_generated_units(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 5]);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
        $before = DB::table('service_units')->where('service_id', $service->id)->count();
        $this->assertSame(5, $before);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $after = DB::table('service_units')->where('service_id', $service->id)->count();
        $this->assertSame(5, $after, 'down() must preserve the generated units, not delete them');

        // Re-migrate so RefreshDatabase's teardown finds the expected state.
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }

    /**
     * The actual, unconditionally-safe way to undo this feature: rolling
     * back BOTH migrations (this one, then the schema migration underneath
     * it) drops the whole service_units table.
     */
    public function test_rolling_back_both_migrations_together_drops_the_service_units_table(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
        Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 2]);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
        $this->assertTrue(Schema::hasTable('service_units'));

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        // Dependent FK first — the order a real `migrate:rollback` always
        // applies (see DEPENDENT_ORDER_ITEM_UNIT_MIGRATION_PATH's docblock).
        $this->artisan('migrate:rollback', ['--path' => self::DEPENDENT_ORDER_ITEM_UNIT_MIGRATION_PATH])->run();
        $this->artisan('migrate:rollback', ['--path' => self::SCHEMA_MIGRATION_PATH])->run();

        $this->assertFalse(
            Schema::hasTable('service_units'),
            'rolling back both migrations together must drop the service_units table entirely'
        );

        // Re-migrate so RefreshDatabase's teardown finds the expected state.
        $this->artisan('migrate', ['--path' => self::SCHEMA_MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::DEPENDENT_ORDER_ITEM_UNIT_MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }
}
