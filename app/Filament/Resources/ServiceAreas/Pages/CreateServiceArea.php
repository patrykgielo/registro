<?php

namespace App\Filament\Resources\ServiceAreas\Pages;

use App\Filament\Resources\ServiceAreas\ServiceAreaResource;
use App\Services\ServiceAreaValidator;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceArea extends CreateRecord
{
    protected static string $resource = ServiceAreaResource::class;

    protected function afterCreate(): void
    {
        // Clear cache after creating new area
        app(ServiceAreaValidator::class)->clearCache();
    }
}
