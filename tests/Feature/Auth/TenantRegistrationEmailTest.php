<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Events\TenantRegistered;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\NewTenantRegisteredNotification;
use App\Notifications\TenantWelcomeNotification;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * `TenantRegistered` used to be dispatched by the public self-serve
 * registration wizard (`BusinessRegisterController`, removed -- see
 * routes/web.php); it's now dispatched by `registro:tenant-provision`
 * instead (see TenantProvisionCommandTest for that dispatch coverage). The
 * listener wiring itself -- who gets notified, and the fallback/opt-out
 * rules around the operator address -- did not change, so these tests
 * dispatch the event directly rather than driving it through HTTP, and keep
 * asserting the behavior that survived the removal.
 */
class TenantRegistrationEmailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: User}
     */
    private function dispatchTenantRegistered(): array
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['email' => 'anna@firma.test']);

        TenantRegistered::dispatch($organization, $owner);

        return [$organization, $owner];
    }

    public function test_the_new_owner_receives_a_welcome_email(): void
    {
        Notification::fake();

        [, $owner] = $this->dispatchTenantRegistered();

        Notification::assertSentTo($owner, TenantWelcomeNotification::class);
    }

    public function test_the_operator_is_notified_when_an_address_is_configured(): void
    {
        app(SettingsManager::class)->setGlobal('platform.new_tenant_notification_email', 'operator@registro.test');
        Notification::fake();

        $this->dispatchTenantRegistered();

        Notification::assertSentOnDemand(
            NewTenantRegisteredNotification::class,
            fn ($notification, $channels, $notifiable) => in_array('operator@registro.test', $notifiable->routes, true),
        );
    }

    /**
     * A fresh install should not be silently unmonitored, so the closure-request
     * address -- which PlatformSettings makes mandatory -- is the fallback.
     */
    public function test_it_falls_back_to_the_closure_request_address(): void
    {
        app(SettingsManager::class)->setGlobal('account.closure_request_email', 'zamkniecia@registro.test');
        Notification::fake();

        $this->dispatchTenantRegistered();

        Notification::assertSentOnDemand(
            NewTenantRegisteredNotification::class,
            fn ($notification, $channels, $notifiable) => in_array('zamkniecia@registro.test', $notifiable->routes, true),
        );
    }

    /**
     * An empty address is a deliberate opt-out, not a misconfiguration.
     */
    public function test_no_operator_mail_when_no_address_is_configured(): void
    {
        app(SettingsManager::class)->setGlobal('platform.new_tenant_notification_email', '');
        app(SettingsManager::class)->setGlobal('account.closure_request_email', '');
        Notification::fake();

        $this->dispatchTenantRegistered();

        Notification::assertNothingSentTo(new \Illuminate\Notifications\AnonymousNotifiable);
    }

    /**
     * The owner mail must never depend on the operator address being set.
     */
    public function test_the_owner_is_welcomed_even_with_no_operator_address(): void
    {
        app(SettingsManager::class)->setGlobal('platform.new_tenant_notification_email', '');
        app(SettingsManager::class)->setGlobal('account.closure_request_email', '');
        Notification::fake();

        [, $owner] = $this->dispatchTenantRegistered();

        Notification::assertSentTo($owner, TenantWelcomeNotification::class);
    }

    public function test_both_notifications_are_queued_on_the_emails_queue(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create();

        $this->assertSame('emails', (new TenantWelcomeNotification($org))->queue);
        $this->assertSame('emails', (new NewTenantRegisteredNotification($org, $owner))->queue);
    }

    /**
     * Without these rows EmailService::sendFromTemplate() throws and the queued
     * notification dies in failed_jobs with nothing user-visible.
     */
    public function test_the_email_templates_exist_in_both_languages(): void
    {
        foreach (['tenant-welcome', 'tenant-registered-operator'] as $key) {
            foreach (['pl', 'en'] as $language) {
                $this->assertDatabaseHas('email_templates', [
                    'key' => $key,
                    'language' => $language,
                    'active' => true,
                ]);
            }
        }
    }

    public function test_the_welcome_template_carries_the_panel_address(): void
    {
        $row = DB::table('email_templates')->where('key', 'tenant-welcome')->where('language', 'pl')->first();

        $this->assertNotNull($row);
        $this->assertStringContainsString('{{admin_url}}', $row->html_body);
        $this->assertStringContainsString('{{admin_url}}', $row->text_body);
    }
}
