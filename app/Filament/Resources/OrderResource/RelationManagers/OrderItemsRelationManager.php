<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class OrderItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Pozycje zamówienia';

    protected static ?string $modelLabel = 'Pozycja';

    protected static ?string $pluralModelLabel = 'Pozycje zamówienia';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('service_name')
                    ->label('Usługa')
                    ->searchable(),

                Tables\Columns\TextColumn::make('start_date')
                    ->label('Data od')
                    ->date('d.m.Y'),

                Tables\Columns\TextColumn::make('end_date')
                    ->label('Data do')
                    ->date('d.m.Y'),

                Tables\Columns\TextColumn::make('rental_days')
                    ->label('Dni')
                    ->numeric()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Ilość')
                    ->numeric()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('unit_price')
                    ->label('Cena/dzień')
                    ->money('PLN')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('total_price')
                    ->label('Razem')
                    ->money('PLN')
                    ->alignEnd()
                    ->weight('bold'),
            ])
            ->filters([])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Brak pozycji')
            ->emptyStateDescription('To zamówienie nie zawiera żadnych pozycji.');
    }

    public function canCreate(): bool
    {
        return false;
    }

    public function canEdit($record): bool
    {
        return false;
    }

    public function canDelete($record): bool
    {
        return false;
    }
}
