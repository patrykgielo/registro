<?php

namespace App\Filament\Resources\ServiceAreaWaitlists\Pages;

use App\Filament\Resources\ServiceAreaWaitlists\ServiceAreaWaitlistResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServiceAreaWaitlist extends EditRecord
{
    protected static string $resource = ServiceAreaWaitlistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
