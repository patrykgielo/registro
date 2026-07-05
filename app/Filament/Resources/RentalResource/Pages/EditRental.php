<?php

namespace App\Filament\Resources\RentalResource\Pages;

use App\Enums\RentalStatus;
use App\Filament\Resources\RentalResource;
use App\Models\Service;
use App\Services\RentalAvailabilityService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EditRental extends EditRecord
{
    protected static string $resource = RentalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Re-validates inventory availability before saving edits — same
     * rationale as CreateRental::handleRecordCreation(). $excludeRentalId
     * excludes THIS row's own already-counted reservation from the sum, so
     * increasing quantity/extending dates on an existing rental isn't
     * incorrectly blocked by its own prior reservation.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $status = RentalStatus::from($data['status']);

            if ($status->blocksAvailability()) {
                $service = Service::lockForUpdate()->findOrFail($data['service_id']);

                $available = app(RentalAvailabilityService::class)->getAvailableQuantity(
                    $service,
                    Carbon::parse($data['start_date']),
                    Carbon::parse($data['end_date']),
                    forUpdate: true,
                    excludeRentalId: $record->getKey()
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

            return parent::handleRecordUpdate($record, $data);
        });
    }
}
