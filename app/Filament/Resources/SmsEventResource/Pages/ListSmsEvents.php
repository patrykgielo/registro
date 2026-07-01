<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsEventResource\Pages;

use App\Filament\Resources\SmsEventResource;
use Filament\Resources\Pages\ListRecords;

class ListSmsEvents extends ListRecords
{
    protected static string $resource = SmsEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Brak akcji tworzenia — zasób tylko do odczytu (zdarzenia z webhooków SMSAPI)
        ];
    }
}
