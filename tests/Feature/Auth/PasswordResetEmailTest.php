<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\EmailSend;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Auth\Notifications\ResetPassword as LaravelResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pins that the password-reset e-mail goes through THIS application's pipeline
 * and renders completely.
 *
 * Before this: App\Notifications\PasswordResetNotification existed, used
 * EmailService and the `password-reset` template — and was never sent. User did
 * not override sendPasswordResetNotification(), nothing dispatched
 * PasswordResetRequested, so Laravel's stock English notification went out over
 * the `mail` channel instead, bypassing EmailService (no email_sends row, no
 * suppression check, no retry) and EmailTemplate (no tenant branding). A tenant
 * could edit a "Reset hasła" template in their panel that was never once used.
 *
 * Asserts on the RENDERED e-mail stored in email_sends, not just on "a
 * notification was dispatched" — the payload the dead code carried was missing
 * two of the template's four variables, so merely wiring it up would have mailed
 * a literal "{{app_name}}" to customers.
 *
 * Uses the REAL ResolveTenant. The middleware double the other auth tests use
 * never calls URL::forceRootUrl(), which is what decides the host in the link.
 */
class PasswordResetEmailTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_HOST = 'http://demo.registro.local';

    private const TENANT_NAME = 'Wypozyczalnia Testowa';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local', 'app.name' => 'Registro']);
        $this->withoutMiddleware([ThrottleRequests::class]);

        // SettingsManager caches per tenant+group+key and the array cache driver
        // outlives a single test in one PHPUnit process.
        Cache::flush();
    }

    private function createTenantWithOwnName(): Organization
    {
        $owner = User::factory()->create();

        $org = Organization::create([
            'name' => 'Demo Rental',
            'slug' => 'demo',
            'booking_type' => 'item_rental',
            'owner_id' => $owner->id,
        ]);

        // Wrapped in an array: Setting casts `value` to array and
        // SettingsManager::unwrapValue() unwraps the single-element form.
        Setting::create([
            'organization_id' => $org->id,
            'group' => 'general',
            'key' => 'app_name',
            'value' => [self::TENANT_NAME],
        ]);

        return $org;
    }

    private function requestReset(User $user): void
    {
        $this->post(self::TENANT_HOST.'/password/email', ['email' => $user->email])
            ->assertStatus(302);
    }

    public function test_the_reset_email_goes_through_this_applications_pipeline(): void
    {
        $this->createTenantWithOwnName();
        $user = User::factory()->create();

        $this->requestReset($user);

        $send = EmailSend::withoutGlobalScope('organization')
            ->where('recipient_email', $user->email)
            ->where('template_key', 'password-reset')
            ->first();

        $this->assertNotNull($send,
            'no email_sends row — the reset mail did not go through EmailService');
    }

    public function test_laravels_stock_notification_is_no_longer_what_gets_sent(): void
    {
        $this->createTenantWithOwnName();
        $user = User::factory()->create();

        Notification::fake();
        $this->requestReset($user);

        Notification::assertSentTo($user, PasswordResetNotification::class);
        Notification::assertNotSentTo($user, LaravelResetPassword::class);
    }

    /**
     * The one that catches an incomplete payload. EmailTemplate leaves unknown
     * `{{tokens}}` verbatim (substitutePlaceholders), so a missing key is not an
     * error — it is a customer receiving "{{app_name}}" in their inbox. The dead
     * code passed user_name/reset_url/token while the template declares
     * user_name/app_name/reset_url/expires_in.
     */
    public function test_no_placeholder_survives_rendering(): void
    {
        $this->createTenantWithOwnName();
        $user = User::factory()->create();

        $this->requestReset($user);

        $send = EmailSend::withoutGlobalScope('organization')
            ->where('recipient_email', $user->email)->firstOrFail();

        foreach (['subject' => $send->subject, 'body_html' => $send->body_html, 'body_text' => $send->body_text] as $field => $value) {
            $this->assertDoesNotMatchRegularExpression('/\{\{\w+\}\}/', (string) $value,
                "an unsubstituted placeholder reached the customer in {$field}: ".$value);
        }
    }

    /**
     * The whitelabel promise: the rental company's customer must not be e-mailed
     * in the platform's name. appName() resolves through the tenant, which only
     * works because the listener runs inside the request — on a queue worker
     * TenantFeature::currentTenant() is null and this would say "Registro".
     */
    public function test_the_email_carries_the_tenants_name_not_the_platforms(): void
    {
        $this->createTenantWithOwnName();
        $user = User::factory()->create();

        $this->requestReset($user);

        $send = EmailSend::withoutGlobalScope('organization')
            ->where('recipient_email', $user->email)->firstOrFail();

        $this->assertStringContainsString(self::TENANT_NAME, (string) $send->subject);
        $this->assertStringNotContainsString('Registro', (string) $send->subject);
    }

    /**
     * Same trap as the stock notification had: the URL must be built in the
     * request, where ResolveTenant has already called URL::forceRootUrl(). Built
     * on a worker it falls back to APP_URL — the root domain on today's shared
     * stack, where /admin/login is a 404.
     */
    public function test_the_link_points_at_the_tenant_subdomain(): void
    {
        $this->createTenantWithOwnName();
        $user = User::factory()->create();

        $this->requestReset($user);

        $send = EmailSend::withoutGlobalScope('organization')
            ->where('recipient_email', $user->email)->firstOrFail();

        $this->assertStringContainsString(self::TENANT_HOST.'/password/reset/', (string) $send->body_html);
    }

    /**
     * The mail is a means, not the end: the token it carries must still work.
     */
    public function test_the_emailed_token_actually_resets_the_password(): void
    {
        $this->createTenantWithOwnName();
        $user = User::factory()->create();
        $original = $user->password;

        $token = null;
        Notification::fake();
        $this->requestReset($user);
        Notification::assertSentTo($user, PasswordResetNotification::class,
            function (PasswordResetNotification $notification) use (&$token) {
                $token = $notification->token;

                return true;
            });

        $this->post(self::TENANT_HOST.'/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NoweHaslo123!',
            'password_confirmation' => 'NoweHaslo123!',
        ])->assertStatus(302);

        $this->assertNotSame($original, $user->fresh()->password);
    }
}
