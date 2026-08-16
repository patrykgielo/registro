<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Settings;

use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pins the fix for a real bug: every tenant who never uploaded a logo showed
 * this codebase's previous owner's brand (bundled at public/images/logo.svg,
 * migrated in unchanged since the initial commit) on every public page.
 *
 * headerLogo()/footerLogo() MUST return null — never a bundled asset path —
 * when nothing is configured, so the callers' existing text-brand fallbacks
 * (already written, previously unreachable) actually run.
 */
class SettingsManagerLogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_logo_is_null_when_not_configured(): void
    {
        $logo = app(SettingsManager::class)->headerLogo();

        $this->assertNull($logo);
    }

    public function test_footer_logo_is_null_when_not_configured(): void
    {
        $logo = app(SettingsManager::class)->footerLogo();

        $this->assertNull($logo);
    }

    public function test_header_logo_never_falls_back_to_a_bundled_asset(): void
    {
        // Guards against a regression that isn't null-vs-string: some future
        // change reintroducing `?? asset('images/whatever.svg')` would still
        // return a non-null string, which the two tests above wouldn't catch.
        $logo = app(SettingsManager::class)->headerLogo();

        $this->assertTrue(
            $logo === null || ! str_contains($logo, '/images/'),
            'headerLogo() resolved to a bundled public/images/* asset instead of null.'
        );
    }

    public function test_footer_logo_never_falls_back_to_a_bundled_asset(): void
    {
        $logo = app(SettingsManager::class)->footerLogo();

        $this->assertTrue(
            $logo === null || ! str_contains($logo, '/images/'),
            'footerLogo() resolved to a bundled public/images/* asset instead of null.'
        );
    }

    public function test_header_logo_returns_storage_url_when_tenant_configured_one(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('brand.svg');
        $path = $file->store('settings/logos', 'public');

        app(SettingsManager::class)->set('appearance.header_logo', $path);

        $logo = app(SettingsManager::class)->headerLogo();

        $this->assertNotNull($logo);
        $this->assertStringContainsString($path, $logo);
    }

    /**
     * logoAlt()'s fallback source changed from appName() to brandName()
     * (feature/tenant-branding-fixes): appName() reads general.app_name,
     * which is seeded once with organization_id NULL ("Registro" — see
     * SettingSeeder::seedGeneralSettings()) and so silently returns OUR
     * brand for any tenant who never overrode it — the exact bug class this
     * change fixes for the header/footer/title too. `appearance.logo_alt`
     * itself was also previously seeded with "Registro - Mobilne Myjnie
     * Parowe" for every tenant, removed in
     * 2026_08_13_150000_remove_foreign_default_appearance_marketing_and_wizard_copy.php.
     */
    public function test_logo_alt_falls_back_to_brand_name_when_not_configured(): void
    {
        $alt = app(SettingsManager::class)->logoAlt();

        $this->assertSame(app(SettingsManager::class)->brandName(), $alt);
        $this->assertStringNotContainsString('Myjnie', $alt);
        $this->assertStringNotContainsString('Detailing', $alt);
    }
}
