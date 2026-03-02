<?php

namespace App\Filament\Resources\SmsSendResource\Pages;

use App\Filament\Resources\SmsSendResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSmsSend extends EditRecord
{
    protected static string $resource = SmsSendResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
