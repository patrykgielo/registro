<?php

namespace App\Filament\Resources\StaffDateExceptionResource\Pages;

use App\Filament\Resources\StaffDateExceptionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStaffDateException extends EditRecord
{
    protected static string $resource = StaffDateExceptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
