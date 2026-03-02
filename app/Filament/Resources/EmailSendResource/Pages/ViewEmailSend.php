<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailSendResource\Pages;

use App\Filament\Resources\EmailSendResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewEmailSend extends ViewRecord
{
    protected static string $resource = EmailSendResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No edit/delete actions - read-only resource
        ];
    }
}
