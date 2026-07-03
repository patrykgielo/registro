<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrganizationLifecycleState;
use App\Enums\PageLayout;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for the root-domain home route 404 (hotfix 2026-07-03).
 *
 * PR #101 (VULN-003 Layer 1) blanket-added RequireTenant to every route
 * querying a BelongsToOrganization model, including the home route ('/').
 * ResolveTenant deliberately sets no `tenant` request attribute on the bare
 * root domain ("marketplace, no tenant context"), so RequireTenant hard-404'd
 * the root domain's home page permanently — even though the route already
 * has graceful home-fallback handling for the no-CMS-page case.
 *
 * Fix: RequireTenant removed from the home route only, and the closure now
 * gates directly on `$request->attributes->get('tenant')` before touching
 * SettingsManager/Page at all. This is NOT the same as relying on Layer 2
 * (BelongsToOrganization's fail-closed scope) — that alone is insufficient
 * here, because both SettingsManager::get() and the Page global scope
 * resolve "current tenant" via TenantFeature::currentTenant(), which has a
 * session('tenant_id') fallback that ResolveTenant writes on every subdomain
 * visit. A poisoned/stale session would make currentTenant() return non-null
 * and skip Layer 2's fail-closed branch entirely — see
 * test_poisoned_session_does_not_leak_tenant_homepage_on_root_domain below.
 */
class HomeRouteRootDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
    }

    public function test_root_domain_home_returns_ok_with_fallback_view(): void
    {
        $this->get('http://registro.local/')
            ->assertOk()
            ->assertSee('Homepage Not Configured');
    }

    public function test_tenant_subdomain_home_still_works(): void
    {
        $owner = User::factory()->create();

        $org = Organization::create([
            'name' => 'Active Salon',
            'slug' => 'activehome',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'lifecycle_state' => OrganizationLifecycleState::Active,
        ]);

        $this->get('http://activehome.registro.local/')
            ->assertOk()
            ->assertSee('Homepage Not Configured');
    }

    /**
     * Poisoned-session case (code review finding, 2026-07-03): a visitor who
     * previously browsed orgB's subdomain has session('tenant_id') = orgB.id
     * (ResolveTenant writes this on every subdomain visit, even anonymous
     * ones). If they then hit the root domain, the closure must NOT resolve
     * orgB as "current tenant" via that stale session and render orgB's own
     * configured homepage — it must render home-fallback, exactly as an
     * ordinary root-domain visitor with no session state at all would.
     */
    public function test_poisoned_session_does_not_leak_tenant_homepage_on_root_domain(): void
    {
        $owner = User::factory()->create();

        $orgB = Organization::create([
            'name' => 'Org B',
            'slug' => 'orgb',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'lifecycle_state' => OrganizationLifecycleState::Active,
        ]);

        $page = Page::create([
            'organization_id' => $orgB->id,
            'title' => 'Org B Homepage',
            'slug' => 'org-b-homepage',
            'body' => 'Org B exclusive content',
            'content' => [],
            'layout' => PageLayout::DEFAULT,
            'published_at' => now()->subDay(),
        ]);

        Setting::create([
            'organization_id' => $orgB->id,
            'group' => 'cms',
            'key' => 'homepage_page_id',
            'value' => [$page->id],
        ]);

        $this->withSession(['tenant_id' => $orgB->id])
            ->get('http://registro.local/')
            ->assertOk()
            ->assertDontSee('Org B exclusive content')
            ->assertSee('Homepage Not Configured');
    }
}
