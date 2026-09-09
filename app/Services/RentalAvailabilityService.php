<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RentalStatus;
use App\Exceptions\RentalUnavailableException;
use App\Models\OrderItem;
use App\Models\Rental;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Support\TenantFeature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RentalAvailabilityService
{
    private const HOLD_TTL_MINUTES = 15;

    /**
     * Get available quantity for a service during a date range.
     *
     * NOT thread-safe on its own for the default ($forUpdate = false) mode —
     * callers on write paths (creating/updating a Rental or OrderItem) MUST
     * pass $forUpdate = true, AND must already hold a `Service::lockForUpdate()`
     * lock on this $service row before calling. Why both are required: locking
     * the Service row only serialises *other writers* against each other (they
     * all queue on the same row) — it does NOT, by itself, make THIS
     * transaction's own read of `rentals`/`order_items` see another
     * transaction's commit that happened while we were queued. Under MySQL's
     * default REPEATABLE READ, a transaction's plain (non-locking) SELECTs all
     * share the snapshot established by its first consistent read — a
     * `SELECT ... FOR UPDATE` on a *different* row (the Service) does not reset
     * that snapshot for later plain reads. So a transaction that queued on the
     * Service lock and then resumed after the winner committed could still
     * compute availability from data as of *before* the winner's insert, via a
     * plain SELECT — both transactions would then see "1 available" and both
     * insert: exactly the oversell bug this method exists to prevent.
     * $forUpdate = true makes the `rentals`/`order_items` count queries
     * themselves locking reads, which MySQL always resolves against the latest
     * committed data regardless of snapshot/isolation level — that is the
     * actual mechanism that closes the race, not the Service lock alone.
     *
     * Read-only callers (e.g. "X available" display on the frontend) should
     * keep the default $forUpdate = false — forcing locking reads on every
     * page view would serialise unrelated readers for no benefit.
     *
     * $excludeRentalId lets a caller editing an existing Rental row exclude
     * that row's own (already-counted) reservation from the sum — otherwise
     * an admin increasing the quantity on an existing rental would see its own
     * prior reservation double-counted against itself.
     *
     * Sprint 2: dual-source — accounts for both legacy Rentals and new OrderItems.
     *
     * Faza 4 krok 4.1 (plan-wdrozenia.md, kontrakt-dostepnosci.md Zasada 2)
     * — $locationId, added LAST so every existing call passing
     * $forUpdate/$excludeRentalId as named arguments keeps working
     * unchanged. $locationId === null (the only mode any of the 9 call
     * sites use today — none has been rewired yet, that is Faza 4 krok
     * 4.4+) reads `services.quantity_total` LITERALLY, exactly as before
     * this parameter existed — bit-for-bit the same query shape, same
     * result. This is a deliberate correctness invariant, not an
     * optimisation: it is what makes "a tenant with no locations behaves
     * identically" a statement about the CODE, not about data discipline
     * (a service missing its service_location_stocks row — import, seeder,
     * a factory that bypasses ServiceUnitObserver — must not silently see
     * a different, wrong answer just because $locationId happened to stay
     * null).
     *
     * When $locationId is given, capacity comes from the
     * service_location_stocks anchor row instead of quantity_total (see
     * locationCapacity() below for the Faza 4 krok 4.3 lock hierarchy), and
     * both reservation queries gain an EXTRA outer-WHERE filter — never
     * inside a join or a scope (kontrakt-dostepnosci.md Zasada 5;
     * OrderItem::scopeBlockingAvailability() and Order::scopeExpired()
     * stay completely untouched by this change).
     *
     * A reservation with location_id === NULL (pre-backfill legacy data,
     * or a row created before a future write path starts setting it)
     * BLOCKS EVERY location, not none. This is the conservative direction
     * kontrakt-dostepnosci.md's Zasada 7 already establishes for this
     * whole method ("zaniżanie, nie zawyżanie" — under-promise, never
     * oversell): an unassigned reservation might physically be sitting in
     * ANY location, so treating it as "definitely not this one" could let
     * a location-scoped booking co-exist with a reservation for the same
     * physical unit whose true location was simply never recorded. Blocking
     * everywhere costs a false "unavailable" at worst; blocking nowhere
     * risks a real oversell.
     */
    public function getAvailableQuantity(
        Service $service,
        Carbon $start,
        Carbon $end,
        bool $forUpdate = false,
        ?int $excludeRentalId = null,
        ?int $locationId = null
    ): int {
        $blockedStatuses = collect(RentalStatus::cases())
            ->filter(fn (RentalStatus $s) => $s->blocksAvailability())
            ->map(fn (RentalStatus $s) => $s->value)
            ->values()
            ->all();

        // Legacy: reservations via old Rental flow
        $rentalsQuery = Rental::where('service_id', $service->id)
            ->whereIn('status', $blockedStatuses)
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start);

        if ($excludeRentalId !== null) {
            $rentalsQuery->where('id', '!=', $excludeRentalId);
        }

        if ($locationId !== null) {
            $rentalsQuery->where(function (Builder $q) use ($locationId) {
                $q->where('location_id', $locationId)->orWhereNull('location_id');
            });
        }

        if ($forUpdate) {
            $rentalsQuery->lockForUpdate();
        }

        $reservedViaRentals = (int) $rentalsQuery->sum('quantity');

        // New: reservations via Cart → Order flow (Sprint 2+)
        $ordersQuery = OrderItem::where('service_id', $service->id)
            ->overlappingDates($start, $end)
            ->blockingAvailability();

        // Outer WHERE, appended AFTER blockingAvailability()'s own join +
        // closure — kontrakt-dostepnosci.md Zasada 5. Qualified column:
        // scopeBlockingAvailability() joins `orders`, which also has an
        // unrelated (nonexistent today) `location_id`-shaped column risk
        // to avoid ambiguity against.
        if ($locationId !== null) {
            $ordersQuery->where(function (Builder $q) use ($locationId) {
                $q->where('order_items.location_id', $locationId)
                    ->orWhereNull('order_items.location_id');
            });
        }

        if ($forUpdate) {
            $ordersQuery->lockForUpdate();
        }

        // Qualified column — scopeBlockingAvailability() joins `orders`.
        $reservedViaOrders = (int) $ordersQuery->sum('order_items.quantity');

        $capacity = $locationId === null
            ? ($service->quantity_total ?? 0)
            : $this->locationCapacity($service->id, $locationId, $forUpdate);

        return max(0, $capacity - $reservedViaRentals - $reservedViaOrders);
    }

    /**
     * Faza 4 krok 4.3 (kontrakt-dostepnosci.md, "Po dodaniu kotwicy") —
     * capacity for one (service, location) pair, read from the
     * service_location_stocks anchor row.
     *
     * Lock hierarchy, ALWAYS in this order, never reversed:
     *   1. `services`, by service_id ascending — already acquired by every
     *      write-path caller BEFORE it ever calls getAvailableQuantity()
     *      (CartService::addItem()/convertToOrder()'s existing
     *      `Service::lockForUpdate()` convention, iterated in
     *      `orderBy('service_id')` order for multi-item carts — see
     *      CartService.php's own deterministic-lock-order comment). This
     *      method does not re-acquire that lock; it relies on the caller
     *      already holding it, exactly like every other query in this
     *      class already relies on that same convention.
     *   2. the anchor row itself, by (service_id, location_id) — locked
     *      HERE, inside the already-acquired Service lock, only when
     *      $forUpdate is true.
     *
     * Missing anchor row reads as capacity 0 and is NEVER inserted here:
     * kontrakt-dostepnosci.md is explicit that materialising a missing row
     * (`insertOrIgnore`, App\Actions\Inventory\SyncServiceLocationStock's
     * job) INSIDE this locking path is a deadlock generator — `INSERT
     * IGNORE` on a duplicate unique key takes a shared lock, which combined
     * with `lockForUpdate()` here is exactly the pattern eager
     * materialisation exists to avoid. A service/location pair with no
     * stock row at all is, correctly, zero available.
     *
     * `withoutGlobalScope('organization')`, matching
     * Service::recalculateQuantityTotal()'s own precedent and reasoning:
     * this method already has both `$serviceId` and `$locationId` as
     * explicit arguments — the UNIQUE(service_id, location_id) constraint
     * on this table means at most one row can ever match regardless of
     * ambient tenant context, so there is nothing to gain from depending on
     * one being resolved, and callers from a context without one (a
     * console command, a future job) get the same, correct answer instead
     * of a fail-closed empty result.
     */
    private function locationCapacity(int $serviceId, int $locationId, bool $forUpdate): int
    {
        $query = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $serviceId)
            ->where('location_id', $locationId);

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return (int) ($query->value('quantity') ?? 0);
    }

    /**
     * Get per-day availability for a month (for calendar display).
     * Uses 2 bulk queries instead of 2×daysInMonth individual queries.
     *
     * Faza 4 krok 4.6 (plan-wdrozenia.md, kontrakt-dostepnosci.md Zasada 2/5)
     * — $locationId, added LAST for the same reason as getAvailableQuantity()
     * above: every existing call keeps working unchanged. $locationId ===
     * null reads `services.quantity_total` literally — bit-for-bit today's
     * query shape — exactly mirroring getAvailableQuantity()'s own
     * null-branch invariant. With a location given, capacity comes from the
     * SAME `locationCapacity()` helper getAvailableQuantity() uses (Zasada 1
     * — one entry point for the math, this private helper is still the only
     * caller-visible seam), and both bulk queries gain the SAME outer-WHERE
     * `location_id = X OR location_id IS NULL` filter as the point-check
     * (Zasada 5/Zasada 2's "location_id = NULL blocks every location, not
     * none" resolution) — a reservation with no recorded location must block
     * the calendar exactly as it blocks a single checkAvailability() call, or
     * the two would disagree with each other.
     *
     * This method NEVER locks (`forUpdate` does not exist as a parameter
     * here) — it is a read-only display query, and locationCapacity() is
     * always invoked with `forUpdate: false` below, same as before this
     * parameter existed.
     *
     * @return array<string, array{available_quantity: int, status: string}>
     */
    public function getMonthlyAvailability(Service $service, int $year, int $month, ?int $locationId = null): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $blockedStatuses = collect(RentalStatus::cases())
            ->filter(fn (RentalStatus $s) => $s->blocksAvailability())
            ->map(fn (RentalStatus $s) => $s->value)
            ->values()
            ->all();

        $monthRentalsQuery = Rental::where('service_id', $service->id)
            ->whereIn('status', $blockedStatuses)
            ->where('start_date', '<=', $monthEnd->toDateString())
            ->where('end_date', '>=', $monthStart->toDateString());

        if ($locationId !== null) {
            $monthRentalsQuery->where(function (Builder $q) use ($locationId) {
                $q->where('location_id', $locationId)->orWhereNull('location_id');
            });
        }

        $monthRentals = $monthRentalsQuery->select('start_date', 'end_date', 'quantity')->get();

        // Qualified columns — scopeBlockingAvailability() joins `orders` (see
        // getAvailableQuantity() above, which mirrors this same pattern).
        $monthOrderItemsQuery = OrderItem::where('service_id', $service->id)
            ->overlappingDates($monthStart, $monthEnd)
            ->blockingAvailability();

        if ($locationId !== null) {
            $monthOrderItemsQuery->where(function (Builder $q) use ($locationId) {
                $q->where('order_items.location_id', $locationId)
                    ->orWhereNull('order_items.location_id');
            });
        }

        $monthOrderItems = $monthOrderItemsQuery
            ->select('order_items.start_date', 'order_items.end_date', 'order_items.quantity')
            ->get();

        $daysInMonth = $monthStart->daysInMonth;
        $capacity = $locationId === null
            ? ($service->quantity_total ?? 0)
            : $this->locationCapacity($service->id, $locationId, forUpdate: false);
        $result = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = Carbon::create($year, $month, $day);

            $reservedViaRentals = (int) $monthRentals
                ->filter(fn ($r) => $r->start_date->lte($date) && $r->end_date->gte($date))
                ->sum('quantity');

            $reservedViaOrders = (int) $monthOrderItems
                ->filter(fn ($r) => $r->start_date->lte($date) && $r->end_date->gte($date))
                ->sum('quantity');

            $available = max(0, $capacity - $reservedViaRentals - $reservedViaOrders);

            $status = match (true) {
                $available <= 0 => 'unavailable',
                $available < $capacity => 'partial',
                default => 'available',
            };

            $result[$date->format('Y-m-d')] = [
                'available_quantity' => $available,
                'status' => $status,
            ];
        }

        return $result;
    }

    /**
     * Faza 4 krok 4.7 (plan-wdrozenia.md, kontrakt-dostepnosci.md) — bulk
     * availability for a whole listing of services, in a CONSTANT number of
     * queries (3, always — never O(count($services))). Exists so a category
     * page ("dostępne w Twoim oddziale" tile, Faza 5 krok 5.3/5.5) does not
     * turn into an N+1 of `getAvailableQuantity()` calls.
     *
     * NOT wired to any view yet (Faza 5) — read-only, safe to call from a
     * future controller once it is.
     *
     * Mirrors getAvailableQuantity()'s math exactly (Zasada 1 — one entry
     * point; this is the ONLY other place allowed to touch this
     * calculation, and it must produce numbers identical to N calls of
     * getAvailableQuantity() for the same services/window — proven by
     * RentalAvailabilityServiceBulkTest's parity test, mutated in both
     * directions and reverted).
     *
     * Same "location_id = NULL blocks every location" resolution as
     * getAvailableQuantity() (Zasada 2's decision, Zasada 5's outer-WHERE
     * discipline) — but done as a SUM per (service_id, location_id) instead
     * of a WHERE per call, because a location-less reservation must be
     * folded into EVERY location's bucket, not just its own NULL group. This
     * cannot be expressed as a single `GROUP BY service_id, location_id`
     * query alone (a NULL-location row's group is disjoint from every real
     * location's group) — it is expressed as two DISTINCT aggregate reads
     * (Rental, OrderItem), each grouped by (service_id, location_id)
     * including the NULL group, and merged in PHP: for a given service, the
     * "reserved everywhere" (NULL-group) sum is added on top of every real
     * location's own sum before subtracting from that location's anchor
     * capacity. The "total" (locationId === null / quantity_total) figure is
     * the sum of ALL of a service's location-buckets INCLUDING the NULL
     * one — algebraically identical to a plain `GROUP BY service_id` alone,
     * so no third variant of either query is needed for it.
     *
     * Keyed by service_id. `total` mirrors getAvailableQuantity(locationId:
     * null) (quantity_total-based). `locations` is keyed by location_id,
     * present only for locations that have a service_location_stocks anchor
     * row for that service — each value mirrors
     * getAvailableQuantity(locationId: $that).
     *
     * CONTRACT, not an implementation detail (code review, 2026-09-09): a
     * location_id ABSENT from `locations` means capacity ZERO, identical to
     * what getAvailableQuantity(locationId: $that) would return explicitly
     * — it does NOT mean "no constraint"/"ask elsewhere". This happens for
     * a location that has never had a service_location_stocks anchor row
     * created for this service (e.g. a reservation exists for it via a
     * legacy path, but the anchor itself was never materialized — the
     * anchor and the reservations are two independent tables with no FK
     * between them). Every caller MUST read this array with `?? 0`, never
     * treat a missing key as "unlimited" or skip it silently. Proven by
     * RentalAvailabilityServiceBulkTest::
     * test_a_location_with_reservations_but_no_anchor_row_is_absent_from_the_result_and_that_means_zero_not_unlimited.
     *
     * @param  Collection<int, Service>  $services
     * @return array<int, array{total: int, locations: array<int, int>}>
     */
    public function availabilityForServices(Collection $services, Carbon $start, Carbon $end): array
    {
        if ($services->isEmpty()) {
            return [];
        }

        $serviceIds = $services->pluck('id')->all();

        $blockedStatuses = collect(RentalStatus::cases())
            ->filter(fn (RentalStatus $s) => $s->blocksAvailability())
            ->map(fn (RentalStatus $s) => $s->value)
            ->values()
            ->all();

        // Query 1/3 — legacy Rentals, grouped by (service_id, location_id).
        // The `location_id IS NULL` rows form their OWN group here (MySQL
        // and SQLite both group NULL as a single bucket) — that bucket is
        // read out separately below and added to every real location.
        $rentalRows = Rental::whereIn('service_id', $serviceIds)
            ->whereIn('status', $blockedStatuses)
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start)
            ->select('service_id', 'location_id', DB::raw('SUM(quantity) as reserved'))
            ->groupBy('service_id', 'location_id')
            ->get();

        // Query 2/3 — Cart→Order flow, same grouping. Qualified columns —
        // scopeBlockingAvailability() joins `orders` (Zasada 5, same
        // discipline as getAvailableQuantity()/getMonthlyAvailability()
        // above). `select()` here REPLACES blockingAvailability()'s own
        // `select('order_items.*')`, which is fine — that select existed
        // only to avoid leaking `orders.*` columns, not to be depended on
        // by callers further down the chain.
        $orderItemRows = OrderItem::whereIn('order_items.service_id', $serviceIds)
            ->overlappingDates($start, $end)
            ->blockingAvailability()
            ->select('order_items.service_id', 'order_items.location_id', DB::raw('SUM(order_items.quantity) as reserved'))
            ->groupBy('order_items.service_id', 'order_items.location_id')
            ->get();

        // [serviceId => [locationKey => reserved]] — locationKey is an int,
        // or the literal string 'null' for the "blocks everywhere" bucket.
        $reservedByServiceLocation = [];

        $accumulate = function ($rows) use (&$reservedByServiceLocation): void {
            foreach ($rows as $row) {
                $serviceId = (int) $row->service_id;
                $locationKey = $row->location_id === null ? 'null' : (int) $row->location_id;
                $reservedByServiceLocation[$serviceId][$locationKey] =
                    ($reservedByServiceLocation[$serviceId][$locationKey] ?? 0) + (int) $row->reserved;
            }
        };

        $accumulate($rentalRows);
        $accumulate($orderItemRows);

        // Query 3/3 — anchor capacity per (service_id, location_id).
        // withoutGlobalScope('organization'), same reasoning as
        // locationCapacity() above: $serviceIds is already an explicit,
        // caller-supplied list, so there is nothing to gain from depending
        // on an ambient tenant, and a caller from a context without one
        // (console, job) still gets the correct answer.
        $anchorRows = ServiceLocationStock::withoutGlobalScope('organization')
            ->whereIn('service_id', $serviceIds)
            ->get(['service_id', 'location_id', 'quantity']);

        $capacityByServiceLocation = [];
        foreach ($anchorRows as $row) {
            $capacityByServiceLocation[(int) $row->service_id][(int) $row->location_id] = (int) $row->quantity;
        }

        $result = [];

        foreach ($services as $service) {
            $serviceId = $service->id;
            $buckets = $reservedByServiceLocation[$serviceId] ?? [];

            $nullBlockReserved = (int) ($buckets['null'] ?? 0);
            // Sum across ALL of this service's buckets (real locations AND
            // the null bucket) — algebraically identical to a plain
            // `GROUP BY service_id` read with no location filter at all,
            // which is exactly what getAvailableQuantity(locationId: null)
            // computes.
            $totalReserved = array_sum($buckets);

            $result[$serviceId] = [
                'total' => max(0, (int) ($service->quantity_total ?? 0) - $totalReserved),
                'locations' => [],
            ];

            foreach (($capacityByServiceLocation[$serviceId] ?? []) as $locationId => $capacity) {
                $reserved = (int) ($buckets[$locationId] ?? 0) + $nullBlockReserved;
                $result[$serviceId]['locations'][$locationId] = max(0, $capacity - $reserved);
            }
        }

        return $result;
    }

    /**
     * Faza 5.3/5.4 (86cbahqgb/86cbahqgh, plan-wdrozenia.md) — the ONE place
     * that reads an availabilityForServices() entry into the single number a
     * catalog tile or product page badge shows. Exists to keep
     * kontrakt-dostepnosci.md's "brak klucza w `locations` znaczy ZERO, nie
     * brak ograniczenia" rule in one place instead of three call sites
     * (RentalController::index()/showCategory(), ServiceController::index())
     * each re-deriving it — the exact duplication Zasada 1 warns against, one
     * level up from the raw query.
     *
     * $selectedLocationId === null covers BOTH a tenant with zero locations
     * (the feature is simply unused) AND a multi-location tenant where the
     * customer has not picked a branch yet — LocationContext::selected()
     * already collapses "tenant has exactly one active location" into an
     * explicit id for free (see its own docblock), so by the time this is
     * called with null, showing anything BUT the combined total would imply
     * a specific branch nobody chose. `$entry['total']` is exactly
     * getAvailableQuantity(locationId: null)'s own number for the same
     * service/window (quantity_total minus every reservation regardless of
     * location) — i.e. today's pre-Faza-5 global behaviour, computed live
     * instead of the static `quantity_total` the tile used to read.
     *
     * @param  array{total: int, locations: array<int, int>}  $entry  one
     *                                                                value from availabilityForServices()'s return array
     */
    public function availableQuantityFor(array $entry, ?int $selectedLocationId): int
    {
        if ($selectedLocationId !== null) {
            return $entry['locations'][$selectedLocationId] ?? 0;
        }

        return $entry['total'] ?? 0;
    }

    /**
     * Create a temporary hold with pessimistic locking.
     * Blocks inventory for HOLD_TTL_MINUTES.
     *
     * @deprecated Sprint 4 — use CartService::addItem() + OrderService instead. Will be removed.
     *
     * @throws RentalUnavailableException
     */
    public function createHold(
        Service $service,
        Carbon $start,
        Carbon $end,
        int $quantity,
        ?int $customerId = null
    ): Rental {
        return DB::transaction(function () use ($service, $start, $end, $quantity, $customerId) {
            // Lock the service row — concurrent requests queue here
            $service = Service::lockForUpdate()->findOrFail($service->id);

            $available = $this->getAvailableQuantity($service, $start, $end, forUpdate: true);

            if ($available < $quantity) {
                throw new RentalUnavailableException(
                    "Dostępnych tylko {$available} szt. w wybranym terminie (wymagane: {$quantity})."
                );
            }

            $durationDays = (int) $start->diffInDays($end) + 1;
            $pricing = $this->calculatePricing($service, $durationDays, $quantity);

            return Rental::create([
                'organization_id' => TenantFeature::currentTenant()?->id ?? $service->organization_id,
                'service_id' => $service->id,
                'customer_id' => $customerId,
                'quantity' => $quantity,
                'start_date' => $start,
                'end_date' => $end,
                'status' => RentalStatus::Held,
                'held_until' => now()->addMinutes(self::HOLD_TTL_MINUTES),
                'pricing_unit' => $pricing['unit'],
                'unit_price_at_booking' => $pricing['unit_price'],
                'total_price' => $pricing['total'],
                'deposit_amount' => $service->deposit_amount,
            ]);
        });
    }

    /**
     * Confirm a held rental — sets contact info, transitions held → pending.
     * Pricing is already snapshotted at hold creation time.
     *
     * @deprecated Sprint 4 — use CheckoutController + Przelewy24Service instead. Will be removed.
     */
    public function confirmHold(Rental $rental, array $contactData): Rental
    {
        if ($rental->status !== RentalStatus::Held) {
            throw new \LogicException('Only held rentals can be confirmed.');
        }

        if ($rental->held_until && $rental->held_until->isPast()) {
            $rental->update(['status' => RentalStatus::Expired]);
            throw new RentalUnavailableException('Twoja rezerwacja wygasła. Spróbuj ponownie.');
        }

        $rental->update(array_merge($contactData, [
            'status' => RentalStatus::Pending,
            'held_until' => null,
        ]));

        return $rental->fresh();
    }

    /**
     * Calculate pricing based on duration and service rates.
     */
    public function calculatePricing(Service $service, int $durationDays, int $quantity): array
    {
        $unitPrice = (float) $service->price_per_day;
        $unit = 'daily';

        // Tiered: lower rate after threshold
        if ($service->price_per_day_long && $service->price_threshold_days && $durationDays >= $service->price_threshold_days) {
            $unitPrice = (float) $service->price_per_day_long;
        }

        // Weekly: if >= 7 days and weekly rate is better
        if ($service->price_per_week && $durationDays >= 7) {
            $weeklyPerDay = (float) $service->price_per_week / 7;
            if ($weeklyPerDay < $unitPrice) {
                $weeks = floor($durationDays / 7);
                $remainingDays = $durationDays % 7;
                $total = ($weeks * (float) $service->price_per_week) + ($remainingDays * $unitPrice);

                return [
                    'unit' => 'weekly',
                    'unit_price' => $service->price_per_week,
                    'total' => round($total * $quantity, 2),
                ];
            }
        }

        return [
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'total' => round($unitPrice * $durationDays * $quantity, 2),
        ];
    }

    /**
     * Get hold TTL in minutes (for frontend countdown).
     */
    public static function holdTtlMinutes(): int
    {
        return self::HOLD_TTL_MINUTES;
    }
}
