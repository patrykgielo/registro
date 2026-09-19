<?php

declare(strict_types=1);

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use App\Models\Location;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditLocation extends EditRecord
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(fn (Location $record, DeleteAction $action) => LocationResource::guardDeletion($record, $action)),
        ];
    }

    /**
     * Faza 6 code review (2026-09-10) — friendly halt in front of
     * App\Observers\LocationObserver::updating()'s LocationCannotBeDeactivatedException,
     * same split as guardDeletion()/DeleteAction above and the established
     * EditRental::handleRecordUpdate() pattern (Notification + throw new Halt
     * before calling parent::handleRecordUpdate()).
     *
     * `$data['is_active']` — the SUBMITTED value, not $record->is_active
     * (the pre-edit DB value) — see LocationResource::guardDeactivation()'s
     * own docblock for why using the wrong one would never catch a genuine
     * deactivation attempt.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ($record instanceof Location && LocationResource::guardDeactivation($record, (bool) ($data['is_active'] ?? true))) {
            throw new Halt;
        }

        return parent::handleRecordUpdate($record, $data);
    }
}
