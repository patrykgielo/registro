<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the fix for feature/tenant-branding-fixes: the public storefront
 * (header, footer heading, footer copyright, <title>) showed "Registro" —
 * OUR brand — for any tenant who never uploaded a logo or set an explicit
 * design.brand_name_override, because the previous fallback chain routed
 * through general.app_name, a setting seeded once globally
 * (organization_id NULL) as "Registro". On a page whose whole purpose is a
 * sales presentation of the CLIENT's business, that is the platform's own
 * name leaking into the client's demo. See SettingsManagerBrandNameTest for
 * the unit-level pin of SettingsManager::brandName() itself.
 */
class TenantBrandNameRegressionTest extends TestCase
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

    private function makeOrg(string $name, string $slug): Organization
    {
        $owner = User::factory()->create();

        return Organization::create([
            'name' => $name,
            'slug' => $slug,
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
    }

    public function test_tenant_without_logo_shows_own_name_not_registro(): void
    {
        $org = $this->makeOrg('Wypożyczalnia Budowlana', 'wypozyczalnia-budowlana');

        $response = $this->actingAsTenant($org)
            ->get('http://wypozyczalnia-budowlana.registro.local/')
            ->assertOk();

        $response->assertSee('Wypożyczalnia Budowlana', false);
        $response->assertDontSee('Registro', false);
    }

    public function test_root_domain_without_tenant_still_shows_registro(): void
    {
        $response = $this->get('http://registro.local/')->assertOk();

        $response->assertSee('Registro', false);
    }

    /**
     * The most important test in this fix: two distinct tenants must each
     * see only their own brand, never the other's and never the platform's.
     */
    public function test_two_tenants_do_not_leak_each_others_brand_name(): void
    {
        $orgA = $this->makeOrg('Tenant Alfa', 'tenant-alfa');
        $orgB = $this->makeOrg('Tenant Beta', 'tenant-beta');

        $responseA = $this->actingAsTenant($orgA)
            ->get('http://tenant-alfa.registro.local/')
            ->assertOk();
        $responseA->assertSee('Tenant Alfa', false);
        $responseA->assertDontSee('Tenant Beta', false);
        $responseA->assertDontSee('Registro', false);

        $responseB = $this->actingAsTenant($orgB)
            ->get('http://tenant-beta.registro.local/')
            ->assertOk();
        $responseB->assertSee('Tenant Beta', false);
        $responseB->assertDontSee('Tenant Alfa', false);
        $responseB->assertDontSee('Registro', false);
    }
}
