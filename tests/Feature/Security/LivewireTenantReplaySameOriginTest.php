<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two independent layers guard a replayed `/livewire/update` snapshot, and the existing
 * LivewireAdminTenantIsolationTest cannot tell them apart:
 *
 *  1. Livewire >= 3.8.12 (`PersistentMiddleware`) binds every snapshot to the scheme+host it was rendered
 *     on (`memo.origin`) and to the route it was rendered by (`memo.route` = uri + domain), and `abort(404)`s on a
 *     mismatch BEFORE any persistent middleware runs. A replay from another host therefore dies here, never reaching
 *     our code.
 *  2. Our own persistent middleware (`AppServiceProvider::registerLivewireTenantIsolation()` = ResolveTenant +
 *     RequireTenant), replayed against the ORIGINAL mount path. This is the layer that must still hold when the
 *     origin and the route MATCH — the only one that re-runs the staff-membership check (the real request path is
 *     `livewire/update`, which `ResolveTenant::staffCanAccessTenant()` does not treat as `admin*`).
 *
 * Section A pins layer 1 (so we notice if a Livewire update changes it). Section B reaches layer 2 with the origin
 * and route matching; each test there goes red when the middleware it names is dropped from the persistent list.
 *
 * A same-origin snapshot that our middleware has to reject cannot be produced by a legitimate mount (the mount
 * itself would have been refused), so the cross-tenant and root-host cases re-sign a real snapshot with
 * `Checksum::generate()` after rewriting `memo.origin`. That models "origin binding satisfied" (an attacker holding
 * the signing key, or any future change to how the origin is bound) — i.e. exactly the case where layer 2 is the
 * only thing left.
 */
class LivewireTenantReplaySameOriginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    /** The resource page's own snapshot (every component not namespaced `filament.livewire.*`). */
    private function extractResourcePageSnapshot(string $html): string
    {
        preg_match_all('/wire:snapshot="(.*?)"/s', $html, $matches);

        foreach ($matches[1] as $raw) {
            $decoded = htmlspecialchars_decode($raw, ENT_QUOTES);

            if (! str_starts_with(json_decode($decoded, true)['memo']['name'] ?? '', 'filament.livewire.')) {
                return $decoded;
            }
        }

        throw new \RuntimeException('Could not locate resource page wire:snapshot in rendered HTML.');
    }

    /** Re-signs a snapshot after `$mutate` changed it, so Livewire's checksum check passes. */
    private function resign(string $snapshot, callable $mutate): string
    {
        $data = json_decode($snapshot, true);
        unset($data['checksum']);
        $data = $mutate($data);
        $data['checksum'] = Checksum::generate($data);

        return json_encode($data);
    }

    private function postLivewireUpdate(string $url, string $snapshot): TestResponse
    {
        return $this->postJson($url, [
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
        ], ['X-Livewire' => true]);
    }

    /** @return array{0: Organization, 1: User, 2: string} org, its admin, the snapshot mounted on its own host */
    private function mountedOn(string $slug, string $serviceName): array
    {
        $org = Organization::factory()->create(['slug' => $slug]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($org->id);
        Service::factory()->create(['organization_id' => $org->id, 'name' => $serviceName]);

        $page = $this->actingAs($admin)->get("https://{$slug}.registro.local/admin/services");
        $page->assertOk();

        return [$org, $admin, $this->extractResourcePageSnapshot($page->getContent())];
    }

    // ---------------------------------------------------------------------------------------------
    // A. Livewire's own binding (3.8.12+) — pinned, because we now rely on it as the outer layer
    // ---------------------------------------------------------------------------------------------

    public function test_a_snapshot_records_the_origin_and_route_it_was_rendered_on(): void
    {
        [, , $snapshot] = $this->mountedOn('orga-memo', 'Memo Service');

        $memo = json_decode($snapshot, true)['memo'];

        $this->assertSame('https://orga-memo.registro.local', $memo['origin']);
        $this->assertSame('admin/services', $memo['path']);
        $this->assertSame('admin/services', $memo['route']['uri']);
    }

    public function test_the_same_origin_replay_works(): void
    {
        [, $admin, $snapshot] = $this->mountedOn('orga-same', 'Same Origin Service');

        $update = $this->actingAs($admin)->postLivewireUpdate('https://orga-same.registro.local/livewire/update', $snapshot);

        $update->assertOk();
        $this->assertStringContainsString('Same Origin Service', $update->json('components.0.effects.html') ?? '');
    }

    public function test_livewire_rejects_a_replay_from_a_different_origin(): void
    {
        [, $admin, $snapshot] = $this->mountedOn('orga-origin', 'Origin Service');
        Organization::factory()->create(['slug' => 'orgb-origin']);

        // Another tenant's host, the bare root domain, and the same host over plain http.
        foreach ([
            'https://orgb-origin.registro.local/livewire/update',
            'https://registro.local/livewire/update',
            'http://orga-origin.registro.local/livewire/update',
        ] as $url) {
            $this->actingAs($admin)->postLivewireUpdate($url, $snapshot)->assertNotFound();
        }
    }

    public function test_livewire_rejects_a_snapshot_without_origin_or_route_memo(): void
    {
        [, $admin, $snapshot] = $this->mountedOn('orga-legacy', 'Legacy Service');
        $url = 'https://orga-legacy.registro.local/livewire/update';

        // What a page opened BEFORE the Livewire upgrade still holds: no memo.origin / memo.route.
        $this->actingAs($admin)->postLivewireUpdate($url, $this->resign($snapshot, function (array $data): array {
            unset($data['memo']['origin']);

            return $data;
        }))->assertNotFound();

        $this->actingAs($admin)->postLivewireUpdate($url, $this->resign($snapshot, function (array $data): array {
            unset($data['memo']['route']);

            return $data;
        }))->assertNotFound();
    }

    public function test_livewire_rejects_a_snapshot_whose_route_memo_no_longer_matches(): void
    {
        [, $admin, $snapshot] = $this->mountedOn('orga-route', 'Route Service');

        $tampered = $this->resign($snapshot, function (array $data): array {
            $data['memo']['route']['uri'] = 'admin/customers';

            return $data;
        });

        $this->actingAs($admin)
            ->postLivewireUpdate('https://orga-route.registro.local/livewire/update', $tampered)
            ->assertNotFound();
    }

    // ---------------------------------------------------------------------------------------------
    // B. OUR layer, with origin and route matching (Livewire's 404 is NOT in play)
    // ---------------------------------------------------------------------------------------------

    /**
     * Same host, same origin, same route — the only thing that changed since the mount is that the user lost access to
     * the organisation. The real request path is `livewire/update`, so only the replay against `admin/services`
     * makes `ResolveTenant` run its staff-membership check. Dropping ResolveTenant from the persistent list lets the
     * update through (verified by mutation).
     */
    public function test_a_staff_member_removed_from_the_organisation_after_the_render_is_refused_on_the_same_origin(): void
    {
        [$org, $admin, $snapshot] = $this->mountedOn('orga-revoked', 'Revoked Staff Service');

        $admin->organizations()->detach($org->id);

        $update = $this->actingAs($admin->fresh())
            ->postLivewireUpdate('https://orga-revoked.registro.local/livewire/update', $snapshot);

        $update->assertRedirect();
        $this->assertStringNotContainsString('Revoked Staff Service', $update->getContent());
    }

    /**
     * A's admin, A's snapshot — but the origin memo says B's host and the request goes to B's host, so Livewire's
     * origin and route checks pass. Only our replay (ResolveTenant's membership check) stands between A's admin and
     * B's data. Dropping ResolveTenant from the persistent list renders B's rows (verified by mutation).
     */
    public function test_a_cross_tenant_replay_that_satisfies_the_origin_binding_is_still_refused(): void
    {
        [, $adminA, $snapshotA] = $this->mountedOn('orga-cross', 'Org A Service');
        $orgB = Organization::factory()->create(['slug' => 'orgb-cross']);
        Service::factory()->create(['organization_id' => $orgB->id, 'name' => 'Org B SECRET Service']);

        $forged = $this->resign($snapshotA, function (array $data): array {
            $data['memo']['origin'] = 'https://orgb-cross.registro.local';

            return $data;
        });

        $update = $this->actingAs($adminA)
            ->postLivewireUpdate('https://orgb-cross.registro.local/livewire/update', $forged);

        $update->assertRedirect();
        $this->assertStringNotContainsString('Org B SECRET Service', $update->getContent());
    }

    /**
     * Origin rewritten to the bare root domain and the request sent there: Livewire's origin and route checks pass
     * (the admin routes are not domain-bound), `ResolveTenant` deliberately resolves nothing on the root domain, and
     * only `RequireTenant`'s replay aborts. Dropping RequireTenant from the persistent list lets the update
     * continue without a tenant (verified by mutation).
     */
    public function test_a_replay_on_the_root_domain_that_satisfies_the_origin_binding_is_aborted_by_require_tenant(): void
    {
        [, $admin, $snapshot] = $this->mountedOn('orga-root', 'Root Service');

        $forged = $this->resign($snapshot, function (array $data): array {
            $data['memo']['origin'] = 'https://registro.local';

            return $data;
        });

        $update = $this->actingAs($admin)->postLivewireUpdate('https://registro.local/livewire/update', $forged);

        $update->assertNotFound();
    }
}
