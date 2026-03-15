<?php

namespace App\Models;

use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
    ];

    // Relationships
    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Get the staff members that can perform this service.
     */
    public function staff()
    {
        return $this->belongsToMany(User::class, 'service_staff', 'service_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Get the staff members that can perform this service.
     *
     * This is an alias for staff() to support Filament's AttachAction.
     * Filament expects inverse relationships to use the plural model name (users),
     * but our business logic uses staff() for clarity.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->staff();
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

    /**
     * Get the route key name for Laravel route model binding
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Check if the service is published (published_at in the past)
     */
    public function isPublished(): bool
    {
        return $this->published_at && $this->published_at->isPast();
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
}
