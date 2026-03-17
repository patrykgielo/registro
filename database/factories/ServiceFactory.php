<?php

namespace Database\Factories;

use App\Enums\ServiceType;
use App\Models\Organization;
use App\Models\RentalCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->optional(0.7)->sentence(),
            'service_type' => ServiceType::TimeSlot,
            'duration_minutes' => fake()->randomElement([60, 120, 180, 240, 360, 480]),
            'price' => fake()->randomFloat(2, 100, 1500),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    public function itemRental(): static
    {
        $name = fake()->unique()->randomElement([
            'Wiertarka udarowa Bosch',
            'Szlifierka kątowa Makita',
            'Betoniarka 150L',
            'Agregat prądotwórczy 5kW',
            'Rusztowanie ramowe 6m',
            'Piła tarczowa DeWalt',
            'Zagęszczarka płytowa',
            'Spawarka MIG 200A',
        ]);

        $pricePerDay = fake()->randomFloat(2, 50, 500);

        return $this->state(fn (array $attributes) => [
            'service_type' => ServiceType::ItemRental,
            'organization_id' => Organization::factory(),
            'rental_category_id' => RentalCategory::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'quantity_total' => fake()->numberBetween(1, 10),
            'price' => $pricePerDay,
            'price_per_day' => $pricePerDay,
            'price_per_hour' => fake()->optional(0.3)->randomFloat(2, 10, 100),
            'price_per_week' => fake()->optional(0.5)->randomFloat(2, $pricePerDay * 5, $pricePerDay * 7),
            'deposit_amount' => fake()->optional(0.5)->randomFloat(2, 100, 2000),
            'duration_minutes' => 0,
        ]);
    }
}
