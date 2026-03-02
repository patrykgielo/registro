<?php

namespace App\Filament\Resources\StaffDateExceptionResource\Pages;

use App\Filament\Resources\StaffDateExceptionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStaffDateExceptions extends ListRecords
{
    protected static string $resource = StaffDateExceptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
