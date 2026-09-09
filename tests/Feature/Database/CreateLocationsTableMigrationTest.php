<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Executes migrate:rollback (not just a static `down()` regex) for
 * 2026_08_27_120000_create_locations_table.php — the wycofywalność
 * requirement in plan-wdrozenia.md. Runs on SQLite locally (.env.testing);
 * `deploy-production.yml` runs the Feature suite against real MySQL, which
 * is what actually exercises the composite UNIQUE constraints below — SQLite
 * enforces UNIQUE too, but not ENUM/FK/NOT NULL the way InnoDB does, so this
 * is a preliminary signal, not the final proof (see migrations.md's
 * "Rollback Safety" + plan-wdrozenia.md's "Wycofywalność" section).
 */
class CreateLocationsTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_120000_create_locations_table.php';

    /**
     * `service_location_stocks.location_id` FKs to `locations` (Faza 2,
     * 2026_08_28_090000). MySQL's InnoDB enforces that FK; SQLite does not
     * (this test's own docblock already flags that gap). A REAL
     * `migrate:rollback`/`--step=N` can never hit this: Laravel rolls back a
     * batch newest-migration-first, and this table was created a day after
     * `locations`, so it is always undone before `locations`' own down()
     * runs. Rolling back `locations` in isolation via `--path` — as this
     * test did before this fix — skips that ordering entirely and
     * reproduces an order no genuine rollback command can produce
     * (SQLSTATE[HY000] 3730 on MySQL). Mirroring the real order here, not
     * loosening the assertion, is the fix — see ci-cd-troubleshooting.md's
     * RC26 MySQL gate entry.
     */
    private const DEPENDENT_STOCK_MIGRATION_PATH = 'database/migrations/2026_08_28_090000_create_service_location_stocks_table.php';

    /**
     * `service_units.location_id` ALSO FKs to `locations` (Faza 3,
     * 2026_09_08_090000) — a second, independent dependent added a day after
     * the fix above, and itself has its OWN dependent
     * (`order_items.service_unit_id`, 2026_09_08_100000) that must be undone
     * first or MySQL refuses to drop `service_units` with the same 3730 error
     * one level removed. Same "mirror the real order" rationale as
     * DEPENDENT_STOCK_MIGRATION_PATH's own docblock — see
     * ci-cd-troubleshooting.md's RC31 MySQL gate entry.
     */
    private const DEPENDENT_ORDER_ITEM_UNIT_MIGRATION_PATH = 'database/migrations/2026_09_08_100000_add_service_unit_id_to_order_items_table.php';

    private const DEPENDENT_SERVICE_UNITS_MIGRATION_PATH = 'database/migrations/2026_09_08_090000_create_service_units_table.php';

    /**
     * `rentals.location_id`/`order_items.location_id`/`cart_items.location_id`
     * (Faza 4 krok 4.8, 2026_09_09_090000/090001/090002) EACH FK to
     * `locations` independently — a THIRD, fourth and fifth dependent, added
     * a day after the service_units chain above. Same "mirror the real
     * order" rationale: these are the NEWEST migrations in the whole chain,
     * so a real `migrate:rollback` undoes them FIRST, before even the
     * service_unit_id chain. The backfill migration underneath them
     * (2026_09_09_090003) creates no FK of its own — nothing requires
     * rolling it back before `locations` can be dropped — but it is
     * included anyway for the same realism reason, not because skipping it
     * would reproduce SQLSTATE 3730.
     */
    private const DEPENDENT_BACKFILL_LOCATION_ID_MIGRATION_PATH = 'database/migrations/2026_09_09_090003_backfill_location_id_for_open_reservations.php';

    private const DEPENDENT_CART_ITEMS_LOCATION_MIGRATION_PATH = 'database/migrations/2026_09_09_090002_add_location_id_to_cart_items_table.php';

    private const DEPENDENT_ORDER_ITEMS_LOCATION_MIGRATION_PATH = 'database/migrations/2026_09_09_090001_add_location_id_to_order_items_table.php';

    private const DEPENDENT_RENTALS_LOCATION_MIGRATION_PATH = 'database/migrations/2026_09_09_090000_add_location_id_to_rentals_table.php';

    public function test_up_creates_the_table_with_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('locations'));
        $this->assertTrue(Schema::hasColumns('locations', [
            'id', 'organization_id', 'name', 'slug', 'code',
            'street', 'building', 'postal_code', 'city',
            'latitude', 'longitude', 'phone', 'email', 'opening_hours',
            'photo', 'gallery', 'description', 'is_active', 'sort_order',
            'primary_slot', 'created_at', 'updated_at',
        ]));
    }

    public function test_organization_id_and_slug_must_be_unique_per_organization_not_globally(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        DB::table('locations')->insert($this->rowFor($orgA->id, 'siedziba', primarySlot: 1));

        // Same slug, different organization — must NOT collide (locations.md
        // explicitly requires (organization_id, slug), never bare slug).
        DB::table('locations')->insert($this->rowFor($orgB->id, 'siedziba', primarySlot: 1));

        $this->assertSame(2, DB::table('locations')->where('slug', 'siedziba')->count());
    }

    public function test_slug_must_be_unique_within_the_same_organization(): void
    {
        $org = Organization::factory()->create();
        DB::table('locations')->insert($this->rowFor($org->id, 'siedziba', primarySlot: 1));

        $this->expectException(QueryException::class);

        DB::table('locations')->insert($this->rowFor($org->id, 'siedziba', primarySlot: null, name: 'Druga'));
    }

    public function test_at_most_one_primary_location_per_organization_at_the_database_level(): void
    {
        $org = Organization::factory()->create();
        DB::table('locations')->insert($this->rowFor($org->id, 'a', primarySlot: 1));

        $this->expectException(QueryException::class);

        DB::table('locations')->insert($this->rowFor($org->id, 'b', primarySlot: 1, name: 'Druga'));
    }

    public function test_two_organizations_can_each_have_their_own_primary_location(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        DB::table('locations')->insert($this->rowFor($orgA->id, 'a', primarySlot: 1));
        DB::table('locations')->insert($this->rowFor($orgB->id, 'b', primarySlot: 1));

        $this->assertSame(2, DB::table('locations')->where('primary_slot', 1)->count());
    }

    public function test_a_non_primary_null_slot_never_collides_with_the_unique_index(): void
    {
        $org = Organization::factory()->create();
        DB::table('locations')->insert($this->rowFor($org->id, 'a', primarySlot: 1));
        DB::table('locations')->insert($this->rowFor($org->id, 'b', primarySlot: null, name: 'Druga'));
        DB::table('locations')->insert($this->rowFor($org->id, 'c', primarySlot: null, name: 'Trzecia'));

        $this->assertSame(3, DB::table('locations')->where('organization_id', $org->id)->count());
    }

    public function test_rollback_drops_the_table_and_migrating_again_recreates_it_empty(): void
    {
        $org = Organization::factory()->create();
        DB::table('locations')->insert($this->rowFor($org->id, 'a', primarySlot: 1));
        $this->assertTrue(Schema::hasTable('locations'));

        // Dependent FKs first — newest migration first, the order a real
        // `migrate:rollback` always applies (see each DEPENDENT_* constant's
        // own docblock).
        $this->artisan('migrate:rollback', ['--path' => self::DEPENDENT_BACKFILL_LOCATION_ID_MIGRATION_PATH])->run();
        $this->artisan('migrate:rollback', ['--path' => self::DEPENDENT_CART_ITEMS_LOCATION_MIGRATION_PATH])->run();
        $this->artisan('migrate:rollback', ['--path' => self::DEPENDENT_ORDER_ITEMS_LOCATION_MIGRATION_PATH])->run();
        $this->artisan('migrate:rollback', ['--path' => self::DEPENDENT_RENTALS_LOCATION_MIGRATION_PATH])->run();
        $this->artisan('migrate:rollback', ['--path' => self::DEPENDENT_ORDER_ITEM_UNIT_MIGRATION_PATH])->run();
        $this->artisan('migrate:rollback', ['--path' => self::DEPENDENT_SERVICE_UNITS_MIGRATION_PATH])->run();
        $this->artisan('migrate:rollback', ['--path' => self::DEPENDENT_STOCK_MIGRATION_PATH])->run();
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertFalse(Schema::hasTable('locations'));

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::DEPENDENT_STOCK_MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::DEPENDENT_SERVICE_UNITS_MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::DEPENDENT_ORDER_ITEM_UNIT_MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::DEPENDENT_RENTALS_LOCATION_MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::DEPENDENT_ORDER_ITEMS_LOCATION_MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::DEPENDENT_CART_ITEMS_LOCATION_MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::DEPENDENT_BACKFILL_LOCATION_ID_MIGRATION_PATH])->run();

        $this->assertTrue(Schema::hasTable('locations'));
        $this->assertSame(0, DB::table('locations')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(int $organizationId, string $slug, ?int $primarySlot, string $name = 'Siedziba'): array
    {
        return [
            'organization_id' => $organizationId,
            'name' => $name,
            'slug' => $slug,
            'primary_slot' => $primarySlot,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
