<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailSuppressionResource\Pages;

use App\Filament\Resources\EmailSuppressionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmailSuppression extends CreateRecord
{
    protected static string $resource = EmailSuppressionResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
