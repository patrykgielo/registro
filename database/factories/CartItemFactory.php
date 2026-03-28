<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CartItem>
 */
class CartItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = Carbon::today()->addDays(fake()->numberBetween(1, 10));
        $rentalDays = fake()->numberBetween(1, 7);
        $endDate = $startDate->copy()->addDays($rentalDays - 1);
        $unitPrice = fake()->randomFloat(2, 50, 500);
        $quantity = fake()->numberBetween(1, 3);

        return [
            'cart_id' => Cart::factory(),
            'service_id' => Service::factory()->itemRental(),
            'quantity' => $quantity,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rental_days' => $rentalDays,
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $rentalDays * $quantity,
            'price_snapshot' => null,
        ];
    }
}
