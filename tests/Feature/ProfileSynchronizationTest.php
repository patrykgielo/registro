<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $staff;

    protected Service $service;

    protected int $vehicleTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user with empty profile
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'email_verified_at' => now(),
            'first_name' => null,
            'last_name' => null,
            'phone_e164' => null,
            'street_name' => null,
            'street_number' => null,
            'city' => null,
            'postal_code' => null,
            'access_notes' => null,
        ]);

        // Create test service
        $this->service = Service::factory()->create([
            'name' => 'Test Service',
            'duration_minutes' => 60,
            'price' => 100,
        ]);

        // Get first vehicle type from seeder
        $this->vehicleTypeId = \App\Models\VehicleType::first()->id;

        // Create staff member with availability
        $this->staff = User::factory()->create([
            'email' => 'staff@example.com',
            'email_verified_at' => now(),
        ]);
        $this->staff->assignRole('staff');

        // Create staff schedules for all weekdays (Mon-Fri) to cover test appointments
        for ($day = 1; $day <= 5; $day++) {
            $this->staff->staffSchedules()->create([
                'day_of_week' => $day,
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'effective_from' => now()->subWeek(),
                'effective_until' => now()->addYear(),
            ]);
        }

        // Assign service to staff
        $this->staff->services()->attach($this->service->id);
    }

    /**
     * Helper method to find next valid working day (Mon-Fri) that staff works
     */
    protected function getNextWorkingDay(): Carbon
    {
        $date = Carbon::now()->addDays(2); // Start 2 days from now for 24h advance booking

        // If it's Saturday or Sunday, move to next Monday
        while ($date->dayOfWeek === Carbon::SATURDAY || $date->dayOfWeek === Carbon::SUNDAY) {
            $date->addDay();
        }

        return $date;
    }

    /**
     * Helper method to generate complete booking data with all required fields
     */
    protected function getBookingData(array $overrides = []): array
    {
        // Use next working day to meet 24-hour advance booking + staff availability
        $appointmentDate = $this->getNextWorkingDay()->format('Y-m-d');

        return array_merge([
            'service_id' => $this->service->id,
            'staff_id' => $this->staff->id,
            'appointment_date' => $appointmentDate,
            'start_time' => '10:00',
            'end_time' => '11:00',
            // Profile fields
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'phone_e164' => '+48501234567',
            'street_name' => 'Marszałkowska',
            'street_number' => '12/34',
            'city' => 'Warszawa',
            'postal_code' => '00-000',
            'access_notes' => 'Kod do bramy: 1234',
            'notes' => 'Test appointment',
            // Google Maps location fields (REQUIRED)
            'location_address' => 'Marszałkowska 12/34, 00-000 Warszawa, Polska',
            'location_latitude' => 52.2297,
            'location_longitude' => 21.0122,
            'location_place_id' => 'ChIJAZ-GmmbMHkcRJz90Y5b8Jf8',
            'location_components' => json_encode([
                ['long_name' => 'Warszawa', 'short_name' => 'Warszawa', 'types' => ['locality']],
                ['long_name' => 'Polska', 'short_name' => 'PL', 'types' => ['country']],
            ]),
            // Vehicle fields (REQUIRED)
            'vehicle_type_id' => $this->vehicleTypeId,
            'vehicle_year' => 2020,
        ], $overrides);
    }

    /** @test */
    public function booking_create_redirects_to_wizard()
    {
        // v0.7.0+: booking.create is deprecated and redirects to new wizard
        $response = $this->actingAs($this->user)
            ->get(route('booking.create', $this->service));

        $response->assertStatus(302);
        $response->assertRedirect(route('booking.step', 1));
        $this->assertEquals($this->service->id, session('booking.service_id'));
    }

    /** @test */
    public function first_booking_saves_profile_data()
    {
        $bookingData = $this->getBookingData();

        $response = $this->actingAs($this->user)
            ->post(route('appointments.store'), $bookingData);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('appointments.index'));

        // Verify basic profile fields were updated
        $this->user->refresh();
        $this->assertEquals('Jan', $this->user->first_name);
        $this->assertEquals('Kowalski', $this->user->last_name);
        $this->assertEquals('+48501234567', $this->user->phone_e164);

        // v0.7.0+: New wizard only collects Google Maps full address (not individual fields)
        // Individual address fields only saved by old appointments.store endpoint
        $this->assertEquals('Marszałkowska', $this->user->street_name);
        $this->assertEquals('12/34', $this->user->street_number);
        $this->assertEquals('Warszawa', $this->user->city);
        $this->assertEquals('00-000', $this->user->postal_code);
        $this->assertEquals('Kod do bramy: 1234', $this->user->access_notes);
    }

    // Test removed in v0.7.0 - booking.create is deprecated and redirects to wizard
    // New wizard uses multi-step flow with session state, not a single form view

    /** @test */
    public function second_booking_does_not_overwrite_existing_profile_data()
    {
        // Set initial profile data
        $this->user->update([
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'phone_e164' => '+48501234567',
            'street_name' => 'Marszałkowska',
            'street_number' => '12/34',
            'city' => 'Warszawa',
            'postal_code' => '00-000',
            'access_notes' => 'Kod do bramy: 1234',
        ]);

        // Different data in booking form (should NOT overwrite existing profile)
        $bookingData = $this->getBookingData([
            'first_name' => 'Adam',
            'last_name' => 'Nowak',
            'phone_e164' => '+48600999888',
            'street_name' => 'Nowa',
            'street_number' => '99',
            'city' => 'Kraków',
            'postal_code' => '30-000',
            'access_notes' => 'Nowy kod: 9999',
            'notes' => 'Second appointment',
        ]);

        $this->actingAs($this->user)
            ->post(route('appointments.store'), $bookingData);

        // Verify profile was NOT overwritten
        $this->user->refresh();
        $this->assertEquals('Jan', $this->user->first_name); // Original data preserved
        $this->assertEquals('Kowalski', $this->user->last_name);
        $this->assertEquals('+48501234567', $this->user->phone_e164);
        $this->assertEquals('Marszałkowska', $this->user->street_name);
        $this->assertEquals('12/34', $this->user->street_number);
        $this->assertEquals('Warszawa', $this->user->city);
        $this->assertEquals('00-000', $this->user->postal_code);
        $this->assertEquals('Kod do bramy: 1234', $this->user->access_notes);
    }

    /** @test */
    public function partial_profile_only_fills_empty_fields()
    {
        // User has partial profile
        $this->user->update([
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'phone_e164' => '+48501234567',
            // Address fields empty
        ]);

        $bookingData = $this->getBookingData([
            'first_name' => 'Adam', // Won't overwrite
            'last_name' => 'Nowak', // Won't overwrite
            'phone_e164' => '+48600999888', // Won't overwrite
            'street_name' => 'Marszałkowska', // Will save
            'street_number' => '12/34', // Will save
            'city' => 'Warszawa', // Will save
            'postal_code' => '00-000', // Will save
            'access_notes' => 'Kod: 1234', // Will save
        ]);

        $this->actingAs($this->user)
            ->post(route('appointments.store'), $bookingData);

        // Verify only empty fields were filled
        $this->user->refresh();
        $this->assertEquals('Jan', $this->user->first_name); // Not overwritten
        $this->assertEquals('Kowalski', $this->user->last_name); // Not overwritten
        $this->assertEquals('+48501234567', $this->user->phone_e164); // Not overwritten
        $this->assertEquals('Marszałkowska', $this->user->street_name); // Filled
        $this->assertEquals('12/34', $this->user->street_number); // Filled
        $this->assertEquals('Warszawa', $this->user->city); // Filled
        $this->assertEquals('00-000', $this->user->postal_code); // Filled
        $this->assertEquals('Kod: 1234', $this->user->access_notes); // Filled
    }

    /** @test */
    public function optional_address_fields_only_save_when_provided()
    {
        $bookingData = $this->getBookingData([
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'phone_e164' => '+48501234567',
            // Optional address fields omitted (set to null)
            'street_name' => null,
            'street_number' => null,
            'city' => null,
            'postal_code' => null,
            'access_notes' => null,
            'notes' => 'Test appointment',
        ]);

        $this->actingAs($this->user)
            ->post(route('appointments.store'), $bookingData);

        // Verify required fields saved, optional fields remain null
        $this->user->refresh();
        $this->assertEquals('Jan', $this->user->first_name);
        $this->assertEquals('Kowalski', $this->user->last_name);
        $this->assertEquals('+48501234567', $this->user->phone_e164);
        $this->assertNull($this->user->street_name);
        $this->assertNull($this->user->street_number);
        $this->assertNull($this->user->city);
        $this->assertNull($this->user->postal_code);
        $this->assertNull($this->user->access_notes);
    }
}
