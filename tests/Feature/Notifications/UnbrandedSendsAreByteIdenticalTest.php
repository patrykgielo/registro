<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\EmailSend;
use App\Models\EmailTemplate;
use App\Models\ServiceAreaWaitlist;
use App\Notifications\ServiceAreaAvailableNotification;
use App\Services\Email\EmailGatewayInterface;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Code review (feature/maile-wlasciciel-i-logo, 2026-09-20), item 3: any caller that sends
 * unwrapped (no `organization` known — ServiceAreaAvailableNotification's waitlist has no
 * organization_id column at all, see its own class docblock) must produce output BYTE
 * IDENTICAL to `EmailTemplate::render()` on its own — not merely "doesn't look wrapped".
 * `EmailService::sendFromTemplate()`'s guard is `$organization !== null ? wrap(...) :
 * $rendered['html']` — the false branch performs literally zero transformation, so this
 * pins that structurally rather than by proxy (checking for absent markers, as the other
 * branding tests do, only proves ABSENCE of wrapping artifacts, not full equality).
 */
class UnbrandedSendsAreByteIdenticalTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_area_available_email_body_is_byte_identical_to_the_raw_template_render(): void
    {
        $gateway = Mockery::mock(EmailGatewayInterface::class);
        $gateway->shouldReceive('send')->andReturnTrue();
        $this->app->instance(EmailGatewayInterface::class, $gateway);
        $this->app->forgetInstance(\App\Services\Email\EmailService::class);

        $waitlist = ServiceAreaWaitlist::create([
            'email' => 'czekam@example.com',
            'name' => 'Testowy Klient',
            'requested_address' => 'ul. Testowa 5, 00-100 Warszawa',
            'requested_latitude' => 52.2297,
            'requested_longitude' => 21.0122,
            'status' => 'contacted',
        ]);

        $waitlist->notify(new ServiceAreaAvailableNotification($waitlist));

        $send = EmailSend::where('recipient_email', 'czekam@example.com')
            ->where('template_key', 'service-area-available')
            ->firstOrFail();

        $expected = EmailTemplate::resolveActive('service-area-available', 'pl')->render([
            'name' => 'Testowy Klient',
            'requested_address' => 'ul. Testowa 5, 00-100 Warszawa',
            'app_name' => app(SettingsManager::class)->appName(),
        ]);

        $this->assertSame($expected, $send->body_html);
    }
}
