<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\CarBrand;
use App\Models\VehicleType;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class VehiclesRelationManager extends RelationManager
{
    protected static string $relationship = 'vehicles';

    protected static ?string $title = 'Pojazdy';

    protected static ?string $modelLabel = 'Pojazd';

    protected static ?string $pluralModelLabel = 'Pojazdy';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('vehicle_type_id')
                ->label('Typ pojazdu')
                ->options(VehicleType::active()->ordered()->pluck('name', 'id'))
                ->required(),
            Forms\Components\Select::make('car_brand_id')
                ->label('Marka')
                ->options(CarBrand::where('status', 'active')->orderBy('name')->pluck('name', 'id'))
                ->nullable()
                ->searchable(),
            Forms\Components\TextInput::make('custom_brand')
                ->label('Własna marka')
                ->maxLength(100),
            Forms\Components\TextInput::make('custom_model')
                ->label('Model')
                ->maxLength(100),
            Forms\Components\TextInput::make('year')
                ->label('Rok produkcji')
                ->numeric()
                ->minValue(1900)
                ->maxValue(date('Y') + 1),
            Forms\Components\TextInput::make('nickname')
                ->label('Nazwa własna')
                ->maxLength(50),
            Forms\Components\Toggle::make('is_default')
                ->label('Domyślny')
                ->helperText('Pojazd domyślny będzie automatycznie wybierany przy rezerwacji'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('display_name')
            ->columns([
                Tables\Columns\TextColumn::make('vehicleType.name')
                    ->label('Typ'),
                Tables\Columns\TextColumn::make('brand_name')
                    ->label('Marka'),
                Tables\Columns\TextColumn::make('model_name')
                    ->label('Model'),
                Tables\Columns\TextColumn::make('year')
                    ->label('Rok'),
                Tables\Columns\TextColumn::make('nickname')
                    ->label('Nazwa'),
                Tables\Columns\IconColumn::make('is_default')
                    ->label('Domyślny')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dodany')
                    ->dateTime('d.m.Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Dodaj pojazd'),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Brak pojazdów')
            ->emptyStateDescription('Klient nie ma jeszcze zapisanych pojazdów.');
    }
}
