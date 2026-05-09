<?php

namespace Database\Factories;

use App\Enums\Industry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 9999),
            'booking_type' => 'time_slot',
            'industry' => null,
            'owner_id' => User::factory(),
            'is_active' => true,
            'settings' => null,
            'trial_ends_at' => null,
        ];
    }

    public function itemRental(): static
    {
        return $this->state(fn (array $attributes) => [
            'booking_type' => 'item_rental',
        ]);
    }

    public function equipmentRental(): static
    {
        return $this->state(fn (array $attributes) => [
            'booking_type' => 'item_rental',
            'industry' => Industry::EquipmentRental,
        ]);
    }

    public function autoDetailing(): static
    {
        return $this->state(fn (array $attributes) => [
            'booking_type' => 'time_slot',
            'industry' => Industry::AutoDetailing,
        ]);
    }

    public function generalServices(): static
    {
        return $this->state(fn (array $attributes) => [
            'booking_type' => 'time_slot',
            'industry' => Industry::GeneralServices,
        ]);
    }

    public function onTrial(): static
    {
        return $this->state(fn (array $attributes) => [
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
