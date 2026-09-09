<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'service_id',
        'location_id',
        'quantity',
        'start_date',
        'end_date',
        'rental_days',
        'unit_price',
        'total_price',
        'price_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'price_snapshot' => 'array',
            'quantity' => 'integer',
            'location_id' => 'integer',
            'rental_days' => 'integer',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Faza 4 krok 4.8 (plan-wdrozenia.md) — nullable, and stays nullable
     * forever. CartItem does not block availability
     * (kontrakt-dostepnosci.md, "Co rezerwuje, a co nie") — this column has
     * no read-path significance today; see the migration's own docblock.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function scopeOverlappingDates(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start);
    }
}
