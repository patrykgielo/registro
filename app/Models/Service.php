<?php

namespace App\Models;

use App\Enums\RentalStatus;
use App\Enums\ServiceType;
use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Service extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        // Existing fields
        'name',
        'description',
        'duration_minutes',
        'price',
        'is_active',
        'sort_order',
        // CMS fields
        'slug',
        'icon',
        'excerpt',
        'body',
        'content',
        'meta_title',
        'meta_description',
        'featured_image',
        'hero_overlay_color',
        'hero_overlay_opacity',
        'published_at',
        // P0 fields
        'price_from',
        'area_served',
        // Conversion optimization fields
        'average_rating',
        'total_reviews',
        'is_popular',
        'booking_count_week',
        'features',
        'metadata',
        // Rental fields
        'service_type',
        'rental_category_id',
        'quantity_total',
        'price_per_day',
        'price_per_hour',
        'price_per_week',
        'price_per_day_long',
        'price_threshold_days',
        'deposit_amount',
        'brand',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price' => 'decimal:2',
        'price_from' => 'decimal:2',
        'duration_minutes' => 'integer',
        'content' => 'array',
        'hero_overlay_opacity' => 'integer',
        'published_at' => 'datetime',
        // Conversion optimization fields
        'average_rating' => 'decimal:1',
        'total_reviews' => 'integer',
        'is_popular' => 'boolean',
        'booking_count_week' => 'integer',
        'features' => 'array',
        'metadata' => 'array',
        // Rental fields
        'service_type' => ServiceType::class,
        'quantity_total' => 'integer',
        'price_per_day' => 'decimal:2',
        'price_per_hour' => 'decimal:2',
        'price_per_week' => 'decimal:2',
        'price_per_day_long' => 'decimal:2',
        'price_threshold_days' => 'integer',
        'deposit_amount' => 'decimal:2',
    ];

    // Relationships

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Get the staff members that can perform this service.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function staff()
    {
        return $this->belongsToMany(User::class, 'service_staff', 'service_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Alias for staff() to support Filament's AttachAction.
     * Filament expects inverse relationships to use the plural model name (users),
     * but our business logic uses staff() for clarity.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->staff();
    }

    /**
     * @return BelongsTo<RentalCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RentalCategory::class, 'rental_category_id');
    }

    /**
     * @return HasMany<Rental, $this>
     */
    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Scope: Published services only (published_at not null and in the past)
     */
    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scope: Draft services (published_at is null)
     */
    public function scopeDraft($query)
    {
        return $query->whereNull('published_at');
    }

    /**
     * Scope: Services that are rentable items (service_type = item_rental)
     */
    public function scopeRentable($query)
    {
        return $query->where('service_type', ServiceType::ItemRental->value);
    }

    /**
     * Scope: Services that are bookable by time slot (service_type = time_slot)
     */
    public function scopeBookable($query)
    {
        return $query->where('service_type', ServiceType::TimeSlot->value);
    }

    /**
     * Scope: Items available between given dates with given quantity.
     */
    public function scopeAvailableBetween($query, Carbon $startDate, Carbon $endDate, int $quantity = 1)
    {
        return $query->where('quantity_total', '>=', $quantity)
            ->whereDoesntHave('rentals', function ($q) use ($startDate, $endDate, $quantity) {
                $q->whereIn('status', [RentalStatus::Pending, RentalStatus::Confirmed, RentalStatus::Active])
                    ->where('start_date', '<=', $endDate)
                    ->where('end_date', '>=', $startDate)
                    ->havingRaw('SUM(quantity) > ?', [$quantity - 1]);
            });
    }

    // Boot

    /**
     * Boot method for model events
     */
    protected static function booted(): void
    {
        static::creating(function ($service) {
            // Auto-generate slug from name if not provided
            if (empty($service->slug) && ! empty($service->name)) {
                $service->slug = Str::slug($service->name);
            }
        });
    }

    // Route model binding

    /**
     * Get the route key name for Laravel route model binding
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // Methods

    /**
     * Check if the service is published (published_at in the past)
     */
    public function isPublished(): bool
    {
        return $this->published_at && $this->published_at->isPast();
    }

    /**
     * Calculate how many units are available in a given date range.
     */
    public function availableQuantity(Carbon $startDate, Carbon $endDate): int
    {
        $reserved = Rental::where('service_id', $this->id)
            ->whereIn('status', [RentalStatus::Pending, RentalStatus::Confirmed, RentalStatus::Active])
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

    // Accessors

    public function getFormattedDurationAttribute(): string
    {
        $totalMinutes = $this->duration_minutes;

        $days = floor($totalMinutes / 1440);
        $remainingAfterDays = $totalMinutes % 1440;
        $hours = floor($remainingAfterDays / 60);
        $minutes = $remainingAfterDays % 60;

        $parts = [];

        if ($days > 0) {
            $parts[] = $days.' '.($days === 1 ? 'dzień' : 'dni');
        }

        if ($hours > 0) {
            $parts[] = $hours.' '.($hours === 1 ? 'godz' : 'godz');
        }

        if ($minutes > 0) {
            $parts[] = $minutes.' min';
        }

        return ! empty($parts) ? implode(', ', $parts) : '0 min';
    }

    /**
     * Alias for formatted_duration (used in Blade templates)
     */
    public function getDurationDisplayAttribute(): string
    {
        return $this->formatted_duration;
    }

    /**
     * Formatted rental price summary for display (item_rental services).
     */
    public function getFormattedRentalPriceAttribute(): string
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
}
