<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailSendResource\Pages;

use App\Filament\Resources\EmailSendResource;
use Filament\Resources\Pages\ListRecords;

class ListEmailSends extends ListRecords
{
    protected static string $resource = EmailSendResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No create action - read-only resource
        ];
    }
}
