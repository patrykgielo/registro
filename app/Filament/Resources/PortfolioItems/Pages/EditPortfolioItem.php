<?php

declare(strict_types=1);

namespace App\Filament\Resources\PortfolioItems\Pages;

use App\Filament\Resources\PortfolioItems\PortfolioItemResource;
use App\Filament\Traits\StaysOnPageAfterSave;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPortfolioItem extends EditRecord
{
    use StaysOnPageAfterSave;

    protected static string $resource = PortfolioItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Usuń'),
        ];
    }
}
