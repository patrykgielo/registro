<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RentalCategoryResource\Pages;
use App\Models\RentalCategory;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class RentalCategoryResource extends BaseResource
{
    protected static ?string $model = RentalCategory::class;

    protected static ?string $module = 'rentals';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|UnitEnum|null $navigationGroup = 'rentals';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Kategoria wypożyczeń';

    protected static ?string $pluralModelLabel = 'Kategorie wypożyczeń';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->label('Nazwa')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (string $state, callable $set) {
                    $set('slug', Str::slug($state));
                }),

            Forms\Components\TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->helperText('Automatycznie generowany z nazwy'),

            Forms\Components\Textarea::make('description')
                ->label('Opis')
                ->rows(3)
                ->columnSpanFull(),

            Forms\Components\Select::make('icon')
                ->label('Ikona')
                ->options(fn () => self::getHeroiconOptions())
                ->searchable(),

            Forms\Components\TextInput::make('sort_order')
                ->label('Kolejność')
                ->required()
                ->numeric()
                ->default(0)
                ->helperText('Niższe wartości = wyżej na liście'),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktywna')
                ->default(true),
        ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nazwa')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('rental_items_count')
                    ->counts('rentalItems')
                    ->label('Przedmioty'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktywna')
                    ->boolean(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Kolejność')
                    ->numeric()
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRentalCategories::route('/'),
            'create' => Pages\CreateRentalCategory::route('/create'),
            'edit' => Pages\EditRentalCategory::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }
}
