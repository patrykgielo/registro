<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Appointment Confirmed Event
 *
 * Dispatched when an admin confirms a pending appointment (status change to 'confirmed').
 * Triggers confirmation SMS to customer.
 */
class AppointmentConfirmed
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Appointment  $appointment  The confirmed appointment
     */
    public function __construct(
        public Appointment $appointment
    ) {}
}
