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

        return $query->whereHas('order', function (Builder $q) use ($graceMinutes) {
            $q->where(function (Builder $inner) use ($graceMinutes) {
                $inner->whereIn('status', ['paid', 'confirmed', 'in_progress'])
                    ->orWhere(function (Builder $pending) use ($graceMinutes) {
                        $pending->where('status', 'pending_payment')
                            ->where(function (Builder $ttl) use ($graceMinutes) {
                                $ttl->where(function (Builder $noTransaction) {
                                    $noTransaction->whereNull('p24_token')
                                        ->where('expires_at', '>', now());
                                })->orWhere(function (Builder $withTransaction) use ($graceMinutes) {
                                    $withTransaction->whereNotNull('p24_token')
                                        ->where('expires_at', '>', now()->subMinutes($graceMinutes));
                                });
                            });
                    });
            });
        });
    }
}
