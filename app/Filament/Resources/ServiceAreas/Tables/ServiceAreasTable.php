<?php

namespace App\Filament\Resources\ServiceAreas\Tables;

use App\Services\ServiceAreaValidator;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ServiceAreasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('city_name')
                    ->label('Miasto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('latitude')
                    ->label('Szerokość')
                    ->numeric(8)
                    ->toggleable(),

                TextColumn::make('longitude')
                    ->label('Długość')
                    ->numeric(8)
                    ->toggleable(),

                TextColumn::make('radius_km')
                    ->label('Promień')
                    ->suffix(' km')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Aktywny')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('waitlistEntries_count')
                    ->label('Lista oczekujących')
                    ->counts('waitlistEntries')
                    ->badge()
                    ->color('warning')
                    ->toggleable(),

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
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),

                    BulkAction::make('activate')
                        ->label('Aktywuj zaznaczone')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $records->each->update(['is_active' => true]);
                            app(ServiceAreaValidator::class)->clearCache();
                        }),

                    BulkAction::make('deactivate')
                        ->label('Dezaktywuj zaznaczone')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $records->each->update(['is_active' => false]);
                            app(ServiceAreaValidator::class)->clearCache();
                        }),
                ]),
            ]);
    }
}
