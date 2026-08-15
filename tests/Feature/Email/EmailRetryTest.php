<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailSend;
use App\Services\Email\EmailGatewayInterface;
use App\Services\Email\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * The idempotency check used to return any existing record regardless of status,
 * so a FAILED send blocked its own retry for ever: the message key is derived
 * from (template, recipient, metadata), which a genuine retry reproduces exactly.
 * A brief SMTP outage lost those e-mails permanently, and the caller got an
 * EmailSend object back -- which reads as success.
 *
 * These tests pin the distinction between outcomes that are final and outcomes
 * that are not.
 */
class EmailRetryTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE = 'user-registered';

    private const RECIPIENT = 'adresat@example.test';

    /**
     * @return array<string, mixed>
     */
    private function metadata(): array
    {
        return ['user_id' => 42, 'notification' => 'Whatever'];
    }

    private function serviceWithGateway(bool $succeeds): EmailService
    {
        $gateway = Mockery::mock(EmailGatewayInterface::class);

        if ($succeeds) {
            $gateway->shouldReceive('send')->andReturnTrue();
        } else {
            $gateway->shouldReceive('send')->andThrow(new \RuntimeException('SMTP down'));
        }

        $this->app->instance(EmailGatewayInterface::class, $gateway);

        // EmailService is a singleton (AppServiceProvider:85), so make() would
        // hand back the instance built with the previous gateway and the
        // swapped mock would never be used.
        $this->app->forgetInstance(EmailService::class);

        return $this->app->make(EmailService::class);
    }

    /**
     * sendFromTemplate() re-throws on transport failure -- deliberately, so a
     * queued notification fails and Laravel retries the job. Which is precisely
     * what made the bug so damaging: the RETRY then short-circuited into the
     * failed record and returned successfully, so the queue's own recovery
     * mechanism silently swallowed the e-mail.
     */
    private function sendExpectingFailure(EmailService $service): EmailSend
    {
        try {
            $this->send($service);
        } catch (\Throwable) {
            // expected
        }

        return EmailSend::where('recipient_email', self::RECIPIENT)->firstOrFail();
    }

    private function send(EmailService $service): EmailSend
    {
        return $service->sendFromTemplate(
            self::TEMPLATE,
            'pl',
            self::RECIPIENT,
            ['user_name' => 'Jan', 'app_name' => 'Registro', 'user_email' => self::RECIPIENT],
            $this->metadata(),
        );
    }

    public function test_a_failed_send_is_retried_and_can_succeed(): void
    {
        $failed = $this->sendExpectingFailure($this->serviceWithGateway(false));
        $this->assertSame('failed', $failed->status);
        $this->assertSame(1, EmailSend::count());

        $retried = $this->send($this->serviceWithGateway(true));

        $this->assertSame('sent', $retried->status);
        $this->assertSame($failed->id, $retried->id, 'message_key is unique, so the retry must reuse the row');
        $this->assertSame(1, EmailSend::count(), 'a retry must not insert a second row');
    }

    public function test_the_stale_error_does_not_survive_a_successful_retry(): void
    {
        $this->sendExpectingFailure($this->serviceWithGateway(false));
        $retried = $this->send($this->serviceWithGateway(true));

        $this->assertNull($retried->fresh()->error_message);
        $this->assertNotNull($retried->fresh()->sent_at);
    }

    public function test_a_sent_email_is_never_sent_twice(): void
    {
        $first = $this->send($this->serviceWithGateway(true));
        $this->assertSame('sent', $first->status);

        // A gateway that would throw if touched proves the second call short-circuits.
        $gateway = Mockery::mock(EmailGatewayInterface::class);
        $gateway->shouldNotReceive('send');
        $this->app->instance(EmailGatewayInterface::class, $gateway);
        $this->app->forgetInstance(EmailService::class);

        $second = $this->send($this->app->make(EmailService::class));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, EmailSend::count());
    }

    /**
     * A bounce is the recipient's verdict, not a transport hiccup. Retrying
     * re-bounces and damages sender reputation.
     */
    public function test_a_bounced_email_is_not_retried(): void
    {
        $send = $this->send($this->serviceWithGateway(true));
        $send->markAsBounced();

        $gateway = Mockery::mock(EmailGatewayInterface::class);
        $gateway->shouldNotReceive('send');
        $this->app->instance(EmailGatewayInterface::class, $gateway);
        $this->app->forgetInstance(EmailService::class);

        $returned = $this->send($this->app->make(EmailService::class));

        $this->assertSame('bounced', $returned->status);
        $this->assertSame(1, EmailSend::count());
    }

    /**
     * A send is synchronous, so a row still pending minutes later belongs to a
     * process that died between creating it and recording the outcome. Without
     * this it would block retries exactly as permanently as a failure did.
     */
    public function test_a_freshly_pending_send_is_left_alone(): void
    {
        EmailSend::create([
            'template_key' => self::TEMPLATE,
            'language' => 'pl',
            'recipient_email' => self::RECIPIENT,
            'subject' => 'x',
            'body_html' => 'x',
            'body_text' => 'x',
            'status' => 'pending',
            'metadata' => $this->metadata(),
            'message_key' => md5(self::TEMPLATE.':'.self::RECIPIENT.':'.json_encode($this->metadata())),
        ]);

        $gateway = Mockery::mock(EmailGatewayInterface::class);
        $gateway->shouldNotReceive('send');
        $this->app->instance(EmailGatewayInterface::class, $gateway);
        $this->app->forgetInstance(EmailService::class);

        $returned = $this->send($this->app->make(EmailService::class));

        $this->assertSame('pending', $returned->status);
    }

    public function test_a_stale_pending_send_is_retried(): void
    {
        $stuck = EmailSend::create([
            'template_key' => self::TEMPLATE,
            'language' => 'pl',
            'recipient_email' => self::RECIPIENT,
            'subject' => 'x',
            'body_html' => 'x',
            'body_text' => 'x',
            'status' => 'pending',
            'metadata' => $this->metadata(),
            'message_key' => md5(self::TEMPLATE.':'.self::RECIPIENT.':'.json_encode($this->metadata())),
        ]);

        // Bypass the model so the timestamp is not refreshed on write.
        DB::table('email_sends')->where('id', $stuck->id)->update(['updated_at' => now()->subHour()]);

        $retried = $this->send($this->serviceWithGateway(true));

        $this->assertSame('sent', $retried->status);
        $this->assertSame($stuck->id, $retried->id);
        $this->assertSame(1, EmailSend::count());
    }

    public function test_repeated_failures_keep_reusing_the_same_row(): void
    {
        $first = $this->sendExpectingFailure($this->serviceWithGateway(false));
        $second = $this->sendExpectingFailure($this->serviceWithGateway(false));

        $this->assertSame($first->id, $second->id);
        $this->assertSame('failed', $second->status);
        $this->assertSame(1, EmailSend::count());
        $this->assertNotNull($second->fresh()->error_message);
    }
}
