<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceArea extends Model
{
    use HasFactory;

    protected $fillable = [
        'city_name',
        'latitude',
        'longitude',
        'radius_km',
        'is_active',
        'sort_order',
        'description',
        'color_hex',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'radius_km' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Scope: Only active service areas
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Ordered by sort_order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('city_name');
    }

    /**
     * Check if given coordinates are within this service area
     */
    public function containsLocation(float $latitude, float $longitude): bool
    {
        $distance = $this->calculateDistanceKm($latitude, $longitude);

        return $distance <= $this->radius_km;
    }

    /**
     * Calculate distance from service area center to given coordinates using Haversine formula
     *
     * @param  float  $latitude  Target latitude
     * @param  float  $longitude  Target longitude
     * @return float Distance in kilometers
     */
    public function calculateDistanceKm(float $latitude, float $longitude): float
    {
        return $this->haversineDistance(
            (float) $this->latitude,
            (float) $this->longitude,
            $latitude,
            $longitude
        );
    }

    /**
     * Haversine formula implementation
     * Calculates great-circle distance between two points on Earth
     *
     * @param  float  $lat1  Latitude of point 1
     * @param  float  $lon1  Longitude of point 1
     * @param  float  $lat2  Latitude of point 2
     * @param  float  $lon2  Longitude of point 2
     * @return float Distance in kilometers
     */
    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    /**
     * Get radius in meters (for JavaScript Google Maps API)
     */
    protected function radiusMeters(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->radius_km * 1000,
        );
    }

    /**
     * Get coordinates as array (for JavaScript)
     */
    protected function coordinates(): Attribute
    {
        return Attribute::make(
            get: fn () => [
                'lat' => (float) $this->latitude,
                'lng' => (float) $this->longitude,
            ],
        );
    }

    /**
     * Relationship: Waitlist entries requesting this area
     */
    public function waitlistEntries()
    {
        return $this->hasMany(ServiceAreaWaitlist::class, 'nearest_area_city', 'city_name');
    }
}
