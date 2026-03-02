<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\EmailSend;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Email Delivery Failed Event
 *
 * Dispatched when an email fails to send via the gateway.
 * Can be used to trigger alerts, retry logic, or suppression list updates.
 */
class EmailDeliveryFailed
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\EmailSend  $emailSend  The failed email send record
     * @param  string  $error  Error message from gateway
     */
    public function __construct(
        public EmailSend $emailSend,
        public string $error
    ) {}
}
