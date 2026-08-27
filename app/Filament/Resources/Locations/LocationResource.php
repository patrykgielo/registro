<?php

declare(strict_types=1);

namespace App\Filament\Resources\Locations;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Filament\Resources\Locations\Tables\LocationsTable;
use App\Models\Location;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Faza 1, krok 1.3 (app/docs/features/lokalizacje/plan-wdrozenia.md). Deliberately
 * has NO `$module` — unlike ServiceAreaResource, the location entity is never
 * behind a feature flag (every tenant has a physical address; see
 * tryb-jednooddzialowy.md). Only `multi_location_stock` (Faza 2+) is gated.
 */
class LocationResource extends BaseResource
{
    protected static ?string $model = Location::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Lokalizacje';

    protected static ?string $modelLabel = 'lokalizacja';

    protected static ?string $pluralModelLabel = 'lokalizacje';

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 35;

    public static function form(Schema $schema): Schema
    {
        return LocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LocationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocations::route('/'),
            'create' => CreateLocation::route('/create'),
            'edit' => EditLocation::route('/{record}/edit'),
        ];
    }

    /**
     * BaseResource::canViewAny() is deny-by-default — a Resource with no
     * override disappears even for super-admin (ServiceAreaResource hit this
     * exact trap, see its own canViewAny() docblock). Same admin/super-admin
     * gate as its `settings` group sibling.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }

    /**
     * Shared by LocationsTable's row DeleteAction, its bulk DeleteBulkAction
     * (called once per selected record), AND EditLocation's header
     * DeleteAction — the table isn't the only way to delete a record, so the
     * guard has to live in one place all three call, not just in the table.
     *
     * Blocks two cases, per plan-wdrozenia.md step 1.3 ("przemyśl, co ma się
     * stać przy próbie usunięcia głównej lokalizacji albo ostatniej
     * lokalizacji tenanta"):
     *
     * 1. Last location of the tenant — tryb-jednooddzialowy.md's "stan
     *    domyślny" assumes a tenant always has at least a primary branch;
     *    letting the count reach zero breaks that invariant for every future
     *    phase that reads it (front-end address block, Faza 2 stock anchor).
     * 2. The primary location while siblings exist — deleting it would leave
     *    the tenant with zero rows at `primary_slot = 1` until an admin
     *    happens to promote another one. Silently auto-promoting whichever
     *    row happens to be $sort_order-first was considered and rejected:
     *    it's a decision the product doc treats as explicit and one-click
     *    ("Zmiana głównej później — ręcznie, jednym kliknięciem"), not
     *    something to guess on delete. Forcing "Ustaw jako główną" first
     *    keeps that guarantee explicit.
     *
     * Both predicates are Location::isOnlyLocationForOrganization() /
     * ::isPrimary() — the same methods App\Observers\LocationObserver::
     * deleting() checks to throw LocationCannotBeDeletedException. This
     * method exists only to turn that into a friendly halted Filament
     * notification instead of a raw exception; it is not a second copy of
     * the rule.
     */
    public static function guardDeletion(Location $record, DeleteAction|Action $action): void
    {
        if ($record->isOnlyLocationForOrganization()) {
            Notification::make()
                ->title('Nie można usunąć ostatniej lokalizacji')
                ->body('Każdy tenant musi mieć co najmniej jedną lokalizację — to na niej opiera się tryb jednooddziałowy.')
                ->danger()
                ->send();

            $action->halt();

            return;
        }

        if ($record->isPrimary()) {
            Notification::make()
                ->title('Nie można usunąć głównej lokalizacji')
                ->body('Najpierw ustaw inną lokalizację jako główną (akcja „Ustaw jako główną"), dopiero potem usuń tę.')
                ->danger()
                ->send();

            $action->halt();
        }
    }
}
