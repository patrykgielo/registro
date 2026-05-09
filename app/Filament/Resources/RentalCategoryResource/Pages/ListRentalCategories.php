<?php

namespace App\Filament\Resources\RentalCategoryResource\Pages;

use App\Filament\Resources\RentalCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRentalCategories extends ListRecords
{
    protected static string $resource = RentalCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
