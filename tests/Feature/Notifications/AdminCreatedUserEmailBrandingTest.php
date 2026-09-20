<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\EmailSend;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AdminCreatedUserNotification;
use App\Services\Email\EmailGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Code review (feature/maile-wlasciciel-i-logo, 2026-09-20): AdminCreatedUserNotification
 * is dispatched from two DIFFERENT kinds of call sites with different guarantees:
 * - Filament panel (UserResource, CreateUser page) — a resolved panel tenant always
 *   exists, so `organization` is passed and the e-mail is branded for it.
 * - `registro:password-setup-link` (CLI) — the target user can belong to MULTIPLE
 *   organizations, so no single tenant is safely inferable; `organization` stays null
 *   and the e-mail is sent bare, exactly as before EmailBrandedLayout existed.
 *
 * Both are proven directly against the notification (not through the full Filament
 * UI/console command), matching this suite's existing style for notification-level tests.
 */
class AdminCreatedUserEmailBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function serviceWithFakeGateway(): void
    {
        $gateway = Mockery::mock(EmailGatewayInterface::class);
        $gateway->shouldReceive('send')->andReturnTrue();
        $this->app->instance(EmailGatewayInterface::class, $gateway);
        $this->app->forgetInstance(\App\Services\Email\EmailService::class);
    }

    private function userWithSetupToken(): User
    {
        $user = User::factory()->create(['preferred_language' => 'pl']);
        $user->initiatePasswordSetup();

        return $user->fresh();
    }

    public function test_the_setup_email_carries_the_panel_tenants_name_when_organization_is_passed(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->create(['name' => 'Panel Tenant Sp. z o.o.']);
        $user = $this->userWithSetupToken();

        $user->notify(new AdminCreatedUserNotification($user, $org));

        $send = EmailSend::where('recipient_email', $user->email)
            ->where('template_key', 'admin-user-created')->firstOrFail();

        $this->assertStringContainsString('<title>'.$org->name.'</title>', $send->body_html);
    }

    public function test_the_setup_email_is_sent_bare_when_no_organization_is_known(): void
    {
        $this->serviceWithFakeGateway();

        $user = $this->userWithSetupToken();

        $user->notify(new AdminCreatedUserNotification($user));

        $send = EmailSend::where('recipient_email', $user->email)
            ->where('template_key', 'admin-user-created')->firstOrFail();

        $this->assertStringNotContainsString('<!DOCTYPE html>', $send->body_html);
        $this->assertStringNotContainsString('<title>', $send->body_html);
    }
}
