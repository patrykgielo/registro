<?php

namespace App\Filament\Resources\StaffVacationPeriodResource\Pages;

use App\Filament\Resources\StaffVacationPeriodResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStaffVacationPeriods extends ListRecords
{
    protected static string $resource = StaffVacationPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
