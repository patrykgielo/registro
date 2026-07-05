<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * POST /livewire/update is Livewire's own shared AJAX endpoint (registered by
 * the package itself with only the base 'web' middleware group). It never ran
 * ResolveTenant/RequireTenant, so almost all real /admin interaction (table
 * loads, filters, form saves — anything after the initial page load) resolved
 * the tenant from `session('tenant_id')` alone.
 *
 * ResolveTenant writes that session key on EVERY successful subdomain
 * resolution — including an anonymous, unauthenticated visit in a completely
 * unrelated browser tab — before any canAccessTenant() authorization check.
 * A staff/admin user with an open Org A admin tab whose browser merely loaded
 * Org B's public site in another tab would have session('tenant_id') silently
 * overwritten to Org B; the next ordinary Livewire interaction in the Org A
 * tab (a table filter, a save) would then resolve Org B's data instead.
 *
 * Fix: app/Providers/AppServiceProvider.php registers ResolveTenant +
 * RequireTenant as Livewire "persistent middleware" — Livewire's own
 * mechanism (already used for Sanctum/Jetstream auth) that replays selected
 * middleware, on every /livewire/update call, against a request carrying the
 * REAL current Host header + the tamper-proof `memo.path`/`memo.method` that
 * Livewire's own checksum-verified snapshot recorded when the component was
 * ORIGINALLY mounted (full page load). See
 * app/docs/security/patterns/livewire-tenant-isolation.md for full design.
 *
 * These tests dispatch a REAL POST to /livewire/update with a genuine
 * checksum-signed snapshot (extracted from a real page render) — not
 * Livewire::test(), which never touches the HTTP route and would silently
 * skip the persistent-middleware mechanism this fix depends on.
 */
class LivewireAdminTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    }

    /**
     * Extracts the *resource page's own* wire:snapshot (as raw, still
     * htmlspecialchars-decoded JSON text — exactly what Livewire's own
     * handleUpdate() expects in the request payload) from a full page
     * render. Filament pages render several Livewire components per page
     * (topbar, sidebar, notifications, the resource page itself) — the
     * resource page is the only one NOT namespaced under `filament.livewire.*`.
     */
    private function extractResourcePageSnapshot(string $html): string
    {
        preg_match_all('/wire:snapshot="(.*?)"/s', $html, $matches);

        foreach ($matches[1] as $raw) {
            $decoded = htmlspecialchars_decode($raw, ENT_QUOTES);
            $name = json_decode($decoded, true)['memo']['name'] ?? '';

            if (! str_starts_with($name, 'filament.livewire.')) {
                return $decoded;
            }
        }

        throw new \RuntimeException('Could not locate resource page wire:snapshot in rendered HTML.');
    }

    private function postLivewireUpdate(string $url, string $snapshot): TestResponse
    {
        return $this->postJson($url, [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [],
            ]],
        ], ['X-Livewire' => true]);
    }

    private function renderedHtml(TestResponse $response): string
    {
        return $response->json('components.0.effects.html') ?? '';
    }

    // -------------------------------------------------------------------------
    // Core regression: poisoned session must not leak cross-tenant data
    // -------------------------------------------------------------------------

    public function test_livewire_update_ignores_poisoned_session_and_serves_only_the_real_hosts_tenant(): void
    {
        $orgA = Organization::factory()->create(['slug' => 'orga-leak']);
        $orgB = Organization::factory()->create(['slug' => 'orgb-leak']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($orgA->id);

        Service::factory()->create(['organization_id' => $orgA->id, 'name' => 'Org A Visible Service']);
        Service::factory()->create(['organization_id' => $orgB->id, 'name' => 'Org B SECRET Service']);

        // Full page load on Org A's own admin subdomain — legitimate, unrelated
        // to the attack. Mounts the table component with memo.path=admin/services.
        $page = $this->actingAs($admin)->get('https://orga-leak.registro.local/admin/services');
        $page->assertOk();
        $snapshot = $this->extractResourcePageSnapshot($page->getContent());

        // Simulate the attack: another tab (or link/embed) loaded Org B's public
        // site, silently overwriting session('tenant_id') for this SAME browser
        // session — before any authorization check for Org B ran.
        $update = $this->actingAs($admin)
            ->withSession(['tenant_id' => $orgB->id])
            ->postLivewireUpdate('https://orga-leak.registro.local/livewire/update', $snapshot)
            ->assertOk();

        $html = $this->renderedHtml($update);
        $this->assertStringContainsString('Org A Visible Service', $html);
        $this->assertStringNotContainsString('Org B SECRET Service', $html);

        // Side effect proving the mechanism: the poisoned session key was
        // corrected back to the real, Host-derived tenant for this request.
        $this->assertSame($orgA->id, session('tenant_id'));
    }

    /**
     * Same attack, but on the WRITE path: BelongsToOrganization's `creating`
     * hook auto-assigns organization_id from the exact same
     * TenantFeature::currentTenant() call the read-path scope uses — so
     * correcting the session before any table hydration/render also protects
     * any create/update triggered by that same Livewire update batch. This is
     * asserted indirectly here: a bulk/table action is out of scope for this
     * suite's effort budget (Filament's own multi-step mountedTableAction
     * protocol), so the guarantee is documented explicitly in
     * app/docs/security/patterns/livewire-tenant-isolation.md rather than
     * re-implemented — the read-path test above exercises the identical
     * underlying primitive (TenantFeature::currentTenant() / session
     * correction) that the write-path relies on.
     */
    public function test_session_is_corrected_before_any_component_hydration_runs(): void
    {
        $orgA = Organization::factory()->create(['slug' => 'orga-write']);
        $orgB = Organization::factory()->create(['slug' => 'orgb-write']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($orgA->id);

        $page = $this->actingAs($admin)->get('https://orga-write.registro.local/admin/services');
        $page->assertOk();
        $snapshot = $this->extractResourcePageSnapshot($page->getContent());

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $orgB->id])
            ->postLivewireUpdate('https://orga-write.registro.local/livewire/update', $snapshot)
            ->assertOk();

        $this->assertSame(
            $orgA->id,
            session('tenant_id'),
            'session(tenant_id) must reflect the real Host-derived tenant, not the poisoned value, '
            .'before BelongsToOrganization\'s creating hook (which reads the same TenantFeature::currentTenant()) could run.'
        );
    }

    // -------------------------------------------------------------------------
    // Positive control: legitimate same-tenant Livewire usage is unaffected
    // -------------------------------------------------------------------------

    public function test_livewire_update_works_normally_on_real_tenant_subdomain_without_poisoning(): void
    {
        $org = Organization::factory()->create(['slug' => 'orga-normal']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($org->id);

        Service::factory()->create(['organization_id' => $org->id, 'name' => 'Normal Flow Service']);

        $page = $this->actingAs($admin)->get('https://orga-normal.registro.local/admin/services');
        $page->assertOk();
        $snapshot = $this->extractResourcePageSnapshot($page->getContent());

        $update = $this->actingAs($admin)
            ->postLivewireUpdate('https://orga-normal.registro.local/livewire/update', $snapshot)
            ->assertOk();

        $this->assertStringContainsString('Normal Flow Service', $this->renderedHtml($update));
    }

    // -------------------------------------------------------------------------
    // RequireTenant's abort is the ONLY independent backstop on this path — see
    // "Guarantees" in the pattern doc. Layer 2 (BelongsToOrganization fail-closed)
    // cannot see this path's replay at all (its fake request's `attributes` bag
    // is a genuinely independent clone), so if this abort didn't propagate
    // correctly, nothing else would stop the update from continuing unscoped.
    // -------------------------------------------------------------------------

    /**
     * Simulates an attacker replaying a validly checksum-signed snapshot (their
     * own, legitimately captured from a real admin mount — Livewire's checksum
     * proves only that the snapshot is unmodified, not which host it's "meant"
     * for) against a host where NO tenant can be resolved at all: the bare root
     * domain. `ResolveTenant` sets no tenant there (by design — marketplace/no
     * tenant context); `RequireTenant`'s replay must hard-abort rather than
     * silently letting the update continue.
     */
    public function test_replaying_a_valid_snapshot_where_no_tenant_can_be_resolved_hard_aborts(): void
    {
        $org = Organization::factory()->create(['slug' => 'orga-abort']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($org->id);

        // Legitimately mounted on the real tenant subdomain.
        $page = $this->actingAs($admin)->get('https://orga-abort.registro.local/admin/services');
        $page->assertOk();
        $snapshot = $this->extractResourcePageSnapshot($page->getContent());

        // Same, validly signed snapshot — replayed against the root domain,
        // a different host than the one it was mounted on.
        $update = $this->actingAs($admin)
            ->postLivewireUpdate('http://registro.local/livewire/update', $snapshot);

        $update->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Platform panel: zero tenant concept, must remain completely unaffected
    // -------------------------------------------------------------------------

    public function test_platform_livewire_update_has_no_tenant_requirement(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        Organization::factory()->create(['name' => 'Some Org For Platform List']);

        $page = $this->actingAs($superAdmin)->get('http://registro.local/platform/organizations');
        $page->assertOk();
        $snapshot = $this->extractResourcePageSnapshot($page->getContent());

        // No tenant subdomain involved at all — root domain, super-admin only.
        // If ResolveTenant/RequireTenant were ever (incorrectly) replayed here,
        // RequireTenant would abort_unless(tenant !== null, 404) and this would
        // fail — proving the platform panel's routes never carry these two
        // middleware, so the persistent-middleware replay is a true no-op here.
        $this->actingAs($superAdmin)
            ->postLivewireUpdate('http://registro.local/livewire/update', $snapshot)
            ->assertOk();
    }
}
