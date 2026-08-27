<?php

declare(strict_types=1);

namespace App\Filament\Resources\Locations\Tables;

use App\Filament\Resources\Locations\LocationResource;
use App\Models\Location;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('photo')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(url('/images/placeholder.jpg'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('name')
                    ->label('Nazwa')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('city')
                    ->label('Miasto')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('phone')
                    ->label('Telefon')
                    ->toggleable(),

                IconColumn::make('primary_slot')
                    ->label('Główna')
                    ->getStateUsing(fn (Location $record): bool => (int) $record->primary_slot === 1)
                    ->boolean()
                    ->trueIcon('heroicon-s-star')
                    ->falseIcon('heroicon-o-star')
                    ->trueColor('warning')
                    ->falseColor('gray'),

                IconColumn::make('is_active')
                    ->label('Aktywna')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Kolejność')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Aktywne')
                    ->placeholder('Wszystkie')
                    ->trueLabel('Tylko aktywne')
                    ->falseLabel('Tylko nieaktywne'),
            ])
            ->recordActions([
                Action::make('promoteToPrimary')
                    ->label('Ustaw jako główną')
                    ->icon('heroicon-o-star')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Location $record): bool => (int) $record->primary_slot !== 1)
                    ->action(function (Location $record): void {
                        Location::promoteToPrimary($record);

                        Notification::make()
                            ->title('Lokalizacja ustawiona jako główna')
                            ->success()
                            ->send();
                    }),

                EditAction::make(),

                DeleteAction::make()
                    ->before(fn (Location $record, DeleteAction $action) => LocationResource::guardDeletion($record, $action)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        // Same LocationResource::guardDeletion() the row DeleteAction
                        // uses, called once per selected record instead of
                        // re-implementing the "last location" / "primary" checks here.
                        // Deleting the whole org's location set always includes its
                        // primary row (exactly one always exists — see
                        // tryb-jednooddzialowy.md), so the primary check alone already
                        // blocks a "delete everything" selection; no separate
                        // count-vs-total check is needed.
                        ->before(function (Collection $records, DeleteBulkAction $action): void {
                            foreach ($records as $record) {
                                LocationResource::guardDeletion($record, $action);
                            }
                        }),
                ]),
            ]);
    }
}
