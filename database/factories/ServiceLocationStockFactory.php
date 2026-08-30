<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServiceLocationStock>
 */
class ServiceLocationStockFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A resolved model, not a raw Factory instance: Service::itemRental()'s
        // own state ALSO sets 'organization_id' => Organization::factory()
        // (a DIFFERENT, unrelated organization) — chaining ->for($organization,
        // 'organization') on top loses that fight regardless of call order
        // (Laravel always applies `for()`'s resolver first internally, and a
        // later state redefining the same key wins — see
        // App\Actions\Inventory\SyncServiceLocationStockTest's sibling
        // discovery of the same gotcha). Passing 'organization_id' explicitly
        // in create() below, on an already-persisted Organization, sidesteps
        // it entirely and guarantees all three FKs agree.
        $organization = Organization::factory()->equipmentRental()->create();

        return [
            'organization_id' => $organization->id,
            'service_id' => Service::factory()->itemRental()->create(['organization_id' => $organization->id]),
            'location_id' => Location::factory()->for($organization, 'organization'),
            'quantity' => fake()->numberBetween(0, 10),
            'is_active' => true,
        ];
    }
}
