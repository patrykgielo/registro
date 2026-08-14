<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

/**
 * bootstrap/app.php previously configured neither TrustProxies nor TrustHosts
 * at all. Both are now wired: TrustProxies is driven by
 * config('trustedproxy.proxies') (TRUSTED_PROXIES_CIDR, config/trustedproxy.php),
 * TrustHosts by App\Support\TrustedTenantHosts + Laravel's own default
 * (config('app.url')'s host and subdomains). See both files' docblocks and
 * bootstrap/app.php for the full reasoning.
 *
 * These tests exercise the REAL classes Laravel registers, not
 * reimplementations — either via the container (after forcing
 * Http\Kernel::class to resolve, which is what actually fires the
 * ->withMiddleware() closure — tests/CreatesApplication.php only resolves
 * Console\Kernel, so without this a Feature test that never calls $this->get()
 * first would see TrustHosts un-configured) or by constructing them directly,
 * matching the style already established in
 * tests/Unit/Middleware/PestBrowserHostBugWorkaroundTest.php.
 */
class TrustedProxiesAndHostsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Both classes hold static state (TrustProxies/TrustHosts::$alwaysTrust*)
        // and Symfony\Component\HttpFoundation\Request holds its own static
        // trusted-proxy/trusted-host lists — none of it is per-Application, so
        // it survives this test's $this->app being torn down and would
        // otherwise leak into every later test in the same process (e.g.
        // ResolveTenantTest's "evil.com"/"unknown.registro.local" cases rely
        // on getHost() NOT throwing).
        Request::setTrustedProxies([], 0);
        Request::setTrustedHosts([]);
        TrustProxies::flushState();
        TrustHosts::flushState();

        parent::tearDown();
    }

    private function requestWithForwardedHost(string $realHost, string $forwardedHost): Request
    {
        $request = Request::create("https://{$realHost}/");
        $request->headers->set('HOST', $realHost);
        $request->headers->set('X-Forwarded-Host', $forwardedHost);
        $request->server->set('REMOTE_ADDR', '198.51.100.7');

        return $request;
    }

    /**
     * config/trustedproxy.php's OWN default — no config() override in this
     * test — must be null (TRUSTED_PROXIES_CIDR unset in .env.testing, as in
     * every real deployment until an edge network exists). Every other test
     * in this class explicitly sets config('trustedproxy.proxies'), which
     * would keep passing even if the file's default silently regressed to
     * something permissive (e.g. '*') — this is the one that actually
     * guards the file.
     */
    public function test_trusted_proxies_config_defaults_to_null(): void
    {
        $this->assertNull(config('trustedproxy.proxies'));
    }

    public function test_x_forwarded_host_is_ignored_without_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => null]);

        $request = $this->requestWithForwardedHost('registro.local', 'evil.com');
        (new TrustProxies)->handle($request, fn ($req) => response('ok'));

        $this->assertSame('registro.local', $request->getHost());
    }

    /**
     * Mutation evidence for the assertion above: prove the safe default is
     * doing real work, not passing by accident. Trusting the exact caller IP
     * makes X-Forwarded-Host take over — which is exactly the shape of
     * misconfiguration (an over-broad or wrong CIDR) this project must never
     * ship, and documents why TRUSTED_PROXIES_CIDR must stay unset until a
     * real, narrow edge-network CIDR exists.
     */
    public function test_x_forwarded_host_is_honored_once_the_caller_is_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => '198.51.100.7']);

        $request = $this->requestWithForwardedHost('registro.local', 'evil.com');
        (new TrustProxies)->handle($request, fn ($req) => response('ok'));

        $this->assertSame('evil.com', $request->getHost());
    }

    /**
     * A tenant subdomain, not the root domain: ResolveTenant only calls
     * URL::forceRootUrl() on the subdomain branch (see its handle()) — that
     * is also the realistic path for the vector this test is about (a
     * PasswordResetNotification triggered from a tenant's own login/reset
     * form), so it is what makes this assertion meaningful rather than
     * incidentally passing because the root-domain branch never touches
     * URL::forceRootUrl() at all.
     */
    private function createResolvableTenant(): void
    {
        $owner = User::factory()->create();
        Organization::create([
            'name' => 'Demo Salon',
            'slug' => 'demo',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
        ]);
    }

    /**
     * Concrete "absolute URL" assertion the task brief asks for: a request
     * carrying an untrusted X-Forwarded-Host must not be able to make
     * subsequently-generated absolute URLs (route(..., true), the shape a
     * password-reset link is built with — see PasswordResetNotification)
     * point at the attacker's host.
     */
    public function test_x_forwarded_host_cannot_redirect_generated_urls_to_an_attacker_host(): void
    {
        config(['app.domain' => 'registro.local', 'trustedproxy.proxies' => null]);
        $this->createResolvableTenant();

        $this->withHeaders(['X-Forwarded-Host' => 'evil.com'])
            ->get('https://demo.registro.local/')
            ->assertOk();

        $resetUrl = route('password.reset', ['token' => 'abc', 'email' => 'a@b.com'], true);

        $this->assertStringStartsWith('https://demo.registro.local/', $resetUrl);
        $this->assertStringNotContainsString('evil.com', $resetUrl);
    }

    /**
     * Mutation evidence for the above: with the caller explicitly trusted,
     * the SAME request DOES poison subsequent URL generation — proving the
     * previous test's safety comes from config, not from some Symfony
     * behavior that would hold regardless of what this project configures.
     */
    public function test_x_forwarded_host_does_redirect_generated_urls_once_the_proxy_is_trusted(): void
    {
        config(['app.domain' => 'registro.local', 'trustedproxy.proxies' => '127.0.0.1']);
        $this->createResolvableTenant();

        // Trusting the proxy poisons getHost() itself, so ResolveTenant now
        // sees Host "evil.com" — which doesn't match/end in app.domain, so it
        // takes its own foreign-domain redirect branch instead of resolving
        // "demo" and reaching URL::forceRootUrl() at all. Confirms the
        // poisoning reaches even earlier than URL generation.
        $this->withHeaders(['X-Forwarded-Host' => 'evil.com'])
            ->get('https://demo.registro.local/')
            ->assertRedirect();

        $resetUrl = route('password.reset', ['token' => 'abc', 'email' => 'a@b.com'], true);

        $this->assertStringContainsString('evil.com', $resetUrl);
    }

    public function test_trust_hosts_is_inert_under_the_real_test_environment(): void
    {
        // Forces the ->withMiddleware() closure to have actually run (see
        // class docblock) before inspecting TrustHosts' own decision.
        $this->app->make(HttpKernel::class);

        $middleware = $this->app->make(TrustHosts::class);
        $method = new \ReflectionMethod($middleware, 'shouldSpecifyTrustedHosts');
        $method->setAccessible(true);

        $this->assertFalse(
            $method->invoke($middleware),
            'TrustHosts must stay inert under APP_ENV=testing — tests/Browser drives real tenant subdomains through it.'
        );
    }

    /**
     * Mutation evidence: flip the app environment the same way
     * tests/Unit/Middleware/PestBrowserHostBugWorkaroundTest.php does, and
     * confirm TrustHosts actually enforces using our real, container-bound
     * closure (App\Support\TrustedTenantHosts + config('app.tenant_hosts')) —
     * not a closure re-declared inside the test, which would only prove the
     * test's own logic, not bootstrap/app.php's wiring.
     */
    public function test_trust_hosts_enforces_the_configured_patterns_outside_local_and_testing(): void
    {
        $this->app->make(HttpKernel::class);
        config(['app.tenant_hosts' => ['acme.pl']]);

        $this->app['env'] = 'production';

        $middleware = $this->app->make(TrustHosts::class);

        $allowed = Request::create('https://acme.pl/');
        $allowed->headers->set('HOST', 'acme.pl');
        $middleware->handle($allowed, fn ($req) => response('ok'));
        $this->assertSame('acme.pl', $allowed->getHost());

        $denied = Request::create('https://evil.com/');
        $denied->headers->set('HOST', 'evil.com');
        $middleware->handle($denied, fn ($req) => response('ok'));

        $this->expectException(SuspiciousOperationException::class);
        $denied->getHost();
    }
}
