<?php

namespace App\Filament\Resources\ServiceAreaWaitlists;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\ServiceAreaWaitlists\Pages\EditServiceAreaWaitlist;
use App\Filament\Resources\ServiceAreaWaitlists\Pages\ListServiceAreaWaitlists;
use App\Filament\Resources\ServiceAreaWaitlists\Schemas\ServiceAreaWaitlistForm;
use App\Filament\Resources\ServiceAreaWaitlists\Tables\ServiceAreaWaitlistsTable;
use App\Models\ServiceAreaWaitlist;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class ServiceAreaWaitlistResource extends BaseResource
{
    protected static ?string $model = ServiceAreaWaitlist::class;

    protected static ?string $module = 'service_area';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Lista Oczekujących';

    protected static ?string $modelLabel = 'wpis na liście oczekujących';

    protected static ?string $pluralModelLabel = 'lista oczekujących';

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 41;

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'pending')->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return ServiceAreaWaitlistForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiceAreaWaitlistsTable::configure($table);
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
            'index' => ListServiceAreaWaitlists::route('/'),
            // 'create' route removed - waitlist entries created by customers via API only
            'edit' => EditServiceAreaWaitlist::route('/{record}/edit'),
        ];
    }
}
