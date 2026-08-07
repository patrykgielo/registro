<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\EmailEvent;
use App\Models\EmailSend;
use App\Models\Organization;
use App\Models\SmsEvent;
use App\Models\SmsSend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * EmailEventResource/SmsEventResource::canViewAny() opened to 'admin' in
 * feature/tenant-admin-access (2026-08-07) — but only after EmailEvent/SmsEvent
 * gained BelongsToOrganization and their creation call sites (EmailService,
 * SmsService, SmsApiWebhookController) started copying organization_id from
 * the owning EmailSend/SmsSend. Before that fix, organization_id existed as a
 * column but was never populated, so opening these resources would either
 * leak every tenant's recipient PII (if unscoped) or silently show nothing
 * (if scoped against an always-null column). This is the "admin tenanta A nie
 * widzi zdarzeń tenanta B" regression guard for that fix.
 */
class EmailSmsEventResourceScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    }

    private function makeEmailSend(Organization $org, string $recipient): EmailSend
    {
        return EmailSend::create([
            'organization_id' => $org->id,
            'template_key' => 'test-template',
            'language' => 'pl',
            'recipient_email' => $recipient,
            'subject' => 'Test',
            'body_html' => '<p>Test</p>',
            'status' => 'sent',
            'message_key' => md5($recipient.$org->id.microtime(true)),
        ]);
    }

    private function makeSmsSend(Organization $org, string $phone): SmsSend
    {
        return SmsSend::create([
            'organization_id' => $org->id,
            'template_key' => 'test-template',
            'language' => 'pl',
            'phone_to' => $phone,
            'message_body' => 'Test',
            'status' => 'sent',
            'message_key' => md5($phone.$org->id.microtime(true)),
        ]);
    }

    public function test_tenant_admin_only_sees_email_events_for_their_own_organization(): void
    {
        $orgA = Organization::factory()->create(['slug' => 'org-a-emailscope']);
        $orgB = Organization::factory()->create(['slug' => 'org-b-emailscope']);

        $adminA = User::factory()->create();
        $adminA->assignRole('admin');
        $adminA->organizations()->attach($orgA->id);

        $sendA = $this->makeEmailSend($orgA, 'visible-a@example.com');
        EmailEvent::create([
            'organization_id' => $orgA->id,
            'email_send_id' => $sendA->id,
            'event_type' => 'sent',
            'occurred_at' => now(),
        ]);

        $sendB = $this->makeEmailSend($orgB, 'secret-b@example.com');
        EmailEvent::create([
            'organization_id' => $orgB->id,
            'email_send_id' => $sendB->id,
            'event_type' => 'sent',
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($adminA)
            ->get("http://{$orgA->slug}.registro.local/admin/email-events");

        $response->assertOk();
        $response->assertSee('visible-a@example.com');
        $response->assertDontSee('secret-b@example.com');
    }

    public function test_tenant_admin_only_sees_sms_events_for_their_own_organization(): void
    {
        $orgA = Organization::factory()->create(['slug' => 'org-a-smsscope']);
        $orgB = Organization::factory()->create(['slug' => 'org-b-smsscope']);

        $adminA = User::factory()->create();
        $adminA->assignRole('admin');
        $adminA->organizations()->attach($orgA->id);

        $sendA = $this->makeSmsSend($orgA, '+48111111111');
        SmsEvent::create([
            'organization_id' => $orgA->id,
            'sms_send_id' => $sendA->id,
            'event_type' => 'sent',
            'occurred_at' => now(),
        ]);

        $sendB = $this->makeSmsSend($orgB, '+48222222222');
        SmsEvent::create([
            'organization_id' => $orgB->id,
            'sms_send_id' => $sendB->id,
            'event_type' => 'sent',
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($adminA)
            ->get("http://{$orgA->slug}.registro.local/admin/sms-events");

        $response->assertOk();
        $response->assertSee('+48111111111');
        $response->assertDontSee('+48222222222');
    }

    public function test_email_event_organization_id_is_copied_from_the_owning_email_send_at_send_time(): void
    {
        $org = Organization::factory()->create(['slug' => 'org-emailevent-populate']);
        $send = $this->makeEmailSend($org, 'populate@example.com');

        $event = EmailEvent::create([
            'organization_id' => $send->organization_id,
            'email_send_id' => $send->id,
            'event_type' => 'sent',
            'occurred_at' => now(),
        ]);

        $this->assertSame($org->id, $event->organization_id);
    }
}
