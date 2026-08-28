<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Every item_rental service's ENTIRE `quantity_total`, as it stands the
     * moment this migration runs, becomes the opening stock of its
     * organization's PRIMARY location (tryb-jednooddzialowy.md — this is the
     * only location every tenant is guaranteed to have after Faza 1's own
     * backfill). Every other active location of the same organization gets
     * no row here at all (App\Actions\Inventory\SyncServiceLocationStock
     * materializes zero-quantity anchors for them lazily, the first time
     * anyone opens that service's "Stany magazynowe" tab or adds a new
     * location) — so SUM(quantity) per service equals its pre-migration
     * quantity_total exactly, satisfying the invariant
     * BackfillServiceLocationStocksMigrationTest asserts.
     *
     * Deliberately uses DB::table(), not Eloquent — same reasoning as
     * 2026_08_27_120001_backfill_primary_location_for_organizations.php:
     * a migration should not depend on application model state (casts,
     * global scopes, observers) that can change shape independently of the
     * schema this migration is pinned to.
     */
    public function up(): void
    {
        $now = now();

        $primaryLocationIdByOrganizationId = DB::table('locations')
            ->where('primary_slot', 1)
            ->pluck('id', 'organization_id');

        DB::table('services')
            ->where('service_type', 'item_rental')
            ->whereNotNull('organization_id')
            ->orderBy('id')
            ->select(['id', 'organization_id', 'quantity_total'])
            ->chunkById(200, function ($services) use ($primaryLocationIdByOrganizationId, $now) {
                $rows = [];

                foreach ($services as $service) {
                    $primaryLocationId = $primaryLocationIdByOrganizationId[$service->organization_id] ?? null;

                    if ($primaryLocationId === null) {
                        // Defensive skip, not expected in practice: every
                        // organization has had a primary location since Faza
                        // 1's own backfill ran directly beneath this one in
                        // the migration log. An organization whose primary
                        // was demoted and not yet re-promoted at the exact
                        // moment this runs would land here — nothing to
                        // anchor its services' stock to, so it is left for a
                        // manual `SyncServiceLocationStock` run once it has
                        // a primary again, rather than crashing the whole
                        // batch over one organization's transient state.
                        continue;
                    }

                    $rows[] = [
                        'organization_id' => $service->organization_id,
                        'service_id' => $service->id,
                        'location_id' => $primaryLocationId,
                        'quantity' => $service->quantity_total ?? 0,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    // insertOrIgnore, not insert: idempotent against a
                    // re-run after the deliberate no-op down() below, same
                    // guarantee the schema migration's UNIQUE(service_id,
                    // location_id) gives 2.1's own backfill for locations.
                    DB::table('service_location_stocks')->insertOrIgnore($rows);
                }
            });
    }

    /**
     * Deliberate no-op, same rationale and same precedent as
     * 2026_08_27_120001_backfill_primary_location_for_organizations.php's
     * down(): a backfilled service_location_stocks row is ordinary, correct
     * data — a quantity an admin could equally well have typed by hand into
     * a location that happens to be primary — and no column combination on
     * this table can tell those two cases apart. Rolling back on a guess
     * would delete real stock data.
     *
     * The actual, unconditionally-safe way to undo this feature is rolling
     * back BOTH migrations together (this one, then
     * 2026_08_28_090000_create_service_location_stocks_table.php underneath
     * it), which DROPs the whole table — see
     * BackfillServiceLocationStocksMigrationTest for that path pinned as an
     * executed test, mirroring
     * BackfillPrimaryLocationForOrganizationsMigrationTest::
     * test_rolling_back_both_migrations_together_drops_the_locations_table().
     */
    public function down(): void
    {
        Log::info(
            'backfill_service_location_stocks_for_item_rental_services: down() is a deliberate no-op — '.
            'backfilled stock rows are preserved on rollback, see migration file docblock.'
        );
    }
};
