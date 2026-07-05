<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsSendResource\Pages;

use App\Filament\Resources\SmsSendResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSmsSend extends ViewRecord
{
    protected static string $resource = SmsSendResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Brak akcji edycji/usuwania — zasób tylko do odczytu
        ];
    }
}
