<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class OrderItem extends Model
{
    use Auditable, HasFactory;

    /**
     * end_date/rental_days/total_price are the fields RentalExtensionService::
     * approve() mutates on an already-paid order item. service_unit_id is the
     * Faza 3 krok 3.6/3.7 handover/return assignment — the audit log IS the
     * "who assigned/reassigned and when" trail requirement.md asks for (see
     * App\Services\Order\OrderService::handOver()/completeReturn()); no
     * separate column duplicates it. service_unit_identifier_snapshot is
     * audited alongside it for the same reason price_snapshot isn't audited
     * separately from total_price — they always change together, one write.
     * Everything else (unit_price, price_snapshot, etc.) is set once at
     * checkout and never changes afterwards, so it isn't worth tracking here.
     */
    protected array $auditInclude = [
        'end_date',
        'rental_days',
        'total_price',
        'service_unit_id',
        'service_unit_identifier_snapshot',
    ];

    protected $fillable = [
        'order_id',
        'service_id',
        'service_unit_id',
        'service_unit_identifier_snapshot',
        'service_name',
        'quantity',
        'start_date',
        'end_date',
        'rental_days',
        'unit_price',
        'total_price',
        'price_snapshot',
        'deposit_amount',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'price_snapshot' => 'array',
            'quantity' => 'integer',
            'service_unit_id' => 'integer',
            'rental_days' => 'integer',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
        ];
    }

    /**
     * @see \App\Models\Order (not yet created)
     *
     * @return BelongsTo<\App\Models\Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo('App\Models\Order');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The physical egzemplarz handed out for this item (Faza 3 krok 3.6),
     * nullable — assignment is entirely optional. Invariant A
     * (kontrakt-dostepnosci.md Zasada 5) applies: this FK records WHICH unit
     * went out, never whether it is "occupied" — ServiceUnit::status/
     * location_id are never touched by setting this.
     *
     * @return BelongsTo<ServiceUnit, $this>
     */
    public function serviceUnit(): BelongsTo
    {
        return $this->belongsTo(ServiceUnit::class);
    }

    /**
     * @return HasMany<OrderItemExtensionRequest, $this>
     */
    public function extensionRequests(): HasMany
    {
        return $this->hasMany(OrderItemExtensionRequest::class);
    }

    public function scopeOverlappingDates(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start);
    }

    /**
     * CRITICAL (2026-07-05, empirically verified with two real concurrent MySQL
     * connections): this used to be `whereHas('order', ...)` — a correlated
     * EXISTS subquery. `FOR UPDATE` on the OUTER query does NOT force a fresh
     * read for a correlated subquery's own table: a transaction that had
     * already done some earlier plain read (fixing its REPEATABLE READ
     * snapshot) could still have `EXISTS(SELECT ... FROM orders ...)` evaluate
     * against that stale snapshot even though the outer `order_items` scan
     * itself was `FOR UPDATE` and genuinely fresh — silently excluding a
     * concurrently-committed reservation from the availability count and
     * allowing overselling. A real INNER JOIN's rows, by contrast, ARE part of
     * the same statement's row set and ARE correctly locked/read-fresh by the
     * outer `FOR UPDATE`. See RentalAvailabilityService::getAvailableQuantity().
     *
     * IMPORTANT: the pending_payment branch below MUST mirror
     * Order::scopeExpired() exactly (same grace-period logic, inverted). An
     * order the expiry scope still considers "alive" (P24 transaction
     * registered, within grace) must also still block the inventory it's
     * holding — otherwise a second customer could book/pay for the same
     * item/dates while the first customer's slow bank/BLIK confirmation is
     * still in flight (overbooking).
     */
    public function scopeBlockingAvailability(Builder $query): Builder
    {
        $graceMinutes = Order::ttlGraceMinutes();

        return $query
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where(function (Builder $inner) use ($graceMinutes) {
                $inner->whereIn('orders.status', ['paid', 'confirmed', 'in_progress'])
                    ->orWhere(function (Builder $pending) use ($graceMinutes) {
                        $pending->where('orders.status', 'pending_payment')
                            ->where(function (Builder $ttl) use ($graceMinutes) {
                                $ttl->where(function (Builder $noTransaction) {
                                    $noTransaction->whereNull('orders.p24_token')
                                        ->where('orders.expires_at', '>', now());
                                })->orWhere(function (Builder $withTransaction) use ($graceMinutes) {
                                    $withTransaction->whereNotNull('orders.p24_token')
                                        ->where('orders.expires_at', '>', now()->subMinutes($graceMinutes));
                                });
                            });
                    });
            })
            ->select('order_items.*');
    }

    /**
     * Order items where the given ServiceUnit is currently assigned AND the
     * order is in a state where the physical unit has NOT been returned.
     *
     * Blocks every status EXCEPT 'completed' and 'refunded' — not just
     * 'confirmed'/'in_progress' as an earlier version of this scope did.
     * Verified before writing this (code review, 2026-09-08, grepped every
     * write to `service_unit_id` across `app/` and `database/`):
     * `OrderService::handOver()` and `::completeReturn()` are the ONLY two
     * places that ever write this column. That means a non-null
     * `service_unit_id` on an order NOT in 'completed'/'refunded' — cancelled
     * included — always describes a unit that physically left and has not
     * come back, regardless of why the order stopped moving forward.
     * `OrderService::cancel()` explicitly allows cancelling an 'in_progress'
     * order (exceptional: forced tenant offboarding) and does NOT clear the
     * assignment — a cancelled order that was never handed out has
     * `service_unit_id === null` and is harmlessly excluded by the `WHERE
     * service_unit_id = ?` above; one that WAS handed out still has the
     * physical unit with a customer and must keep blocking it.
     *
     * 'refunded' is excluded ALONGSIDE 'completed', not just 'completed'
     * alone, because the state machine only ever reaches 'refunded' FROM
     * 'completed' (OrderStatusStateMachine::transitions()) — the physical
     * return already happened at the 'completed' transition; a later refund
     * of the money must not re-block equipment that is already back.
     *
     * DELIBERATE, DOCUMENTED COST: a unit whose order was cancelled AFTER
     * handover stays blocked for that order's date window with NO path to
     * release it today — there is no "return anyway" action for a cancelled
     * order. This is intentional, not an oversight: the alternative (letting
     * a cancelled-but-handed-out unit look free) is how two customers can
     * receive the SAME physical unit with two signed protocols — a strictly
     * worse failure than a support call to manually re-home a blocked unit.
     * Do not "fix" this by loosening the status filter without adding an
     * explicit release action first.
     *
     * Same join-not-whereHas shape as scopeBlockingAvailability() above for
     * the same reason documented there — kept even though this path has no
     * FOR UPDATE lock of its own on `order_items` (locking now lives on the
     * `ServiceUnit` row itself — see OrderService::resolveUnitForItem()).
     */
    public function scopeAssignedToUnitOverlapping(
        Builder $query,
        int $serviceUnitId,
        Carbon $start,
        Carbon $end,
        ?int $excludeItemId = null,
    ): Builder {
        return $query
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.service_unit_id', $serviceUnitId)
            ->whereNotIn('orders.status', ['completed', 'refunded'])
            ->whereDate('order_items.start_date', '<=', $end)
            ->whereDate('order_items.end_date', '>=', $start)
            ->when(
                $excludeItemId,
                fn (Builder $q) => $q->where('order_items.id', '!=', $excludeItemId)
            )
            ->select('order_items.*');
    }
}
