<?php

namespace App\Observers;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AppointmentObserver
{
    /**
     * Handle the Appointment "creating" event.
     */
    public function creating(Appointment $appointment): void
    {
        $this->validateStaffRole($appointment);
    }

    /**
     * Handle the Appointment "updating" event.
     */
    public function updating(Appointment $appointment): void
    {
        if ($appointment->isDirty('staff_id')) {
            $this->validateStaffRole($appointment);
        }
    }

    /**
     * Validate that the assigned staff has the 'staff' role.
     */
    private function validateStaffRole(Appointment $appointment): void
    {
        if (! $appointment->staff_id) {
            throw ValidationException::withMessages([
                'staff_id' => 'Pole pracownika jest wymagane.',
            ]);
        }

        $staff = User::find($appointment->staff_id);

        if (! $staff) {
            throw ValidationException::withMessages([
                'staff_id' => 'Wybrany pracownik nie istnieje.',
            ]);
        }

        if (! $staff->hasRole('staff')) {
            throw ValidationException::withMessages([
                'staff_id' => 'Tylko użytkownicy z rolą "staff" mogą być przypisani do wizyt.',
            ]);
        }
    }
}
