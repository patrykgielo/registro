<?php

namespace App\Filament\Resources\StaffDateExceptionResource\Pages;

use App\Filament\Resources\StaffDateExceptionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStaffDateException extends CreateRecord
{
    protected static string $resource = StaffDateExceptionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return StaffDateExceptionResource::forceOwnUserId($data);
    }
}
