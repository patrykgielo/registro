<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\TemplateKey;
use App\Filament\Resources\SmsSendResource\Pages;
use App\Models\SmsSend;
use BackedEnum;
use Filament\Actions;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class SmsSendResource extends BaseResource
{
    protected static ?string $model = SmsSend::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static string|UnitEnum|null $navigationGroup = 'communication';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'SMS History';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // Read-only resource, no forms needed
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('template_key')
                    ->label('Template')
                    ->badge()
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => TemplateKey::tryFrom($state)?->label() ?? $state),

                Tables\Columns\TextColumn::make('phone_to')
                    ->label('Phone')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('message_body')
                    ->label('Message')
                    ->limit(50)
                    ->tooltip(fn (SmsSend $record): string => $record->message_body),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        'invalid_number' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('message_length')
                    ->label('Length')
                    ->suffix(' chars'),

                Tables\Columns\TextColumn::make('message_parts')
                    ->label('Parts')
                    ->badge(),

                Tables\Columns\TextColumn::make('sent_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'sent' => 'Sent',
                        'pending' => 'Pending',
                        'failed' => 'Failed',
                        'invalid_number' => 'Invalid Number',
                    ]),

                Tables\Filters\SelectFilter::make('template_key')
                    ->label('Template')
                    ->options(TemplateKey::optionsForChannel('sms')),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false; // Read-only resource
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSmsSends::route('/'),
            'view' => Pages\ViewSmsSend::route('/{record}'),
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
