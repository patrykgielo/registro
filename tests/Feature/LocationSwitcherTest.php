<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faza 5.2 (86cbahqg8) — the header/drawer location switcher itself:
 * `LocationSelectionController` (the write side) and the two render sites in
 * `components/nav/header.blade.php` (desktop dropdown + mobile drawer list).
 *
 * Uses the same `actingAsTenant()` bind-a-fake-ResolveTenant pattern as
 * `Tests\Feature\Middleware\ShareSelectedLocationTest` — a real request
 * through the real 'web' group, not a hand-built LocationContext, so the
 * route's own middleware stack (ResolveTenant, RequireTenant, throttle) and
 * the header component actually run.
 */
class LocationSwitcherTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsTenant(Organization $org): static
    {
        $this->app->bind(ResolveTenant::class, function () use ($org) {
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

    /**
     * The 86cbahqg8 acceptance criterion is literal: "nie renderuje się w
     * ogóle" — no element at all, not a CSS-hidden one. Asserts on the
     * absence of every string that only exists inside the switcher markup
     * (the mutating route's own path, and the two section headings), so a
     * regression that renders the block merely `hidden` would still fail
     * this the same way a regression that removes `@if($__locationSwitcherVisible)`
     * entirely would be caught by the companion "multi-location" test below.
     */
    public function test_single_location_tenant_gets_no_trace_of_the_switcher(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create(['is_active' => true, 'name' => 'Jedyny Oddział']);

        $response = $this->actingAsTenant($org)->get('/');

        $response->assertOk();
        $response->assertDontSee('lokalizacja/wybierz', false);
        $response->assertDontSee('Oddziały');
        $response->assertDontSee('menuitemradio', false);
    }

    public function test_zero_location_tenant_gets_no_trace_of_the_switcher(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $response = $this->actingAsTenant($org)->get('/');

        $response->assertOk();
        $response->assertDontSee('lokalizacja/wybierz', false);
        $response->assertDontSee('Oddziały');
    }

    public function test_multi_location_tenant_sees_the_switcher_with_both_own_options_and_no_foreign_one(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($org, 'organization')->create(['is_active' => true, 'name' => 'Warszawa Centrum']);
        $locationB = Location::factory()->for($org, 'organization')->create(['is_active' => true, 'name' => 'Kraków Wschód']);

        $foreignOrg = Organization::factory()->equipmentRental()->create();
        $foreignLocation = Location::factory()->for($foreignOrg, 'organization')->create(['is_active' => true, 'name' => 'Gdańsk Obcy']);

        $response = $this->actingAsTenant($org)->get('/');

        $response->assertOk();
        $response->assertSee('lokalizacja/wybierz', false);
        $response->assertSee($locationA->name);
        $response->assertSee($locationB->name);
        $response->assertDontSee($foreignLocation->name);
    }

    public function test_an_inactive_third_location_does_not_count_toward_requiring_the_switcher(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create(['is_active' => true, 'name' => 'Jedyny Aktywny']);
        Location::factory()->for($org, 'organization')->create(['is_active' => false, 'name' => 'Nieaktywny Oddział']);

        $response = $this->actingAsTenant($org)->get('/');

        $response->assertOk();
        $response->assertDontSee('lokalizacja/wybierz', false);
    }

    public function test_selecting_a_location_survives_the_next_request(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        [$locationA, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);

        $response = $this->actingAsTenant($org)->post(route('location.select'), [
            'location_id' => $locationB->id,
        ]);

        $response->assertSessionHas('selected_location_id', $locationB->id);
        $response->assertRedirect(route('home'));

        $followUp = $this->actingAsTenant($org)->get('/');
        $followUp->assertOk();
        $followUp->assertSessionHas('selected_location_id', $locationB->id);
    }

    /**
     * `LocationContext::set()` would throw for this — the controller's own
     * `Rule::exists(...)->where('organization_id', ...)` must reject it
     * BEFORE set() is ever called, so the caller gets a normal validation
     * failure, never a 500.
     */
    public function test_selecting_another_tenants_location_is_denied_without_a_500_and_leaves_context_unchanged(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();
        [$ownLocationA, $ownLocationB] = Location::factory()->for($orgA, 'organization')->count(2)->create(['is_active' => true]);
        $foreignLocation = Location::factory()->for($orgB, 'organization')->create(['is_active' => true]);

        $response = $this->actingAsTenant($orgA)
            ->withSession(['selected_location_id' => $ownLocationA->id])
            ->post(route('location.select'), ['location_id' => $foreignLocation->id]);

        $response->assertSessionHasErrors('location_id');
        $this->assertNotEquals(500, $response->getStatusCode());
        $response->assertSessionHas('selected_location_id', $ownLocationA->id);
    }

    public function test_selecting_an_inactive_location_is_denied_without_a_500_and_leaves_context_unchanged(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        [$activeA, $activeB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        $inactive = Location::factory()->for($org, 'organization')->create(['is_active' => false]);

        $response = $this->actingAsTenant($org)
            ->withSession(['selected_location_id' => $activeA->id])
            ->post(route('location.select'), ['location_id' => $inactive->id]);

        $response->assertSessionHasErrors('location_id');
        $this->assertNotEquals(500, $response->getStatusCode());
        $response->assertSessionHas('selected_location_id', $activeA->id);
    }

    /**
     * `redirect_to` is attacker-reachable (a direct POST, not necessarily
     * through the rendered form) — an off-origin value must never be
     * followed. Mirrors auth-redirects.md's isSameOrigin() contract, the
     * same guard `IntendedDestination` uses for post-login returns.
     */
    public function test_an_off_origin_redirect_to_is_not_followed(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        [, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);

        $response = $this->actingAsTenant($org)->post(route('location.select'), [
            'location_id' => $locationB->id,
            'redirect_to' => 'https://evil.example/steal',
        ]);

        $response->assertRedirect(route('home'));
    }

    public function test_a_same_origin_redirect_to_is_honored(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        [, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        $target = url('/wypozyczalnia');

        $response = $this->actingAsTenant($org)->post(route('location.select'), [
            'location_id' => $locationB->id,
            'redirect_to' => $target,
        ]);

        $response->assertRedirect($target);
    }

    /**
     * Code review (2026-09-09): `isSameOrigin()` alone is not enough —
     * `/admin/...` is same-origin but must never be a `redirect_to` target
     * from this PUBLIC, unauthenticated route. Proves the fix uses
     * `IntendedDestination::isSafeUrl()` (origin AND
     * DENYLISTED_PATH_PREFIXES), not just origin.
     */
    public function test_a_same_origin_but_denylisted_path_redirect_to_is_not_followed(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        [, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);

        $response = $this->actingAsTenant($org)->post(route('location.select'), [
            'location_id' => $locationB->id,
            'redirect_to' => url('/admin/organizations'),
        ]);

        $response->assertRedirect(route('home'));
    }

    /**
     * Code review (2026-09-09): an overlong/malformed `redirect_to` must not
     * abort the whole request — the customer asked to change location, not
     * to be told their return address is too long. Proves the location
     * itself still gets selected even though the redirect target falls back.
     */
    public function test_an_overlong_redirect_to_does_not_block_the_location_selection(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        [, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);

        $response = $this->actingAsTenant($org)->post(route('location.select'), [
            'location_id' => $locationB->id,
            'redirect_to' => url('/').'?padding='.str_repeat('a', 2100),
        ]);

        $response->assertSessionDoesntHaveErrors();
        $response->assertSessionHas('selected_location_id', $locationB->id);
        $response->assertRedirect(route('home'));
    }

    /**
     * Code review (2026-09-09): no cap exists on locations per tenant, and
     * neither render site had a bounded/scrollable container — an
     * unbounded list would run off-screen with no way to reach the bottom
     * entries. This is an HTTP-level proxy for that CSS property (the real
     * visual behaviour needs a browser, out of scope here) — it pins that
     * the scroll container classes are actually present in the markup, so a
     * regression that drops them is at least caught structurally.
     */
    public function test_both_switcher_render_sites_have_a_bounded_scrollable_list(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);

        $response = $this->actingAsTenant($org)->get('/');

        $response->assertOk();
        $response->assertSeeInOrder(['max-h-72 overflow-y-auto', 'max-h-72 overflow-y-auto space-y-1'], false);
    }
}
