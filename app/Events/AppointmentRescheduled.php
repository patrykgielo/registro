<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Appointment Rescheduled Event
 *
 * Dispatched when an appointment's date or time is changed.
 * Triggers reschedule notification to customer and admin.
 */
class AppointmentRescheduled
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Appointment  $appointment  The rescheduled appointment
     * @param  \Carbon\Carbon  $oldDate  Original appointment date/time
     * @param  \Carbon\Carbon  $newDate  New appointment date/time
     */
    public function __construct(
        public Appointment $appointment,
        public Carbon $oldDate,
        public Carbon $newDate
    ) {}
}
