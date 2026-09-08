<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\ServiceUnitStatus;
use App\Models\Service;
use App\Models\ServiceUnit;
use Illuminate\Support\Facades\DB;

/**
 * Faza 3 krok 3.2 (app/docs/features/lokalizacje/model-danych.md,
 * kontrakt-dostepnosci.md) — keeps the Faza 2 anchor in sync with the
 * physical egzemplarze that back it:
 *
 *     service_location_stocks.quantity
 *         = COUNT(service_units WHERE service_id = S AND location_id = L
 *                                 AND status = 'available')
 *
 * Fires on every create/update/delete of a ServiceUnit and recalculates
 * WITHIN THE SAME TRANSACTION as the triggering write (DB::transaction()
 * either starts one or, if the caller is already inside one, becomes a
 * savepoint — either way the anchor update commits or rolls back atomically
 * with the unit change that caused it).
 *
 * On update, BOTH the unit's old and new (service_id, location_id) pair are
 * recalculated when either `location_id` or `status` changed — a transfer
 * or a status flip can move a unit OUT of one anchor's count and INTO
 * another's (or simply out of/into the 'available' set at the same
 * location). Recalculating only the new pair would leave the old location's
 * anchor permanently overstated by one unit it no longer has.
 *
 * Deliberately mirrors Service::recalculateQuantityTotal()'s own SUM-based
 * "recompute the whole thing", not an incremental +1/-1 — the established
 * pattern in this codebase for anchor mirrors, and immune to drift from a
 * missed edge case (bulk status change, direct DB::table() write bypassing
 * this observer, etc. always self-heals on the next unit save that touches
 * that pair).
 *
 * WARNING for anyone touching a unit's `status`/`location_id` from inside a
 * transaction that holds `Service::lockForUpdate()` (rental-availability.md,
 * kontrakt-dostepnosci.md Zasada 4): DON'T. `updated()` below re-triggers
 * `insertOrIgnore` on `service_location_stocks` whenever either field
 * changes — the exact S-lock-under-lockForUpdate deadlock generator Zasada 4
 * keeps materialization out of the lock path to avoid. Today this never
 * happens by construction: Invariant A ("egzemplarz wypożyczony pozostaje
 * available") means issue/return never change status or location_id, so
 * this observer never fires on the availability hot path at all — only on
 * created() and on a genuine transfer/status-flip from the panel, neither of
 * which ever holds that lock. If a future step (e.g. issuing/returning from
 * the panel) ever needs to flip status/location_id inside a lock-held
 * transaction, materialize the anchor FIRST via
 * App\Actions\Inventory\SyncServiceLocationStock::forService(), before
 * entering the lock — do not rely on this observer to do it safely mid-lock.
 *
 * `is_active` on service_location_stocks is DELIBERATELY left untouched by
 * this observer. It is an operator toggle for "does this location stock
 * this product AT ALL" and is not read by any logic today
 * (model-danych.md's own "Zaimplementowane w Fazie 2" section says so
 * explicitly). A branch whose units are ALL temporarily in `maintenance`
 * would have quantity=0 through this observer already — which correctly
 * communicates "zero available right now" — without also claiming the
 * location doesn't carry the product at all. Conflating "temporarily empty"
 * with "not stocked here" would be a real behavioural change for whatever
 * future code eventually reads is_active (out of this phase's scope), not a
 * neutral no-op — so it is left alone, on purpose.
 */
class ServiceUnitObserver
{
    public function created(ServiceUnit $unit): void
    {
        DB::transaction(function () use ($unit) {
            $this->recalculateAnchor($unit->organization_id, $unit->service_id, $unit->location_id);
        });
    }

    public function updated(ServiceUnit $unit): void
    {
        if (! $unit->wasChanged('location_id') && ! $unit->wasChanged('status')) {
            return;
        }

        DB::transaction(function () use ($unit) {
            $this->recalculateAnchor($unit->organization_id, $unit->service_id, $unit->location_id);

            $originalLocationId = (int) $unit->getOriginal('location_id');

            if ($unit->wasChanged('location_id') && $originalLocationId !== (int) $unit->location_id) {
                $this->recalculateAnchor($unit->organization_id, $unit->service_id, $originalLocationId);
            }
        });
    }

    public function deleted(ServiceUnit $unit): void
    {
        DB::transaction(function () use ($unit) {
            $this->recalculateAnchor($unit->organization_id, $unit->service_id, $unit->location_id);
        });
    }

    private function recalculateAnchor(int $organizationId, int $serviceId, int $locationId): void
    {
        // Materialize the anchor row if it does not exist yet (e.g. a unit
        // created for a location the "Stany magazynowe" tab has never been
        // opened for). insertOrIgnore, not an upsert-with-count, so a
        // concurrent SyncServiceLocationStock materialization never races
        // this UPDATE below into an inconsistent quantity — kontrakt-
        // dostepnosci.md Zasada 4 keeps materialization out of any lock
        // path; this observer never takes one either.
        DB::table('service_location_stocks')->insertOrIgnore([[
            'organization_id' => $organizationId,
            'service_id' => $serviceId,
            'location_id' => $locationId,
            'quantity' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        $count = ServiceUnit::withoutGlobalScope('organization')
            ->where('service_id', $serviceId)
            ->where('location_id', $locationId)
            ->where('status', ServiceUnitStatus::Available->value)
            ->count();

        DB::table('service_location_stocks')
            ->where('service_id', $serviceId)
            ->where('location_id', $locationId)
            ->update(['quantity' => $count, 'updated_at' => now()]);

        Service::withoutGlobalScope('organization')->find($serviceId)?->recalculateQuantityTotal();
    }
}
