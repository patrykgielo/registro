<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\OrganizationLifecycleLogResource\Pages;

use App\Filament\Platform\Resources\OrganizationLifecycleLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewOrganizationLifecycleLog extends ViewRecord
{
    protected static string $resource = OrganizationLifecycleLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
