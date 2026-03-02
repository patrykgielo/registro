<?php

namespace App\Filament\Resources\StaffScheduleResource\Pages;

use App\Filament\Resources\StaffDateExceptionResource;
use App\Filament\Resources\StaffScheduleResource;
use App\Filament\Resources\StaffVacationPeriodResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStaffSchedules extends ListRecords
{
    protected static string $resource = StaffScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('manage_exceptions')
                ->label('Zarządzaj wyjątkami')
                ->icon('heroicon-o-calendar')
                ->color('warning')
                ->url(fn () => StaffDateExceptionResource::getUrl('index'))
                ->badge(fn () => \App\Models\StaffDateException::where('exception_date', '>=', now()->toDateString())->count())
                ->badgeColor('warning'),
            Actions\Action::make('manage_vacations')
                ->label('Zarządzaj urlopami')
                ->icon('heroicon-o-sun')
                ->color('success')
                ->url(fn () => StaffVacationPeriodResource::getUrl('index'))
                ->badge(fn () => \App\Models\StaffVacationPeriod::where('end_date', '>=', now()->toDateString())->count())
                ->badgeColor('success'),
        ];
    }
}
