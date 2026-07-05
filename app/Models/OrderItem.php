<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'service_id',
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
     */
    public function scopeBlockingAvailability(Builder $query): Builder
    {
        return $query
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where(function (Builder $inner) {
                $inner->whereIn('orders.status', ['paid', 'confirmed', 'in_progress'])
                    ->orWhere(function (Builder $pending) {
                        $pending->where('orders.status', 'pending_payment')
                            ->where('orders.expires_at', '>', now());
                    });
            })
            ->select('order_items.*');
    }
}
