<?php

namespace Database\Factories;

use App\Enums\RentalStatus;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Rental>
 */
class RentalFactory extends Factory
{
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-30 days', '+30 days');
        $endDate = (clone $startDate)->modify('+'.fake()->numberBetween(1, 14).' days');
        $unitPrice = fake()->randomFloat(2, 50, 500);
        $days = max(1, (int) $startDate->diff($endDate)->days + 1);

        return [
            'organization_id' => Organization::factory(),
            'service_id' => Service::factory()->itemRental(),
            'customer_id' => User::factory(),
            'quantity' => 1,
            // 'date' cast on the model (rentals.start_date/end_date are DATE columns, never
            // datetime) -- fake()->dateTimeBetween() returns a DateTime WITH a random time
            // component, which SQLite (used by the test suite) happily stores verbatim,
            // producing a spurious no-op-save diff every time the model's own 'date' cast
            // round-trips it back to midnight (PanelWalkthroughTest, 2026-08-30). MySQL would
            // have truncated it silently at INSERT time instead -- this was never a production
            // bug, only a fixture/schema mismatch invisible on SQLite (see tests.md's "MySQL
            // 8.0 gate" for the general class of this gap).
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'pricing_unit' => 'daily',
            'unit_price_at_booking' => $unitPrice,
            'total_price' => $unitPrice * $days,
            'deposit_amount' => fake()->optional(0.5)->randomFloat(2, 100, 1000),
            'status' => RentalStatus::Pending,
            'notes' => fake()->optional()->sentence(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->numerify('#########'),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RentalStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RentalStatus::Active,
            'confirmed_at' => now()->subDay(),
            'picked_up_at' => now(),
        ]);
    }

    public function returned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RentalStatus::Returned,
            'confirmed_at' => now()->subDays(3),
            'picked_up_at' => now()->subDays(2),
            'returned_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RentalStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => fake()->sentence(),
        ]);
    }
}
