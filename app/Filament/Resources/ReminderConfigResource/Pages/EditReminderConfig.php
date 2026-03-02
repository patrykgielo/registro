<?php

namespace App\Filament\Resources\ReminderConfigResource\Pages;

use App\Filament\Resources\ReminderConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReminderConfig extends EditRecord
{
    protected static string $resource = ReminderConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
