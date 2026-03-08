<?php

namespace App\Models;

use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RentalItem extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'rental_category_id',
        'name',
        'slug',
        'description',
        'quantity_total',
        'price_per_day',
        'price_per_hour',
        'price_per_week',
        'deposit_amount',
        'featured_image',
        'is_active',
        'sort_order',
        'specifications',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'quantity_total' => 'integer',
        'price_per_day' => 'decimal:2',
        'price_per_hour' => 'decimal:2',
        'price_per_week' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'specifications' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($item) {
            if (empty($item->slug) && ! empty($item->name)) {
                $item->slug = Str::slug($item->name);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RentalCategory::class, 'rental_category_id');
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    /**
     * Calculate how many units are available in a given date range.
     */
    public function availableQuantity(Carbon $startDate, Carbon $endDate): int
    {
        $reserved = Rental::where('rental_item_id', $this->id)
            ->whereIn('status', ['pending', 'confirmed', 'active'])
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->sum('quantity');

        return max(0, $this->quantity_total - $reserved);
    }

    /**
     * Check if a given quantity is available in a date range.
     */
    public function isAvailable(Carbon $startDate, Carbon $endDate, int $quantity = 1): bool
    {
        return $this->availableQuantity($startDate, $endDate) >= $quantity;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Scope: items available between given dates with given quantity.
     */
    public function scopeAvailableBetween($query, Carbon $startDate, Carbon $endDate, int $quantity = 1)
    {
        return $query->where('quantity_total', '>=', $quantity)
            ->whereDoesntHave('rentals', function ($q) use ($startDate, $endDate, $quantity) {
                $q->whereIn('status', ['pending', 'confirmed', 'active'])
                    ->where('start_date', '<=', $endDate)
                    ->where('end_date', '>=', $startDate)
                    ->havingRaw('SUM(quantity) > ?', [$quantity - 1]);
            });
    }

    public function getFormattedPriceAttribute(): string
    {
        $price = number_format((float) $this->price_per_day, 2, ',', ' ').' zł/dzień';

        if ($this->price_per_hour) {
            $price .= ' | '.number_format((float) $this->price_per_hour, 2, ',', ' ').' zł/godz';
        }

        if ($this->price_per_week) {
            $price .= ' | '.number_format((float) $this->price_per_week, 2, ',', ' ').' zł/tydz';
        }

        return $price;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
