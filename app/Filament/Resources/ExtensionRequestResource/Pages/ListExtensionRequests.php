<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExtensionRequestResource\Pages;

use App\Filament\Resources\ExtensionRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListExtensionRequests extends ListRecords
{
    protected static string $resource = ExtensionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
