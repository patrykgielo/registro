<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function scopeBlockingAvailability(Builder $query): Builder
    {
        return $query->whereHas('order', function (Builder $q) {
            $q->where(function (Builder $inner) {
                $inner->whereIn('status', ['paid', 'confirmed', 'in_progress'])
                    ->orWhere(function (Builder $pending) {
                        $pending->where('status', 'pending_payment')
                            ->where('expires_at', '>', now());
                    });
            });
        });
    }

    /**
     * @return HasMany<OrderItemExtensionRequest, $this>
     */
    public function extensionRequests(): HasMany
    {
        return $this->hasMany(OrderItemExtensionRequest::class);
    }
}
