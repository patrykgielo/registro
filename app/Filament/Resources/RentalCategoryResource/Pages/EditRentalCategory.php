<?php

namespace App\Filament\Resources\RentalCategoryResource\Pages;

use App\Filament\Resources\RentalCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRentalCategory extends EditRecord
{
    protected static string $resource = RentalCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
