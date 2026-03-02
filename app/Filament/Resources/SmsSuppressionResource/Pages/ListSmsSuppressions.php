<?php

namespace App\Filament\Resources\SmsSuppressionResource\Pages;

use App\Filament\Resources\SmsSuppressionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSmsSuppressions extends ListRecords
{
    protected static string $resource = SmsSuppressionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
