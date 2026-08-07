<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use App\Support\TenantFeature;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Pracownik został utworzony';
    }

    protected function afterCreate(): void
    {
        // Automatically assign staff role to new employee
        $this->record->assignRole('staff');

        // Without the pivot row the employee cannot log in at all: canAccessTenant()
        // reads organization_user and nothing else, so ResolveTenant bounces them
        // straight back off /admin. Assigning the Spatie role alone produced an
        // account that looked created and was unusable.
        if ($tenant = TenantFeature::currentTenant()) {
            $this->record->organizations()->syncWithoutDetaching([$tenant->id => ['role' => 'staff']]);
        }
    }
}
