<?php

namespace App\Filament\Resources\ServiceAreas;

use App\Filament\Resources\ServiceAreas\Pages\CreateServiceArea;
use App\Filament\Resources\ServiceAreas\Pages\EditServiceArea;
use App\Filament\Resources\ServiceAreas\Pages\ListServiceAreas;
use App\Filament\Resources\ServiceAreas\Schemas\ServiceAreaForm;
use App\Filament\Resources\ServiceAreas\Tables\ServiceAreasTable;
use App\Models\ServiceArea;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class ServiceAreaResource extends Resource
{
    protected static ?string $model = ServiceArea::class;

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
}
