<?php

namespace App\Filament\Resources\ServiceResource\Pages;

use App\Filament\Resources\ServiceResource;
use App\Filament\Traits\CreatesAndRedirectsToEdit;
use Filament\Resources\Pages\CreateRecord;

class CreateService extends CreateRecord
{
    use CreatesAndRedirectsToEdit;

    protected static string $resource = ServiceResource::class;
}
