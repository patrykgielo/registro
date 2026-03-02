<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailSuppressionResource\Pages;

use App\Filament\Resources\EmailSuppressionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEmailSuppression extends EditRecord
{
    protected static string $resource = EmailSuppressionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Remove from Suppression List')
                ->requiresConfirmation()
                ->modalHeading('Remove Email from Suppression List')
                ->modalDescription('This will allow emails to be sent to this address again.')
                ->modalSubmitActionLabel('Yes, Remove'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
