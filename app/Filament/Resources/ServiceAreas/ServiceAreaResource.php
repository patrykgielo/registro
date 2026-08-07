<?php

namespace App\Filament\Resources\ServiceAreas;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\ServiceAreas\Pages\CreateServiceArea;
use App\Filament\Resources\ServiceAreas\Pages\EditServiceArea;
use App\Filament\Resources\ServiceAreas\Pages\ListServiceAreas;
use App\Filament\Resources\ServiceAreas\Schemas\ServiceAreaForm;
use App\Filament\Resources\ServiceAreas\Tables\ServiceAreasTable;
use App\Models\ServiceArea;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class ServiceAreaResource extends BaseResource
{
    protected static ?string $model = ServiceArea::class;

    protected static ?string $module = 'service_area';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Obszary Obsługi';

    protected static ?string $modelLabel = 'obszar obsługi';

    protected static ?string $pluralModelLabel = 'obszary obsługi';

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return ServiceAreaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiceAreasTable::configure($table);
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
            'index' => ListServiceAreas::route('/'),
            'create' => CreateServiceArea::route('/create'),
            'edit' => EditServiceArea::route('/{record}/edit'),
        ];
    }

    /**
     * Had no can*() overrides at all before this fix — BaseResource's
     * canViewAny() defaults to deny, which would have locked everyone
     * including super-admin out. Same admin/super-admin gate as its settings
     * group siblings (CategoryResource, RentalCategoryResource); create/edit/
     * delete/deleteAny fall through to BaseResource's matching default.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }
}
