<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every tenant who never uploaded a logo was rendering this codebase's
 * previous owner's brand (public/images/logo.svg, present unchanged since
 * the initial "migrated from Paradocks codebase" commit) on every public
 * page — the fallback text branches already written in header.blade.php
 * were unreachable because SettingsManager::headerLogo()/footerLogo() never
 * returned an empty value. See SettingsManagerLogoTest for the unit-level
 * pin of that contract.
 */
class NoForeignBrandingRegressionTest extends TestCase
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

    public function test_public_page_never_links_a_bundled_logo_asset_when_none_configured(): void
    {
        $owner = User::factory()->create();

        $org = Organization::create([
            'name' => 'No Logo Org',
            'slug' => 'no-logo-org',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $response = $this->actingAsTenant($org)
            ->get('http://no-logo-org.registro.local/')
            ->assertOk();

        // The regression: any src="...images/logo*.svg" in a page that has
        // no configured logo means a fallback fell back to a bundled asset
        // again, foreign brand or otherwise.
        $response->assertDontSee('images/logo', false);

        // The pre-existing text fallback (previously unreachable) must be
        // what actually renders instead. header.blade.php's @else branch
        // originally printed config('app.name') here — itself later found to
        // be the same bug one level up (showing "Registro" instead of the
        // tenant's own name, see SettingsManager::brandName()'s docblock and
        // tenant-branding.md's "Fifth pass") — so this now asserts the
        // tenant's own Organization::name, not the platform's.
        $response->assertSee($org->name, false);
        $response->assertDontSee(config('app.name'), false);
    }
}
