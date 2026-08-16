<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Middleware\ResolveTenant;
use App\Models\Organization;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Pins two defects reported against the login/register screens:
 *
 * 1. The "Zarejestruj się" link was always rendered (guarded by
 *    $registrationEnabled, default true) but effectively invisible: the
 *    footer sat on components/ios/auth-card.blade.php's page background,
 *    which used bg-primary-600 — a Tailwind class referencing a `primary`
 *    color scale that design-tokens.css never registers (only `brand`
 *    exists). Tailwind v4 silently drops unresolvable utilities, so the
 *    background rendered transparent and every white-on-brand text
 *    (title, subtitle, footer links) sat on the page's plain white body,
 *    i.e. white text on a white background.
 * 2. Once bg-brand replaced bg-primary-600, some of that white text used
 *    /90 or /70 opacity, which fails WCAG AA contrast against the
 *    default brand color (measured ~4.13:1 and ~3.09:1, both below the
 *    4.5:1 floor for this text size) — see app/docs/features/tenant-branding.md.
 *
 * See resources/views/components/ios/auth-card.blade.php.
 */
class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    /**
     * Same test-double pattern as CheckRegistrationEnabledSessionFallbackTest
     * and BookingCrossTenantSessionFallbackTest — binds a fake ResolveTenant
     * so the `tenant` request attribute is deterministic without a real
     * subdomain request.
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

    /**
     * Every class the login/register auth-card chain used to reference
     * that Tailwind v4 cannot resolve (no `primary` scale is registered
     * in design-tokens.css — only `brand`), matched with word boundaries
     * so it can't false-positive on the unrelated, legitimate
     * `text-text-primary` semantic token.
     */
    private function assertNoDeadPrimaryScaleClasses(string $html, string $page): void
    {
        $pattern = '/(?<![\w-])(bg|text|border|from|to|via|ring)-primary(-[0-9]+)?(\/[0-9]+)?\b/';

        $this->assertDoesNotMatchRegularExpression(
            $pattern,
            $html,
            "{$page} still renders a dead `primary` Tailwind class (no such color scale is registered in design-tokens.css)."
        );
    }

    public function test_guest_sees_registration_link_on_login_page_by_default(): void
    {
        $response = $this->get('http://registro.local/login');

        $response->assertOk();
        $response->assertViewIs('auth.login');
        $response->assertSee(route('customer.register'), false);
    }

    public function test_registration_link_hidden_when_disabled_for_resolved_tenant(): void
    {
        $org = Organization::factory()->create();
        $this->setRegistrationEnabled($org, false);

        $response = $this->actingAsTenant($org)->get('http://registro.local/login');

        $response->assertOk();
        $response->assertDontSee(route('customer.register'), false);
    }

    public function test_login_page_uses_resolvable_brand_classes_not_dead_primary_scale(): void
    {
        $response = $this->get('http://registro.local/login');

        $response->assertOk();

        $html = $response->getContent();
        $this->assertNoDeadPrimaryScaleClasses($html, 'login page');

        // Positive assertion: the card's page background actually resolves
        // to a real registered token, not just "no dead class present".
        $this->assertStringContainsString('bg-brand', $html);
    }

    public function test_register_page_uses_resolvable_brand_classes_not_dead_primary_scale(): void
    {
        // Root domain has no public self-serve registration (routes/web.php)
        // and redirects to /login — a tenant must be resolved to reach the
        // register view at all.
        $org = Organization::factory()->create();
        $this->setRegistrationEnabled($org, true);

        $response = $this->actingAsTenant($org)->get('http://registro.local/customer/register');

        $response->assertOk();
        $response->assertViewIs('auth.register');

        $html = $response->getContent();
        $this->assertNoDeadPrimaryScaleClasses($html, 'register page');
        $this->assertStringContainsString('bg-brand', $html);
    }

    /**
     * Guards against translucent white text on the brand-colored auth-card
     * background regressing back in — WCAG AA needs >=4.5:1 for this text
     * size, and text-white/90 or text-white/70 both fail it against the
     * default brand color (see class docblock).
     */
    public function test_login_page_footer_text_is_not_translucent_white(): void
    {
        $response = $this->get('http://registro.local/login');
        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringNotContainsString('text-white/90', $html);
        $this->assertStringNotContainsString('text-white/70', $html);
    }
}
