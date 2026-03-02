<?php

namespace App\Filament\Resources\AppointmentResource\Pages;

use App\Filament\Resources\AppointmentResource;
use App\Models\User;
use App\Services\AppointmentService;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAppointment extends EditRecord
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
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

        // Only validate if appointment details changed (staff, date, or time)
        // Note: Filament form returns strings, but getOriginal() returns Carbon objects
        // due to model casts (date, datetime:H:i). Must normalize before comparing.
        $original = $this->record->getOriginal();

        $formatDate = fn ($value) => $value instanceof Carbon ? $value->format('Y-m-d') : (string) $value;
        $formatTime = fn ($value) => $value instanceof Carbon ? $value->format('H:i') : substr((string) $value, 0, 5);

        $changed = $data['staff_id'] != $original['staff_id']
            || $formatDate($data['appointment_date']) !== $formatDate($original['appointment_date'])
            || $formatTime($data['start_time']) !== $formatTime($original['start_time'])
            || $formatTime($data['end_time']) !== $formatTime($original['end_time']);

        if ($changed) {
            $appointmentService = app(AppointmentService::class);

            $validation = $appointmentService->validateAppointment(
                staffId: $data['staff_id'],
                serviceId: $data['service_id'],
                appointmentDate: $data['appointment_date'],
                startTime: $data['start_time'],
                endTime: $data['end_time'],
                excludeAppointmentId: $this->record->id
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
        }

        return $data;
    }
}
