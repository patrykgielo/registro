<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RentalStatus;
use App\Exceptions\RentalUnavailableException;
use App\Models\OrderItem;
use App\Models\Rental;
use App\Models\Service;
use App\Support\TenantFeature;
use Illuminate\Support\Carbon;
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
     */
    public function getAvailableQuantity(
        Service $service,
        Carbon $start,
        Carbon $end,
        bool $forUpdate = false,
        ?int $excludeRentalId = null
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

        if ($forUpdate) {
            $rentalsQuery->lockForUpdate();
        }

        $reservedViaRentals = (int) $rentalsQuery->sum('quantity');

        // New: reservations via Cart → Order flow (Sprint 2+)
        $ordersQuery = OrderItem::where('service_id', $service->id)
            ->overlappingDates($start, $end)
            ->blockingAvailability();

        if ($forUpdate) {
            $ordersQuery->lockForUpdate();
        }

        // Qualified column — scopeBlockingAvailability() joins `orders`.
        $reservedViaOrders = (int) $ordersQuery->sum('order_items.quantity');

        return max(0, ($service->quantity_total ?? 0) - $reservedViaRentals - $reservedViaOrders);
    }

    /**
     * Get per-day availability for a month (for calendar display).
     *
     * @return array<string, array{available_quantity: int, status: string}>
     */
    public function getMonthlyAvailability(Service $service, int $year, int $month): array
    {
        $daysInMonth = Carbon::create($year, $month)->daysInMonth;
        $result = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = Carbon::create($year, $month, $day);
            $available = $this->getAvailableQuantity($service, $date->copy()->startOfDay(), $date->copy()->endOfDay());

            $status = match (true) {
                $available <= 0 => 'unavailable',
                $available < ($service->quantity_total ?? 0) => 'partial',
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
