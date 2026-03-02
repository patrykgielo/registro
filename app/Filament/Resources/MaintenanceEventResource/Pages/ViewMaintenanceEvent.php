<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceEventResource\Pages;

use App\Filament\Resources\MaintenanceEventResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewMaintenanceEvent extends ViewRecord
{
    protected static string $resource = MaintenanceEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Back to List')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(route('filament.admin.resources.maintenance-events.index')),
        ];
    }
}
