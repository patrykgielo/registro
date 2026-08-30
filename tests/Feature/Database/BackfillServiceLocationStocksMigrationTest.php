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
 * 2026_08_28_090001_backfill_service_location_stocks_for_item_rental_services.php
 * — the wycofywalność requirement in plan-wdrozenia.md, and the specific
 * acceptance criterion the team lead asked for as an ASSERTION, not a
 * comment: "suma stanów per usługa == quantity_total sprzed migracji, dla
 * każdego tenanta."
 *
 * By the time RefreshDatabase's own initial `migrate` runs there are zero
 * item_rental services in the DB, so that first run of this migration is a
 * genuine no-op — same situation
 * BackfillPrimaryLocationForOrganizationsMigrationTest documents for its own
 * migration. Every test below rolls back first, creates its own fixtures,
 * then re-runs `migrate --path=...` to actually exercise up() against real
 * data.
 */
class BackfillServiceLocationStocksMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_28_090001_backfill_service_location_stocks_for_item_rental_services.php';

    private const SCHEMA_MIGRATION_PATH = 'database/migrations/2026_08_28_090000_create_service_location_stocks_table.php';

    public function test_up_anchors_the_pre_migration_quantity_total_to_the_primary_location(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $org = Organization::factory()->equipmentRental()->create();
        $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 7]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $row = DB::table('service_location_stocks')
            ->where('service_id', $service->id)
            ->where('location_id', $primary->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(7, (int) $row->quantity);
    }

    /**
     * The exact acceptance criterion from plan-wdrozenia.md, written as an
     * assertion: SUM(quantity) per service equals the service's OWN
     * pre-migration quantity_total — not a fixed relationship to any one
     * location's row, and independently correct for MULTIPLE tenants at
     * once (no cross-tenant bleed in the aggregation).
     */
    public function test_sum_of_stock_quantities_per_service_equals_its_pre_migration_quantity_total_for_every_tenant(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $orgA = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($orgA, 'organization')->create(['primary_slot' => 1]);
        $serviceA1 = Service::factory()->itemRental()->create(['organization_id' => $orgA->id, 'quantity_total' => 3]);
        $serviceA2 = Service::factory()->itemRental()->create(['organization_id' => $orgA->id, 'quantity_total' => 12]);

        $orgB = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($orgB, 'organization')->create(['primary_slot' => 1]);
        $serviceB1 = Service::factory()->itemRental()->create(['organization_id' => $orgB->id, 'quantity_total' => 9]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        foreach ([[$serviceA1, 3], [$serviceA2, 12], [$serviceB1, 9]] as [$service, $expectedTotal]) {
            $sum = (int) DB::table('service_location_stocks')->where('service_id', $service->id)->sum('quantity');
            $this->assertSame($expectedTotal, $sum, "service #{$service->id} stock sum must equal its pre-migration quantity_total");
        }
    }

    public function test_up_treats_a_null_quantity_total_as_zero(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $org = Organization::factory()->equipmentRental()->create();
        $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
        $service = Service::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'service_type' => ServiceType::ItemRental,
            'name' => 'Bez ilości',
            'quantity_total' => null,
            'duration_minutes' => 0,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $row = DB::table('service_location_stocks')
            ->where('service_id', $service->id)
            ->where('location_id', $primary->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->quantity);
    }

    /**
     * time_slot services never carry quantity_total in any meaningful sense
     * and must not get a stock row.
     */
    public function test_up_skips_time_slot_services(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
        $service = Service::factory()->for($org, 'organization')->create();

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame(0, DB::table('service_location_stocks')->where('service_id', $service->id)->count());
    }

    /**
     * An organization with no primary location at migration time (should
     * not happen after Faza 1's own backfill, but defensively guarded) is
     * skipped rather than crashing the whole batch — its service is simply
     * left without a stock row.
     */
    public function test_up_skips_an_organization_with_no_primary_location_without_failing_the_batch(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $orgWithoutPrimary = Organization::factory()->equipmentRental()->create();
        $serviceWithoutPrimary = Service::factory()->itemRental()->create(['organization_id' => $orgWithoutPrimary->id, 'quantity_total' => 4]);

        $orgWithPrimary = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($orgWithPrimary, 'organization')->create(['primary_slot' => 1]);
        $serviceWithPrimary = Service::factory()->itemRental()->create(['organization_id' => $orgWithPrimary->id, 'quantity_total' => 6]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame(0, DB::table('service_location_stocks')->where('service_id', $serviceWithoutPrimary->id)->count());
        $this->assertSame(6, (int) DB::table('service_location_stocks')->where('service_id', $serviceWithPrimary->id)->sum('quantity'));
    }

    /**
     * down() is a deliberate no-op — see the migration file's own docblock
     * for why (same reasoning, same precedent, as
     * BackfillPrimaryLocationForOrganizationsMigrationTest::test_down_preserves_the_backfilled_location()).
     */
    public function test_down_preserves_the_backfilled_stock_row(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 5]);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
        $before = DB::table('service_location_stocks')->where('service_id', $service->id)->count();
        $this->assertSame(1, $before);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $after = DB::table('service_location_stocks')->where('service_id', $service->id)->count();
        $this->assertSame(1, $after, 'down() must preserve the backfilled row, not delete it');

        // Re-migrate so RefreshDatabase's teardown finds the expected state.
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }

    /**
     * The actual, unconditionally-safe way to undo this feature: rolling
     * back BOTH migrations (this one, then the schema migration underneath
     * it) drops the whole service_location_stocks table — same pattern as
     * BackfillPrimaryLocationForOrganizationsMigrationTest::
     * test_rolling_back_both_migrations_together_drops_the_locations_table().
     */
    public function test_rolling_back_both_migrations_together_drops_the_service_location_stocks_table(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
        Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 2]);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
        $this->assertTrue(Schema::hasTable('service_location_stocks'));

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate:rollback', ['--path' => self::SCHEMA_MIGRATION_PATH])->run();

        $this->assertFalse(
            Schema::hasTable('service_location_stocks'),
            'rolling back both migrations together must drop the service_location_stocks table entirely'
        );

        // Re-migrate so RefreshDatabase's teardown finds the expected state.
        $this->artisan('migrate', ['--path' => self::SCHEMA_MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }
}
