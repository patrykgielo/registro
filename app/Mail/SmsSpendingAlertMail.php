<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * SMS Spending Alert Email
 *
 * Sent to admin when SMS spending reaches configured threshold (default 80%).
 * Alerts for both daily and monthly limits.
 */
class SmsSpendingAlertMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param  string  $period  'daily' or 'monthly'
     * @param  int  $currentCount  Current SMS count
     * @param  int  $limit  SMS limit
     * @param  int  $percentage  Percentage of limit used
     */
    public function __construct(
        public string $period,
        public int $currentCount,
        public int $limit,
        public int $percentage
    ) {
        $this->onQueue('emails');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $periodLabel = $this->period === 'daily' ? 'dzienny' : 'miesięczny';

        return new Envelope(
            subject: "[ALERT] Limit SMS {$periodLabel} - {$this->percentage}% wykorzystany",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.sms-spending-alert',
        );
    }
}
