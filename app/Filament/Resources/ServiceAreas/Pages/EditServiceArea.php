<?php

namespace App\Filament\Resources\ServiceAreas\Pages;

use App\Filament\Resources\ServiceAreas\ServiceAreaResource;
use App\Services\ServiceAreaValidator;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServiceArea extends EditRecord
{
    protected static string $resource = ServiceAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        // Clear cache after updating area
        app(ServiceAreaValidator::class)->clearCache();
    }
}
