<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceArea;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Models\VehicleType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tests for service area validation bypass prevention in booking flow.
 *
 * SECURITY: These tests verify that even if frontend validation is bypassed,
 * the backend will still reject bookings outside the service area.
 */
class BookingServiceAreaBypassTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Service $service;

    protected User $staff;

    protected VehicleType $vehicleType;

    protected ServiceArea $warsawArea;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache
        Cache::flush();

        // Seed database with required data
        $this->artisan('db:seed', ['--class' => 'ServiceSeeder']);
        $this->artisan('db:seed', ['--class' => 'VehicleTypeSeeder']);

        $this->user = User::factory()->create();

        // Create staff user with "staff" role
        $this->staff = User::factory()->create();
        $staffRole = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $this->staff->assignRole($staffRole);

        // Create staff schedule for Monday-Friday 09:00-18:00
        for ($day = Carbon::MONDAY; $day <= Carbon::FRIDAY; $day++) {
            StaffSchedule::create([
                'user_id' => $this->staff->id,
                'day_of_week' => $day,
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'is_active' => true,
            ]);
        }

        $this->service = Service::first();
        $this->vehicleType = VehicleType::first();

        // Attach service to staff member
        $this->staff->services()->attach($this->service->id);

        // Create Warsaw service area (only area available)
        $this->warsawArea = ServiceArea::factory()->create([
            'city_name' => 'Warszawa',
            'latitude' => 52.2297,
            'longitude' => 21.0122,
            'radius_km' => 50,
            'is_active' => true,
        ]);
    }

    protected function getNextWorkingDay(): Carbon
    {
        $date = Carbon::now()->addDays(2);

        while ($date->dayOfWeek === Carbon::SATURDAY || $date->dayOfWeek === Carbon::SUNDAY) {
            $date->addDay();
        }

        return $date;
    }

    /**
     * Test that confirm() blocks booking with coordinates outside service area.
     *
     * SCENARIO: User bypasses frontend validation (dismisses alert) and attempts
     * to submit booking with Poznań coordinates (outside Warsaw 50km radius).
     */
    public function test_confirm_blocks_booking_outside_service_area(): void
    {
        // Arrange: Set up session with valid booking data but INVALID coordinates (Poznań)
        session([
            'booking' => [
                'service_id' => $this->service->id,
                'date' => $this->getNextWorkingDay()->format('Y-m-d'),
                'time_slot' => '10:00',
                'vehicle_type_id' => $this->vehicleType->id,
                'location_address' => 'Stary Rynek, Poznań',  // Fake valid address
                'location_latitude' => 52.4064,  // Poznań coordinates (260km from Warsaw)
                'location_longitude' => 16.9252,
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'email' => 'jan@example.com',
                'phone' => '+48123456789',
            ],
        ]);

        // Act: Submit confirmation (simulating frontend bypass)
        $response = $this->actingAs($this->user)
            ->post(route('booking.confirm'));

        // Assert: Should redirect back to step 3 with error
        $response->assertRedirect(route('booking.step', 3));
        $response->assertSessionHas('error');

        // Verify NO appointment was created
        $this->assertDatabaseMissing('appointments', [
            'customer_id' => $this->user->id,
            'email' => 'jan@example.com',
        ]);
    }

    /**
     * Test that confirm() allows booking with coordinates inside service area.
     */
    public function test_confirm_allows_booking_inside_service_area(): void
    {
        // Arrange: Set up session with valid booking data AND valid coordinates (Warsaw)
        session([
            'booking' => [
                'service_id' => $this->service->id,
                'date' => $this->getNextWorkingDay()->format('Y-m-d'),
                'time_slot' => '10:00',
                'vehicle_type_id' => $this->vehicleType->id,
                'location_address' => 'Plac Defilad 1, Warszawa',
                'location_latitude' => 52.2297,  // Warsaw center (inside 50km radius)
                'location_longitude' => 21.0122,
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'email' => 'jan@example.com',
                'phone' => '+48123456789',
            ],
        ]);

        // Act: Submit confirmation
        $response = $this->actingAs($this->user)
            ->post(route('booking.confirm'));

        // Assert: Should redirect to confirmation page
        $response->assertRedirect(route('booking.confirmation'));

        // Verify appointment WAS created
        $this->assertDatabaseHas('appointments', [
            'customer_id' => $this->user->id,
            'email' => 'jan@example.com',
        ]);
    }

    /**
     * Test that confirm() blocks booking when coordinates are missing.
     *
     * SCENARIO: User somehow bypassed step 3 validation without selecting a location.
     */
    public function test_confirm_blocks_booking_without_coordinates(): void
    {
        // Arrange: Set up session WITHOUT coordinates
        session([
            'booking' => [
                'service_id' => $this->service->id,
                'date' => $this->getNextWorkingDay()->format('Y-m-d'),
                'time_slot' => '10:00',
                'vehicle_type_id' => $this->vehicleType->id,
                'location_address' => 'Some Address',
                // location_latitude and location_longitude are MISSING
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'email' => 'jan@example.com',
                'phone' => '+48123456789',
            ],
        ]);

        // Act: Submit confirmation
        $response = $this->actingAs($this->user)
            ->post(route('booking.confirm'));

        // Assert: Should redirect back to step 3 with error
        $response->assertRedirect(route('booking.step', 3));
        $response->assertSessionHas('error');

        // Verify NO appointment was created
        $this->assertDatabaseMissing('appointments', [
            'customer_id' => $this->user->id,
            'email' => 'jan@example.com',
        ]);
    }

    /**
     * Test that confirm() blocks booking at edge of service area (just outside).
     *
     * SCENARIO: Location is approximately 51km from Warsaw center (just outside 50km radius).
     */
    public function test_confirm_blocks_booking_just_outside_radius(): void
    {
        // Calculate point approximately 51km north of Warsaw (just outside 50km radius)
        // 1 degree latitude ≈ 111km
        $justOutsideLatitude = 52.2297 + (51 / 111);

        // Arrange: Set up session with coordinates just outside radius
        session([
            'booking' => [
                'service_id' => $this->service->id,
                'date' => $this->getNextWorkingDay()->format('Y-m-d'),
                'time_slot' => '10:00',
                'vehicle_type_id' => $this->vehicleType->id,
                'location_address' => 'Somewhere just north of Warsaw',
                'location_latitude' => $justOutsideLatitude,
                'location_longitude' => 21.0122,
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'email' => 'jan@example.com',
                'phone' => '+48123456789',
            ],
        ]);

        // Act: Submit confirmation
        $response = $this->actingAs($this->user)
            ->post(route('booking.confirm'));

        // Assert: Should redirect back to step 3 with error
        $response->assertRedirect(route('booking.step', 3));
        $response->assertSessionHas('error');

        // Verify NO appointment was created
        $this->assertDatabaseMissing('appointments', [
            'customer_id' => $this->user->id,
            'email' => 'jan@example.com',
        ]);
    }

    /**
     * Test that confirm() allows booking at edge of service area (just inside).
     */
    public function test_confirm_allows_booking_just_inside_radius(): void
    {
        // Calculate point approximately 49km north of Warsaw (just inside 50km radius)
        $justInsideLatitude = 52.2297 + (49 / 111);

        // Arrange: Set up session with coordinates just inside radius
        session([
            'booking' => [
                'service_id' => $this->service->id,
                'date' => $this->getNextWorkingDay()->format('Y-m-d'),
                'time_slot' => '10:00',
                'vehicle_type_id' => $this->vehicleType->id,
                'location_address' => 'Somewhere just north of Warsaw (inside)',
                'location_latitude' => $justInsideLatitude,
                'location_longitude' => 21.0122,
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'email' => 'jan@example.com',
                'phone' => '+48123456789',
            ],
        ]);

        // Act: Submit confirmation
        $response = $this->actingAs($this->user)
            ->post(route('booking.confirm'));

        // Assert: Should redirect to confirmation page
        $response->assertRedirect(route('booking.confirmation'));

        // Verify appointment WAS created
        $this->assertDatabaseHas('appointments', [
            'customer_id' => $this->user->id,
            'email' => 'jan@example.com',
        ]);
    }

    /**
     * Test that confirm() properly returns error message for invalid location.
     */
    public function test_confirm_returns_proper_error_message(): void
    {
        // Arrange: Set up session with invalid coordinates
        session([
            'booking' => [
                'service_id' => $this->service->id,
                'date' => $this->getNextWorkingDay()->format('Y-m-d'),
                'time_slot' => '10:00',
                'vehicle_type_id' => $this->vehicleType->id,
                'location_address' => 'Gdańsk',
                'location_latitude' => 54.3520,  // Gdańsk (far from Warsaw)
                'location_longitude' => 18.6466,
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'email' => 'jan@example.com',
                'phone' => '+48123456789',
            ],
        ]);

        // Act: Submit confirmation
        $response = $this->actingAs($this->user)
            ->post(route('booking.confirm'));

        // Assert: Should have meaningful error message
        $response->assertSessionHas('error');
        $errorMessage = session('error');
        // Error message contains Polish word "lokalizacja" (location)
        $this->assertStringContainsString('lokalizacja', $errorMessage);
    }
}
