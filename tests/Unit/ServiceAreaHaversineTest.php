<?php

namespace Tests\Unit;

use App\Models\ServiceArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceAreaHaversineTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function haversine_formula_calculates_accurate_distance_warsaw_to_krakow(): void
    {
        // Arrange: Create Warsaw service area
        $warsaw = ServiceArea::factory()->create([
            'city_name' => 'Warszawa',
            'latitude' => 52.2297,
            'longitude' => 21.0122,
            'radius_km' => 50,
        ]);

        // Kraków coordinates
        $krakowLat = 50.0647;
        $krakowLng = 19.9450;

        // Act: Calculate distance
        $distance = $warsaw->calculateDistanceKm($krakowLat, $krakowLng);

        // Assert: Warsaw-Kraków is approximately 252km (±5km tolerance)
        $expectedDistance = 252;
        $tolerance = 5;

        $this->assertGreaterThanOrEqual($expectedDistance - $tolerance, $distance);
        $this->assertLessThanOrEqual($expectedDistance + $tolerance, $distance);
    }

    /** @test */
    public function haversine_formula_calculates_accurate_distance_warsaw_to_gdansk(): void
    {
        // Arrange: Create Warsaw service area
        $warsaw = ServiceArea::factory()->create([
            'city_name' => 'Warszawa',
            'latitude' => 52.2297,
            'longitude' => 21.0122,
            'radius_km' => 50,
        ]);

        // Gdańsk coordinates
        $gdanskLat = 54.3520;
        $gdanskLng = 18.6466;

        // Act: Calculate distance
        $distance = $warsaw->calculateDistanceKm($gdanskLat, $gdanskLng);

        // Assert: Warsaw-Gdańsk is approximately 280km (±5km tolerance)
        $expectedDistance = 280;
        $tolerance = 5;

        $this->assertGreaterThanOrEqual($expectedDistance - $tolerance, $distance);
        $this->assertLessThanOrEqual($expectedDistance + $tolerance, $distance);
    }

    /** @test */
    public function haversine_formula_calculates_accurate_distance_warsaw_to_poznan(): void
    {
        // Arrange: Create Warsaw service area
        $warsaw = ServiceArea::factory()->create([
            'city_name' => 'Warszawa',
            'latitude' => 52.2297,
            'longitude' => 21.0122,
            'radius_km' => 50,
        ]);

        // Poznań coordinates
        $poznanLat = 52.4064;
        $poznanLng = 16.9252;

        // Act: Calculate distance
        $distance = $warsaw->calculateDistanceKm($poznanLat, $poznanLng);

        // Assert: Warsaw-Poznań is approximately 278km (±5km tolerance)
        $expectedDistance = 278;
        $tolerance = 5;

        $this->assertGreaterThanOrEqual($expectedDistance - $tolerance, $distance);
        $this->assertLessThanOrEqual($expectedDistance + $tolerance, $distance);
    }

    /** @test */
    public function haversine_formula_returns_zero_for_same_location(): void
    {
        // Arrange: Create Warsaw service area
        $warsaw = ServiceArea::factory()->create([
            'city_name' => 'Warszawa',
            'latitude' => 52.2297,
            'longitude' => 21.0122,
            'radius_km' => 50,
        ]);

        // Act: Calculate distance to same location
        $distance = $warsaw->calculateDistanceKm(52.2297, 21.0122);

        // Assert: Distance should be 0 (or very close to 0 due to floating point)
        $this->assertLessThan(0.001, $distance);
    }

    /** @test */
    public function contains_location_returns_true_within_radius(): void
    {
        // Arrange: Create Warsaw service area with 50km radius
        $warsaw = ServiceArea::factory()->create([
            'city_name' => 'Warszawa',
            'latitude' => 52.2297,
            'longitude' => 21.0122,
            'radius_km' => 50,
        ]);

        // Act & Assert: Location 30km away (within radius)
        // Using approx. 1 degree latitude = 111km
        $nearbyLat = 52.2297 + (30 / 111); // ~30km north
        $this->assertTrue($warsaw->containsLocation($nearbyLat, 21.0122));
    }

    /** @test */
    public function contains_location_returns_false_outside_radius(): void
    {
        // Arrange: Create Warsaw service area with 50km radius
        $warsaw = ServiceArea::factory()->create([
            'city_name' => 'Warszawa',
            'latitude' => 52.2297,
            'longitude' => 21.0122,
            'radius_km' => 50,
        ]);

        // Act & Assert: Location 100km away (outside radius)
        // Using approx. 1 degree latitude = 111km
        $farLat = 52.2297 + (100 / 111); // ~100km north
        $this->assertFalse($warsaw->containsLocation($farLat, 21.0122));
    }

    /** @test */
    public function contains_location_returns_true_at_edge_of_radius(): void
    {
        // Arrange: Create Warsaw service area with 50km radius
        $warsaw = ServiceArea::factory()->create([
            'city_name' => 'Warszawa',
            'latitude' => 52.2297,
            'longitude' => 21.0122,
            'radius_km' => 50,
        ]);

        // Act & Assert: Location approximately 49km away (near edge, within radius)
        // Using approx. 1 degree latitude = 111km
        $edgeLat = 52.2297 + (49 / 111); // ~49km north
        $this->assertTrue($warsaw->containsLocation($edgeLat, 21.0122));
    }

    /** @test */
    public function contains_location_handles_small_radius(): void
    {
        // Arrange: Create service area with 1km radius
        $tinyArea = ServiceArea::factory()->create([
            'city_name' => 'Test Area',
            'latitude' => 52.2297,
            'longitude' => 21.0122,
            'radius_km' => 1,
        ]);

        // Act & Assert: Location 0.5km away (within radius)
        $nearbyLat = 52.2297 + (0.5 / 111);
        $this->assertTrue($tinyArea->containsLocation($nearbyLat, 21.0122));

        // Act & Assert: Location 2km away (outside radius)
        $farLat = 52.2297 + (2 / 111);
        $this->assertFalse($tinyArea->containsLocation($farLat, 21.0122));
    }

    /** @test */
    public function contains_location_handles_large_radius(): void
    {
        // Arrange: Create service area with 200km radius
        $largeArea = ServiceArea::factory()->create([
            'city_name' => 'Large Area',
            'latitude' => 52.2297,
            'longitude' => 21.0122,
            'radius_km' => 200,
        ]);

        // Act & Assert: Kraków (252km away) should be outside
        $this->assertFalse($largeArea->containsLocation(50.0647, 19.9450));

        // Act & Assert: Location 150km away should be inside
        $withinLat = 52.2297 + (150 / 111);
        $this->assertTrue($largeArea->containsLocation($withinLat, 21.0122));
    }

    /** @test */
    public function haversine_handles_negative_coordinates(): void
    {
        // Arrange: Create service area at negative coordinates (e.g., South America)
        $southAmerica = ServiceArea::factory()->create([
            'city_name' => 'Test Area',
            'latitude' => -23.5505,
            'longitude' => -46.6333,
            'radius_km' => 50,
        ]);

        // Act: Calculate distance to nearby point
        $distance = $southAmerica->calculateDistanceKm(-23.5000, -46.6000);

        // Assert: Should calculate reasonable distance
        $this->assertGreaterThan(0, $distance);
        $this->assertLessThan(100, $distance);
    }

    /** @test */
    public function haversine_handles_antimeridian_crossing(): void
    {
        // Arrange: Create service area near antimeridian (180°)
        $pacific = ServiceArea::factory()->create([
            'city_name' => 'Pacific Area',
            'latitude' => 0,
            'longitude' => 179,
            'radius_km' => 50,
        ]);

        // Act: Calculate distance across antimeridian
        $distance = $pacific->calculateDistanceKm(0, -179);

        // Assert: Should be relatively small distance (not halfway around Earth)
        $this->assertLessThan(300, $distance);
    }

    /** @test */
    public function haversine_handles_equator(): void
    {
        // Arrange: Create service area at equator
        $equator = ServiceArea::factory()->create([
            'city_name' => 'Equator Area',
            'latitude' => 0,
            'longitude' => 0,
            'radius_km' => 50,
        ]);

        // Act: Calculate distance along equator
        $distance = $equator->calculateDistanceKm(0, 1); // 1 degree longitude

        // Assert: Should be approximately 111km (at equator, 1° ≈ 111km)
        $this->assertGreaterThan(100, $distance);
        $this->assertLessThan(120, $distance);
    }

    /** @test */
    public function haversine_handles_poles(): void
    {
        // Arrange: Create service area near North Pole
        $arctic = ServiceArea::factory()->create([
            'city_name' => 'Arctic Area',
            'latitude' => 89,
            'longitude' => 0,
            'radius_km' => 50,
        ]);

        // Act: Calculate distance at high latitude
        $distance = $arctic->calculateDistanceKm(89, 10);

        // Assert: Should calculate reasonable distance
        $this->assertGreaterThan(0, $distance);
        $this->assertLessThan(200, $distance);
    }

    /** @test */
    public function contains_location_is_symmetric(): void
    {
        // Arrange: Create two service areas at same locations
        $area1 = ServiceArea::factory()->create([
            'latitude' => 52.2297,
            'longitude' => 21.0122,
            'radius_km' => 50,
        ]);

        $area2 = ServiceArea::factory()->create([
            'latitude' => 52.4064,
            'longitude' => 16.9252,
            'radius_km' => 50,
        ]);

        // Act: Check if distance calculation is symmetric
        $distance1 = $area1->calculateDistanceKm($area2->latitude, $area2->longitude);
        $distance2 = $area2->calculateDistanceKm($area1->latitude, $area1->longitude);

        // Assert: Distance should be the same in both directions
        $this->assertEquals(round($distance1, 2), round($distance2, 2));
    }
}
