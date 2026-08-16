<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Settings;

use App\Models\Organization;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the fix for the branding leak in the public site's header/footer/
 * <title>: brandName() previously fell back to appName(), which reads
 * general.app_name — a setting seeded once with organization_id NULL
 * ("Registro", SettingSeeder::seedGeneralSettings()). Any tenant who never
 * explicitly overrode it inherited that platform-wide default and showed
 * "Registro" instead of their own name on their own sales-facing storefront.
 *
 * New chain: design.brand_name_override → Organization::name → config('app.name')
 * (root domain / no tenant resolved only).
 */
class SettingsManagerBrandNameTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsTenant(Organization $org): static
    {
        app('request')->attributes->set('tenant', $org);

        return $this;
    }

    public function test_brand_name_falls_back_to_organization_name_not_registro(): void
    {
        $org = Organization::factory()->create(['name' => 'Wypożyczalnia Budowlana']);

        $this->actingAsTenant($org);
        $brandName = app(SettingsManager::class)->brandName();

        $this->assertSame('Wypożyczalnia Budowlana', $brandName);
        $this->assertNotSame('Registro', $brandName);
    }

    public function test_brand_name_prefers_explicit_override_over_organization_name(): void
    {
        $org = Organization::factory()->create(['name' => 'Wypożyczalnia Budowlana']);
        $this->actingAsTenant($org);

        app(SettingsManager::class)->set('design.brand_name_override', 'Marka Publiczna');

        $this->assertSame('Marka Publiczna', app(SettingsManager::class)->brandName());
    }

    public function test_brand_name_falls_back_to_registro_when_no_tenant_resolved(): void
    {
        // No tenant set on the request — root domain.
        $this->assertSame(config('app.name'), app(SettingsManager::class)->brandName());
    }

    /**
     * The most important assertion in this whole fix: two tenants, neither
     * with a brand_name_override, must never see each other's name (nor
     * "Registro") — each must resolve strictly to its own Organization::name.
     */
    public function test_two_tenants_never_leak_each_others_brand_name(): void
    {
        $orgA = Organization::factory()->create(['name' => 'Tenant Alfa']);
        $orgB = Organization::factory()->create(['name' => 'Tenant Beta']);

        $this->actingAsTenant($orgA);
        $brandA = app(SettingsManager::class)->brandName();

        $this->actingAsTenant($orgB);
        $brandB = app(SettingsManager::class)->brandName();

        $this->assertSame('Tenant Alfa', $brandA);
        $this->assertSame('Tenant Beta', $brandB);
        $this->assertNotSame($brandA, $brandB);
        $this->assertNotSame('Registro', $brandA);
        $this->assertNotSame('Registro', $brandB);
    }

    /**
     * appName()/general.app_name itself is unchanged — it still resolves to
     * the shared global default when a tenant hasn't overridden it. This is
     * the exact value brandName() must NOT return, pinning why brandName()
     * can no longer delegate to appName().
     */
    public function test_general_app_name_setting_is_shared_across_tenants_by_design(): void
    {
        $orgA = Organization::factory()->create(['name' => 'Tenant Alfa']);
        $orgB = Organization::factory()->create(['name' => 'Tenant Beta']);

        $this->actingAsTenant($orgA);
        $appNameA = app(SettingsManager::class)->appName();

        $this->actingAsTenant($orgB);
        $appNameB = app(SettingsManager::class)->appName();

        $this->assertSame($appNameA, $appNameB);
    }
}
