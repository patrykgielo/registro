<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsSendResource\Pages;

use App\Filament\Resources\SmsSendResource;
use Filament\Resources\Pages\ListRecords;

class ListSmsSends extends ListRecords
{
    protected static string $resource = SmsSendResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Brak akcji tworzenia — zasób tylko do odczytu (historia wysyłek SMS)
        ];
    }
}
