<?php

namespace App\Filament\Resources\StaffScheduleResource\Pages;

use App\Filament\Resources\StaffScheduleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStaffSchedule extends EditRecord
{
    protected static string $resource = StaffScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
