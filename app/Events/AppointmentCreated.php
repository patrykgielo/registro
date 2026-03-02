<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Appointment Created Event
 *
 * Dispatched when a new appointment is successfully created.
 * Triggers confirmation emails to customer and admin.
 */
class AppointmentCreated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Appointment  $appointment  The newly created appointment
     */
    public function __construct(
        public Appointment $appointment
    ) {}
}
