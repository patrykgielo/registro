<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Drives the REAL Filament login page through Livewire, for both panels.
 *
 * AdminPanelLoginResponseTest calls `(new LoginResponse)->toResponse(request())` directly, outside
 * Livewire, where `redirect()` returns a plain RedirectResponse. Inside a Livewire request Livewire
 * rebinds `redirect`, and `redirect($url)` returns `Livewire\Features\SupportRedirects\Redirector`
 * (its `to()` returns `$this`). A `: RedirectResponse` return type therefore passes every direct test
 * and still answers HTTP 500 on `POST /livewire/update` for every real login, on both panels.
 */
class PanelLoginLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // Livewire::test() sends its update through the kernel with middleware disabled, so the
        // request it builds has no session store — and the app-level auth Login listener calls
        // request()->session()->regenerate(). Hand every request the test app's session store.
        $this->app->rebinding('request', function ($app, $request): void {
            $request->setLaravelSession($app['session.store']);
        });
    }

    private function tenantAdmin(): User
    {
        $org = Organization::factory()->create();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($org->id, ['role' => 'owner']);

        return $admin;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user;
    }

    private function loginTo(string $panelId, User $user)
    {
        Filament::setCurrentPanel(Filament::getPanel($panelId));

        return Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'password')
            ->call('authenticate');
    }

    public function test_admin_panel_login_redirects_to_the_panel_home(): void
    {
        $admin = $this->tenantAdmin();

        $component = $this->loginTo('admin', $admin);

        $component->assertHasNoErrors()->assertRedirect(Filament::getUrl());
        $this->assertAuthenticatedAs($admin);
    }

    public function test_platform_panel_login_redirects_to_the_panel_home(): void
    {
        $super = $this->superAdmin();

        $component = $this->loginTo('platform', $super);

        $component->assertHasNoErrors()->assertRedirect(Filament::getUrl());
        $this->assertAuthenticatedAs($super);
    }

    public function test_admin_panel_login_honors_an_intended_url_inside_the_panel(): void
    {
        $admin = $this->tenantAdmin();
        $intended = request()->getSchemeAndHttpHost().'/admin/orders/123';
        session()->put('url.intended', $intended);

        $component = $this->loginTo('admin', $admin);

        $component->assertHasNoErrors()->assertRedirect($intended);
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('url.intended'));
    }

    public function test_admin_panel_login_ignores_an_intended_url_outside_the_panel(): void
    {
        $admin = $this->tenantAdmin();
        session()->put('url.intended', request()->getSchemeAndHttpHost().'/uslugi/some-service');

        $component = $this->loginTo('admin', $admin);

        $component->assertHasNoErrors()->assertRedirect(Filament::getUrl());
        $this->assertNull(session('url.intended'));
    }

    public function test_platform_panel_login_honors_an_intended_url_inside_the_panel(): void
    {
        $super = $this->superAdmin();
        $intended = request()->getSchemeAndHttpHost().'/platform/organizations';
        session()->put('url.intended', $intended);

        $component = $this->loginTo('platform', $super);

        $component->assertHasNoErrors()->assertRedirect($intended);
        $this->assertAuthenticatedAs($super);
    }
}
