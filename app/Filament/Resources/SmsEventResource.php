<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SmsEventResource\Pages;
use App\Models\SmsEvent;
use BackedEnum;
use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class SmsEventResource extends Resource
{
    protected static ?string $model = SmsEvent::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string|UnitEnum|null $navigationGroup = 'communication';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'SMS Events';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // Read-only resource
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('smsSend.phone_to')
                    ->label('Phone')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('event_type')
                    ->label('Event')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'info',
                        'delivered' => 'success',
                        'failed' => 'danger',
                        'invalid_number' => 'warning',
                        'expired' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('Occurred At')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event_type')
                    ->label('Event Type')
                    ->options([
                        'sent' => 'Sent',
                        'delivered' => 'Delivered',
                        'failed' => 'Failed',
                        'invalid_number' => 'Invalid Number',
                        'expired' => 'Expired',
                    ]),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false; // Read-only resource (populated by webhooks)
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSmsEvents::route('/'),
            'view' => Pages\ViewSmsEvent::route('/{record}'),
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
