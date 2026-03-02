<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServiceArea>
 */
class ServiceAreaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'city_name' => fake()->city(),
            'latitude' => fake()->latitude(49, 54), // Poland latitude range
            'longitude' => fake()->longitude(14, 24), // Poland longitude range
            'radius_km' => fake()->numberBetween(20, 80),
            'description' => fake()->optional()->sentence(),
            'color_hex' => fake()->hexColor(),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    /**
     * Indicate that the service area is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set a specific location for the service area.
     */
    public function location(float $latitude, float $longitude, int $radiusKm = 50): static
    {
        return $this->state(fn (array $attributes) => [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius_km' => $radiusKm,
        ]);
    }
}
