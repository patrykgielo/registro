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
            $this->materializePlaceholdersForFirstUnit($unit);
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

    /**
     * ClickUp 123k99cvc54: an admin who typed "5" into ServiceResource's
     * "Ilość w magazynie" field (routed by RouteQuantityFieldToPrimaryLocationStock
     * into service_location_stocks.quantity, with NO ServiceUnit rows behind
     * it) and later adds their first physical egzemplarz was silently
     * losing 4 of those 5 the moment recalculateAnchor()'s COUNT() ran —
     * the anchor dropped straight to 1 (the single unit just created),
     * clobbering a number that came from a completely different writer.
     *
     * Fires ONLY for the genuinely first-ever ServiceUnit of a (service,
     * location) pair (checked by TOTAL count, any status — this is about
     * "was this pair ever unit-tracked before", not "was it ever
     * available") — and ONLY when a service_location_stocks row already
     * existed for that pair with a quantity higher than what the unit(s)
     * created so far account for. Backfills the difference as UNNUMBERED
     * placeholder units (identifier/inventory_number both null — Faza 3's
     * own product decision that a unit number is optional, model-danych.md)
     * so the available count comes out exactly where it was before this
     * unit existed, regardless of whether the triggering unit itself is
     * Available (counts toward the total already) or was created directly
     * in maintenance/retired (does not — the full previous quantity is
     * then backfilled as placeholders, none of which is this one).
     *
     * No stock row at all (a service that has never been routed through the
     * single-quantity field, or a location that has never carried this
     * service) means there is nothing to preserve — recalculateAnchor()
     * below materializes a fresh 0 row and counts up from there, unchanged
     * from before this fix.
     *
     * Deliberately outside any Service::lockForUpdate() transaction — see
     * this class's own docblock ("WARNING for anyone touching a unit's
     * status/location_id..."): a plain INSERT here, same discipline as
     * recalculateAnchor()'s own insertOrIgnore, kontrakt-dostepnosci.md
     * Zasada 4.
     *
     * **Concurrency (code review 2026-09-19, ClickUp 123k99cvc54 follow-up):**
     * everything below the anchor-row `lockForUpdate()` is a locking read on
     * purpose. Two units created near-simultaneously for the SAME (service,
     * location) pair could otherwise BOTH see `totalUnitsAtPair === 1` and
     * BOTH independently backfill the full previous quantity — inflating the
     * unit count (and, transiently, the anchor) well beyond what should
     * exist. Locking the anchor row FIRST serializes the two transactions on
     * that row; the second one blocks until the first commits. Per
     * rental-availability.md Zasada 3 ("REPEATABLE READ nie resetuje
     * snapshotu dla zwykłych odczytów — dopiero blokujące zapytania
     * zliczające zamykają wyścig"), simply WAITING on an unrelated lock does
     * NOT refresh a plain SELECT's snapshot — the total-units COUNT below is
     * therefore ALSO `lockForUpdate()`, not just the anchor row read, so the
     * second transaction (once unblocked) counts the FIRST transaction's now
     * -committed unit and correctly sees `totalUnitsAtPair > 1`, bailing out
     * without a second backfill round. The anchor row is guaranteed to exist
     * before the lock is taken (`insertOrIgnore`, itself outside any lock —
     * same Zasada 4 discipline as recalculateAnchor()'s own) — `SELECT ...
     * FOR UPDATE` on a row that does not exist yet takes no lock at all,
     * which would leave this exact race open for a pair whose anchor has
     * never been touched before.
     *
     * **Precondition for this race, verified not assumed
     * (tests/Concurrency/ServiceUnitFirstUnitRaceTest.php's own docblock has
     * the full proof):** a genuinely BARE, unwrapped `ServiceUnit::create()`
     * (Filament's actual default — no panel here enables
     * `->databaseTransactions()`) auto-commits the unit's own INSERT before
     * this method's transaction even starts, which makes two SEPARATE such
     * calls mathematically unable to both miss each other (each process's
     * own insert always precedes, and is therefore always visible to, that
     * SAME process's own count). The race requires a caller with an AMBIENT
     * transaction already open around the whole create — e.g. a bulk/batch
     * unit-creation action, or a future `->databaseTransactions(true)` panel
     * — both legitimate, undramatic ways to reach it, and exactly the
     * calling convention this class's own top docblock already documents
     * supporting ("either starts one or ... becomes a savepoint"). The fix
     * below is correct and required for that case regardless of whether
     * today's specific panel wiring happens to also reach it.
     *
     * **Falsification result, measured, not predicted:** with the
     * transaction-wrapped scenario above reproduced by
     * ServiceUnitFirstUnitRaceTest.php, reverting this lock did NOT surface
     * as silent double-backfill — it surfaced as a REAL
     * `SQLSTATE[40001]: 1213 Deadlock found` on the second probe's own
     * `insertOrIgnore` into `service_location_stocks` (two concurrent
     * `insertOrIgnore`s against the SAME unique key take conflicting
     * S-locks, kontrakt-dostepnosci.md Zasada 4 — a DIFFERENT mechanism from
     * the AB-BA lock-order risk discussed below, both landing on the same
     * "InnoDB refuses rather than corrupts" outcome). The test still fails
     * either way (an `error` status is not `ok`) — this is the safe-failure
     * precedent rental-availability.md's own "Realny deadlock InnoDB"
     * section already established for a different pair of paths: do not
     * assume a concurrency bug's symptom will always be silent corruption.
     *
     * Lock ORDER evaluated, not just the lock itself: cart/checkout paths
     * (CartService, RentalAvailabilityService) lock `services` FIRST, then
     * read `service_location_stocks` capacity inside that lock
     * (kontrakt-dostepnosci.md, "Po dodaniu kotwicy"). `recalculateAnchor()`
     * below — UNCHANGED by this fix, already true before it — does the
     * OPPOSITE order: `service_location_stocks` (this lock, then its own
     * UPDATE) THEN `services` (via `recalculateQuantityTotal()`'s raw
     * UPDATE). This is a genuine, PRE-EXISTING AB-BA risk between an admin
     * editing "Egzemplarze" and a customer checking out the SAME service at
     * the SAME location concurrently — this fix does not introduce it (the
     * anchor row was already being locked, implicitly, by
     * `recalculateAnchor()`'s own `UPDATE ... WHERE service_id = ? AND
     * location_id = ?` in the exact same relative position; making the
     * acquisition explicit and earlier does not change which resource is
     * locked first relative to `services`) and does not worsen it. Left
     * unfixed here deliberately: closing it would mean reordering
     * `recalculateAnchor()` to lock `services` first on EVERY unit
     * create/update/delete, which touches the customer checkout hot path
     * this ticket is not asking to touch, is not provably safe without its
     * own concurrency harness, and was never observed here — if MySQL's
     * deadlock detector ever does catch this cycle, the losing transaction
     * gets `SQLSTATE[40001]: 1213 Deadlock found` (the same safe-failure
     * mode already documented for a different pair of paths in
     * rental-availability.md's "Realny deadlock InnoDB" section), not silent
     * corruption.
     */
    private function materializePlaceholdersForFirstUnit(ServiceUnit $unit): void
    {
        DB::table('service_location_stocks')->insertOrIgnore([[
            'organization_id' => $unit->organization_id,
            'service_id' => $unit->service_id,
            'location_id' => $unit->location_id,
            'quantity' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        $lockedRow = DB::table('service_location_stocks')
            ->where('organization_id', $unit->organization_id)
            ->where('service_id', $unit->service_id)
            ->where('location_id', $unit->location_id)
            ->lockForUpdate()
            ->first();

        $totalUnitsAtPair = ServiceUnit::withoutGlobalScope('organization')
            ->where('service_id', $unit->service_id)
            ->where('location_id', $unit->location_id)
            ->lockForUpdate()
            ->count();

        if ($totalUnitsAtPair !== 1) {
            // Not the first unit ever registered for this pair — either an
            // earlier unit already reconciled the anchor with reality, or a
            // concurrent transaction (see docblock above) already claimed
            // "first" and has since committed.
            return;
        }

        $previousQuantity = (int) ($lockedRow->quantity ?? 0);

        if ($previousQuantity <= 0) {
            return;
        }

        // $unit->status can be null here despite the column's NOT NULL
        // DEFAULT 'available' (create_service_units_table.php): Eloquent's
        // create() only merges the attributes it was GIVEN plus the new
        // primary key — it never round-trips the DB's own default back into
        // the in-memory model unless something explicitly refreshes it. A
        // caller that omits 'status' entirely (relying on the column
        // default, same as ServiceUnitObserverTest's own fixtures) would
        // otherwise crash here. Falls back to what the DB default actually
        // means, not an arbitrary guess.
        $status = $unit->status ?? ServiceUnitStatus::Available;
        $accountedFor = $status->countsTowardStock() ? 1 : 0;
        $missing = $previousQuantity - $accountedFor;

        if ($missing <= 0) {
            return;
        }

        $now = now();

        $placeholders = array_fill(0, $missing, [
            'organization_id' => $unit->organization_id,
            'service_id' => $unit->service_id,
            'location_id' => $unit->location_id,
            'identifier' => null,
            'inventory_number' => null,
            'status' => ServiceUnitStatus::Available->value,
            'acquired_at' => null,
            'notes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('service_units')->insert($placeholders);
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
