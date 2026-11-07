<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A password reset must not be a way into the platform.
 *
 * Laravel's ResetsPasswords::resetPassword() ends in guard()->login(), and the
 * reset link goes to whoever controls the mailbox. For a super-admin that meant
 * "owns the mailbox" == "owns every tenant": the token alone opened /platform,
 * with no knowledge of the old password. A reset proves control of the inbox, not
 * of the account, so the super-admin is never signed in by it — they sign in with
 * the password they just chose, through the normal login (throttled, audited).
 *
 * Customers and tenant admins keep the old behaviour (PasswordResetRedirectTest
 * pins where they land); this file pins the split.
 */
class SuperAdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = 'http://registro.local';

    private const TENANT = 'http://demo.registro.local';

    private const NEW_PASSWORD = 'NoweHaslo123!';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function tenant(): Organization
    {
        return Organization::create([
            'name' => 'Demo Rental',
            'slug' => 'demo',
            'booking_type' => 'item_rental',
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** Token from the notification Laravel really sends, not one minted by the test. */
    private function requestResetToken(User $user, string $host): string
    {
        Notification::fake();

        $this->post($host.'/password/email', ['email' => $user->email])->assertStatus(302);

        $token = null;
        Notification::assertSentTo($user, PasswordResetNotification::class,
            function (PasswordResetNotification $notification) use (&$token) {
                $token = $notification->token;

                return true;
            });

        $this->assertIsString($token, 'no reset token reached the notification');

        return $token;
    }

    private function completeReset(User $user, string $token, string $host): \Illuminate\Testing\TestResponse
    {
        return $this->post($host.'/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, AuditLog> */
    private function auditRows(User $user, string $event)
    {
        return AuditLog::withoutGlobalScope('organization')
            ->where('user_id', $user->id)
            ->where('event', $event)
            ->get();
    }

    public function test_a_super_admin_is_not_signed_in_by_a_password_reset(): void
    {
        $super = $this->userWithRole('super-admin');
        $token = $this->requestResetToken($super, self::ROOT);

        $response = $this->completeReset($super, $token, self::ROOT);

        // the reset itself worked...
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $super->fresh()->password));

        // ...but it did not authenticate anyone, and it did not do so and then undo it
        $this->assertGuest();
        $response->assertRedirect(self::ROOT.'/platform/login');
        $this->assertCount(0, $this->auditRows($super, AuditLog::EVENT_LOGIN),
            'a login event was recorded: login() ran for a super-admin');
        $this->assertCount(0, $this->auditRows($super, AuditLog::EVENT_LOGOUT),
            'a logout event was recorded: the user was signed in and then signed out');
    }

    public function test_the_session_does_not_open_the_platform_after_a_super_admin_reset(): void
    {
        $super = $this->userWithRole('super-admin');
        $token = $this->requestResetToken($super, self::ROOT);
        $this->completeReset($super, $token, self::ROOT);

        $this->get(self::ROOT.'/platform')->assertRedirect(self::ROOT.'/platform/login');
    }

    public function test_the_super_admin_lands_on_the_platform_login_with_a_sign_in_message(): void
    {
        $super = $this->userWithRole('super-admin');
        $token = $this->requestResetToken($super, self::ROOT);

        $this->completeReset($super, $token, self::ROOT)
            ->assertSessionHas('status', __('passwords.reset_sign_in'));

        // The Filament login page has no slot for a plain flash `status`; the message has to
        // reach the user as a Filament notification or it is set and never seen.
        $this->get(self::ROOT.'/platform/login')
            ->assertOk()
            ->assertSee(__('passwords.reset_sign_in'));
    }

    public function test_the_new_password_then_signs_the_super_admin_in_normally(): void
    {
        $super = $this->userWithRole('super-admin');
        $token = $this->requestResetToken($super, self::ROOT);
        $this->completeReset($super, $token, self::ROOT);

        // a real credential check against the stored hash, not actingAs()
        $this->assertFalse(Auth::attempt(['email' => $super->email, 'password' => 'password']),
            'the old password still works after the reset');
        $this->assertTrue(Auth::attempt(['email' => $super->email, 'password' => self::NEW_PASSWORD]));
        $this->assertAuthenticatedAs($super);
        $this->get(self::ROOT.'/platform')->assertOk();
    }

    public function test_the_password_reset_event_is_still_dispatched_for_a_super_admin(): void
    {
        $super = $this->userWithRole('super-admin');
        $token = $this->requestResetToken($super, self::ROOT);

        // Only this event is faked: the audit listener and everything else stay real.
        Event::fake([PasswordReset::class]);
        $this->completeReset($super, $token, self::ROOT);

        Event::assertDispatched(PasswordReset::class, fn (PasswordReset $e) => $e->user->is($super));
    }

    public function test_the_remember_token_is_rotated_so_old_remember_me_cookies_die(): void
    {
        $super = $this->userWithRole('super-admin');
        $super->forceFill(['remember_token' => 'old-remember-token'])->save();
        $token = $this->requestResetToken($super, self::ROOT);

        $this->completeReset($super, $token, self::ROOT);

        $rotated = $super->fresh()->remember_token;
        $this->assertNotSame('old-remember-token', $rotated);
        $this->assertSame(60, strlen((string) $rotated));
    }

    public function test_a_reset_token_cannot_be_replayed(): void
    {
        $super = $this->userWithRole('super-admin');
        $token = $this->requestResetToken($super, self::ROOT);
        $this->completeReset($super, $token, self::ROOT);
        $hashAfterFirst = $super->fresh()->password;

        $second = $this->post(self::ROOT.'/password/reset', [
            'token' => $token,
            'email' => $super->email,
            'password' => 'AnotherHaslo456!',
            'password_confirmation' => 'AnotherHaslo456!',
        ]);

        $second->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame($hashAfterFirst, $super->fresh()->password, 'a spent token changed the password');
        $this->assertCount(1, $this->auditRows($super, AuditLog::EVENT_PASSWORD_RESET));
    }

    public function test_a_super_admin_reset_does_not_leak_into_the_next_reset_in_the_same_app(): void
    {
        // Laravel caches the controller instance on the Route, so any per-instance flag set by
        // the first reset is still there for the second. Reviewer's probe: the customer ended up
        // signed in but redirected to /platform/login.
        $this->tenant();
        $super = $this->userWithRole('super-admin');
        $customer = $this->userWithRole('customer');

        $this->completeReset($super, $this->requestResetToken($super, self::TENANT), self::TENANT);
        $this->assertGuest();

        $response = $this->completeReset($customer, $this->requestResetToken($customer, self::TENANT), self::TENANT);

        $this->assertAuthenticatedAs($customer);
        $this->assertStringNotContainsString('/platform', (string) $response->headers->get('Location'));
        $this->assertCount(0, $this->auditRows($customer, AuditLog::EVENT_PASSWORD_RESET));
    }

    public function test_a_super_admin_reset_is_written_to_the_audit_log(): void
    {
        $super = $this->userWithRole('super-admin');
        $token = $this->requestResetToken($super, self::ROOT);

        $this->completeReset($super, $token, self::ROOT);

        $rows = $this->auditRows($super, AuditLog::EVENT_PASSWORD_RESET);
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('authentication', $row->auditable_type);
        $this->assertSame($super->id, (int) $row->auditable_id);
        $this->assertNotEmpty($row->ip_address);
        $this->assertFalse($row->new_values['signed_in']);
        $this->assertSame('super-admin', $row->new_values['role']);
    }

    public function test_the_audit_row_never_carries_the_password_or_the_token(): void
    {
        $super = $this->userWithRole('super-admin');
        $token = $this->requestResetToken($super, self::ROOT);
        $this->completeReset($super, $token, self::ROOT);

        $json = json_encode($this->auditRows($super, AuditLog::EVENT_PASSWORD_RESET)->first()->new_values);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $json);
        $this->assertStringNotContainsString($token, $json);
    }

    public function test_a_failed_reset_leaves_no_audit_row_and_no_session(): void
    {
        $super = $this->userWithRole('super-admin');
        $this->requestResetToken($super, self::ROOT);

        $this->completeReset($super, 'not-the-token', self::ROOT);

        $this->assertGuest();
        $this->assertCount(0, $this->auditRows($super, AuditLog::EVENT_PASSWORD_RESET));
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $super->fresh()->password));
    }

    public function test_a_super_admin_resetting_from_a_tenant_host_is_not_signed_in_either(): void
    {
        $this->tenant();
        $super = $this->userWithRole('super-admin');
        $token = $this->requestResetToken($super, self::TENANT);

        $response = $this->completeReset($super, $token, self::TENANT);

        $this->assertGuest();
        $this->assertStringEndsWith('/platform/login', (string) $response->headers->get('Location'));
        $this->assertCount(1, $this->auditRows($super, AuditLog::EVENT_PASSWORD_RESET));
    }

    public function test_a_super_admin_who_is_also_a_tenant_admin_is_not_signed_in(): void
    {
        // The role check must not be defeated by holding a second, weaker role.
        $org = $this->tenant();
        $super = $this->userWithRole('super-admin');
        $super->assignRole('admin');
        $super->organizations()->attach($org->id, ['role' => 'owner']);
        $token = $this->requestResetToken($super, self::TENANT);

        $this->completeReset($super, $token, self::TENANT);

        $this->assertGuest();
    }

    public function test_a_customer_is_still_signed_in_immediately(): void
    {
        $this->tenant();
        $customer = $this->userWithRole('customer');
        $token = $this->requestResetToken($customer, self::TENANT);

        $response = $this->completeReset($customer, $token, self::TENANT);

        $this->assertAuthenticatedAs($customer);
        $response->assertStatus(302);
        $this->assertStringNotContainsString('/platform', (string) $response->headers->get('Location'));
        // assertNotEmpty, not a count: LogAuthenticationEvents is both subscribed explicitly and
        // auto-discovered (handle* methods), so every login is audited twice today — pre-existing.
        $this->assertNotEmpty($this->auditRows($customer, AuditLog::EVENT_LOGIN));
        $this->assertCount(0, $this->auditRows($customer, AuditLog::EVENT_PASSWORD_RESET),
            'the sign-in guard is a super-admin rule; customers get no extra audit row');
    }

    public function test_a_tenant_admin_is_still_signed_in_and_lands_in_their_panel(): void
    {
        $org = $this->tenant();
        $admin = $this->userWithRole('admin');
        $admin->organizations()->attach($org->id, ['role' => 'owner']);
        $token = $this->requestResetToken($admin, self::TENANT);

        $this->completeReset($admin, $token, self::TENANT)->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin);
        $this->get(self::TENANT.'/admin')->assertOk();
    }
}
