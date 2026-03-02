<?php

namespace App\Filament\Resources\ServiceAreaWaitlists\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ServiceAreaWaitlistsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Email skopiowany!')
                    ->weight('bold'),

                TextColumn::make('name')
                    ->label('Imię')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('requested_address')
                    ->label('Żądany adres')
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->requested_address),

                TextColumn::make('nearest_area_city')
                    ->label('Najbliższe miasto')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('distance_to_nearest_area_km')
                    ->label('Odległość')
                    ->numeric(2)
                    ->suffix(' km')
                    ->sortable(),

                SelectColumn::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Oczekujące',
                        'contacted' => 'Skontaktowano',
                        'area_added' => 'Strefa dodana',
                        'declined' => 'Odrzucone',
                    ])
                    ->selectablePlaceholder(false)
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Data zgłoszenia')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('notified_at')
                    ->label('Data powiadomienia')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('phone')
                    ->label('Telefon')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Ostatnia zmiana')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Oczekujące',
                        'contacted' => 'Skontaktowano',
                        'area_added' => 'Strefa dodana',
                        'declined' => 'Odrzucone',
                    ])
                    ->default('pending')
                    ->placeholder('Wszystkie statusy'),

                SelectFilter::make('nearest_area_city')
                    ->label('Najbliższe miasto')
                    ->options(fn () => \App\Models\ServiceAreaWaitlist::query()
                        ->distinct()
                        ->whereNotNull('nearest_area_city')
                        ->pluck('nearest_area_city', 'nearest_area_city')
                        ->toArray()
                    )
                    ->placeholder('Wszystkie miasta'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
