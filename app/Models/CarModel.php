<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'car_brand_id',
        'name',
        'slug',
        'year_from',
        'year_to',
        'status',
    ];

    protected $casts = [
        'year_from' => 'integer',
        'year_to' => 'integer',
        'status' => 'string',
    ];

    /**
     * Get the brand for this model
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(CarBrand::class, 'car_brand_id');
    }

    /**
     * Get all vehicle types for this model
     */
    public function vehicleTypes(): BelongsToMany
    {
        return $this->belongsToMany(VehicleType::class, 'vehicle_type_car_model')
            ->withTimestamps();
    }

    /**
     * Get all appointments for this model
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Get full name (brand + model)
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->brand ? $this->brand->name.' ' : '').$this->name,
        );
    }

    /**
     * Scope to get only active models
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get only pending models
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to filter by brand
     */
    public function scopeForBrand($query, int $brandId)
    {
        return $query->where('car_brand_id', $brandId);
    }

    /**
     * Scope to filter by vehicle type
     */
    public function scopeForVehicleType($query, int $typeId)
    {
        return $query->whereHas('vehicleTypes', fn ($q) => $q->where('vehicle_types.id', $typeId));
    }
}
