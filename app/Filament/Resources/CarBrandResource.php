<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CarBrandResource\Pages;
use App\Models\CarBrand;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class CarBrandResource extends Resource
{
    protected static ?string $model = CarBrand::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationLabel = 'Marki';

    protected static ?string $modelLabel = 'Marka';

    protected static ?string $pluralModelLabel = 'Marki';

    protected static string|UnitEnum|null $navigationGroup = 'vehicles';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->label('Nazwa marki')
                ->required()
                ->maxLength(100)
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, callable $set) => $set('slug', \Illuminate\Support\Str::slug($state))),
            Forms\Components\TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->maxLength(100)
                ->unique(ignoreRecord: true)
                ->helperText('Automatycznie generowany z nazwy'),
            Forms\Components\Select::make('status')
                ->label('Status')
                ->options([
                    'pending' => 'Oczekująca',
                    'active' => 'Aktywna',
                    'inactive' => 'Nieaktywna',
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
                Tables\Columns\TextColumn::make('name')
                    ->label('Nazwa')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('models_count')
                    ->label('Liczba modeli')
                    ->counts('models')
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'active',
                        'danger' => 'inactive',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Oczekująca',
                        'active' => 'Aktywna',
                        'inactive' => 'Nieaktywna',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Oczekująca',
                        'active' => 'Aktywna',
                        'inactive' => 'Nieaktywna',
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
            'index' => Pages\ListCarBrands::route('/'),
            'create' => Pages\CreateCarBrand::route('/create'),
            'edit' => Pages\EditCarBrand::route('/{record}/edit'),
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
