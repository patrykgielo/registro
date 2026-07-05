<?php

namespace App\Filament\Resources\RentalResource\Pages;

use App\Enums\RentalStatus;
use App\Filament\Resources\RentalResource;
use App\Models\Service;
use App\Services\RentalAvailabilityService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateRental extends CreateRecord
{
    protected static string $resource = RentalResource::class;

    /**
     * Re-validates inventory availability before creating the Rental row.
     *
     * This admin form is an independent, unlocked entry point writing to the
     * same `rentals` table that CartService::convertToOrder() protects via a
     * Service-row lock + a locking (`forUpdate: true`) availability recheck —
     * without this override an admin could create a Rental for a service/date
     * range with no remaining stock, racing (and oversubscribing) a concurrent
     * customer checkout for the same item. Only statuses that actually
     * consume capacity (RentalStatus::blocksAvailability()) are checked.
     *
     * Filament's own `beginDatabaseTransaction()` is a no-op here (the admin
     * panel does not call `->databaseTransactions()`), so the lock + recheck
     * is wrapped in an explicit DB::transaction() of our own.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $status = RentalStatus::from($data['status']);

            if ($status->blocksAvailability()) {
                $service = Service::lockForUpdate()->findOrFail($data['service_id']);

                $available = app(RentalAvailabilityService::class)->getAvailableQuantity(
                    $service,
                    Carbon::parse($data['start_date']),
                    Carbon::parse($data['end_date']),
                    forUpdate: true
                );

                if ((int) $data['quantity'] > $available) {
                    Notification::make()
                        ->danger()
                        ->title('Brak dostępności')
                        ->body("Dostępnych tylko {$available} szt. \"{$service->name}\" w wybranym terminie.")
                        ->send();

                    throw new Halt;
                }
            }

            return parent::handleRecordCreation($data);
        });
    }
}
