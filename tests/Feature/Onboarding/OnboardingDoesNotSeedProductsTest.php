<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Actions\Onboarding\SeedOrganizationDefaults;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingDoesNotSeedProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_organization_defaults_creates_no_services(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        app(SeedOrganizationDefaults::class)->execute($org);

        $this->assertEquals(
            0,
            Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );
    }

    public function test_seed_organization_defaults_creates_no_rental_categories(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        app(SeedOrganizationDefaults::class)->execute($org);

        $this->assertEquals(
            0,
            RentalCategory::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );
    }

    public function test_seed_organization_defaults_creates_settings(): void
    {
        $org = Organization::factory()->generalServices()->create();

        app(SeedOrganizationDefaults::class)->execute($org);

        $this->assertDatabaseHas('settings', [
            'organization_id' => $org->id,
            'group' => 'general',
            'key' => 'app_name',
        ]);

        $this->assertDatabaseHas('settings', [
            'organization_id' => $org->id,
            'group' => 'booking',
            'key' => 'slot_interval_minutes',
        ]);
    }

    public function test_seed_organization_defaults_sets_industry_features(): void
    {
        $org = Organization::factory()->autoDetailing()->create();

        app(SeedOrganizationDefaults::class)->execute($org);

        $org->refresh();
        $settings = $org->settings ?? [];

        $this->assertTrue(data_get($settings, 'features.vehicles', false));
        $this->assertTrue(data_get($settings, 'features.mobile_service', false));
    }

    public function test_seed_without_industry_creates_no_services(): void
    {
        $org = Organization::factory()->create(['industry' => null]);

        app(SeedOrganizationDefaults::class)->execute($org);

        $this->assertEquals(
            0,
            Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );
    }
}
