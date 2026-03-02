<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceEventResource\Pages;

use App\Filament\Resources\MaintenanceEventResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceEvents extends ListRecords
{
    protected static string $resource = MaintenanceEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('manage_maintenance')
                ->label('Manage Maintenance')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('primary')
                ->url(route('filament.admin.pages.maintenance-settings')),
        ];
    }
}
