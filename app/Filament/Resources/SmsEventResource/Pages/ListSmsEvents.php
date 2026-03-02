<?php

namespace App\Filament\Resources\SmsEventResource\Pages;

use App\Filament\Resources\SmsEventResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSmsEvents extends ListRecords
{
    protected static string $resource = SmsEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
