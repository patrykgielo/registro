<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Middleware\ResolveTenant;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * POST /customer/register must be guarded exactly like the GET form: a customer
 * account only ever belongs to a tenant. Before the fix only the GET redirected a
 * tenant-less (root domain) visitor to login; the POST sailed through
 * (ResolveTenant passes the root domain without a tenant, CheckRegistrationEnabled
 * defaults to "enabled") and created a user attached to no organization -- an
 * orphan holding a unique e-mail that blocked the same person's later registration
 * on a real tenant.
 *
 * Real ResolveTenant, real Host headers -- no bound test double, so the test walks
 * the same path a browser does.
 */
class RegisterRequiresTenantTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = 'http://registro.local';

    private const EMAIL = 'orphan.candidate@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local', 'app.tenant_slug' => null]);
        $this->withoutMiddleware([ThrottleRequests::class]);
        Cache::flush();
    }

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => self::EMAIL,
            'password' => 'HasloTest123!',
            'password_confirmation' => 'HasloTest123!',
        ], $overrides);
    }

    private function createTenant(string $slug): Organization
    {
        return Organization::create([
            'name' => 'Firma '.$slug,
            'slug' => $slug,
            'booking_type' => 'item_rental',
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    public function test_post_on_root_domain_creates_no_user_and_redirects_to_login(): void
    {
        $before = User::count();

        $response = $this->post(self::ROOT.'/customer/register', $this->payload());

        // Same answer as the GET form on the root domain (see
        // RegisterTenantTest::test_customer_registration_on_root_domain_redirects_to_login).
        $response->assertRedirect(route('login'));
        $this->assertSame($before, User::count());
        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
        $this->assertGuest();
    }

    public function test_post_on_root_domain_redirects_before_validation_runs(): void
    {
        // An invalid payload must get the same redirect, not validation errors:
        // the tenant check is the first thing the POST does, so a probe of the
        // root domain learns nothing (e.g. which e-mails already exist).
        $existing = User::factory()->create();

        $response = $this->post(self::ROOT.'/customer/register', $this->payload([
            'email' => $existing->email,
            'password_confirmation' => 'does-not-match',
        ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasNoErrors();
    }

    public function test_post_on_root_domain_ignores_a_stale_session_tenant(): void
    {
        // Pins that the guard reads the request ATTRIBUTE, not
        // TenantFeature::currentTenant(). A visitor who browsed a tenant subdomain
        // earlier carries session('tenant_id') to the root domain. The real
        // ResolveTenant sets `tenant_resolution_attempted`, which already shuts the
        // session fallback, so to make this falsifiable we use the same no-marker
        // test double as CheckRegistrationEnabledSessionFallbackTest: it resolves
        // no tenant and leaves the test-only session branch of currentTenant() open.
        $org = $this->createTenant('demo');

        $this->app->bind(ResolveTenant::class, fn () => new class
        {
            public function handle($request, $next)
            {
                return $next($request);
            }
        });

        $this->withSession(['tenant_id' => $org->id])
            ->post(self::ROOT.'/customer/register', $this->payload())
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
        $this->assertGuest();
    }

    public function test_post_on_tenant_subdomain_still_registers_and_attaches_the_organization(): void
    {
        $org = $this->createTenant('demo');

        $this->post('http://demo.registro.local/customer/register', $this->payload())
            ->assertStatus(302);

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRole('customer'));
        $this->assertSame('customer', $user->organizations()->where('organization_id', $org->id)->firstOrFail()->pivot->role);
    }

    public function test_post_on_tenant_with_registration_disabled_still_creates_no_user(): void
    {
        $org = $this->createTenant('demo');
        Setting::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'group' => 'auth',
            'key' => 'registration_enabled',
            'value' => [false],
        ]);

        $this->post('http://demo.registro.local/customer/register', $this->payload())
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function test_dedicated_stack_registers_on_the_tenants_own_host(): void
    {
        // Stack-per-tenant: TENANT_SLUG pins the tenant, the Host is only checked
        // against TENANT_HOSTS. The tenant attribute is therefore set on a host
        // that looks nothing like config('app.domain') -- the guard must not
        // depend on a subdomain.
        config(['app.tenant_slug' => 'acme', 'app.tenant_hosts' => ['acme.pl']]);
        $org = $this->createTenant('acme');

        $this->post('http://acme.pl/customer/register', $this->payload())
            ->assertStatus(302);

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertTrue($user->organizations()->where('organization_id', $org->id)->exists());
    }

    public function test_dedicated_stack_refuses_a_host_outside_tenant_hosts_and_creates_no_user(): void
    {
        config(['app.tenant_slug' => 'acme', 'app.tenant_hosts' => ['acme.pl']]);
        $this->createTenant('acme');

        $this->post('http://stray.example/customer/register', $this->payload())
            ->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }
}
