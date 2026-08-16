<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Page;
use App\Models\User;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the fix for feature/tenant-branding-fixes: the footer's "Nawigacja"
 * and "Kontakt" columns rendered their heading with an empty body whenever
 * the tenant had no footer-location pages (NavigationService reads
 * menu_location) or no `contact` settings group rows — an empty column
 * under a heading reads as broken, not as "nothing configured yet". Each
 * column must now render only when it has content, and a tenant with
 * neither must not fall back to fabricated data.
 *
 * Also pins a second, narrower bug in the same column: the Kontakt column
 * read $contact['address'], a key the Contact tab (SystemSettings.php) has
 * never written — the real keys are address_line/city/postal_code — so the
 * address line silently never rendered even for a tenant who filled the
 * form in.
 */
class FooterEmptyColumnsRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // NavigationService caches menu items under a location-only key with
        // no tenant id (a separate, already-documented cross-tenant leak —
        // see the "Found, documented, not fixed here" note on PR #196's
        // onboarding:seed-website). RefreshDatabase rolls back Page rows
        // between tests but does not touch the cache, so a prior test's
        // cached footer menu could otherwise leak into this one.
        app(\App\Services\NavigationService::class)->clearCache();
    }

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

    private function makeOrg(string $slug): Organization
    {
        $owner = User::factory()->create();

        return Organization::create([
            'name' => 'Footer Test Org',
            'slug' => $slug,
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
    }

    public function test_both_columns_are_absent_when_tenant_has_no_nav_and_no_contact(): void
    {
        $org = $this->makeOrg('no-footer-data');

        $response = $this->actingAsTenant($org)
            ->get('http://no-footer-data.registro.local/')
            ->assertOk();

        $response->assertDontSee('Nawigacja', false);
        $response->assertDontSee('Kontakt', false);
    }

    public function test_navigation_column_appears_when_footer_pages_exist(): void
    {
        $org = $this->makeOrg('footer-nav-org');

        Page::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'title' => 'Regulamin',
            'slug' => 'regulamin-footer-nav-org',
            'published_at' => now()->subDay(),
            'show_in_menu' => true,
            'menu_location' => 'footer',
            'menu_order' => 1,
        ]);

        $response = $this->actingAsTenant($org)
            ->get('http://footer-nav-org.registro.local/')
            ->assertOk();

        $response->assertSee('Nawigacja', false);
        $response->assertDontSee('Kontakt', false);
    }

    public function test_contact_column_appears_and_renders_full_address_when_contact_settings_exist(): void
    {
        $org = $this->makeOrg('footer-contact-org');
        $this->actingAsTenant($org);

        $settings = app(SettingsManager::class);
        $settings->set('contact.phone', '+48 600 000 000');
        $settings->set('contact.email', 'kontakt@example.test');
        $settings->set('contact.address_line', 'ul. Testowa 1');
        $settings->set('contact.city', 'Warszawa');
        $settings->set('contact.postal_code', '00-001');

        $response = $this->get('http://footer-contact-org.registro.local/')->assertOk();

        $response->assertSee('Kontakt', false);
        $response->assertDontSee('Nawigacja', false);
        $response->assertSee('+48 600 000 000', false);
        $response->assertSee('kontakt@example.test', false);
        // The regression: address_line/postal_code/city must be assembled
        // into the visible address, not silently dropped via the dead
        // `$contact['address']` key.
        $response->assertSee('ul. Testowa 1', false);
        $response->assertSee('00-001 Warszawa', false);
    }
}
