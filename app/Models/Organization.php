<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Industry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    /**
     * Default modules per booking_type.
     * Override per-tenant via settings.modules JSON.
     */
    private const MODULE_DEFAULTS = [
        'time_slot' => ['services', 'bookings', 'website'],
        'item_rental' => ['rentals', 'website'],
        'both' => ['services', 'bookings', 'rentals', 'website'],
    ];

    /**
     * Default feature flags per booking_type.
     * Override per-tenant via settings.features JSON.
     */
    private const FEATURE_DEFAULTS = [
        'time_slot' => [
            'vehicles' => false,
            'mobile_service' => false,
            'service_area' => false,
        ],
        'item_rental' => [
            'vehicles' => false,
            'mobile_service' => false,
            'service_area' => false,
        ],
        'both' => [
            'vehicles' => false,
            'mobile_service' => false,
            'service_area' => false,
        ],
    ];

    protected $fillable = [
        'name',
        'slug',
        'booking_type',
        'industry',
        'owner_id',
        'is_active',
        'settings',
        'trial_ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'industry' => Industry::class,
        'settings' => 'array',
        'trial_ends_at' => 'datetime',
    ];

    /**
     * Get the owner of this organization.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get all members (users) of this organization.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get services belonging to this organization.
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Get appointments belonging to this organization.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Get rental categories belonging to this organization.
     */
    public function rentalCategories(): HasMany
    {
        return $this->hasMany(RentalCategory::class);
    }

    /**
     * Get rental services (item_rental type) belonging to this organization.
     */
    public function rentalItems(): HasMany
    {
        return $this->hasMany(Service::class)->where('service_type', \App\Enums\ServiceType::ItemRental->value);
    }

    /**
     * Get rentals belonging to this organization.
     */
    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    /**
     * Get settings for this organization.
     */
    public function settingRecords(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    /**
     * Check if this organization supports item rentals.
     */
    public function supportsRentals(): bool
    {
        return in_array($this->booking_type, ['item_rental', 'both']);
    }

    /**
     * Check if this organization supports time-slot appointments.
     */
    public function supportsAppointments(): bool
    {
        return in_array($this->booking_type, ['time_slot', 'both']);
    }

    /**
     * Check if organization is on trial.
     */
    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    /**
     * Check if organization trial has expired.
     */
    public function trialExpired(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isPast();
    }

    /**
     * Get a setting value with fallback to global defaults.
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        $value = data_get($this->settings, $key);

        return $value !== null ? $value : $default;
    }

    /**
     * Check if a feature is enabled for this organization.
     * Priority: explicit override > industry defaults > booking_type defaults.
     */
    public function hasFeature(string $feature): bool
    {
        $override = data_get($this->settings, "features.{$feature}");

        if ($override !== null) {
            return (bool) $override;
        }

        if ($this->industry !== null) {
            $industryDefaults = $this->industry->defaultFeatures();
            if (isset($industryDefaults[$feature])) {
                return $industryDefaults[$feature];
            }
        }

        return self::FEATURE_DEFAULTS[$this->booking_type][$feature] ?? false;
    }

    /**
     * Enable a feature for this organization.
     */
    public function enableFeature(string $feature): void
    {
        $settings = $this->settings ?? [];
        data_set($settings, "features.{$feature}", true);
        $this->update(['settings' => $settings]);
    }

    /**
     * Disable a feature for this organization.
     */
    public function disableFeature(string $feature): void
    {
        $settings = $this->settings ?? [];
        data_set($settings, "features.{$feature}", false);
        $this->update(['settings' => $settings]);
    }

    /**
     * Check if a module is enabled for this organization.
     * Priority: explicit override > industry defaults > booking_type defaults.
     */
    public function hasModule(string $module): bool
    {
        $override = data_get($this->settings, "modules.{$module}");

        if ($override !== null) {
            return (bool) $override;
        }

        if ($this->industry !== null) {
            return in_array($module, $this->industry->defaultModules(), true);
        }

        return in_array($module, self::MODULE_DEFAULTS[$this->booking_type] ?? [], true);
    }

    /**
     * Enable a module for this organization.
     */
    public function enableModule(string $module): void
    {
        $settings = $this->settings ?? [];
        data_set($settings, "modules.{$module}", true);
        $this->update(['settings' => $settings]);
    }

    /**
     * Disable a module for this organization.
     */
    public function disableModule(string $module): void
    {
        $settings = $this->settings ?? [];
        data_set($settings, "modules.{$module}", false);
        $this->update(['settings' => $settings]);
    }

    /**
     * Get industry-specific terminology.
     * Falls back to default terms if no industry set.
     */
    public function term(string $key): string
    {
        $defaults = [
            'service' => 'usługa',
            'booking' => 'rezerwacja',
            'customer' => 'klient',
        ];

        if ($this->industry !== null) {
            return $this->industry->terminology()[$key] ?? $defaults[$key] ?? $key;
        }

        return $defaults[$key] ?? $key;
    }
}
