<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\RentalCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RentalItem>
 */
class RentalItemFactory extends Factory
{
    public function definition(): array
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

        return [
            'organization_id' => Organization::factory(),
            'rental_category_id' => RentalCategory::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->optional()->paragraph(),
            'quantity_total' => fake()->numberBetween(1, 10),
            'price_per_day' => $pricePerDay,
            'price_per_hour' => fake()->optional(0.3)->randomFloat(2, 10, 100),
            'price_per_week' => fake()->optional(0.5)->randomFloat(2, $pricePerDay * 5, $pricePerDay * 7),
            'deposit_amount' => fake()->optional(0.5)->randomFloat(2, 100, 2000),
            'featured_image' => null,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 20),
            'specifications' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withSpecifications(): static
    {
        return $this->state(fn (array $attributes) => [
            'specifications' => [
                'Moc' => fake()->randomElement(['500W', '800W', '1200W', '2000W']),
                'Waga' => fake()->randomFloat(1, 2, 50).' kg',
                'Napięcie' => fake()->randomElement(['230V', '400V']),
            ],
        ]);
    }
}
