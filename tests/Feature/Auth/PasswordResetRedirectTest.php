<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pins where a password reset LANDS, and which host its link points at.
 *
 * ResetPasswordController carried `$redirectTo = '/home'`. Nothing is routed at
 * that path — the route named `home` is `/`. Measured end-to-end before the fix:
 * the password was set, the user was redirected to `/home` on their own tenant
 * subdomain, and got a 404 with no way back to their panel. Nothing logged a
 * failure; to the user the reset simply looked broken.
 *
 * Deliberately uses the REAL ResolveTenant rather than the middleware double the
 * other auth tests use. The double never calls URL::forceRootUrl(), which is the
 * exact mechanism that decides the host in the e-mailed link — pinning that with
 * the double in place would assert nothing about the thing being pinned.
 */
class PasswordResetRedirectTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_HOST = 'http://demo.registro.local';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function createTenant(): Organization
    {
        $owner = User::factory()->create();

        return Organization::create([
            'name' => 'Demo Rental',
            'slug' => 'demo',
            'booking_type' => 'item_rental',
            'owner_id' => $owner->id,
        ]);
    }

    /**
     * Requests a reset and returns the token from the notification Laravel
     * actually sends — not a token this test minted, so a change of
     * notification class fails here instead of passing on a fabricated one.
     */
    private function requestResetToken(User $user): string
    {
        Notification::fake();

        $this->post(self::TENANT_HOST.'/password/email', ['email' => $user->email])
            ->assertStatus(302);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class,
            function (ResetPassword $notification) use (&$token) {
                $token = $notification->token;

                return true;
            });

        $this->assertIsString($token, 'no reset token reached the notification');

        return $token;
    }

    private function completeReset(User $user, string $token): \Illuminate\Testing\TestResponse
    {
        return $this->post(self::TENANT_HOST.'/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NoweHaslo123!',
            'password_confirmation' => 'NoweHaslo123!',
        ]);
    }

    public function test_a_customer_lands_on_a_real_page_after_resetting_their_password(): void
    {
        $this->createTenant();

        $user = User::factory()->create();
        $user->assignRole('customer');

        $token = $this->requestResetToken($user);
        $response = $this->completeReset($user, $token);

        $response->assertStatus(302);
        $target = $response->headers->get('Location');

        $this->assertStringNotContainsString('/home', (string) $target,
            'the reset still points at the unrouted /home');

        // The whole point: the destination must actually exist.
        $this->get((string) $target)->assertOk();
    }

    public function test_a_tenant_admin_lands_in_their_own_panel_not_on_a_404(): void
    {
        $org = $this->createTenant();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($org->id, ['role' => 'owner']);

        $token = $this->requestResetToken($admin);
        $response = $this->completeReset($admin, $token);

        $response->assertStatus(302);
        $target = (string) $response->headers->get('Location');

        $this->assertStringNotContainsString('/home', $target);
        $this->assertStringContainsString('/admin', $target,
            'a tenant admin belongs in the admin panel after a reset, same as after a login');

        $this->assertNotSame(404, $this->get($target)->status(),
            'the admin was sent somewhere that does not exist');
    }

    public function test_the_password_is_actually_changed(): void
    {
        $this->createTenant();

        $user = User::factory()->create();
        $user->assignRole('customer');
        $original = $user->password;

        $token = $this->requestResetToken($user);
        $this->completeReset($user, $token)->assertStatus(302);

        $this->assertNotSame($original, $user->fresh()->password,
            'the redirect was fixed but the reset itself stopped working');
    }

    /**
     * Pins the host of the e-mailed link, which was wrongly reported as broken
     * before it was measured. It is correct today only because Laravel's
     * ResetPassword notification is NOT queued, so it renders inside the request
     * where ResolveTenant has already called URL::forceRootUrl(). Marking it
     * ShouldQueue — a one-word change that looks like a pure optimisation —
     * moves rendering to a worker with no request context and silently drops the
     * link back to APP_URL, i.e. the root domain, where /admin/login is a 404.
     */
    public function test_the_emailed_link_points_at_the_tenant_subdomain(): void
    {
        $this->createTenant();

        $user = User::factory()->create();
        $user->assignRole('customer');

        Notification::fake();

        $this->post(self::TENANT_HOST.'/password/email', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class,
            function (ResetPassword $notification) use ($user) {
                $url = $notification->toMail($user)->actionUrl;

                $this->assertStringStartsWith(self::TENANT_HOST.'/password/reset/', (string) $url);

                return true;
            });
    }

    /**
     * ConfirmPasswordController carried the same dead `/home`, on a route that
     * IS registered. Its fallback is only consulted when no intended URL exists,
     * which is exactly the case this asserts.
     */
    public function test_password_confirmation_falls_back_to_a_real_page(): void
    {
        $this->createTenant();

        $user = User::factory()->create();
        $user->assignRole('customer');

        $response = $this->actingAs($user)
            ->post(self::TENANT_HOST.'/password/confirm', ['password' => 'password']);

        $response->assertStatus(302);
        $target = (string) $response->headers->get('Location');

        $this->assertStringNotContainsString('/home', $target);
        $this->get($target)->assertOk();
    }
}
