<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $appointmentDate = fake()->dateTimeBetween('now', '+30 days');
        $startTime = fake()->time('H:i:s');

        return [
            'appointment_date' => $appointmentDate->format('Y-m-d'),
            'start_time' => $startTime,
            'end_time' => date('H:i:s', strtotime($startTime) + 3600),
            'status' => 'pending',
            'vehicle_type_id' => \App\Models\VehicleType::factory(),
            'location_address' => fake()->address(),
            'location_latitude' => fake()->latitude(),
            'location_longitude' => fake()->longitude(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'notify_email' => true,
            'notify_sms' => false,
        ];
    }
}
