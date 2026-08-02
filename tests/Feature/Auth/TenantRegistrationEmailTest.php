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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Business registration used to send nothing at all: the welcome machinery
 * existed but was wired only to the end-CUSTOMER flow, so the person who had
 * just created a tenant received no confirmation and no panel address, and the
 * operator was never told a tenant had appeared.
 *
 * These tests assert that mail is actually dispatched, and to whom -- not that
 * the controller returns a redirect.
 */
class TenantRegistrationEmailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function ownerPayload(): array
    {
        return [
            'first_name' => 'Anna',
            'last_name' => 'Kowalska',
            'email' => 'anna@firma.test',
            'password' => 'TajneHaslo12345',
            'password_confirmation' => 'TajneHaslo12345',
            'terms' => '1',
        ];
    }

    private function completeStepOne(): void
    {
        $this->post('/register/step/1', [
            'org_name' => 'Wypozyczalnia Anny',
            'slug' => 'anna-rental',
            'industry' => 'equipment_rental',
        ])->assertRedirect(route('register.step2'));
    }

    public function test_registering_a_business_dispatches_the_tenant_registered_event(): void
    {
        Event::fake([TenantRegistered::class]);

        $this->completeStepOne();
        $this->post('/register/step/2', $this->ownerPayload());

        Event::assertDispatched(TenantRegistered::class, function (TenantRegistered $e) {
            return $e->organization->slug === 'anna-rental'
                && $e->owner->email === 'anna@firma.test';
        });
    }

    public function test_the_new_owner_receives_a_welcome_email(): void
    {
        Notification::fake();

        $this->completeStepOne();
        $this->post('/register/step/2', $this->ownerPayload());

        $owner = User::where('email', 'anna@firma.test')->firstOrFail();

        Notification::assertSentTo($owner, TenantWelcomeNotification::class);
    }

    public function test_the_operator_is_notified_when_an_address_is_configured(): void
    {
        app(SettingsManager::class)->setGlobal('platform.new_tenant_notification_email', 'operator@registro.test');
        Notification::fake();

        $this->completeStepOne();
        $this->post('/register/step/2', $this->ownerPayload());

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

        $this->completeStepOne();
        $this->post('/register/step/2', $this->ownerPayload());

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

        $this->completeStepOne();
        $this->post('/register/step/2', $this->ownerPayload());

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

        $this->completeStepOne();
        $this->post('/register/step/2', $this->ownerPayload());

        Notification::assertSentTo(
            User::where('email', 'anna@firma.test')->firstOrFail(),
            TenantWelcomeNotification::class,
        );
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
