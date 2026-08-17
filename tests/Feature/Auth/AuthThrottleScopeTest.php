<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pins the fix for: every auth-related route (POST /login, POST /logout,
 * and all password reset/confirm routes) used to share ONE 5/min-per-IP
 * throttle bucket. Laravel's default throttle key is domain+IP, not
 * per-route-URI, and `Auth::routes(['login' => false, 'register' => false])`
 * used to register all of them inside a single `throttle:5,1` group.
 *
 * A routine, non-abusive password-recovery flow — view the "forgot password"
 * form, submit it, open the link from the inbox — alone spent 3 of that
 * bucket's 5 slots, on top of anything already spent on login attempts, so a
 * user doing nothing wrong could hit 429 on a plain login/recovery attempt.
 *
 * See routes/web.php for the per-route rationale behind each new bucket.
 */
class AuthThrottleScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
    }

    private function url(string $path): string
    {
        return 'http://registro.local'.$path;
    }

    public function test_routine_password_recovery_flow_does_not_consume_the_login_bucket(): void
    {
        // Exact flow from the bug report: view the "forgot password" form,
        // submit it, open the emailed link.
        $this->get($this->url('/password/reset'))->assertOk();
        $this->post($this->url('/password/email'), ['email' => 'someone@example.com']);
        $this->get($this->url('/password/reset/some-token'))->assertOk();

        // Two mistyped-password login attempts afterwards are still well
        // within POST /login's own 5/min bucket — none of the above touched it.
        for ($i = 0; $i < 2; $i++) {
            $response = $this->post($this->url('/login'), [
                'email' => 'nobody@example.com',
                'password' => 'wrong-password',
            ]);
            $this->assertNotEquals(429, $response->getStatusCode());
        }
    }

    public function test_get_routes_that_only_render_a_page_are_never_throttled(): void
    {
        foreach (range(1, 8) as $_) {
            $this->get($this->url('/password/reset'))->assertOk();
            $this->get($this->url('/password/reset/some-token'))->assertOk();
        }
    }

    public function test_post_login_still_has_its_own_rate_limit(): void
    {
        // 5 distinct emails so Illuminate\Foundation\Auth\ThrottlesLogins
        // (keyed by email+IP, only counts failures) never locks any single
        // one of them — a 429 here can only come from the route-level
        // `throttle:5,1,login` IP bucket, proving it is still enforced (just
        // no longer shared with page-render routes).
        for ($i = 0; $i < 5; $i++) {
            $response = $this->post($this->url('/login'), [
                'email' => "user{$i}@example.com",
                'password' => 'wrong-password',
            ]);
            $this->assertNotEquals(429, $response->getStatusCode());
        }

        $response = $this->post($this->url('/login'), [
            'email' => 'user5@example.com',
            'password' => 'wrong-password',
        ]);
        $response->assertStatus(429);
    }

    public function test_password_email_has_its_own_stricter_bucket_than_login(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $response = $this->post($this->url('/password/email'), ['email' => "user{$i}@example.com"]);
            $this->assertNotEquals(429, $response->getStatusCode());
        }

        $response = $this->post($this->url('/password/email'), ['email' => 'user3@example.com']);
        $response->assertStatus(429);

        // Its own bucket is exhausted, but POST /login is untouched.
        $response = $this->post($this->url('/login'), [
            'email' => 'user4@example.com',
            'password' => 'wrong-password',
        ]);
        $this->assertNotEquals(429, $response->getStatusCode());
    }

    public function test_throttles_logins_brute_force_protection_is_unaffected(): void
    {
        // Disable the route-level IP bucket entirely so a 429 below can only
        // come from Illuminate\Foundation\Auth\ThrottlesLogins itself — the
        // real per-account (email+IP) brute-force defense this change must
        // not touch.
        $this->withoutMiddleware(ThrottleRequests::class);

        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $failedAttemptMessage = null;

        for ($i = 0; $i < 5; $i++) {
            $response = $this->post($this->url('/login'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
            $this->assertNotEquals(429, $response->getStatusCode());
            $response->assertSessionHasErrors(['email']);
            $failedAttemptMessage = session('errors')->get('email')[0];
        }

        // A non-JSON login form redirects (302) even on lockout — Laravel's
        // ValidationException handler ignores the custom 429 status for
        // standard web requests. The observable pin is that the 6th attempt
        // takes a DIFFERENT error branch (ThrottlesLogins::sendLockoutResponse,
        // "auth.throttle") than the first 5 ("auth.failed") — proving the
        // lockout itself fired, without hardcoding either translation's
        // exact wording (no app-level lang/*/auth.php override exists here).
        $response = $this->post($this->url('/login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
        $response->assertSessionHasErrors(['email']);
        $lockoutMessage = session('errors')->get('email')[0];
        $this->assertNotSame($failedAttemptMessage, $lockoutMessage);
    }

    public function test_logout_is_not_rate_limited(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 8) as $_) {
            $response = $this->actingAs($user)->post($this->url('/logout'));
            $this->assertNotEquals(429, $response->getStatusCode());
        }
    }
}
