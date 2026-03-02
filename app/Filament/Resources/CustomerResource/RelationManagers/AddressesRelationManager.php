<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    protected static ?string $title = 'Adresy';

    protected static ?string $modelLabel = 'Adres';

    protected static ?string $pluralModelLabel = 'Adresy';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('address')
                ->label('Adres')
                ->required()
                ->maxLength(500)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('latitude')
                ->label('Szerokość geograficzna')
                ->required()
                ->numeric(),
            Forms\Components\TextInput::make('longitude')
                ->label('Długość geograficzna')
                ->required()
                ->numeric(),
            Forms\Components\TextInput::make('place_id')
                ->label('Google Place ID')
                ->maxLength(255),
            Forms\Components\TextInput::make('nickname')
                ->label('Nazwa własna')
                ->maxLength(50)
                ->placeholder('np. Dom, Praca'),
            Forms\Components\Toggle::make('is_default')
                ->label('Domyślny')
                ->helperText('Adres domyślny będzie automatycznie wybierany przy rezerwacji usług mobilnych'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('display_name')
            ->columns([
                Tables\Columns\TextColumn::make('nickname')
                    ->label('Nazwa')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('short_address')
                    ->label('Adres')
                    ->limit(50),
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
                    ->label('Dodaj adres'),
            ])
            ->recordActions([
                Actions\Action::make('view_map')
                    ->label('Mapa')
                    ->icon('heroicon-o-map')
                    ->url(fn ($record) => $record->google_maps_link)
                    ->openUrlInNewTab(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Brak adresów')
            ->emptyStateDescription('Klient nie ma jeszcze zapisanych adresów.');
    }
}
