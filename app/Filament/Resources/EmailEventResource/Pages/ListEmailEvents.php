<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailEventResource\Pages;

use App\Filament\Resources\EmailEventResource;
use Filament\Resources\Pages\ListRecords;

class ListEmailEvents extends ListRecords
{
    protected static string $resource = EmailEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No create action - read-only resource
        ];
    }
}
