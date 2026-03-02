<?php

namespace App\Filament\Resources\ReminderConfigResource\Pages;

use App\Filament\Resources\ReminderConfigResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReminderConfig extends CreateRecord
{
    protected static string $resource = ReminderConfigResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
