<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\RentalStatus;
use App\Models\EmailSend;
use App\Models\Rental;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rental-cancellation e-mail, end to end: cancel a rental, then read what
 * the customer actually received.
 *
 * EveryNotificationHasItsTemplateTest proves the template EXISTS. This proves it
 * RENDERS — a distinct property, and the one that fails silently. A template can
 * resolve and still mail a literal "{{app_name}}", because EmailTemplate leaves
 * unknown `{{tokens}}` verbatim rather than erroring on them.
 *
 * Drives the real path (status -> Cancelled fires Rental's `updated` hook ->
 * RentalCancelled -> SendRentalCancelledNotification), not the notification in
 * isolation, so a break anywhere along it shows up here.
 */
class RentalCancelledEmailTest extends TestCase
{
    use RefreshDatabase;

    private function cancelledRental(): Rental
    {
        $rental = Rental::factory()->create([
            'status' => RentalStatus::Confirmed,
            'cancellation_reason' => 'Sprzet niedostepny',
        ]);

        $rental->update(['status' => RentalStatus::Cancelled]);

        return $rental;
    }

    public function test_cancelling_a_rental_sends_the_customer_an_email(): void
    {
        $rental = $this->cancelledRental();

        $send = EmailSend::withoutGlobalScope('organization')
            ->where('template_key', 'rental-cancelled')
            ->first();

        $this->assertNotNull($send,
            'no email_sends row — until 2026-08-23 this threw "template not found" on every cancellation');
        $this->assertSame($rental->customer->email, $send->recipient_email);
    }

    public function test_no_placeholder_reaches_the_customer(): void
    {
        $this->cancelledRental();

        $send = EmailSend::withoutGlobalScope('organization')
            ->where('template_key', 'rental-cancelled')->firstOrFail();

        foreach (['subject' => $send->subject, 'body_html' => $send->body_html, 'body_text' => $send->body_text] as $field => $value) {
            $this->assertDoesNotMatchRegularExpression('/\{\{\w+\}\}/', (string) $value,
                "an unsubstituted placeholder reached the customer in {$field}: ".$value);
        }
    }

    public function test_the_email_names_the_cancelled_item_and_the_reason(): void
    {
        $rental = $this->cancelledRental();

        $send = EmailSend::withoutGlobalScope('organization')
            ->where('template_key', 'rental-cancelled')->firstOrFail();

        // Without these the message is technically delivered and practically
        // useless — the customer cannot tell which rental was cancelled or why.
        $this->assertStringContainsString($rental->service->name, (string) $send->body_html);
        $this->assertStringContainsString('Sprzet niedostepny', (string) $send->body_html);
    }
}
