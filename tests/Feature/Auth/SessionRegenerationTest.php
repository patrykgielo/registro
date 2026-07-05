<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Session-fixation defense-in-depth: authenticating a guest session (via any
 * registration flow) must rotate the session ID, matching the behavior already
 * enforced on the login route (AuthenticatesUsers::sendLoginResponse()).
 */
class SessionRegenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_business_registration_step2_regenerates_session_id(): void
    {
        // Establish a guest session first so we have a "before" session ID.
        $this->get(route('register'));
        $sessionIdBefore = $this->app['session']->getId();

        $response = $this->withSession([
            'business_register.step1' => [
                'org_name' => 'Session Test Org',
                'slug' => 'session-test-org',
                'industry' => 'general_services',
            ],
        ])->post(route('register.step2.store'), [
            'first_name' => 'Jan',
            'last_name' => 'Testowy',
            'email' => 'session-regen@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $response->assertRedirect(route('register.step3'));

        $sessionIdAfter = $this->app['session']->getId();

        $this->assertNotEquals($sessionIdBefore, $sessionIdAfter);

        $user = User::where('email', 'session-regen@example.com')->first();
        $this->assertAuthenticatedAs($user);
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
