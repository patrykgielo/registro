<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\ServiceUnitStatus;
use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ServiceUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    private const OFFLINE_PAYMENT_METHODS = ['cash', 'bank_transfer'];

    /**
     * Keys of one entry in state_histories.custom_properties['mismatches'],
     * written by completeReturn() below and read back by
     * OrderProtocolPdfService::unitMismatchesByItemId() to print "handed out X,
     * returned Y" on the signed return protocol. They are constants because the
     * reader resolves them with `?? null`: renaming a literal on one side alone
     * would not fail, it would silently print an EMPTY unit number on a legal
     * document. Keep both sides referencing these.
     */
    public const MISMATCH_HANDED_OUT_LABEL = 'handed_out_label';

    public const MISMATCH_RETURNED_LABEL = 'returned_label';

    /**
     * Cancels an order by transitioning its status via the state machine.
     *
     * Supports: pending_payment, paid, confirmed, in_progress.
     * in_progress cancellation is exceptional (e.g. tenant offboarding) and logged.
     *
     * @param  bool  $notify  Whether to send the customer-facing cancellation email.
     *                        Set to false for internal-compensation scenarios (e.g. P24
     *                        registration failure right after checkout) where the
     *                        customer never actually saw a completed order and a
     *                        "your order was cancelled" email would just be confusing
     *                        noise ahead of an immediate, successful retry.
     *
     * @throws \LogicException when order status does not allow cancellation
     */
    public function cancel(Order $order, string $reason, bool $notify = true): Order
    {
        if (! in_array($order->status, ['pending_payment', 'paid', 'confirmed', 'in_progress'], strict: true)) {
            throw new \LogicException("Zamówienie o statusie '{$order->status}' nie może zostać anulowane");
        }

        if ($order->status === 'in_progress') {
            Log::warning('OrderService::cancel: forcing cancellation of in_progress order', [
                'order_id' => $order->id,
                'reason' => $reason,
            ]);
        }

        $order->notifyOnCancel = $notify;

        $order->status()->transitionTo('cancelled', ['reason' => $reason]);

        $order->update(['cancelled_at' => now()]);

        return $order;
    }

    /**
     * Records a manually-collected offline payment (cash / bank transfer at
     * pickup) and transitions the order pending_payment -> paid — the exact
     * same legal transition the P24 webhook uses, so downstream logic
     * (reconciliation guard, deposit workflow, protocols) needs no changes.
     *
     * Mirrors Przelewy24Service::handleWebhook(): lock the row, re-check
     * status under the lock (defends against a double-click / concurrent
     * "odnotuj wpłatę" submission), create the Payment audit row, transition,
     * stamp paid_at — all inside one transaction. OrderPaid is dispatched
     * AFTER the transaction commits (not inside it, unlike
     * Przelewy24Service's pre-existing pattern — see notifications.md on
     * notify()-inside-DB::transaction()) so a rollback can never leave a
     * "your order was paid" email in flight for a payment that didn't stick.
     *
     * $amount is staff-entered and NOT required to equal $order->total_amount
     * — we don't support partial payments (no instalments), but we DO support
     * a deliberate discount given in person at pickup, which is a real thing
     * in this business. A mismatch must therefore be POSSIBLE but never
     * ACCIDENTAL: any amount that doesn't equal the order total requires
     * $amountMismatchConfirmed = true AND a non-empty $notes explaining why —
     * enforced here, not only in the Filament form, so no caller can silently
     * mark an order paid for the wrong amount with nothing downstream
     * (deposit workflow, protocols, state machine) ever re-checking it.
     *
     * @throws \InvalidArgumentException when $method is invalid, or the amount doesn't
     *                                   match the order total and the mismatch wasn't
     *                                   explicitly confirmed with a reason
     * @throws \LogicException when the order is not awaiting payment
     */
    public function recordOfflinePayment(
        Order $order,
        float $amount,
        string $method,
        ?string $notes,
        int $recordedByUserId,
        bool $amountMismatchConfirmed = false,
    ): Order {
        if (! in_array($method, self::OFFLINE_PAYMENT_METHODS, strict: true)) {
            throw new \InvalidArgumentException("Nieprawidłowa metoda rozliczenia: '{$method}'.");
        }

        $amountCents = (int) round($amount * 100);
        $expectedCents = (int) round(((float) $order->total_amount) * 100);

        if ($amountCents !== $expectedCents) {
            if (! $amountMismatchConfirmed) {
                throw new \InvalidArgumentException(sprintf(
                    'Kwota (%s zł) różni się od sumy zamówienia (%s zł). Jeśli to zamierzone (np. rabat przy odbiorze), potwierdź rozbieżność.',
                    number_format($amount, 2, ',', ' '),
                    number_format((float) $order->total_amount, 2, ',', ' '),
                ));
            }

            if ($notes === null || trim($notes) === '') {
                throw new \InvalidArgumentException('Podaj powód rozbieżności kwoty w notatce.');
            }
        }

        $order = DB::transaction(function () use ($order, $amount, $method, $notes, $recordedByUserId): Order {
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending_payment') {
                throw new \LogicException("Zamówienie o statusie '{$locked->status}' nie oczekuje na płatność.");
            }

            Payment::create([
                'order_id' => $locked->id,
                'organization_id' => $locked->organization_id,
                'method' => $method,
                'amount' => (int) round($amount * 100),
                'currency' => $locked->currency,
                'status' => 'success',
                'recorded_by' => $recordedByUserId,
                'notes' => $notes,
                'verified_at' => now(),
            ]);

            $locked->status()->transitionTo('paid');
            $locked->update(['paid_at' => now()]);

            return $locked;
        });

        event(new OrderPaid($order));

        return $order;
    }

    /**
     * Faza 3 krok 3.6 — assigns an (optional) physical ServiceUnit to each
     * order item being handed out, then transitions confirmed -> in_progress
     * ("Wydano klientowi"). All FOUR Filament call sites (OrderResource row
     * action, OrderResource table `mark_in_progress`, EditOrder header
     * action — see plan-wdrozenia.md's own table of measured line numbers)
     * MUST go through this single method: divergent validation between them
     * would silently give staff different rules depending on which button
     * they clicked, which is the exact failure mode the task write-up warns
     * about.
     *
     * $unitAssignments: array<int order_item_id, int|null service_unit_id>.
     * An item omitted from the array, or explicitly mapped to null, is left
     * unassigned — assignment is entirely optional (requirement #2: a tenant
     * that doesn't track units must be able to hand over exactly as before,
     * with zero extra clicks).
     *
     * Invariant A (kontrakt-dostepnosci.md Zasada 5 / rental-availability.md
     * §5): assigning a unit here NEVER touches ServiceUnit::status or
     * ::location_id. A unit legitimately stays 'available' while out with a
     * customer — occupancy lives only in order_items/rentals. This method
     * only ever writes OrderItem::service_unit_id.
     *
     * Conflict checking happens BOTH against already-committed order items
     * (OrderItem::scopeAssignedToUnitOverlapping()) AND against assignments
     * made earlier in THIS SAME batch — mirroring CartService::
     * convertToOrder()'s greedy per-batch demand accounting
     * (rental-availability.md Zasada 7): two order items in the SAME
     * submission could otherwise both claim the same physical unit for
     * overlapping dates without either one being visible to the other's
     * database check.
     *
     * $unitIdentifiers: array<int order_item_id, string|null> — plan-
     * wdrozenia.md krok 3.6: "Jeśli wybrana sztuka nie ma jeszcze numeru —
     * pracownik wpisuje go na miejscu". Applied ONLY when the resolved unit
     * currently has no identifier at all — this never overwrites an
     * existing one, even if a stale form submission somehow carries a value
     * for an already-numbered unit (defense-in-depth matching
     * ServiceUnitAssignmentForms's own visibility guard, not a substitute
     * for it).
     *
     * @param  array<int, int|null>  $unitAssignments
     * @param  array<int, string|null>  $unitIdentifiers
     *
     * @throws \InvalidArgumentException on a unit that doesn't belong to the
     *                                   item's service/tenant, isn't
     *                                   'available', overlaps another
     *                                   active handover of the SAME unit, or
     *                                   a submitted identifier that's
     *                                   already taken by another unit of the
     *                                   same tenant
     * @throws \LogicException when the order isn't 'confirmed'
     */
    public function handOver(Order $order, array $unitAssignments, array $unitIdentifiers = []): Order
    {
        return DB::transaction(function () use ($order, $unitAssignments, $unitIdentifiers): Order {
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'confirmed') {
                throw new \LogicException("Zamówienie o statusie '{$locked->status}' nie może zostać wydane.");
            }

            $items = $locked->items()->get()->keyBy('id');

            /** @var array<int, array<int, array{start: \Illuminate\Support\Carbon, end: \Illuminate\Support\Carbon}>> $claimedInThisBatch */
            $claimedInThisBatch = [];

            foreach (self::sortAssignmentsByUnitId($unitAssignments) as $orderItemId => $unitId) {
                if ($unitId === null) {
                    continue;
                }

                $item = $items->get((int) $orderItemId);

                if ($item === null) {
                    throw new \InvalidArgumentException("Pozycja zamówienia #{$orderItemId} nie należy do tego zamówienia.");
                }

                $unit = $this->resolveUnitForItem((int) $unitId, $item, $locked);

                if ($unit->status !== ServiceUnitStatus::Available) {
                    throw new \InvalidArgumentException("Egzemplarz '{$unit->display_label}' nie jest dostępny (status: {$unit->status->label()}).");
                }

                foreach ($claimedInThisBatch[$unit->id] ?? [] as $claim) {
                    if ($item->start_date->lte($claim['end']) && $item->end_date->gte($claim['start'])) {
                        throw new \InvalidArgumentException("Egzemplarz '{$unit->display_label}' jest już przypisany do innej pozycji w tym samym zamówieniu w nakładającym się terminie.");
                    }
                }

                if (OrderItem::assignedToUnitOverlapping($unit->id, $item->start_date, $item->end_date, excludeItemId: $item->id)->exists()) {
                    throw new \InvalidArgumentException("Egzemplarz '{$unit->display_label}' jest już wydany innemu klientowi w nakładającym się terminie.");
                }

                $this->assignIdentifierIfMissing($unit, $unitIdentifiers[$orderItemId] ?? null);

                $claimedInThisBatch[$unit->id][] = ['start' => $item->start_date, 'end' => $item->end_date];

                if ($item->service_unit_id !== $unit->id) {
                    // service_unit_identifier_snapshot is written in the SAME
                    // call as service_unit_id, never separately — see that
                    // column's own migration docblock for why a point-in-time
                    // copy is needed alongside a nullOnDelete FK.
                    $item->update([
                        'service_unit_id' => $unit->id,
                        'service_unit_identifier_snapshot' => $unit->identifier,
                    ]);
                }
            }

            $locked->status()->transitionTo('in_progress', ['unit_assignments' => $unitAssignments]);

            return $locked;
        });
    }

    /**
     * @throws \InvalidArgumentException when the submitted identifier is
     *                                   already used by another unit of the
     *                                   same tenant (UNIQUE(organization_id,
     *                                   identifier) — see service_units' own
     *                                   creation migration)
     */
    private function assignIdentifierIfMissing(ServiceUnit $unit, ?string $identifier): void
    {
        if ($unit->identifier !== null) {
            return;
        }

        $trimmed = trim((string) $identifier);

        if ($trimmed === '') {
            return;
        }

        $taken = ServiceUnit::where('organization_id', $unit->organization_id)
            ->where('identifier', $trimmed)
            ->exists();

        if ($taken) {
            throw new \InvalidArgumentException("Numer '{$trimmed}' jest już użyty przez inny egzemplarz.");
        }

        // The `exists()` check above is a TOCTOU race (noted, not required to
        // fix, by the code review this method's docblock references): two
        // staff members submitting the SAME brand-new identifier for two
        // DIFFERENT units in the same instant could both pass it. This
        // catch is the two-line backstop — translates the resulting
        // UNIQUE(organization_id, identifier) violation into the same
        // friendly message instead of a raw QueryException, and still
        // propagates to the enclosing DB::transaction() (handOver()/
        // completeReturn()) for rollback exactly like any other
        // InvalidArgumentException thrown in this class.
        try {
            $unit->update(['identifier' => $trimmed]);
        } catch (\Illuminate\Database\QueryException $e) {
            throw new \InvalidArgumentException("Numer '{$trimmed}' jest już użyty przez inny egzemplarz.", previous: $e);
        }
    }

    /**
     * Faza 3 krok 3.7 — confirms which physical unit actually came back for
     * each order item, then transitions in_progress -> completed ("Sprzęt
     * zwrócony"). Same "all four call sites, one method" rule as handOver().
     *
     * $returnedUnitAssignments: array<int order_item_id, int|null
     * service_unit_id> — the unit staff selected as ACTUALLY returned. The
     * Filament form defaults each select to whatever was recorded at
     * handover, so a staff member who touches nothing submits the unchanged
     * value and nothing below ever fires.
     *
     * A returned unit that differs from what was handed out is a real-world
     * case (a customer hands back a different unit of the same model), not
     * necessarily an error — so it is never blocked outright, only gated
     * behind $mismatchConfirmed, exactly like recordOfflinePayment()'s
     * amount-mismatch guard above ("possible, never accidental"). A
     * confirmed mismatch OVERWRITES OrderItem::service_unit_id with what was
     * actually returned; Auditable (OrderItem::$auditInclude) is the "who
     * confirmed and what changed" trail (old value = handed out, new value =
     * returned, user_id = who accepted it) — no separate column duplicates
     * it. The state transition itself also carries a summary in
     * state_histories.custom_properties (recorded by the state machine
     * library alongside responsible_id — see StateMachine::transitionTo()),
     * so "kto przyjął" is answered by existing infrastructure, not new code.
     *
     * @param  array<int, int|null>  $returnedUnitAssignments
     *
     * @throws \InvalidArgumentException on a returned unit that doesn't
     *                                   belong to the item's service/tenant,
     *                                   or an unconfirmed mismatch
     * @throws \LogicException when the order isn't 'in_progress'
     */
    public function completeReturn(
        Order $order,
        array $returnedUnitAssignments,
        bool $mismatchConfirmed = false,
        array $unitIdentifiers = [],
    ): Order {
        return DB::transaction(function () use ($order, $returnedUnitAssignments, $mismatchConfirmed, $unitIdentifiers): Order {
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'in_progress') {
                throw new \LogicException("Zamówienie o statusie '{$locked->status}' nie może zostać zakończone.");
            }

            $items = $locked->items()->get()->keyBy('id');

            /** @var array<int, int|null> $toApply */
            $toApply = [];

            /** @var array<int, ServiceUnit> $resolvedUnits */
            $resolvedUnits = [];

            /** @var list<array{order_item_id: int, service_name: string, handed_out_unit_id: int|null, handed_out_label: string|null, returned_unit_id: int|null, returned_label: string|null}> $mismatches
             * ClickUp 123k99cu2b4 requirement #3: "fakt niezgodności zapisany
             * w historii zamówienia razem z tym, kto potwierdził" — this is
             * that fact, built here and carried into state_histories.
             * custom_properties below (the state machine library already
             * stamps responsible_id/responsible_type on every transition —
             * see StateMachine::transitionTo() — so "kto potwierdził" needs
             * no separate write).
             */
            $mismatches = [];

            foreach (self::sortAssignmentsByUnitId($returnedUnitAssignments) as $orderItemId => $unitId) {
                $item = $items->get((int) $orderItemId);

                if ($item === null) {
                    throw new \InvalidArgumentException("Pozycja zamówienia #{$orderItemId} nie należy do tego zamówienia.");
                }

                if ($unitId !== null) {
                    $resolvedUnits[$item->id] = $this->resolveUnitForItem((int) $unitId, $item, $locked);
                }

                if ($item->service_unit_id !== $unitId) {
                    $mismatches[] = [
                        'order_item_id' => $item->id,
                        'service_name' => $item->service_name,
                        'handed_out_unit_id' => $item->service_unit_id,
                        self::MISMATCH_HANDED_OUT_LABEL => $item->serviceUnit?->display_label,
                        'returned_unit_id' => $unitId,
                        self::MISMATCH_RETURNED_LABEL => $resolvedUnits[$item->id]->display_label ?? null,
                    ];
                }

                $toApply[$item->id] = $unitId;
            }

            if ($mismatches !== [] && ! $mismatchConfirmed) {
                throw new \InvalidArgumentException(sprintf(
                    'Zwrócony egzemplarz różni się od wydanego dla: %s. Potwierdź niezgodność, aby kontynuować.',
                    implode(', ', array_unique(array_column($mismatches, 'service_name'))),
                ));
            }

            foreach ($toApply as $itemId => $unitId) {
                $item = $items->get($itemId);

                // Edge case from the ticket: a unit handed out with no
                // identifier has nothing to compare against at return time.
                // Offering the field is suggested, never enforced — see
                // ServiceUnitAssignmentForms's own docblock.
                if ($unitId !== null && isset($resolvedUnits[$itemId])) {
                    $this->assignIdentifierIfMissing($resolvedUnits[$itemId], $unitIdentifiers[$itemId] ?? null);
                }

                if ($item->service_unit_id !== $unitId) {
                    $item->update([
                        'service_unit_id' => $unitId,
                        'service_unit_identifier_snapshot' => $unitId !== null
                            ? $resolvedUnits[$itemId]->identifier
                            : null,
                    ]);
                }
            }

            $locked->status()->transitionTo('completed', [
                'unit_assignments' => $returnedUnitAssignments,
                'mismatch_confirmed' => $mismatchConfirmed,
                'mismatches' => $mismatches,
            ]);

            return $locked;
        });
    }

    /**
     * Shared load+validate for a submitted service_unit_id against one order
     * item: must exist, belong to the SAME service as the item (a
     * ServiceUnit's service_id is immutable — App\Models\ServiceUnit::
     * booted() — so this transitively pins the tenant too), and belong to
     * the SAME organization as the order (redundant, cheap defense-in-depth:
     * order_items carries no organization_id column of its own — see the
     * migration's own docblock — so this is the one place that check can
     * live).
     *
     * `lockForUpdate()` — code review, 2026-09-08: a plain `find()` plus a
     * plain SELECT for the overlap check serialises nothing. Two staff
     * members handing out the SAME unit in the same instant write to
     * DIFFERENT `order_items` rows, so no unique constraint stops them —
     * both read "not yet assigned" and both proceed. Locking the
     * `ServiceUnit` row itself for the rest of THIS transaction closes that:
     * the second caller blocks here until the first commits (at which point
     * `assignedToUnitOverlapping()` sees the first's now-committed row and
     * rejects), instead of racing a stale read.
     *
     * Lock ordering: both handOver() and completeReturn() sort their
     * assignments by unit id ASCENDING before looping (see each method's
     * own call site) specifically so that whenever a single submission
     * touches more than one ServiceUnit, every concurrent transaction in
     * this codebase acquires those locks in the same global order —
     * required to rule out a circular wait between two submissions that
     * both touch the same two units in opposite order (rental-availability.md
     * §3's same concern, applied here to a different table).
     *
     * @throws \InvalidArgumentException
     */
    private function resolveUnitForItem(int $unitId, OrderItem $item, Order $order): ServiceUnit
    {
        $unit = ServiceUnit::where('id', $unitId)->lockForUpdate()->first();

        if ($unit === null || $unit->service_id !== $item->service_id || $unit->organization_id !== $order->organization_id) {
            throw new \InvalidArgumentException("Egzemplarz nie należy do usługi '{$item->service_name}'.");
        }

        return $unit;
    }

    /**
     * Sorts an order_item_id => unit_id map by unit id ascending, preserving
     * keys — see resolveUnitForItem()'s own docblock for why lock ordering
     * needs this. `asort()` puts `null` entries first (loose comparison
     * treats `null` as smaller than any positive id); harmless, since a
     * `null` assignment never reaches resolveUnitForItem() at all (both
     * call sites skip/no-op on `null` before calling it).
     *
     * @param  array<int, int|null>  $assignments
     * @return array<int, int|null>
     */
    private static function sortAssignmentsByUnitId(array $assignments): array
    {
        asort($assignments);

        return $assignments;
    }

    /**
     * Cancels all expired pending_payment orders and returns the count.
     */
    public function cleanupExpired(): int
    {
        $cancelled = 0;

        Order::expired()->get()->each(function (Order $order) use (&$cancelled): void {
            $this->cancel($order, 'TTL expired');
            $cancelled++;
        });

        return $cancelled;
    }
}
