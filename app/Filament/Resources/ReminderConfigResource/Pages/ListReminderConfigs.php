<?php

namespace App\Filament\Resources\ReminderConfigResource\Pages;

use App\Filament\Resources\ReminderConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReminderConfigs extends ListRecords
{
    protected static string $resource = ReminderConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Dodaj przypomnienie'),
        ];
    }
}
