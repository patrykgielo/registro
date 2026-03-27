<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 2000);

        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'order_number' => strtoupper(Str::random(8)).'-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => 'pending_payment',
            'currency' => 'PLN',
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => $subtotal,
            'customer_email' => fake()->safeEmail(),
            'customer_first_name' => fake()->firstName(),
            'customer_last_name' => fake()->lastName(),
            'customer_phone' => null,
            'invoice_requested' => false,
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function pendingPayment(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending_payment',
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'paid_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'confirmed',
            'paid_at' => now()->subMinutes(10),
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_progress',
            'paid_at' => now()->subHour(),
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'paid_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending_payment',
            'expires_at' => now()->subMinutes(5),
        ]);
    }
}
