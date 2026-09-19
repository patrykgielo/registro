<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceResource\RelationManagers;

use App\Actions\Inventory\SyncServiceLocationStock;
use App\Enums\ServiceType;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\ServiceUnit;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * "Stany magazynowe" — plan-wdrozenia.md Krok 2.5. Only ever visible for
 * item_rental services (canViewForRecord()); for a tenant with a single
 * active location the "Ilość w magazynie" field on the parent form is the
 * one an owner actually edits (App\Actions\Inventory\
 * RouteQuantityFieldToPrimaryLocationStock keeps this table's single row in
 * sync with it) — this tab exists for the case that field can no longer
 * represent: more than one location.
 */
class LocationStocksRelationManager extends RelationManager
{
    protected static string $relationship = 'locationStocks';

    protected static ?string $title = 'Stany magazynowe';

    protected static ?string $modelLabel = 'stan magazynowy';

    protected static ?string $pluralModelLabel = 'stany magazynowe';

    public static function canViewForRecord(Model $ownerRecord, string $panel): bool
    {
        return $ownerRecord instanceof Service
            && $ownerRecord->service_type === ServiceType::ItemRental
            && (auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        /** @var Service $service */
        $service = $this->getOwnerRecord();

        // Self-heal on every mount, NOT inside any lockForUpdate transaction
        // (kontrakt-dostepnosci.md Zasada 4) — a plain read-then-insertOrIgnore,
        // safe to run on every page load. Covers a location added before
        // this specific service existed, or a service created before this
        // tab was ever opened for it.
        SyncServiceLocationStock::forService($service);

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Oddział'),

                // Security note (Filament\Tables\Columns\Concerns\CanUpdateState's
                // own docblock): inline editable columns do NOT check
                // Laravel authorization before saving, only ->disabled()
                // is checked. Not an extra hole here — canViewForRecord()
                // above already gates the whole tab to admin/super-admin,
                // the same population that could reach this table at all.
                Tables\Columns\TextInputColumn::make('quantity')
                    ->label('Ilość')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:0'])
                    // ClickUp 123k99cvc54: once THIS (service, location) pair
                    // has any egzemplarz of its own, ServiceUnitObserver is
                    // the sole writer of this row's quantity — it recomputes
                    // it from a COUNT() on every unit create/update/delete.
                    // Leaving the inline edit open here would make it a
                    // second writer racing the observer, the exact same
                    // hazard the parent form's "Ilość w magazynie" field is
                    // already guarded against
                    // (RouteQuantityFieldToPrimaryLocationStock::
                    // eligibleForDirectRouting()) — mirrored here per-row
                    // instead of per-service, since a service can have units
                    // at location A and none yet at B (that row stays
                    // editable).
                    ->disabled(fn (ServiceLocationStock $record): bool => self::locationHasUnits($record))
                    ->afterStateUpdated(function (ServiceLocationStock $record): void {
                        DB::transaction(function () use ($record): void {
                            $record->service->recalculateQuantityTotal();
                        });
                    }),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Aktywny'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Brak stanów magazynowych')
            ->emptyStateDescription('Ta organizacja nie ma jeszcze żadnego aktywnego oddziału.');
    }

    private static function locationHasUnits(ServiceLocationStock $record): bool
    {
        return ServiceUnit::withoutGlobalScope('organization')
            ->where('service_id', $record->service_id)
            ->where('location_id', $record->location_id)
            ->exists();
    }

    public function canCreate(): bool
    {
        return false;
    }

    public function canEdit($record): bool
    {
        return false;
    }

    public function canDelete($record): bool
    {
        return false;
    }

    /**
     * Same reasoning as OrderItemsRelationManager: no CreateAction/
     * DeleteAction is wired above, so this is currently unreachable — but
     * RelationManager (unlike App\Filament\Resources\BaseResource) does not
     * consult canCreate()/canDelete() by default, it falls through to
     * Gate/policy resolution, which allows by default with no policy class
     * for this model. Wired now so a later action can't silently reopen
     * this.
     */
    public function getCreateAuthorizationResponse(): Response
    {
        return $this->canCreate() ? Response::allow() : Response::deny();
    }

    public function getEditAuthorizationResponse(Model $record): Response
    {
        return $this->canEdit($record) ? Response::allow() : Response::deny();
    }

    public function getDeleteAuthorizationResponse(Model $record): Response
    {
        return $this->canDelete($record) ? Response::allow() : Response::deny();
    }
}
