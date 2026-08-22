<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\DesignHub;
use App\Models\Organization;
use App\Models\User;
use App\Support\Settings\SettingsManager;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression guard for DesignHub, the OTHER page on `HasGroupedSettings`
 * (feature/checkout-settings-unsaveable, 2026-08-22). DesignHub has no RichEditor fields, so
 * it was never blocked by the bug `HasGroupedSettings::getGroupStateFromComponents()` fixes —
 * but the fix changes how EVERY group on EVERY page using this trait reads its data, including
 * DesignHub's FileUpload fields (`appearance.header_logo`/`footer_logo`) and its
 * `saveBrandIdentitySettings()`, which spans TWO groups (`appearance` + `design`) from fields
 * that live in the SAME Section — pinning that group-boundary-by-statePath-prefix, not by
 * Section, still resolves correctly.
 *
 * Verified while writing this test: DesignHub's `design.*` fields (font_family, brand_color,
 * use_logo_in_emails, use_color_in_emails) hydrate correctly on a completely fresh tenant
 * despite the SAME "->default() never consulted on a non-null fill" mechanic documented in
 * SystemSettingsCheckoutOfflineDefaultTest's docblock — because, unlike checkout's offline/
 * online toggles, DesignHub's `design` group has seeded GLOBAL (organization_id=NULL) default
 * rows (`database/migrations/2026_05_09_000001_add_design_settings_defaults.php`), which
 * `SettingsManager::all()` merges in for any tenant without its own override. DesignHub was
 * never exposed to that gap; nothing about it needed fixing here.
 */
class DesignHubSaveRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function actingAsDesignAdmin(): Organization
    {
        $org = Organization::factory()->equipmentRental()->create();
        $org->enableModule('design');

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($org->id);

        $this->actingAs($admin);

        // See SystemSettingsCheckoutTabSaveTest's docblock: Filament::setTenant() is required
        // (not the request-attribute form) to survive Livewire::test()'s request-object swap.
        Filament::setTenant($org);

        return $org;
    }

    public function test_saving_untouched_brand_identity_tab_succeeds_across_both_its_groups(): void
    {
        $this->actingAsDesignAdmin();

        // brandIdentitySection() mixes appearance.* and design.* fields in ONE Section;
        // saveBrandIdentitySettings() saves BOTH groups from that one save click.
        Livewire::test(DesignHub::class)
            ->call('saveBrandIdentitySettings')
            ->assertHasNoErrors();
    }

    public function test_saving_untouched_typography_tab_succeeds(): void
    {
        $this->actingAsDesignAdmin();

        Livewire::test(DesignHub::class)
            ->call('saveTypographySettings')
            ->assertHasNoErrors();
    }

    public function test_saving_untouched_email_branding_tab_succeeds(): void
    {
        $this->actingAsDesignAdmin();

        Livewire::test(DesignHub::class)
            ->call('saveEmailBrandingSettings')
            ->assertHasNoErrors();
    }

    public function test_saving_brand_identity_persists_a_real_content_change_in_each_group(): void
    {
        $org = $this->actingAsDesignAdmin();

        $uniqueName = 'BrandOverride-'.uniqid();

        Livewire::test(DesignHub::class)
            ->set('data.appearance.logo_alt', 'Logo testowe')
            ->set('data.design.brand_name_override', $uniqueName)
            ->call('saveBrandIdentitySettings')
            ->assertHasNoErrors();

        $settingsManager = app(SettingsManager::class);

        $this->assertSame('Logo testowe', $settingsManager->get('appearance.logo_alt'));
        $this->assertSame($uniqueName, $settingsManager->get('design.brand_name_override'));
    }
}
