<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserVehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vehicle_type_id',
        'car_brand_id',
        'car_model_id',
        'custom_brand',
        'custom_model',
        'year',
        'nickname',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'year' => 'integer',
    ];

    /**
     * Get the user that owns this vehicle.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the vehicle type.
     */
    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    /**
     * Get the car brand (if selected from database).
     */
    public function carBrand(): BelongsTo
    {
        return $this->belongsTo(CarBrand::class);
    }

    /**
     * Get the car model (if selected from database).
     */
    public function carModel(): BelongsTo
    {
        return $this->belongsTo(CarModel::class);
    }

    /**
     * Get the display name for this vehicle.
     * Returns nickname if set, otherwise constructs from brand/model/year.
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->nickname) {
            return $this->nickname;
        }

        $parts = [];

        // Brand
        if ($this->carBrand) {
            $parts[] = $this->carBrand->name;
        } elseif ($this->custom_brand) {
            $parts[] = $this->custom_brand;
        }

        // Model
        if ($this->carModel) {
            $parts[] = $this->carModel->name;
        } elseif ($this->custom_model) {
            $parts[] = $this->custom_model;
        }

        // Year
        if ($this->year) {
            $parts[] = '('.$this->year.')';
        }

        if (empty($parts)) {
            return $this->vehicleType?->name ?? __('Pojazd');
        }

        return implode(' ', $parts);
    }

    /**
     * Get the brand name (from DB or custom).
     */
    public function getBrandNameAttribute(): ?string
    {
        return $this->carBrand?->name ?? $this->custom_brand;
    }

    /**
     * Get the model name (from DB or custom).
     */
    public function getModelNameAttribute(): ?string
    {
        return $this->carModel?->name ?? $this->custom_model;
    }

    /**
     * Scope to filter vehicles for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get only default vehicles.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
