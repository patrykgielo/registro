<?php

declare(strict_types=1);

namespace App\Filament\Resources\Promotions\Pages;

use App\Filament\Resources\Promotions\PromotionResource;
use App\Filament\Traits\StaysOnPageAfterSave;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPromotion extends EditRecord
{
    use StaysOnPageAfterSave;

    protected static string $resource = PromotionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Usuń'),
        ];
    }
}
