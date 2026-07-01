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
                ->label('Usuń z listy wykluczeń')
                ->requiresConfirmation()
                ->modalHeading('Usuń e-mail z listy wykluczeń')
                ->modalDescription('Ten adres będzie mógł ponownie otrzymywać e-maile.')
                ->modalSubmitActionLabel('Tak, usuń'),
        ];
    }
}
