<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    /**
     * The user row and the `customer` role commit together. Opt-in per page, NOT panel-wide - see
     * `.claude/rules/filament.md` ("Transakcje zapisu").
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Klient został utworzony';
    }

    protected function afterCreate(): void
    {
        // Automatically assign customer role to new customer
        $this->record->assignRole('customer');
    }
}
