<?php

declare(strict_types=1);

namespace App\Filament\Resources\Promotions\Pages;

use App\Filament\Resources\Promotions\PromotionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPromotions extends ListRecords
{
    protected static string $resource = PromotionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nowa promocja'),
        ];
    }
}
