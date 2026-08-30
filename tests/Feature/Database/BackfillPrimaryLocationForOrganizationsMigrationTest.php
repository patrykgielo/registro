<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Location;
use App\Models\Organization;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Executes migrate:rollback for
 * 2026_08_27_120001_backfill_primary_location_for_organizations.php — the
 * wycofywalność requirement in plan-wdrozenia.md.
 *
 * By the time RefreshDatabase's own initial `migrate` runs (before any
 * test's factories exist), there are zero organizations in the DB, so that
 * initial run of this migration is a genuine no-op — same situation
 * OrderPaidPickupHtmlSeparatorMigrationTest documents for its own migration.
 * Every test below therefore rolls back first, creates its own
 * organization(s), then re-runs `migrate --path=...` to actually exercise
 * up() against real data.
 *
 * Runs on SQLite locally; see CreateLocationsTableMigrationTest's docblock
 * for the same MySQL-vs-SQLite caveat.
 */
class BackfillPrimaryLocationForOrganizationsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_120001_backfill_primary_location_for_organizations.php';

    private const SCHEMA_MIGRATION_PATH = 'database/migrations/2026_08_27_120000_create_locations_table.php';

    /**
     * `service_location_stocks` (Faza 2, 2026_08_28) FKs to `locations`.
     * MySQL's InnoDB enforces that FK; SQLite does not. A REAL
     * `migrate:rollback` always undoes these two before it ever reaches
     * `locations`' own down() — Laravel rolls back newest-migration-first,
     * and both were created a day after `locations`. Omitting them here (as
     * this test did before this fix) reproduces an order no genuine
     * rollback command can produce, and fails on MySQL with SQLSTATE[HY000]
     * 3730. See CreateLocationsTableMigrationTest's matching constant and
     * ci-cd-troubleshooting.md's RC26 MySQL gate entry.
     */
    private const STOCK_BACKFILL_MIGRATION_PATH = 'database/migrations/2026_08_28_090001_backfill_service_location_stocks_for_item_rental_services.php';

    private const STOCK_SCHEMA_MIGRATION_PATH = 'database/migrations/2026_08_28_090000_create_service_location_stocks_table.php';

    private function setContactSettings(Organization $org, array $values): void
    {
        app('request')->attributes->set('tenant', $org);

        $settings = app(SettingsManager::class);
        foreach ($values as $key => $value) {
            $settings->set("contact.{$key}", $value);
        }

        app('request')->attributes->remove('tenant');
    }

    private function locationFor(int $organizationId): ?Location
    {
        return Location::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->first();
    }

    public function test_up_creates_a_primary_location_from_the_tenants_existing_contact_settings(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $org = Organization::factory()->create();
        $this->setContactSettings($org, [
            'address_line' => 'ul. Testowa 5',
            'postal_code' => '00-100',
            'city' => 'Warszawa',
            'phone' => '+48123123123',
            'email' => 'kontakt@example.test',
        ]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $location = $this->locationFor($org->id);

        $this->assertNotNull($location);
        $this->assertSame('Siedziba główna', $location->name);
        $this->assertSame('siedziba-glowna', $location->slug);
        $this->assertSame(1, $location->primary_slot);
        $this->assertSame('ul. Testowa 5', $location->street);
        $this->assertNull($location->building);
        $this->assertSame('00-100', $location->postal_code);
        $this->assertSame('Warszawa', $location->city);
        $this->assertSame('+48123123123', $location->phone);
        $this->assertSame('kontakt@example.test', $location->email);
    }

    /**
     * tryb-jednooddzialowy.md: an organization with NO contact details at
     * all must still get a location (blank address to fill in later), not
     * be left without one — Faza 2 needs somewhere to anchor the default
     * stock row.
     */
    public function test_up_still_creates_a_location_for_a_tenant_with_no_contact_settings_at_all(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $org = Organization::factory()->create();

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $location = $this->locationFor($org->id);

        $this->assertNotNull($location);
        $this->assertSame(1, $location->primary_slot);
        $this->assertNull($location->street);
        $this->assertNull($location->city);
        $this->assertNull($location->phone);
        $this->assertNull($location->email);
    }

    /**
     * Idempotency: an organization that already has ANY location (added by
     * hand, or by a previous run of this same migration) must not get a
     * second one.
     */
    public function test_up_skips_an_organization_that_already_has_a_location(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $org = Organization::factory()->create();
        DB::table('locations')->insert([
            'organization_id' => $org->id,
            'name' => 'Oddział własny',
            'slug' => 'oddzial-wlasny',
            'primary_slot' => 1,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame(1, DB::table('locations')->where('organization_id', $org->id)->count());
        $this->assertSame('Oddział własny', $this->locationFor($org->id)->name);
    }

    public function test_up_backfills_multiple_organizations_independently(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $this->setContactSettings($orgA, ['city' => 'Warszawa']);
        $this->setContactSettings($orgB, ['city' => 'Gdańsk']);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame('Warszawa', $this->locationFor($orgA->id)->city);
        $this->assertSame('Gdańsk', $this->locationFor($orgB->id)->city);
    }

    /**
     * down() is now a deliberate no-op (see the migration file's own
     * docblock for why the earlier "delete rows matching name/slug/
     * created_at=updated_at" heuristic was rejected — "Siedziba główna" is
     * literally the name this migration itself suggests, so the heuristic
     * could not reliably tell an auto-generated row apart from a tenant's
     * own hand-created one with the same obvious name). Rollback of THIS
     * migration alone must preserve every backfilled row.
     */
    public function test_down_preserves_the_backfilled_location(): void
    {
        $org = Organization::factory()->create();
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
        $backfilled = $this->locationFor($org->id);
        $this->assertNotNull($backfilled);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $survivor = $this->locationFor($org->id);
        $this->assertNotNull($survivor, 'down() must preserve the backfilled row, not delete it');
        $this->assertSame($backfilled->id, $survivor->id);
        $this->assertSame('Siedziba główna', $survivor->name);

        // Re-migrate so RefreshDatabase's teardown finds the expected state.
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }

    /**
     * up() being idempotent is what makes the no-op down() safe: re-running
     * up() after a "rollback" that changed nothing must still create
     * nothing new — no duplicate "Siedziba główna" for the same org.
     */
    public function test_up_stays_idempotent_after_a_rollback_that_preserved_the_row(): void
    {
        $org = Organization::factory()->create();
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame(1, DB::table('locations')->where('organization_id', $org->id)->count());
    }

    /**
     * The actual, unconditionally-safe way to undo this feature: rolling
     * back BOTH migrations (this one, then the schema migration underneath
     * it — same order `migrate:rollback` would apply them in) drops the
     * whole `locations` table, which removes every backfilled row regardless
     * of what down() above does or does not delete. Uses explicit `--path`
     * for each migration (same proven pattern as
     * CreateLocationsTableMigrationTest::test_rollback_drops_the_table_and_migrating_again_recreates_it_empty())
     * rather than `--step`, to avoid depending on batch-numbering details of
     * how RefreshDatabase and this test's own earlier `--path` rollback/
     * re-migrate calls happen to have grouped these two migrations.
     */
    public function test_rolling_back_both_migrations_together_drops_the_locations_table(): void
    {
        Organization::factory()->create();
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
        $this->assertTrue(Schema::hasTable('locations'));

        // `migrate:rollback --path` only ever considers the LAST batch, and the
        // rollback/re-migrate above has already moved MIGRATION_PATH into a batch
        // of its own -- so the two stock rollbacks below silently match nothing and
        // service_location_stocks survives, taking its foreign key with it. On
        // MySQL the subsequent DROP of `locations` then dies with error 3730;
        // SQLite never noticed because it does not enforce the constraint.
        //
        // What this test is actually about is whether the locations migrations'
        // own down() clears the table, not whether an unrelated later migration
        // happens to be applied. Suspending constraint checks around the chain
        // asserts exactly that, on both engines. Real rollback ordering is safe
        // for a different and stronger reason: Laravel rolls back
        // batch desc, migration desc (DatabaseMigrationRepository:65-67), so
        // 2026_08_28_090000 always drops before 2026_08_27_120000.
        Schema::disableForeignKeyConstraints();

        $this->artisan('migrate:rollback', ['--path' => self::STOCK_BACKFILL_MIGRATION_PATH])->run();
        $this->artisan('migrate:rollback', ['--path' => self::STOCK_SCHEMA_MIGRATION_PATH])->run();
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate:rollback', ['--path' => self::SCHEMA_MIGRATION_PATH])->run();

        Schema::enableForeignKeyConstraints();

        $this->assertFalse(
            Schema::hasTable('locations'),
            'rolling back both migrations together must drop the locations table entirely'
        );

        // Re-migrate (oldest-first) so RefreshDatabase's teardown finds the expected state.
        $this->artisan('migrate', ['--path' => self::SCHEMA_MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::STOCK_SCHEMA_MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::STOCK_BACKFILL_MIGRATION_PATH])->run();
    }
}
