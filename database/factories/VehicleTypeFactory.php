<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VehicleType>
 */
class VehicleTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Sedan', 'SUV', 'Kombi', 'Hatchback', 'Coupe']),
            'slug' => fn (array $attributes) => \Illuminate\Support\Str::slug($attributes['name']),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
