<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The TTL itself lived only as `now()->addMinutes(30)`, hardcoded twice, with
 * nothing asserting it -- so a change to `User::PASSWORD_SETUP_TTL_HOURS`
 * (or a regression back to a hardcoded number) would pass every existing
 * test. These pin the constant's effect end-to-end, at both the model and
 * the two HTTP entry points that read `password_setup_expires_at`.
 */
class SetPasswordControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_initiate_password_setup_sets_the_configured_ttl(): void
    {
        $user = User::factory()->create();

        $user->initiatePasswordSetup();
        $user->refresh();

        $this->assertNotNull($user->password_setup_expires_at);
        $this->assertEqualsWithDelta(
            now()->addHours(User::PASSWORD_SETUP_TTL_HOURS)->timestamp,
            $user->password_setup_expires_at->timestamp,
            5,
            'password_setup_expires_at must be PASSWORD_SETUP_TTL_HOURS from now, not a hardcoded value'
        );
    }

    public function test_the_setup_ttl_is_independent_from_the_password_reset_ttl(): void
    {
        // config/auth.php's passwords.users.expire is a DIFFERENT flow (Laravel's
        // built-in reset-token table) and must not move when this constant does.
        $this->assertSame(60, config('auth.passwords.users.expire'));
        $this->assertNotEquals(config('auth.passwords.users.expire'), User::PASSWORD_SETUP_TTL_HOURS * 60);
    }

    public function test_show_renders_the_setup_form_for_a_valid_token(): void
    {
        $user = User::factory()->create();
        $token = $user->initiatePasswordSetup();

        $response = $this->get(route('password.setup', ['token' => $token]));

        $response->assertOk();
        $response->assertViewIs('auth.passwords.setup');
    }

    public function test_show_rejects_an_expired_token(): void
    {
        $user = User::factory()->create();
        $token = $user->initiatePasswordSetup();

        $user->forceFill(['password_setup_expires_at' => now()->subMinute()])->save();

        $response = $this->get(route('password.setup', ['token' => $token]));

        $response->assertOk();
        $response->assertViewIs('auth.passwords.token-expired');
    }

    public function test_show_rejects_an_unknown_token(): void
    {
        $response = $this->get(route('password.setup', ['token' => 'does-not-exist']));

        $response->assertOk();
        $response->assertViewIs('auth.passwords.token-expired');
    }

    public function test_store_rejects_an_expired_token(): void
    {
        $user = User::factory()->create();
        $token = $user->initiatePasswordSetup();

        $user->forceFill(['password_setup_expires_at' => now()->subMinute()])->save();

        $response = $this->post(route('password.setup.store'), [
            'token' => $token,
            'password' => 'BrandNewPassword123',
            'password_confirmation' => 'BrandNewPassword123',
        ]);

        $response->assertSessionHasErrors('token');
        $this->assertFalse(auth()->check());
    }

    public function test_store_sets_the_password_for_a_valid_token(): void
    {
        $user = User::factory()->create(['password' => null]);
        $token = $user->initiatePasswordSetup();

        $response = $this->post(route('password.setup.store'), [
            'token' => $token,
            'password' => 'BrandNewPassword123',
            'password_confirmation' => 'BrandNewPassword123',
        ]);

        $response->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('BrandNewPassword123', $user->password));
        $this->assertNull($user->password_setup_token);
        $this->assertNull($user->password_setup_expires_at);
    }
}
