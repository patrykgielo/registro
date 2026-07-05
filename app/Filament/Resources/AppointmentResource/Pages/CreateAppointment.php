<?php

namespace App\Filament\Resources\AppointmentResource\Pages;

use App\Filament\Resources\AppointmentResource;
use App\Models\User;
use App\Services\AppointmentService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

class CreateAppointment extends CreateRecord
{
    protected static string $resource = AppointmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Validate staff role
        if (isset($data['staff_id'])) {
            $staff = User::find($data['staff_id']);
            if ($staff && ! $staff->hasRole('staff')) {
                Notification::make()
                    ->danger()
                    ->title('Błąd walidacji')
                    ->body('Tylko użytkownicy z rolą "staff" mogą być przypisani do wizyt.')
                    ->persistent()
                    ->send();

                $this->halt();
            }
        }

        $appointmentService = app(AppointmentService::class);

        $validation = $appointmentService->validateAppointment(
            staffId: $data['staff_id'],
            serviceId: $data['service_id'],
            appointmentDate: $data['appointment_date'],
            startTime: $data['start_time'],
            endTime: $data['end_time']
        );

        if (! $validation['valid']) {
            foreach ($validation['errors'] as $error) {
                Notification::make()
                    ->danger()
                    ->title('Błąd walidacji')
                    ->body($error)
                    ->persistent()
                    ->send();
            }

            $this->halt();
        }

        return $data;
    }

    /**
     * Catches the appointments_staff_slot_unique DB constraint (double-booking
     * guard — see database/migrations/2026_07_05_000001_*) so an admin who
     * races a customer/another admin for the same slot sees a friendly
     * notification instead of an uncaught QueryException. The mutateFormDataBeforeCreate()
     * SELECT-based check above already narrows this to genuinely concurrent
     * saves; the DB constraint remains the authoritative guard either way.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
        } catch (QueryException $e) {
            if (! app(AppointmentService::class)->isDoubleBookingViolation($e)) {
                throw $e;
            }

            Notification::make()
                ->danger()
                ->title('Termin już zajęty')
                ->body('Wybrany termin został właśnie zarezerwowany przez inną osobę. Wybierz inny termin.')
                ->persistent()
                ->send();

            $this->halt();
        }
    }
}
