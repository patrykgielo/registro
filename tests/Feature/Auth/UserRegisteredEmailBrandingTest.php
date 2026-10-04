<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\EmailSend;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Code review (feature/maile-wlasciciel-i-logo, 2026-09-20): proves the
 * EVENT-carried organization family (UserRegistered → UserRegisteredNotification)
 * end-to-end through the real registration route, for the normal path (GET the
 * form on a tenant subdomain, then POST) — RegisterController::registered()
 * resolves the tenant from the request (`$request->attributes->get('tenant')`)
 * and passes it through the event into the notification's constructor, which
 * threads it into sendFromTemplate() as `organization:`.
 *
 * This does NOT prove the tenant is always non-null: a direct POST to the root
 * domain skips the GET form's redirect-to-login guard and can currently reach
 * `registered()` with no tenant resolved (separate, already-flagged gap, not
 * fixed here) — `RegisterController` already handles that defensively (passes
 * null), and `UserRegistered`'s own docblock documents it. Not asserted here
 * because it belongs to that separate ticket, not this branding test.
 *
 * Same pipeline-integrity style as PasswordResetEmailTest — real ResolveTenant,
 * asserts on the persisted EmailSend row, not a faked notification.
 */
class UserRegisteredEmailBrandingTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_HOST = 'http://demo.registro.local';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local', 'app.name' => 'Registro']);
        $this->withoutMiddleware([ThrottleRequests::class]);
        Cache::flush();
    }

    private function createTenant(string $name): Organization
    {
        return Organization::create([
            'name' => $name,
            'slug' => 'demo',
            'booking_type' => 'item_rental',
            'owner_id' => \App\Models\User::factory()->create()->id,
        ]);
    }

    public function test_the_welcome_email_carries_the_tenants_name_not_the_platforms(): void
    {
        $org = $this->createTenant('Wypozyczalnia Testowa Sp. z o.o.');

        $this->post(self::TENANT_HOST.'/customer/register', [
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => 'jan.kowalski@example.com',
            'password' => 'HasloTest123!',
            'password_confirmation' => 'HasloTest123!',
        ])->assertStatus(302);

        $send = EmailSend::withoutGlobalScope('organization')
            ->where('recipient_email', 'jan.kowalski@example.com')
            ->where('template_key', 'user-registered')
            ->firstOrFail();

        $this->assertStringContainsString('<title>'.$org->name.'</title>', $send->body_html);
    }
}
