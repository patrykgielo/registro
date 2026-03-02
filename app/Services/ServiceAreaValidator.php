<?php

namespace App\Services;

use App\Models\ServiceArea;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ServiceAreaValidator
{
    private const CACHE_KEY = 'service_areas:active';

    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Validate if coordinates are within any active service area
     *
     * @return array ['valid' => bool, 'area' => ?ServiceArea, 'nearest' => ?array, 'message' => ?string]
     */
    public function validate(float $latitude, float $longitude): array
    {
        $serviceAreas = $this->getActiveServiceAreas();

        if ($serviceAreas->isEmpty()) {
            // Failsafe: if no areas configured, allow all bookings
            return [
                'valid' => true,
                'area' => null,
                'nearest' => null,
                'message' => null,
            ];
        }

        // Find if coordinates are within any service area
        $matchingArea = $serviceAreas->first(function (ServiceArea $area) use ($latitude, $longitude) {
            return $area->containsLocation($latitude, $longitude);
        });

        if ($matchingArea) {
            return [
                'valid' => true,
                'area' => $matchingArea,
                'nearest' => null,
                'message' => null,
            ];
        }

        // Not within any area - find nearest area and provide available areas info
        $nearestArea = $this->findNearestArea($serviceAreas, $latitude, $longitude);

        return [
            'valid' => false,
            'area' => null,
            'nearest' => $nearestArea,
            'message' => $this->getErrorMessage($nearestArea),
            'available_areas' => $this->getAvailableAreasInfo($serviceAreas),
        ];
    }

    /**
     * Get all active service areas (cached)
     */
    public function getActiveServiceAreas(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return ServiceArea::active()->ordered()->get();
        });
    }

    /**
     * Get service areas for client-side map display (public data only)
     */
    public function getPublicServiceAreas(): array
    {
        return $this->getActiveServiceAreas()->map(function (ServiceArea $area) {
            return [
                'city' => $area->city_name,
                'center' => $area->coordinates,
                'radius' => $area->radius_meters,
                'color' => $area->color_hex,
            ];
        })->values()->toArray();
    }

    /**
     * Find nearest service area to given coordinates
     */
    private function findNearestArea(Collection $serviceAreas, float $latitude, float $longitude): ?array
    {
        $nearestArea = null;
        $minDistance = PHP_FLOAT_MAX;

        foreach ($serviceAreas as $area) {
            $distance = $area->calculateDistanceKm($latitude, $longitude);

            if ($distance < $minDistance) {
                $minDistance = $distance;
                $nearestArea = [
                    'city' => $area->city_name,
                    'distance_km' => round($distance, 1),
                    'area_id' => $area->id,
                ];
            }
        }

        return $nearestArea;
    }

    /**
     * Get localized error message
     */
    private function getErrorMessage(?array $nearestArea): string
    {
        if (! $nearestArea) {
            return __('service_area.validation.not_available');
        }

        return __('service_area.validation.outside_area', [
            'city' => $nearestArea['city'],
            'distance' => $nearestArea['distance_km'],
        ]);
    }

    /**
     * Get available areas info for display to user
     */
    private function getAvailableAreasInfo(Collection $serviceAreas): array
    {
        return $serviceAreas->map(function (ServiceArea $area) {
            return [
                'city' => $area->city_name,
                'radius_km' => $area->radius_km,
            ];
        })->values()->toArray();
    }

    /**
     * Clear service areas cache (call after admin updates)
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Warm cache (call after clearing or on deployment)
     */
    public function warmCache(): void
    {
        $this->clearCache();
        $this->getActiveServiceAreas();
    }
}
