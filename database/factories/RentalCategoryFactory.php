<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RentalCategory>
 */
class RentalCategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Elektronarzędzia',
            'Maszyny budowlane',
            'Sprzęt ogrodniczy',
            'Rusztowania',
            'Agregaty prądotwórcze',
            'Narzędzia ręczne',
            'Sprzęt spawalniczy',
            'Kompresory',
        ]);

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->optional()->sentence(),
            'icon' => fake()->optional()->randomElement(['wrench-screwdriver', 'cube', 'cog-6-tooth']),
            'sort_order' => fake()->numberBetween(0, 10),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
