<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\MenuLocation;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedWebsiteCommandTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsTenant(Organization $org): static
    {
        config(['app.domain' => 'registro.local']);

        $this->app->bind(\App\Http\Middleware\ResolveTenant::class, function () use ($org) {
            return new class($org)
            {
                public function __construct(private Organization $org) {}

                public function handle($request, $next)
                {
                    $request->attributes->set('tenant', $this->org);

                    return $next($request);
                }
            };
        });

        return $this;
    }

    public function test_seeds_homepage_and_menu_for_equipment_rental_org(): void
    {
        $org = Organization::factory()->equipmentRental()->create(['name' => 'Wypożyczalnia Testowa']);

        $this->artisan('onboarding:seed-website', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $homepage = Page::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('slug', 'strona-glowna')
            ->firstOrFail();

        $this->assertSame('Wypożyczalnia Testowa', $homepage->title);
        $this->assertTrue($homepage->isPublished());
        $this->assertNotEmpty($homepage->content);

        $this->assertDatabaseHas('pages', [
            'organization_id' => $org->id,
            'slug' => 'o-nas',
            'show_in_menu' => true,
        ]);

        $this->assertDatabaseHas('pages', [
            'organization_id' => $org->id,
            'slug' => 'wypozyczalnia',
            'show_in_menu' => true,
            'menu_location' => MenuLocation::HEADER->value,
        ]);
    }

    public function test_homepage_setting_is_organization_scoped_not_global(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $this->artisan('onboarding:seed-website', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $homepage = Page::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('slug', 'strona-glowna')
            ->firstOrFail();

        $tenantSetting = Setting::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('group', 'cms')
            ->where('key', 'homepage_page_id')
            ->first();

        $this->assertNotNull($tenantSetting);
        $this->assertSame($homepage->id, $tenantSetting->value[0]);

        // The critical regression this guards against: a console command with no
        // ambient tenant writing organization_id = NULL via SettingsManager::set()
        // would leak this homepage to every other tenant that has no override.
        $globalSetting = Setting::withoutGlobalScope('organization')
            ->whereNull('organization_id')
            ->where('group', 'cms')
            ->where('key', 'homepage_page_id')
            ->first();

        $this->assertNull($globalSetting);
    }

    public function test_industry_neutral_org_gets_no_rental_menu_link(): void
    {
        $org = Organization::factory()->generalServices()->create();

        $this->artisan('onboarding:seed-website', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('pages', [
            'organization_id' => $org->id,
            'slug' => 'wypozyczalnia',
        ]);

        // The menu must still work for a non-rental tenant — "O nas" is universal.
        $this->assertDatabaseHas('pages', [
            'organization_id' => $org->id,
            'slug' => 'o-nas',
            'show_in_menu' => true,
        ]);
    }

    public function test_seeds_successfully_with_zero_services(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $this->assertSame(0, Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count());

        $this->artisan('onboarding:seed-website', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $homepage = Page::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('slug', 'strona-glowna')
            ->firstOrFail();

        $blockTypes = collect($homepage->content)->pluck('type')->all();

        $this->assertNotContains('content_grid', $blockTypes, 'content_grid must be omitted, not seeded empty, when the tenant has no active services.');
        $this->assertContains('hero', $blockTypes);
    }

    public function test_refuses_when_pages_already_exist(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $this->artisan('onboarding:seed-website', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $this->artisan('onboarding:seed-website', ['organization' => (string) $org->id])
            ->assertExitCode(1);
    }

    public function test_force_flag_reseeds_and_replaces_the_homepage(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $this->artisan('onboarding:seed-website', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $firstHomepage = Page::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('slug', 'strona-glowna')
            ->firstOrFail();

        // NOTE: this asserts end-state only (old page gone, new one seeded, setting
        // repointed) — it does NOT prove purge()'s setting-before-pages ordering is
        // load-bearing. In a console command PageObserver::deleting()'s homepage guard
        // never fires (SettingsManager::get() resolves no ambient tenant, falls
        // through to a global row this codebase never writes — confirmed empirically);
        // the ordering is defense-in-depth for a hypothetical tenant-context caller,
        // not something exercised here. See SeedTenantWebsite::purge()'s docblock and
        // tenant-website-seeder.md.
        $this->artisan('onboarding:seed-website', [
            'organization' => (string) $org->id,
            '--force' => true,
        ])
            ->expectsConfirmation('To NIEODWRACALNE. Kontynuować?', 'yes')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('pages', ['id' => $firstHomepage->id]);

        $newHomepage = Page::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('slug', 'strona-glowna')
            ->firstOrFail();

        $this->assertNotSame($firstHomepage->id, $newHomepage->id);

        $tenantSetting = Setting::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('group', 'cms')
            ->where('key', 'homepage_page_id')
            ->firstOrFail();

        $this->assertSame($newHomepage->id, $tenantSetting->value[0]);
    }

    public function test_confirm_cancel_aborts_force_reseed(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $this->artisan('onboarding:seed-website', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $countBefore = Page::withoutGlobalScope('organization')->where('organization_id', $org->id)->count();

        $this->artisan('onboarding:seed-website', [
            'organization' => (string) $org->id,
            '--force' => true,
        ])
            ->expectsConfirmation('To NIEODWRACALNE. Kontynuować?', 'no')
            ->assertExitCode(1);

        $this->assertEquals(
            $countBefore,
            Page::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );
    }

    public function test_dry_run_returns_success_without_seeding(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $this->artisan('onboarding:seed-website', [
            'organization' => (string) $org->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, Page::withoutGlobalScope('organization')->where('organization_id', $org->id)->count());
    }

    public function test_dry_run_without_force_fails_when_org_has_pages(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $this->artisan('onboarding:seed-website', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $countBefore = Page::withoutGlobalScope('organization')->where('organization_id', $org->id)->count();

        $this->artisan('onboarding:seed-website', [
            'organization' => (string) $org->id,
            '--dry-run' => true,
        ])->assertExitCode(1);

        $this->assertEquals(
            $countBefore,
            Page::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );
    }

    public function test_fails_for_unknown_organization(): void
    {
        $this->artisan('onboarding:seed-website', ['organization' => '999999'])
            ->assertExitCode(1);
    }

    public function test_homepage_renders_with_content_blocks_not_fallback(): void
    {
        $org = Organization::factory()->equipmentRental()->create(['name' => 'Render Test Sp. z o.o.']);

        $this->artisan('onboarding:seed-website', ['organization' => (string) $org->id])
            ->assertExitCode(0);

        $this->actingAsTenant($org)
            ->get("http://{$org->slug}.registro.local/")
            ->assertOk()
            ->assertSee('Render Test Sp. z o.o.')
            ->assertDontSee('Strona w przygotowaniu');
    }
}
