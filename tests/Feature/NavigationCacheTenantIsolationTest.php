<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Page;
use App\Models\User;
use App\Services\NavigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Pins the fix for ClickUp 123k99ct3z9 (fix/tenant-scoped-cache-keys): NavigationService
 * cached header/footer menu items under a bare "navigation.pages.{location}" key with no
 * tenant id. CACHE_PREFIX is not set in .env, so config/cache.php derives it from APP_NAME —
 * identical for every tenant on this shared stack. The first tenant to visit any page for up
 * to CACHE_TTL (30 min) dictated the header/footer menu for every OTHER tenant on the same
 * instance.
 *
 * Uses the REAL ResolveTenant middleware with real Host headers (StorefrontWalkthroughTest's
 * pattern), not a bound test double. A bound double never runs ResolveTenant's own body, so
 * it never sets `tenant_resolution_attempted` — BelongsToOrganization's fail-closed branch
 * (models.md, VULN-003 Layer 2) then silently no-ops instead of fail-closing, which would
 * make the root-domain/no-tenant assertion below pass for the wrong reason (an unrelated
 * scope gap masquerading as "the cache fix works").
 *
 * Falsified empirically before applying the fix (git stash of NavigationService.php +
 * Page.php only, reverted after, `git diff` clean afterward) — all 3 tests failed:
 *   - test_navigation_menu_does_not_leak_between_tenants_on_shared_cache: `To contain: Strona
 *     Tenanta B` — tenant B's footer rendered tenant A's cached page instead of its own.
 *   - test_clearing_cache_for_one_tenant_does_not_touch_another_tenants_cache: `Failed
 *     asserting that false is true` on the very first direct cache-store assertion — with the
 *     pre-fix bare key format, neither tenant's page was ever cached under a key this test's
 *     tenant-scoped `navCacheKey()` helper can find, since that key never carried a tenant id.
 *   - test_root_domain_navigation_renders_without_a_tenant_and_does_not_mix_tenant_menus:
 *     `Not to contain: Strona Root A` — same shared-key leak, this time onto the root domain.
 */
class NavigationCacheTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
    }

    private function makeOrgWithFooterPage(string $slug, string $label): Organization
    {
        $owner = User::factory()->create();

        $org = Organization::create([
            'name' => "Org {$slug}",
            'slug' => $slug,
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        Page::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'title' => $label,
            'slug' => "regulamin-{$slug}",
            'published_at' => now()->subDay(),
            'show_in_menu' => true,
            'menu_location' => 'footer',
            'menu_order' => 1,
            'menu_label' => $label,
        ]);

        return $org;
    }

    /**
     * Reconstructs NavigationService::cacheKey()'s format (private method, so this test
     * builds it independently) — keep in sync if that format ever changes.
     */
    private function navCacheKey(string $location, int $tenantId): string
    {
        return "navigation.pages.{$location}.{$tenantId}";
    }

    public function test_navigation_menu_does_not_leak_between_tenants_on_shared_cache(): void
    {
        $orgA = $this->makeOrgWithFooterPage('nav-tenant-a', 'Strona Tenanta A');
        $orgB = $this->makeOrgWithFooterPage('nav-tenant-b', 'Strona Tenanta B');

        // Tenant A visits first — warms whatever cache bucket its request resolves to.
        $this->get("http://{$orgA->slug}.registro.local/")
            ->assertOk()
            ->assertSee('Strona Tenanta A', false);

        // Tenant B visits second, inside the same 30-minute TTL window. Before the fix this
        // was served tenant A's cached menu, because the cache key carried no tenant id.
        $response = $this->get("http://{$orgB->slug}.registro.local/")->assertOk();

        $response->assertSee('Strona Tenanta B', false);
        $response->assertDontSee('Strona Tenanta A', false);
    }

    public function test_clearing_cache_for_one_tenant_does_not_touch_another_tenants_cache(): void
    {
        $orgA = $this->makeOrgWithFooterPage('nav-clear-a', 'Strona Clear A');
        $orgB = $this->makeOrgWithFooterPage('nav-clear-b', 'Strona Clear B');

        $this->get("http://{$orgA->slug}.registro.local/")->assertOk();
        $this->get("http://{$orgB->slug}.registro.local/")->assertOk();

        // Both tenants' own buckets are warmed before the clear — direct cache-store proof,
        // not just an HTTP round trip (which would still show correct content on a fresh
        // re-fetch even if clearCache() cleared the wrong bucket, masking the very bug this
        // is meant to catch).
        self::assertTrue(Cache::has($this->navCacheKey('footer', $orgA->id)));
        self::assertTrue(Cache::has($this->navCacheKey('footer', $orgB->id)));
        $cachedB = Cache::get($this->navCacheKey('footer', $orgB->id));

        // Simulate an admin editing/saving tenant A's page — Page::booted() calls
        // NavigationService::clearCache($page->organization_id) with A's own id.
        app(NavigationService::class)->clearCache($orgA->id);

        self::assertFalse(
            Cache::has($this->navCacheKey('footer', $orgA->id)),
            'clearCache($tenantId) must invalidate that tenant\'s own bucket.'
        );
        self::assertTrue(
            Cache::has($this->navCacheKey('footer', $orgB->id)),
            'clearCache($tenantId) must not touch a DIFFERENT tenant\'s bucket.'
        );
        self::assertEquals(
            $cachedB,
            Cache::get($this->navCacheKey('footer', $orgB->id)),
            'tenant B\'s cached value itself must be byte-for-byte untouched, not merely still present.'
        );

        // Tenant B's own cached menu must survive — untouched by A's invalidation.
        $response = $this->get("http://{$orgB->slug}.registro.local/")->assertOk();
        $response->assertSee('Strona Clear B', false);

        // Tenant A's page save/delete invalidation actually takes effect: editing the
        // menu_label must be reflected on the very next request (not served stale for 30 min).
        $page = Page::withoutGlobalScope('organization')
            ->where('organization_id', $orgA->id)
            ->firstOrFail();
        $page->menu_label = 'Regulamin Po Edycji';
        $page->save();

        $response = $this->get("http://{$orgA->slug}.registro.local/")->assertOk();
        $response->assertSee('Regulamin Po Edycji', false);
        $response->assertDontSee('Strona Clear A', false);
    }

    public function test_root_domain_navigation_renders_without_a_tenant_and_does_not_mix_tenant_menus(): void
    {
        $orgA = $this->makeOrgWithFooterPage('nav-root-a', 'Strona Root A');

        // Warm tenant A's own bucket first.
        $this->get("http://{$orgA->slug}.registro.local/")->assertOk();

        // Flush the session before the root-domain visit — ResolveTenant writes
        // session('tenant_id') on EVERY subdomain visit (for Livewire requests that skip
        // the middleware), and TenantFeature::currentTenant()'s 3rd fallback branch reads
        // it back. Without this flush, the assertion below fails for a DIFFERENT, already
        // documented reason than this test targets: BelongsToOrganization's global scope
        // itself (not this fix's cache key) resolves the stale session tenant and serves
        // tenant A's page on the root domain — the same failure class as VULN-003
        // Layers 1/2/5 and models.md's GOTCHA LC-9, orthogonal to NavigationService's cache
        // key and out of this task's scope (confirmed empirically while writing this test;
        // NavigationService's own cacheKey() already avoids this fallback deliberately —
        // see its docblock — but the underlying Page query's tenant scope does not).
        $this->flushSession();

        // Root domain — ResolveTenant sets no `tenant` attribute (see routes/web.php's own
        // "public home route" docblock). Must not blow up, and must not show tenant A's menu.
        $response = $this->get('http://registro.local/')->assertOk();

        $response->assertDontSee('Strona Root A', false);
    }
}
