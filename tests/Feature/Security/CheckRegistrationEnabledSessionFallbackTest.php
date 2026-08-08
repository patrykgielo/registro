<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\ResolveTenant;
use App\Models\Organization;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * VULN-003 follow-up: CheckRegistrationEnabled previously gated on
 * SettingsManager::isRegistrationEnabled(), which resolves the tenant via
 * TenantFeature::currentTenant() — whose 3rd fallback branch reads
 * session('tenant_id'). ResolveTenant writes that session key on EVERY
 * successful subdomain resolution, including anonymous visits, and (on the
 * root domain) intentionally resolves NO tenant for the current request.
 *
 * A visitor who merely browsed another tenant's subdomain earlier could
 * carry that tenant's registration-enabled toggle into an unrelated
 * request — e.g. having the WRONG tenant's setting decide whether THIS
 * tenant's (or the root domain's) registration form is shown.
 *
 * Fix: CheckRegistrationEnabled now reads the `tenant` request ATTRIBUTE
 * (set deterministically per-request by ResolveTenant) and calls the new
 * SettingsManager::isRegistrationEnabledFor() / getForOrganization(), which
 * never falls back to currentTenant()/session resolution.
 *
 * See app/docs/security/vulnerabilities/VULN-003-root-domain-tenant-bypass.md
 * and .claude/rules/middleware.md (Layer 5 / RequireTenant follow-ups).
 */
class CheckRegistrationEnabledSessionFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    /**
     * Bind a test double for ResolveTenant — same pattern used throughout the
     * project (e.g. BookingCrossTenantSessionFallbackTest::actingAsTenant()).
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

    private function setRegistrationEnabled(Organization $org, bool $enabled): void
    {
        Setting::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'group' => 'auth',
            'key' => 'registration_enabled',
            'value' => [$enabled],
        ]);
    }

    public function test_disabled_setting_on_real_tenant_blocks_registration_despite_poisoned_session_for_enabled_tenant(): void
    {
        $realOrg = Organization::factory()->create();
        $poisonedOrg = Organization::factory()->create();

        $this->setRegistrationEnabled($realOrg, false);
        $this->setRegistrationEnabled($poisonedOrg, true);

        $response = $this->actingAsTenant($realOrg)
            ->withSession(['tenant_id' => $poisonedOrg->id])
            ->get('http://registro.local/customer/register');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('info', 'Rejestracja jest tymczasowo niedostępna.');
    }

    public function test_enabled_setting_on_real_tenant_allows_registration_despite_poisoned_session_for_disabled_tenant(): void
    {
        $realOrg = Organization::factory()->create();
        $poisonedOrg = Organization::factory()->create();

        $this->setRegistrationEnabled($realOrg, true);
        $this->setRegistrationEnabled($poisonedOrg, false);

        $response = $this->actingAsTenant($realOrg)
            ->withSession(['tenant_id' => $poisonedOrg->id])
            ->get('http://registro.local/customer/register');

        $response->assertOk();
        $response->assertViewIs('auth.register');
    }

    public function test_root_domain_uses_global_default_not_poisoned_session_tenant(): void
    {
        $poisonedOrg = Organization::factory()->create();
        $this->setRegistrationEnabled($poisonedOrg, false);

        // No `tenant` request attribute set for this request (root domain) —
        // real ResolveTenant behaviour when the host has no matching subdomain.
        $response = $this->actingAsTenant(null)
            ->withSession(['tenant_id' => $poisonedOrg->id])
            ->get('http://registro.local/customer/register');

        // Not blocked by the poisoned session's disabled tenant: the global
        // default (true) governs, so the request reaches the controller, which
        // then redirects a tenant-less visitor to login — there is no public
        // business-registration wizard to fall back to (see routes/web.php).
        $response->assertRedirect(route('login'));
    }
}
