<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ServiceUnitStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServiceUnit>
 */
class ServiceUnitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Same gotcha as ServiceLocationStockFactory: Service::itemRental()'s
        // own state sets 'organization_id' => Organization::factory() (a
        // DIFFERENT organization), which wins over a later ->for() call
        // regardless of chain order. Resolve one Organization first and pass
        // it explicitly to every FK.
        $organization = Organization::factory()->equipmentRental()->create();

        return [
            'organization_id' => $organization->id,
            'service_id' => Service::factory()->itemRental()->create(['organization_id' => $organization->id]),
            'location_id' => Location::factory()->for($organization, 'organization'),
            'identifier' => null,
            'inventory_number' => null,
            'status' => ServiceUnitStatus::Available,
            'acquired_at' => null,
            'notes' => null,
        ];
    }

    public function maintenance(): static
    {
        return $this->state(fn () => ['status' => ServiceUnitStatus::Maintenance]);
    }

    public function inTransit(): static
    {
        return $this->state(fn () => ['status' => ServiceUnitStatus::InTransit]);
    }

    public function retired(): static
    {
        return $this->state(fn () => ['status' => ServiceUnitStatus::Retired]);
    }

    public function withIdentifier(?string $identifier = null): static
    {
        return $this->state(fn () => ['identifier' => $identifier ?? strtoupper(fake()->bothify('???-##'))]);
    }
}
