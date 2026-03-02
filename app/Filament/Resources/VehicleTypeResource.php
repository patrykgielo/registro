<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleTypeResource\Pages;
use App\Models\VehicleType;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class VehicleTypeResource extends Resource
{
    protected static ?string $model = VehicleType::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Typy Pojazdów';

    protected static ?string $modelLabel = 'Typ Pojazdu';

    protected static ?string $pluralModelLabel = 'Typy Pojazdów';

    protected static string|UnitEnum|null $navigationGroup = 'vehicles';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->label('Nazwa')
                ->required()
                ->maxLength(100),
            Forms\Components\TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->maxLength(100)
                ->unique(ignoreRecord: true)
                ->helperText('Unikalny identyfikator (np. city_car)'),
            Forms\Components\Textarea::make('description')
                ->label('Opis')
                ->rows(3)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('examples')
                ->label('Przykłady')
                ->maxLength(500)
                ->helperText('Lista przykładowych pojazdów (np. Toyota Aygo, Fiat 500...)')
                ->columnSpanFull(),
            Forms\Components\TextInput::make('sort_order')
                ->label('Kolejność')
                ->required()
                ->numeric()
                ->default(0)
                ->helperText('Kolejność wyświetlania (niższe = wyżej)'),
            Forms\Components\Toggle::make('is_active')
                ->label('Aktywny')
                ->default(true)
                ->required(),
        ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Kolejność')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nazwa')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('examples')
                    ->label('Przykłady')
                    ->limit(50)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktywny')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Aktywny')
                    ->placeholder('Wszystkie')
                    ->trueLabel('Tylko aktywne')
                    ->falseLabel('Tylko nieaktywne'),
            ])
            ->recordActions([
                Actions\EditAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListVehicleTypes::route('/'),
            'edit' => Pages\EditVehicleType::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // Types are seeded, not created manually
    }

    /**
     * Restrict access to admins and super-admins only.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }
}
