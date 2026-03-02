<?php

namespace App\Filament\Resources\StaffVacationPeriodResource\Pages;

use App\Filament\Resources\StaffVacationPeriodResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStaffVacationPeriod extends EditRecord
{
    protected static string $resource = StaffVacationPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Ensure dates are strings for form compatibility
        if (isset($data['start_date']) && $data['start_date'] instanceof \Carbon\Carbon) {
            $data['start_date'] = $data['start_date']->format('Y-m-d');
        }

        if (isset($data['end_date']) && $data['end_date'] instanceof \Carbon\Carbon) {
            $data['end_date'] = $data['end_date']->format('Y-m-d');
        }

        return $data;
    }
}
