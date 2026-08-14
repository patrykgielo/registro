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
     * logoAlt() itself was never buggy — its fallback-to-appName() already
     * existed — but `appearance.logo_alt` (the key it reads) was seeded with
     * "Registro - Mobilne Myjnie Parowe" for every tenant, so this contract
     * was unreachable in practice until that seeded row was removed
     * (2026_08_13_150000_remove_foreign_default_appearance_marketing_and_wizard_copy.php).
     * Pins the contract so a future re-seed can't silently break it again.
     */
    public function test_logo_alt_falls_back_to_app_name_when_not_configured(): void
    {
        $alt = app(SettingsManager::class)->logoAlt();

        $this->assertSame(app(SettingsManager::class)->appName(), $alt);
        $this->assertStringNotContainsString('Myjnie', $alt);
        $this->assertStringNotContainsString('Detailing', $alt);
    }
}
