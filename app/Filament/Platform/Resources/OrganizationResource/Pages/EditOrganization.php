<?php

namespace App\Filament\Platform\Resources\OrganizationResource\Pages;

use App\Filament\Platform\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\TenantObligationService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (Organization $record, Actions\DeleteAction $action): void {
                    $service = app(TenantObligationService::class);
                    $counts = $service->activeObligations($record);

                    if ($counts['total'] > 0) {
                        Notification::make()
                            ->title('Nie można usunąć organizacji')
                            ->body(sprintf(
                                'Organizacja ma aktywne zobowiązania: %d wizyt, %d zamówień, %d wypożyczeń. Rozwiąż je lub uruchom proces zamknięcia.',
                                $counts['appointments'],
                                $counts['orders'],
                                $counts['rentals'],
                            ))
                            ->danger()
                            ->persistent()
                            ->send();

                        $action->halt();
                    }
                }),
        ];
    }
}
