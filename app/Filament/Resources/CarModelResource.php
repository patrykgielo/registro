<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CarModelResource\Pages;
use App\Models\CarModel;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class CarModelResource extends Resource
{
    protected static ?string $model = CarModel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Modele';

    protected static ?string $modelLabel = 'Model';

    protected static ?string $pluralModelLabel = 'Modele';

    protected static string|UnitEnum|null $navigationGroup = 'vehicles';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('car_brand_id')
                ->label('Marka')
                ->relationship('brand', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->createOptionForm([
                    Forms\Components\TextInput::make('name')
                        ->label('Nazwa marki')
                        ->required()
                        ->maxLength(100)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, callable $set) => $set('slug', \Illuminate\Support\Str::slug($state))),
                    Forms\Components\TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->maxLength(100),
                ]),
            Forms\Components\TextInput::make('name')
                ->label('Nazwa modelu')
                ->required()
                ->maxLength(100)
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, callable $set) => $set('slug', \Illuminate\Support\Str::slug($state))),
            Forms\Components\TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->maxLength(100)
                ->helperText('Automatycznie generowany z nazwy'),
            Forms\Components\CheckboxList::make('vehicleTypes')
                ->label('Typy pojazdów')
                ->relationship('vehicleTypes', 'name')
                ->columns(2)
                ->helperText('Wybierz do jakich typów należy ten model')
                ->columnSpanFull(),
            Forms\Components\TextInput::make('year_from')
                ->label('Rok od')
                ->numeric()
                ->minValue(1990)
                ->maxValue(date('Y'))
                ->helperText('Opcjonalnie - pierwszy rok produkcji'),
            Forms\Components\TextInput::make('year_to')
                ->label('Rok do')
                ->numeric()
                ->minValue(1990)
                ->maxValue(date('Y') + 1)
                ->helperText('Opcjonalnie - ostatni rok produkcji'),
            Forms\Components\Select::make('status')
                ->label('Status')
                ->options([
                    'pending' => 'Oczekujący',
                    'active' => 'Aktywny',
                    'inactive' => 'Nieaktywny',
                ])
                ->default('active')
                ->required()
                ->native(false),
        ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('brand.name')
                    ->label('Marka')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Model')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vehicleTypes.name')
                    ->label('Typy')
                    ->badge()
                    ->separator(','),
                Tables\Columns\TextColumn::make('year_from')
                    ->label('Rok od')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('year_to')
                    ->label('Rok do')
                    ->toggleable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'active',
                        'danger' => 'inactive',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Oczekujący',
                        'active' => 'Aktywny',
                        'inactive' => 'Nieaktywny',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('brand.name')
            ->filters([
                Tables\Filters\SelectFilter::make('brand')
                    ->label('Marka')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('vehicleTypes')
                    ->label('Typ pojazdu')
                    ->relationship('vehicleTypes', 'name')
                    ->multiple()
                    ->preload(),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Oczekujący',
                        'active' => 'Aktywny',
                        'inactive' => 'Nieaktywny',
                    ]),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                    Actions\BulkAction::make('approve')
                        ->label('Zatwierdź zaznaczone')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['status' => 'active'])),
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
            'index' => Pages\ListCarModels::route('/'),
            'create' => Pages\CreateCarModel::route('/create'),
            'edit' => Pages\EditCarModel::route('/{record}/edit'),
        ];
    }

    /**
     * Restrict access to admins and super-admins only.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }
}
