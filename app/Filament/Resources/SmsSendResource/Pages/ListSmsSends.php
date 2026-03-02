<?php

namespace App\Filament\Resources\SmsSendResource\Pages;

use App\Filament\Resources\SmsSendResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSmsSends extends ListRecords
{
    protected static string $resource = SmsSendResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
