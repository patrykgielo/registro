<?php

namespace App\Filament\Resources\ServiceResource\Pages;

use App\Actions\Inventory\RouteQuantityFieldToPrimaryLocationStock;
use App\Filament\Resources\ServiceResource;
use App\Filament\Traits\StaysOnPageAfterSave;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditService extends EditRecord
{
    use StaysOnPageAfterSave;

    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        RouteQuantityFieldToPrimaryLocationStock::handle($this->record);
    }
}
