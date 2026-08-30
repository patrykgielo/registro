<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Inventory\SyncServiceLocationStock;
use App\Models\Location;

/**
 * Deliberately a SEPARATE observer class from App\Observers\LocationObserver
 * — plan-wdrozenia.md's Faza 2 scope forbids touching that file (or
 * Location.php itself), and Eloquent fully supports attaching more than one
 * observer to the same model. This one has nothing to do with the
 * primary_slot invariant LocationObserver guards; it is registered alongside
 * it in AppServiceProvider.
 *
 * Materializes zero-quantity service_location_stocks anchor rows for every
 * existing item_rental service of the location's organization the moment a
 * NEW location is created — the mirror image of what
 * App\Actions\Inventory\SyncServiceLocationStock::forService() does lazily
 * when a service's "Stany magazynowe" tab is opened for the first time.
 * Without this, a tenant adding their second branch would see every existing
 * product silently missing a row for it until an admin happened to open that
 * specific product's stock tab.
 */
class ServiceLocationStockObserver
{
    public function created(Location $location): void
    {
        SyncServiceLocationStock::forLocation($location);
    }
}
