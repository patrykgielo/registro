<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedVerticalDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_equipment_rental_by_id(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $this->artisan('onboarding:seed-vertical', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $this->assertGreaterThan(
            0,
            Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );

        $this->assertGreaterThan(
            0,
            RentalCategory::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );
    }

    public function test_seeds_equipment_rental_by_slug(): void
    {
        $org = Organization::factory()->equipmentRental()->create(['slug' => 'test-rental-slug']);

        $this->artisan('onboarding:seed-vertical', ['organization' => 'test-rental-slug'])
            ->assertExitCode(0);

        $this->assertGreaterThan(
            0,
            Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );
    }

    public function test_seeds_auto_detailing(): void
    {
        $org = Organization::factory()->autoDetailing()->create();

        $this->artisan('onboarding:seed-vertical', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $this->assertEquals(
            8,
            Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );
    }

    public function test_refuses_when_services_already_exist(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $this->artisan('onboarding:seed-vertical', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $this->artisan('onboarding:seed-vertical', ['organization' => (string) $org->id])
            ->assertExitCode(1);
    }

    public function test_force_flag_allows_reseed(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $this->artisan('onboarding:seed-vertical', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $this->artisan('onboarding:seed-vertical', [
            'organization' => (string) $org->id,
            '--force' => true,
        ])
            ->expectsConfirmation('To NIEODWRACALNE. Kontynuować?', 'yes')
            ->assertExitCode(0);
    }

    public function test_confirm_cancel_aborts_force_seed(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $this->artisan('onboarding:seed-vertical', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $countBefore = Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count();

        $this->artisan('onboarding:seed-vertical', [
            'organization' => (string) $org->id,
            '--force' => true,
        ])
            ->expectsConfirmation('To NIEODWRACALNE. Kontynuować?', 'no')
            ->assertExitCode(1);

        // Data must be untouched — confirm 'no' must not trigger purge
        $this->assertEquals(
            $countBefore,
            Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );
    }

    public function test_dry_run_returns_success_without_seeding(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $this->artisan('onboarding:seed-vertical', [
            'organization' => (string) $org->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertEquals(
            0,
            Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );
    }

    public function test_dry_run_with_force_shows_deletion_info_without_changes(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $this->artisan('onboarding:seed-vertical', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $countBefore = Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count();

        $this->artisan('onboarding:seed-vertical', [
            'organization' => (string) $org->id,
            '--force' => true,
            '--dry-run' => true,
        ])->assertExitCode(0);

        // Dry-run must not delete or re-seed anything
        $this->assertEquals(
            $countBefore,
            Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );
    }

    public function test_fails_for_unknown_organization(): void
    {
        $this->artisan('onboarding:seed-vertical', ['organization' => '999999'])
            ->assertExitCode(1);
    }

    public function test_fails_when_no_industry_and_no_override(): void
    {
        $org = Organization::factory()->create(['industry' => null]);

        $this->artisan('onboarding:seed-vertical', ['organization' => (string) $org->id])
            ->assertExitCode(1);
    }

    public function test_industry_override_takes_precedence(): void
    {
        $org = Organization::factory()->create(['industry' => null, 'booking_type' => 'time_slot']);

        $this->artisan('onboarding:seed-vertical', [
            'organization' => (string) $org->id,
            '--industry' => 'general_services',
        ])->assertExitCode(0);

        $this->assertEquals(
            1,
            Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );
    }

    public function test_fails_with_invalid_industry_override(): void
    {
        $org = Organization::factory()->create(['industry' => null]);

        $this->artisan('onboarding:seed-vertical', [
            'organization' => (string) $org->id,
            '--industry' => 'invalid_value',
        ])->assertExitCode(1);
    }
}
