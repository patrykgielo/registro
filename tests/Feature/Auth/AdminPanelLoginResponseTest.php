<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Middleware\ResolveTenant;
use App\Http\Responses\LoginResponse;
use App\Models\Organization;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * App\Http\Responses\LoginResponse — the admin panel's override of Filament's
 * default post-login redirect.
 *
 * Until 2026-08-17, AdminPanelProvider bound this class against the WRONG
 * (Filament v3) contract namespace — `::class` on a non-existent
 * interface still compiles, so the bind silently registered under a
 * container key Filament's Login page never actually asks for. The vendor
 * default ran instead (`redirect()->intended(Filament::getUrl())` —
 * unconditional), and this class itself would have fataled with "Interface
 * ... not found" had anything ever tried to load it (it also implemented the
 * wrong namespace). See app/docs/features/post-login-return.md.
 *
 * test_admin_panel_login_response_contract_resolves_to_the_custom_class is
 * the regression test for the bind itself — it must fail if the v3
 * namespace is ever reintroduced in either file.
 */
class AdminPanelLoginResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    /**
     * Same test-double pattern as PostLoginRedirectTest/LoginPageTest — the
     * admin panel's own middleware list references the SAME ResolveTenant
     * class (AdminPanelProvider::panel()), so this container bind applies to
     * it too.
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

    public function test_admin_panel_login_response_contract_resolves_to_the_custom_class(): void
    {
        $this->assertInstanceOf(
            LoginResponse::class,
            app(LoginResponseContract::class)
        );
    }

    public function test_intended_pointing_into_the_admin_panel_is_honored(): void
    {
        $org = Organization::factory()->create();

        $this->actingAsTenant($org)
            ->withSession(['url.intended' => 'http://registro.local/admin/orders/123'])
            ->get('http://registro.local/admin/login')
            ->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $response = (new LoginResponse)->toResponse(request());

        $this->assertSame('http://registro.local/admin/orders/123', $response->getTargetUrl());
    }

    /**
     * The leak this class exists to close: a browser session that captured a
     * PUBLIC page via App\Support\Auth\IntendedDestination (customer-facing
     * /login flow) must never be followed into the admin panel.
     */
    public function test_intended_pointing_outside_the_admin_panel_falls_back_to_panel_home(): void
    {
        $org = Organization::factory()->create();

        $this->actingAsTenant($org)
            ->withSession(['url.intended' => 'http://registro.local/uslugi/some-service'])
            ->get('http://registro.local/admin/login')
            ->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $panelHome = Filament::getUrl();

        $response = (new LoginResponse)->toResponse(request());

        $this->assertSame($panelHome, $response->getTargetUrl());
        $this->assertNotSame('http://registro.local/uslugi/some-service', $response->getTargetUrl());
    }

    /**
     * The path-prefix check alone is NOT sufficient — a value can look
     * exactly like a legitimate panel deep link (`/admin/steal`) while
     * pointing at a completely different host. This is the case the path
     * check by itself would miss; it only passes here because
     * belongsToCurrentPanel() checks origin FIRST, via the same
     * IntendedDestination::isSameOrigin() used by consume().
     */
    public function test_intended_with_a_panel_shaped_path_on_a_foreign_host_falls_back_to_panel_home(): void
    {
        $org = Organization::factory()->create();

        $this->actingAsTenant($org)
            ->withSession(['url.intended' => 'https://evil.example/admin/steal'])
            ->get('http://registro.local/admin/login')
            ->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $panelHome = Filament::getUrl();

        $response = (new LoginResponse)->toResponse(request());

        $this->assertSame($panelHome, $response->getTargetUrl());
        $this->assertNotSame('https://evil.example/admin/steal', $response->getTargetUrl());
    }

    /**
     * PHP's parse_url() and the WHATWG URL Standard (browsers) disagree on
     * "\" in an authority — see IntendedDestination::isSameOrigin()'s
     * docblock. Doubles the blast radius this addendum closes: before this
     * class honored `url.intended` at all, the divergence only reached the
     * customer flow's own consume(); once it does, the SAME divergence
     * reaches admin login.
     */
    public function test_backslash_authority_trick_falls_back_to_panel_home(): void
    {
        $org = Organization::factory()->create();

        $this->actingAsTenant($org)
            ->withSession(['url.intended' => 'http://evil.example\@registro.local/admin/x'])
            ->get('http://registro.local/admin/login')
            ->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $panelHome = Filament::getUrl();

        $response = (new LoginResponse)->toResponse(request());

        $this->assertSame($panelHome, $response->getTargetUrl());
    }

    /**
     * A literal ".." segment is rejected outright (fail-closed), even though
     * — unlike the customer-flow test for the same thing — a browser
     * normalizing this exact string would actually land back inside the
     * panel ("/uslugi/../admin/x" → "/admin/x"). Consistency with the
     * shared, single check in IntendedDestination matters more than
     * recovering this one benign case.
     */
    public function test_dot_segment_path_falls_back_to_panel_home(): void
    {
        $org = Organization::factory()->create();

        $this->actingAsTenant($org)
            ->withSession(['url.intended' => 'http://registro.local/uslugi/../admin/x'])
            ->get('http://registro.local/admin/login')
            ->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $panelHome = Filament::getUrl();

        $response = (new LoginResponse)->toResponse(request());

        $this->assertSame($panelHome, $response->getTargetUrl());
    }

    /**
     * A candidate equal to the bare origin (no path) passes isSameOrigin()
     * (see that method's docblock — explicit design decision) but still
     * falls back here: pathWithinOrigin() resolves it to "/", which does not
     * match the panel's own "/admin" prefix. Not a security boundary, just
     * confirms the bare-origin case is handled deliberately, not by accident
     * (e.g. panelPath check throwing or matching everything).
     */
    public function test_bare_origin_intended_falls_back_to_panel_home(): void
    {
        $org = Organization::factory()->create();

        $this->actingAsTenant($org)
            ->withSession(['url.intended' => 'http://registro.local'])
            ->get('http://registro.local/admin/login')
            ->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $panelHome = Filament::getUrl();

        $response = (new LoginResponse)->toResponse(request());

        $this->assertSame($panelHome, $response->getTargetUrl());
    }

    public function test_no_intended_value_falls_back_to_panel_home(): void
    {
        $org = Organization::factory()->create();

        $this->actingAsTenant($org)
            ->get('http://registro.local/admin/login')
            ->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $panelHome = Filament::getUrl();

        $response = (new LoginResponse)->toResponse(request());

        $this->assertSame($panelHome, $response->getTargetUrl());
    }

    /**
     * Both session keys must be cleared regardless of outcome — a bare
     * session()->pull('url.intended') alone would leave url.intended_at
     * orphaned, letting a LATER, unrelated capture pair a fresh URL with a
     * STALE timestamp (the TTL would then measure from the wrong moment).
     * Covers both branches: target belongs to the panel, and target doesn't.
     */
    public function test_both_session_keys_are_cleared_when_intended_belongs_to_the_panel(): void
    {
        $org = Organization::factory()->create();

        $this->actingAsTenant($org)
            ->withSession([
                'url.intended' => 'http://registro.local/admin/orders/123',
                'url.intended_at' => now()->timestamp,
            ])
            ->get('http://registro.local/admin/login')
            ->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        (new LoginResponse)->toResponse(request());

        $this->assertNull(session('url.intended'));
        $this->assertNull(session('url.intended_at'));
    }

    public function test_both_session_keys_are_cleared_when_intended_does_not_belong_to_the_panel(): void
    {
        $org = Organization::factory()->create();

        $this->actingAsTenant($org)
            ->withSession([
                'url.intended' => 'http://registro.local/uslugi/some-service',
                'url.intended_at' => now()->timestamp,
            ])
            ->get('http://registro.local/admin/login')
            ->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        (new LoginResponse)->toResponse(request());

        $this->assertNull(session('url.intended'));
        $this->assertNull(session('url.intended_at'));
    }
}
