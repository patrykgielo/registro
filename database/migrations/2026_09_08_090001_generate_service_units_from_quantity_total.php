<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Faza 3 krok 3.3 (plan-wdrozenia.md) — the generator. Every item_rental
     * service's `quantity_total`, as it stands the moment this migration
     * runs, becomes N ServiceUnit rows WITHOUT an `identifier` (product
     * decision: the mark is optional, filled in at first hand-over — krok
     * 3.6), all placed in the organization's PRIMARY location — same
     * "oddział domyślny" concept 2026_08_27_120001's own backfill anchors
     * Faza 2's stock to.
     *
     * Deliberately uses DB::table(), not Eloquent — same reasoning as the
     * two Faza 1/2 backfills underneath it: a migration should not depend on
     * application model state (casts, global scopes, observers) that can
     * change shape independently of the schema this migration is pinned to.
     * Concretely, this means App\Observers\ServiceUnitObserver never fires
     * for these inserts — see the manual anchor fix-up below, which performs
     * exactly what that observer would have computed.
     *
     * Idempotency guard (`$alreadyHasUnits`): UNIQUE(organization_id,
     * identifier) does NOT protect against a re-run here, because every row
     * this migration writes has `identifier = NULL` and NULL never collides
     * with NULL in a unique index (this table's own defining property — see
     * the schema migration's docblock). The guard is therefore at the
     * service level: a service that already has ANY service_units row —
     * whether from an earlier run of this migration or a manual addition
     * through a future panel — is skipped entirely, never topped up.
     *
     * Safety guard (`$hasStockOutsidePrimary`): a service whose stock is
     * ALREADY split across more than the primary location (a
     * `multi_location_stock` tenant with a real, deliberate distribution —
     * `service_location_stocks` rows with quantity > 0 outside the primary)
     * is skipped, not force-collapsed into "all quantity_total units live in
     * the primary location". Blindly generating here would silently
     * overwrite the primary's own anchor with the WHOLE quantity_total
     * (inflating it past its real per-location share) while leaving the
     * other locations' stock rows referencing units that were never
     * actually created there. No real tenant hits this today — every tenant
     * that has ever had a `service_location_stocks` row runs with
     * `multi_location_stock` OFF (single-active-location, 8/8 real tenants,
     * per Faza 2's own measurement) — but the guard exists so a future
     * split tenant's real distribution is never corrupted by a re-run of
     * this migration. Redistributing units across a genuine multi-location
     * split is explicitly left to Faza 3's own later steps (krok 3.4/3.5
     * panel tooling), not this generator.
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
            ->where('quantity_total', '>', 0)
            ->orderBy('id')
            ->select(['id', 'organization_id', 'quantity_total'])
            ->chunkById(200, function ($services) use ($primaryLocationIdByOrganizationId, $now) {
                foreach ($services as $service) {
                    $primaryLocationId = $primaryLocationIdByOrganizationId[$service->organization_id] ?? null;

                    if ($primaryLocationId === null) {
                        // Defensive skip, not expected in practice — same
                        // rationale as 2026_08_28_090001's identical guard.
                        continue;
                    }

                    $alreadyHasUnits = DB::table('service_units')
                        ->where('service_id', $service->id)
                        ->exists();

                    if ($alreadyHasUnits) {
                        continue;
                    }

                    $hasStockOutsidePrimary = DB::table('service_location_stocks')
                        ->where('service_id', $service->id)
                        ->where('location_id', '!=', $primaryLocationId)
                        ->where('quantity', '>', 0)
                        ->exists();

                    if ($hasStockOutsidePrimary) {
                        continue;
                    }

                    $quantity = (int) $service->quantity_total;

                    $rows = [];
                    for ($i = 0; $i < $quantity; $i++) {
                        $rows[] = [
                            'organization_id' => $service->organization_id,
                            'service_id' => $service->id,
                            'location_id' => $primaryLocationId,
                            'identifier' => null,
                            'inventory_number' => null,
                            'status' => 'available',
                            'acquired_at' => null,
                            'notes' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if ($rows === []) {
                        continue;
                    }

                    DB::table('service_units')->insert($rows);

                    // Manual anchor fix-up — what ServiceUnitObserver would
                    // have computed had these gone through Eloquent.
                    // insertOrIgnore first: a service whose Faza 2 stock row
                    // was never materialized for the primary location (e.g.
                    // a service created after that backfill ran, via
                    // ServiceFactory/a vertical seeder — model-danych.md's
                    // own documented gap) still gets one here.
                    DB::table('service_location_stocks')->insertOrIgnore([[
                        'organization_id' => $service->organization_id,
                        'service_id' => $service->id,
                        'location_id' => $primaryLocationId,
                        'quantity' => 0,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]]);

                    DB::table('service_location_stocks')
                        ->where('service_id', $service->id)
                        ->where('location_id', $primaryLocationId)
                        ->update(['quantity' => $quantity, 'updated_at' => $now]);
                }
            });
    }

    /**
     * Deliberate no-op — same rationale and precedent as
     * 2026_08_28_090001_backfill_service_location_stocks_for_item_rental_services.php's
     * down(): a generated ServiceUnit row is ordinary, correct data (a unit
     * an admin could equally well have added by hand through a future panel
     * with no mark yet), and no column combination distinguishes the two.
     * Rolling back on a guess would delete real inventory.
     *
     * The actual, unconditionally-safe way to undo this feature: roll back
     * BOTH this migration AND the schema migration underneath it
     * (2026_09_08_090000_create_service_units_table.php), which DROPs the
     * whole table — see GenerateServiceUnitsFromQuantityTotalMigrationTest
     * for that path pinned as an executed test.
     */
    public function down(): void
    {
        Log::info(
            'generate_service_units_from_quantity_total: down() is a deliberate no-op — '.
            'generated units are preserved on rollback, see migration file docblock.'
        );
    }
};
