<?php

namespace App\Filament\Resources\ServiceAreaWaitlists\Pages;

use App\Filament\Resources\ServiceAreaWaitlists\ServiceAreaWaitlistResource;
use Filament\Resources\Pages\ListRecords;

class ListServiceAreaWaitlists extends ListRecords
{
    protected static string $resource = ServiceAreaWaitlistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No create action - waitlist entries are created by customers via API
        ];
    }
}
