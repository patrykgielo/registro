<?php

namespace App\Filament\Resources\StaffScheduleResource\Pages;

use App\Filament\Resources\StaffScheduleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStaffSchedule extends CreateRecord
{
    protected static string $resource = StaffScheduleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return StaffScheduleResource::forceOwnUserId($data);
    }
}
