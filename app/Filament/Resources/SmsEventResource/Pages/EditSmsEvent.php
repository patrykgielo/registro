<?php

namespace App\Filament\Resources\SmsEventResource\Pages;

use App\Filament\Resources\SmsEventResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSmsEvent extends EditRecord
{
    protected static string $resource = SmsEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
