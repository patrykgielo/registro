<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Events\AppointmentRescheduled;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeStaff(): User
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        return $staff;
    }

    /**
     * Pins the ArgumentCountError fix (App\Events\AppointmentRescheduled now
     * requires appointment + oldDate + newDate) AND the spurious-dirty guard:
     * start_time/end_time only carry minute precision (TimePicker UI + the
     * 'datetime:H:i' cast both drop seconds), and static::saving() re-derives
     * a canonical ':00' second on every save -- a factory row seeded with
     * non-zero seconds (AppointmentFactory's fake()->time('H:i:s')) must NOT
     * make a genuinely no-op save look like a reschedule. Regression:
     * PanelWalkthroughTest, 2026-08-30.
     */
    public function test_no_op_save_does_not_dispatch_rescheduled_event(): void
    {
        Event::fake([AppointmentRescheduled::class]);

        $appointment = Appointment::factory()->create([
            'staff_id' => $this->makeStaff()->id,
            'start_time' => '10:00:35',
            'end_time' => '11:00:47',
        ]);

        $fresh = Appointment::find($appointment->id);
        $fresh->appointment_date = $fresh->appointment_date->format('Y-m-d');
        $fresh->start_time = $fresh->start_time->format('H:i');
        $fresh->end_time = $fresh->end_time->format('H:i');
        $fresh->save();

        Event::assertNotDispatched(AppointmentRescheduled::class);
    }

    public function test_changing_start_time_dispatches_rescheduled_event_with_old_and_new_dates(): void
    {
        Event::fake([AppointmentRescheduled::class]);

        $appointment = Appointment::factory()->create([
            'staff_id' => $this->makeStaff()->id,
            'appointment_date' => '2026-09-10',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        $appointment->start_time = '12:00';
        $appointment->end_time = '13:00';
        $appointment->save();

        Event::assertDispatched(AppointmentRescheduled::class, function (AppointmentRescheduled $event) {
            return $event->oldDate->format('Y-m-d H:i') === '2026-09-10 10:00'
                && $event->newDate->format('Y-m-d H:i') === '2026-09-10 12:00';
        });
    }

    /**
     * Pins AppointmentFactory generating minute-precision times only -- see
     * the factory's own docblock for why a random second broke no-op saves.
     */
    public function test_factory_produces_start_and_end_times_with_zero_seconds(): void
    {
        $appointment = Appointment::factory()->create(['staff_id' => $this->makeStaff()->id]);

        $this->assertSame('00', $appointment->fresh()->start_time->format('s'));
        $this->assertSame('00', $appointment->fresh()->end_time->format('s'));
    }
}
