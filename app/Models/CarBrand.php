<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarBrand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    /**
     * Get all models for this brand
     */
    public function models(): HasMany
    {
        return $this->hasMany(CarModel::class);
    }

    /**
     * Get all appointments for this brand
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Scope to get only active brands
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get only pending brands
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to filter by status
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
