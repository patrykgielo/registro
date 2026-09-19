<?php

namespace App\Filament\Resources\ServiceResource\Pages;

use App\Actions\Inventory\RouteQuantityFieldToPrimaryLocationStock;
use App\Actions\Inventory\SyncServiceLocationStock;
use App\Filament\Resources\ServiceResource;
use App\Filament\Traits\CreatesAndRedirectsToEdit;
use Filament\Resources\Pages\CreateRecord;

class CreateService extends CreateRecord
{
    use CreatesAndRedirectsToEdit;

    protected static string $resource = ServiceResource::class;

    /**
     * ClickUp 123k99cvcc3: without this, a MULTI-location tenant creating a
     * new item_rental product through the panel got zero
     * service_location_stocks rows at all until an admin happened to open
     * "Stany magazynowe" for it — SyncServiceLocationStock::forService()
     * here closes that gap immediately at creation, for every tenant shape,
     * not only the single-location one RouteQuantityFieldToPrimaryLocationStock
     * already handles below. Order-independent and idempotent with it: for
     * a single-location tenant this seeds the primary row to the just-typed
     * quantity_total (already correctly dehydrated on create), and
     * handle()'s own updateOrCreate() + recalculateQuantityTotal() below
     * then write the exact same value back — a no-op, not a race. For a
     * multi-location tenant quantity_total is null at this point (the field
     * is un-dehydrated), so every location — including the primary — is
     * correctly seeded at 0, matching exactly what opening the tab manually
     * would already have produced.
     */
    protected function afterCreate(): void
    {
        SyncServiceLocationStock::forService($this->record);
        RouteQuantityFieldToPrimaryLocationStock::handle($this->record);
    }
}
