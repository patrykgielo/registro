<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Middleware\ResolveTenant;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Post-login/registration return-to-origin (App\Support\Auth\IntendedDestination
 * + App\Support\Auth\CustomerLandingUrl).
 *
 * Two causes fixed together:
 * 1. LoginController::authenticated() always returned a Response, so
 *    AuthenticatesUsers::sendLoginResponse()'s `redirect()->intended(...)`
 *    fallback (vendor/laravel/ui) was dead code — even a guest intercepted on
 *    a protected route (Laravel's own redirect()->guest() correctly wrote
 *    session `url.intended`) never actually landed back there.
 * 2. A voluntary "Zaloguj się" link click never threw an AuthenticationException,
 *    so Laravel's own capture mechanism never fired for it at all —
 *    IntendedDestination::capture() (called from showLoginForm()/
 *    showRegistrationForm()) covers this case via Session::previousUrl()/
 *    previousRoute(), never the `Referer` header.
 */
class PostLoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    /**
     * Same test-double pattern as LoginPageTest/CheckRegistrationEnabledSessionFallbackTest.
     */
    private function actingAsTenant(?Organization $org): static
    {
        $this->app->bind(ResolveTenant::class, function () use ($org) {
            return new class($org)
            {
                public function __construct(private ?Organization $org) {}

                public function handle($request, $next)
                {
                    if ($this->org !== null) {
                        $request->attributes->set('tenant', $this->org);
                    }

                    return $next($request);
                }
            };
        });

        return $this;
    }

    private function url(string $path): string
    {
        return 'http://registro.local'.$path;
    }

    private function createCustomer(): User
    {
        $user = User::factory()->create();
        $user->assignRole('customer');

        return $user;
    }

    private function login(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->post($this->url('/login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);
    }

    private function createPublishedService(Organization $org): Service
    {
        return Service::factory()->create([
            'organization_id' => $org->id,
            'published_at' => now()->subDay(),
        ]);
    }

    private function setBookingEnabled(Organization $org, bool $enabled): void
    {
        Setting::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'group' => 'booking',
            'key' => 'booking_enabled',
            'value' => [$enabled],
        ]);
    }

    /**
     * Cause 1: Laravel's own auth middleware already writes `url.intended`
     * when an unauthenticated visitor is intercepted on a protected GET
     * route. Proves that value is finally honored (previously dead, per
     * class docblock).
     */
    public function test_intercepted_protected_route_returns_there_after_login(): void
    {
        $org = Organization::factory()->itemRental()->create();
        $user = $this->createCustomer();

        $this->actingAsTenant($org);

        $this->get($this->url('/koszyk'))->assertRedirect($this->url('/login'));

        // The browser follows the redirect and loads the login page — this is
        // where IntendedDestination::capture() re-affirms (and stamps) the
        // value Laravel's own auth middleware already wrote.
        $this->get($this->url('/login'))->assertOk();

        $this->login($user)->assertRedirect($this->url('/koszyk'));
    }

    /**
     * Cause 2: a voluntary click on a login link never throws — Laravel's own
     * capture never fires. IntendedDestination::capture() covers it.
     */
    public function test_voluntary_login_link_returns_to_the_page_it_was_clicked_from(): void
    {
        $org = Organization::factory()->create();
        $service = $this->createPublishedService($org);
        $user = $this->createCustomer();

        $this->actingAsTenant($org);

        $this->get($this->url("/uslugi/{$service->slug}"))->assertOk();
        $this->get($this->url('/login'))->assertOk();

        $this->login($user)->assertRedirect($this->url("/uslugi/{$service->slug}"));
    }

    /**
     * The pitfall: url()->previous()/UrlGenerator::previous() reads the
     * Referer header before falling back to session — a page on another site
     * linking to our /login would resurrect an open-redirect. This asserts a
     * forged Referer on a first-ever request (no session previousUrl at all)
     * is never used: IntendedDestination reads Session::previousUrl() only.
     */
    public function test_referer_header_is_never_used_as_a_destination(): void
    {
        $user = $this->createCustomer();
        $this->actingAsTenant(null);

        $this->get($this->url('/login'), ['Referer' => 'https://evil.example/'])->assertOk();

        $this->login($user)->assertRedirect(route('home'));
    }

    /**
     * consume() re-checks the host independently of capture() — a value that
     * somehow ended up pointing at a foreign host (e.g. a stale/tampered
     * session) must never be followed, even with a fresh, valid timestamp.
     * POSTs directly (skips GET /login) to isolate consume()'s own check from
     * capture()'s (capture() would separately discard this value anyway,
     * for the unrelated reason that this session's real previousUrl is null).
     */
    public function test_foreign_host_in_session_with_fresh_timestamp_is_rejected(): void
    {
        $user = $this->createCustomer();
        $this->actingAsTenant(null);

        $this->withSession([
            'url.intended' => 'https://evil.example/steal',
            'url.intended_at' => now()->timestamp,
        ]);

        $this->login($user)->assertRedirect(route('home'));
    }

    /**
     * PHP's parse_url() and the WHATWG URL Standard (what every real browser
     * implements) disagree on how to parse an authority containing a raw
     * backslash: parse_url() treats everything before the LAST "@" as
     * userinfo, so this string's host comes out as "registro.local" — but a
     * browser normalizes "\" to "/" in a special-scheme authority BEFORE
     * parsing, resolving the SAME string to host "evil.example". A check
     * built on parse_url() would call this "our own host"; the browser that
     * actually receives the Location: header navigates to evil.example.
     * isSameOrigin() never parses at all — see its docblock.
     */
    public function test_backslash_authority_trick_is_rejected(): void
    {
        $user = $this->createCustomer();
        $this->actingAsTenant(null);

        $this->withSession([
            'url.intended' => 'http://evil.example\@registro.local/admin/x',
            'url.intended_at' => now()->timestamp,
        ]);

        $this->login($user)->assertRedirect(route('home'));
    }

    /**
     * A literal ".." path segment is rejected outright, before origin/path
     * comparison even runs. Chosen deliberately NOT to land on a denylisted
     * prefix (/uslugi is not denylisted) — this proves the dedicated
     * dot-segment check, not the pre-existing path denylist, is what closes
     * it: without it, "/uslugi/../admin/dashboard" would pass isSafePath()
     * (its literal, un-normalized prefix is "/uslugi", not "/admin") while a
     * browser normalizes the same string to "/admin/dashboard".
     */
    public function test_dot_segment_path_traversal_is_rejected(): void
    {
        $user = $this->createCustomer();
        $this->actingAsTenant(null);

        $this->withSession([
            'url.intended' => $this->url('/uslugi/../admin/dashboard'),
            'url.intended_at' => now()->timestamp,
        ]);

        $this->login($user)->assertRedirect(route('home'));
    }

    /**
     * getSchemeAndHttpHost() never has a trailing slash, so a candidate that
     * is EXACTLY the origin (no path at all — e.g. a plain "GET /" request's
     * fullUrl()) must not be rejected just because it doesn't start with
     * "{origin}/". Explicit design decision, not an accident: root is a
     * legitimate destination.
     */
    public function test_url_equal_to_bare_origin_is_honored(): void
    {
        $user = $this->createCustomer();
        $this->actingAsTenant(null);

        $this->withSession([
            'url.intended' => $this->url(''),
            'url.intended_at' => now()->timestamp,
        ]);

        $this->login($user)->assertRedirect($this->url(''));
    }

    /**
     * card → /login → "Nie mam konta" → /customer/register → back to /login
     * (e.g. after a mistyped password) must never lose the originally
     * captured card URL — previousRoute() at each /login visit is either
     * 'customer.register' or 'login' itself, both on the auth-chain allowlist
     * that preserves (rather than overwrites/discards) the existing value.
     */
    public function test_auth_chain_loop_does_not_lose_the_captured_destination(): void
    {
        $org = Organization::factory()->create();
        $service = $this->createPublishedService($org);
        $user = $this->createCustomer();

        $this->actingAsTenant($org);

        $this->get($this->url("/uslugi/{$service->slug}"))->assertOk();
        $this->get($this->url('/login'))->assertOk();

        // "Zapomniałeś hasła?" bounce — previousRoute() becomes 'password.request'.
        $this->get($this->url('/password/reset'))->assertOk();
        $this->get($this->url('/login'))->assertOk();

        $this->login($user)->assertRedirect($this->url("/uslugi/{$service->slug}"));
    }

    /**
     * Defense in depth: capture()'s auth-chain branch ("keep existing,
     * refresh timestamp") re-validates the ALREADY-stored value before
     * trusting it, rather than blindly re-stamping it. Without this,
     * consume() would be the only place that ever re-checks host/path
     * safety for a value written outside the normal "safe candidate" path.
     */
    public function test_auth_chain_branch_discards_a_stored_value_that_fails_revalidation(): void
    {
        $this->actingAsTenant(null);

        // First /login visit — sets previousRoute() to 'login' for the NEXT
        // request, which is what routes the following visit into the
        // auth-chain branch.
        $this->get($this->url('/login'))->assertOk();

        // Simulate a value that is unsafe regardless of how it got there
        // (tampered session, or simply pre-existing from something other
        // than capture()'s own "safe candidate" branch).
        $this->withSession([
            'url.intended' => 'https://evil.example/steal',
            'url.intended_at' => now()->timestamp,
        ]);

        $this->get($this->url('/login'))->assertOk();

        $this->assertNull(session('url.intended'));
        $this->assertNull(session('url.intended_at'));
    }

    /**
     * isSafePath()'s denylist-prefix check must be case-insensitive —
     * otherwise "/Admin/..." would slip past a denylist written in
     * lowercase. (Host/origin comparison itself doesn't need an explicit
     * case-insensitive comparison: Symfony's Request::getHost() always
     * lowercases, on both the read side — isSameOrigin()'s
     * getSchemeAndHttpHost() — and the write side — fullUrl()/previousUrl()
     * are built from the same normalized getUri(), so a stored value's host
     * is never differently-cased than the live request's own host to begin
     * with. Path segments have no such framework-wide normalization.)
     */
    public function test_denylisted_path_prefix_check_is_case_insensitive(): void
    {
        $user = $this->createCustomer();
        $this->actingAsTenant(null);

        $this->withSession([
            'url.intended' => $this->url('/Admin/sneaky'),
            'url.intended_at' => now()->timestamp,
        ]);

        $this->login($user)->assertRedirect(route('home'));
    }

    /**
     * card → /login → /customer/register → submit form must return to the
     * card, exercising IntendedDestination::capture() from
     * showRegistrationForm() and ::consume() from registered().
     */
    public function test_registration_from_service_card_returns_to_the_card(): void
    {
        $org = Organization::factory()->create();
        $service = $this->createPublishedService($org);

        $this->actingAsTenant($org);

        $this->get($this->url("/uslugi/{$service->slug}"))->assertOk();
        $this->get($this->url('/login'))->assertOk();
        $this->get($this->url('/customer/register'))->assertOk();

        $response = $this->post($this->url('/customer/register'), [
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => 'jan.kowalski@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect($this->url("/uslugi/{$service->slug}"));
    }

    public function test_super_admin_redirects_to_platform_and_clears_intended(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $this->actingAsTenant(null);

        $response = $this->login($user);

        $response->assertRedirect('/platform');
        $response->assertSessionMissing(['url.intended', 'url.intended_at']);
    }

    public function test_admin_on_tenant_subdomain_redirects_to_admin_panel_and_clears_intended(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->organizations()->attach($org->id);

        $this->actingAsTenant($org);

        $response = $this->login($user);

        $response->assertRedirect('/admin');
        $response->assertSessionMissing(['url.intended', 'url.intended_at']);
    }

    public function test_admin_on_root_domain_redirects_to_tenant_admin_url_and_clears_intended(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->organizations()->attach($org->id);

        $this->actingAsTenant(null);

        $response = $this->login($user);

        $response->assertRedirect("http://{$org->slug}.registro.local/admin");
        $response->assertSessionMissing(['url.intended', 'url.intended_at']);
    }

    /**
     * The finding this plan is built on: an admin/staff user with no
     * organization at all used to fall into the customer branch and land on
     * appointments.index — 404 for a rental-only tenant, and nonsensical
     * regardless. Now an explicit branch.
     */
    public function test_admin_without_organization_redirects_home_and_clears_intended(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAsTenant(null);

        $response = $this->login($user);

        $response->assertRedirect(route('home'));
        $response->assertSessionMissing(['url.intended', 'url.intended_at']);
    }

    public function test_customer_with_no_captured_destination_redirects_to_role_specific_landing_and_session_is_clean(): void
    {
        $org = Organization::factory()->create(); // default booking_type: time_slot
        $user = $this->createCustomer();

        $this->actingAsTenant($org);

        $response = $this->login($user);

        $response->assertRedirect(route('appointments.index'));
        $response->assertSessionMissing(['url.intended', 'url.intended_at']);
    }

    public function test_value_without_timestamp_is_rejected_and_cleared(): void
    {
        $user = $this->createCustomer();
        $this->actingAsTenant(null);

        $this->withSession(['url.intended' => '/some/safe/path']);

        $response = $this->login($user);

        $response->assertRedirect(route('home'));
        $response->assertSessionMissing(['url.intended', 'url.intended_at']);
    }

    public function test_value_older_than_ttl_is_rejected(): void
    {
        $user = $this->createCustomer();
        $this->actingAsTenant(null);

        $this->withSession([
            'url.intended' => $this->url('/uslugi/some-service'),
            'url.intended_at' => now()->subMinutes(61)->timestamp,
        ]);

        $response = $this->login($user);

        $response->assertRedirect(route('home'));
        $response->assertSessionMissing(['url.intended', 'url.intended_at']);
    }

    /**
     * Every CustomerLandingUrl fallback must itself be reachable — this is
     * the test that catches the RequireTenant trap the original bug report
     * was about (rental-only tenant landing on appointments.index → 404).
     */
    public function test_appointments_fallback_returns_200(): void
    {
        $org = Organization::factory()->create(); // time_slot, booking enabled by default
        $user = $this->createCustomer();

        $this->actingAsTenant($org);

        $response = $this->login($user);
        $location = $response->headers->get('Location');

        $this->assertStringContainsString('/my-appointments', $location);
        $this->actingAsTenant($org)->get($location)->assertOk();
    }

    public function test_orders_fallback_returns_200_for_rental_tenant(): void
    {
        $org = Organization::factory()->itemRental()->create();
        $user = $this->createCustomer();

        $this->actingAsTenant($org);

        $response = $this->login($user);
        $location = $response->headers->get('Location');

        $this->assertStringContainsString('/moje-zamowienia', $location);
        $this->actingAsTenant($org)->get($location)->assertOk();
    }

    public function test_profile_fallback_returns_200_when_booking_disabled_on_time_slot_tenant(): void
    {
        $org = Organization::factory()->create(); // time_slot
        $this->setBookingEnabled($org, false);
        $user = $this->createCustomer();

        $this->actingAsTenant($org);

        $response = $this->login($user);
        $location = $response->headers->get('Location');

        $this->assertStringContainsString('/moje-konto', $location);
        $this->actingAsTenant($org)->get($location)->assertOk();
    }

    public function test_root_domain_customer_with_organization_lands_on_that_tenants_own_url_and_it_returns_200(): void
    {
        $org = Organization::factory()->create(); // time_slot
        $user = $this->createCustomer();
        $user->organizations()->attach($org->id);

        $this->actingAsTenant(null);

        $response = $this->login($user);
        $location = $response->headers->get('Location');

        $this->assertStringContainsString("{$org->slug}.registro.local", $location);
        $this->assertStringContainsString('/my-appointments', $location);

        $this->actingAsTenant($org)->get($location)->assertOk();
    }

    public function test_root_domain_customer_with_no_organization_lands_on_home_and_it_returns_200(): void
    {
        $user = $this->createCustomer();

        $this->actingAsTenant(null);

        $response = $this->login($user);
        $location = $response->headers->get('Location');

        $this->assertSame(route('home'), $location);
        $this->actingAsTenant(null)->get($location)->assertOk();
    }

    /**
     * TenantFeature::currentTenant()'s session('tenant_id') fallback branch
     * (VULN-003 class of bug) must never influence the customer landing
     * decision — CustomerLandingUrl::for() reads the `tenant` REQUEST
     * ATTRIBUTE only. The two organizations deliberately have different
     * booking_type so a leak would be observable as the wrong route name.
     */
    public function test_poisoned_tenant_id_session_does_not_influence_landing(): void
    {
        $realOrg = Organization::factory()->itemRental()->create();
        $poisonedOrg = Organization::factory()->create(); // time_slot

        $user = $this->createCustomer();

        $this->actingAsTenant($realOrg);
        $this->withSession(['tenant_id' => $poisonedOrg->id]);

        $response = $this->login($user);

        $response->assertRedirect(route('orders.index'));
    }
}
