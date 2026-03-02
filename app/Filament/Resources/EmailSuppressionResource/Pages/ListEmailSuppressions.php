<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailSuppressionResource\Pages;

use App\Filament\Resources\EmailSuppressionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEmailSuppressions extends ListRecords
{
    protected static string $resource = EmailSuppressionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
