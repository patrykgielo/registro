<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Location>
 */
class LocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->city();

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'code' => strtoupper(fake()->lexify('???')),
            'street' => fake()->streetAddress(),
            'building' => null,
            'postal_code' => fake()->postcode(),
            'city' => $name,
            'latitude' => fake()->latitude(49, 54), // Poland latitude range, same as ServiceAreaFactory
            'longitude' => fake()->longitude(14, 24),
            // fake()->phoneNumber() in the en_US locale (APP_FAKER_LOCALE default) occasionally
            // appends an extension like " x1234" -- the letter fails LocationForm's ->tel()
            // regex (digits/+/()/-/space/./ only), making LocationSlugUniqueScopeTest flaky
            // (known as ClickUp 123k99ct3hv before this fix). numerify() to a fixed PL-shaped
            // pattern is always regex-safe (same approach RentalFactory already uses for its
            // own phone field).
            'phone' => fake()->numerify('+48 ### ### ###'),
            'email' => fake()->companyEmail(),
            'opening_hours' => null,
            'photo' => null,
            'gallery' => null,
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    /**
     * Explicitly marks the location as primary. Bypasses
     * LocationObserver::creating()'s auto-promotion by setting the value
     * directly — useful when a test needs a NON-first location to still be
     * primary.
     */
    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'primary_slot' => 1,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
