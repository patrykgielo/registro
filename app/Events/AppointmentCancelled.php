<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Appointment Cancelled Event
 *
 * Dispatched when an appointment is cancelled by customer or staff.
 * Triggers cancellation notification email.
 */
class AppointmentCancelled
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Appointment  $appointment  The cancelled appointment
     * @param  string|null  $reason  Reason for cancellation
     */
    public function __construct(
        public Appointment $appointment,
        public ?string $reason = null
    ) {}
}
