<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Session-fixation defense-in-depth: authenticating a guest session (via
 * customer registration) must rotate the session ID, matching the behavior
 * already enforced on the login route (AuthenticatesUsers::sendLoginResponse()).
 *
 * This used to also cover the public business-registration wizard
 * (BusinessRegisterController, removed -- see routes/web.php), which had its
 * own manual `Auth::login()` + `session()->regenerate()` call. That subject
 * is gone along with the controller; the pattern it tested lives on here.
 */
class SessionRegenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_customer_registration_regenerates_session_id(): void
    {
        config(['app.domain' => 'registro.local']);

        $owner = User::factory()->create();
        $org = Organization::create([
            'name' => 'Tenant Salon',
            'slug' => 'tenant-salon',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $this->get('http://tenant-salon.registro.local/customer/register');
        $sessionIdBefore = $this->app['session']->getId();

        $response = $this->post('http://tenant-salon.registro.local/customer/register', [
            'first_name' => 'Anna',
            'last_name' => 'Klientka',
            'email' => 'customer-session-regen@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect('/');

        $sessionIdAfter = $this->app['session']->getId();

        $this->assertNotEquals($sessionIdBefore, $sessionIdAfter);

        $user = User::where('email', 'customer-session-regen@example.com')->first();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->organizations()->where('organization_id', $org->id)->exists());
    }
}
