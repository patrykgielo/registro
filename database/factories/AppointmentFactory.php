<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Service;
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
        // Minute precision only: start_time/end_time are never scheduled to a
        // specific second in the real booking flow (TimePicker UI + the
        // 'datetime:H:i' model cast are both H:i-only), and the model's own
        // static::saving() hook re-derives a canonical ':00' second on EVERY
        // save regardless of what was there before. fake()->time('H:i:s')
        // generating a random second meant any subsequent no-op save (edit +
        // resave with zero changes) always mutated the row -- the exact same
        // class of factory/schema-precision mismatch as RentalFactory's
        // start_date/end_date (see RentalFactory.php). Regression: PanelWalkthroughTest, 2026-08-30.
        $startTime = fake()->time('H:i').':00';

        return [
            'service_id' => Service::factory(),
            'customer_id' => \App\Models\User::factory(),
            'appointment_date' => $appointmentDate->format('Y-m-d'),
            'start_time' => $startTime,
            'end_time' => date('H:i:s', strtotime($startTime) + 3600),
            'status' => AppointmentStatus::Pending,
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
