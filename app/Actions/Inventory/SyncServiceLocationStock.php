<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\ServiceType;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use Illuminate\Support\Facades\DB;

/**
 * Materializes missing service_location_stocks anchor rows — the "Krok 2.2"
 * action plan-wdrozenia.md asks for. Two directions, called from two
 * different, unrelated places (never inside a transaction holding
 * Service::lockForUpdate(): kontrakt-dostepnosci.md Zasada 4 — INSERT IGNORE
 * on a duplicate unique key takes an S-lock, which combined with
 * lockForUpdate is exactly the deadlock generator eager materialization is
 * supposed to eliminate):
 *
 * - forService(): a specific service's "Stany magazynowe" relation manager,
 *   the first time it is opened.
 * - forLocation(): App\Observers\ServiceLocationStockObserver, the moment a
 *   NEW location is created.
 */
final class SyncServiceLocationStock
{
    /**
     * Ensures every ACTIVE location of the service's organization has a row.
     *
     * If the service has NO stock row at all yet — reachable for any
     * item_rental service created after the one-time Faza 2 backfill
     * migration ran (ServiceFactory, a vertical seeder, or the panel's
     * "Ilość w magazynie" field before this action has ever run for it;
     * see ServiceResource.php's own docblock on which of those paths are
     * left deliberately dangled) — the PRIMARY location's row is seeded
     * with the service's current `quantity_total`, exactly the rule the
     * backfill migration applied once for pre-existing services, so opening
     * this tab for the first time never shows a silent 0 for a service that
     * visibly has stock elsewhere. Every OTHER active location gets a 0 row.
     * Already-existing rows are left untouched.
     */
    public static function forService(Service $service): void
    {
        if ($service->service_type !== ServiceType::ItemRental || ! $service->organization_id) {
            return;
        }

        $locations = Location::withoutGlobalScope('organization')
            ->where('organization_id', $service->organization_id)
            ->where('is_active', true)
            ->get(['id', 'primary_slot']);

        if ($locations->isEmpty()) {
            return;
        }

        $hasAnyStockRow = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $service->id)
            ->exists();

        $primaryLocationId = $locations->firstWhere('primary_slot', 1)?->id;

        $now = now();

        $rows = $locations->map(fn (Location $location) => [
            'organization_id' => $service->organization_id,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'quantity' => (! $hasAnyStockRow && $location->id === $primaryLocationId)
                ? ($service->quantity_total ?? 0)
                : 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('service_location_stocks')->insertOrIgnore($rows);
    }

    /**
     * Ensures every item_rental service of the location's organization has a
     * (zero-quantity) row for this NEW location. Always 0 — unlike
     * forService() there is no "opening quantity" to infer here, this
     * location genuinely has none of anything yet.
     */
    public static function forLocation(Location $location): void
    {
        if (! $location->organization_id) {
            return;
        }

        $serviceIds = Service::withoutGlobalScope('organization')
            ->where('organization_id', $location->organization_id)
            ->where('service_type', ServiceType::ItemRental->value)
            ->pluck('id');

        if ($serviceIds->isEmpty()) {
            return;
        }

        $now = now();

        $rows = $serviceIds->map(fn (int $serviceId) => [
            'organization_id' => $location->organization_id,
            'service_id' => $serviceId,
            'location_id' => $location->id,
            'quantity' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('service_location_stocks')->insertOrIgnore($rows);
    }
}
