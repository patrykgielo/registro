<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\TemplateKey;
use App\Filament\Resources\SmsSendResource\Pages;
use App\Models\SmsSend;
use BackedEnum;
use Filament\Actions;
use Filament\Infolists;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class SmsSendResource extends BaseResource
{
    protected static ?string $model = SmsSend::class;

    protected static ?string $module = 'communication';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static string|UnitEnum|null $navigationGroup = 'communication';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Historia SMS';

    protected static ?string $modelLabel = 'Wysłany SMS';

    protected static ?string $pluralModelLabel = 'Historia SMS';

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
                    ->label('Szablon')
                    ->badge()
                    ->searchable()
                    ->sortable()
                    ->color('info')
                    ->formatStateUsing(fn (string $state): string => TemplateKey::tryFrom($state)?->label() ?? $state),

                Tables\Columns\TextColumn::make('phone_to')
                    ->label('Telefon')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('message_body')
                    ->label('Wiadomość')
                    ->limit(50)
                    ->tooltip(fn (SmsSend $record): string => $record->message_body),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        'invalid_number' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sent' => 'Wysłano',
                        'pending' => 'Oczekuje',
                        'failed' => 'Błąd',
                        'invalid_number' => 'Nieprawidłowy numer',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('message_length')
                    ->label('Długość')
                    ->suffix(' znaków'),

                Tables\Columns\TextColumn::make('message_parts')
                    ->label('Części')
                    ->badge(),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Wysłano o')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'sent' => 'Wysłano',
                        'pending' => 'Oczekuje',
                        'failed' => 'Błąd',
                        'invalid_number' => 'Nieprawidłowy numer',
                    ]),

                Tables\Filters\SelectFilter::make('template_key')
                    ->label('Szablon')
                    ->options(TemplateKey::optionsForChannel('sms')),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('export')
                        ->label('Eksportuj zaznaczone')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function ($records) {
                            $filename = 'historia-sms-'.now()->format('Y-m-d-His').'.csv';
                            $headers = [
                                'Content-Type' => 'text/csv',
                                'Content-Disposition' => "attachment; filename=\"$filename\"",
                            ];

                            $callback = function () use ($records) {
                                $file = fopen('php://output', 'w');
                                fputcsv($file, ['ID', 'Szablon', 'Telefon', 'Status', 'Wysłano o', 'Utworzono']);

                                foreach ($records as $record) {
                                    fputcsv($file, [
                                        $record->id,
                                        $record->template_key,
                                        $record->phone_to,
                                        $record->status,
                                        $record->sent_at?->format('Y-m-d H:i:s'),
                                        $record->created_at->format('Y-m-d H:i:s'),
                                    ]);
                                }

                                fclose($file);
                            };

                            return response()->stream($callback, 200, $headers);
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s'); // Auto-refresh every 30 seconds
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Infolists\Components\Section::make('Szczegóły wysyłki')
                    ->schema([
                        Infolists\Components\TextEntry::make('phone_to')
                            ->label('Telefon')
                            ->icon('heroicon-o-device-phone-mobile')
                            ->copyable(),

                        Infolists\Components\TextEntry::make('template_key')
                            ->label('Klucz szablonu')
                            ->badge()
                            ->color('info')
                            ->formatStateUsing(fn (string $state): string => TemplateKey::tryFrom($state)?->label() ?? $state),

                        Infolists\Components\TextEntry::make('language')
                            ->label('Język')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pl' => 'success',
                                'en' => 'info',
                                default => 'gray',
                            }),

                        Infolists\Components\TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'sent' => 'success',
                                'pending' => 'warning',
                                'failed' => 'danger',
                                'invalid_number' => 'gray',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'sent' => 'Wysłano',
                                'pending' => 'Oczekuje',
                                'failed' => 'Błąd',
                                'invalid_number' => 'Nieprawidłowy numer',
                                default => $state,
                            }),

                        Infolists\Components\TextEntry::make('message_length')
                            ->label('Długość wiadomości')
                            ->suffix(' znaków'),

                        Infolists\Components\TextEntry::make('message_parts')
                            ->label('Liczba części')
                            ->badge(),

                        Infolists\Components\TextEntry::make('sent_at')
                            ->label('Wysłano o')
                            ->dateTime('Y-m-d H:i:s'),

                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Utworzono')
                            ->dateTime('Y-m-d H:i:s'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Treść SMS')
                    ->schema([
                        Infolists\Components\TextEntry::make('message_body')
                            ->label('Treść wiadomości')
                            ->columnSpanFull(),
                    ]),

                Infolists\Components\Section::make('Metadane')
                    ->schema([
                        Infolists\Components\TextEntry::make('metadata')
                            ->label('Dodatkowe dane')
                            ->placeholder('Brak metadanych')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                            ->markdown()
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('error_message')
                            ->label('Komunikat błędu')
                            ->placeholder('Brak błędów')
                            ->color('danger')
                            ->columnSpanFull()
                            ->visible(fn (SmsSend $record): bool => ! empty($record->error_message)),
                    ])
                    ->collapsed(),

                Infolists\Components\Section::make('Powiązane zdarzenia')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('smsEvents')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('event_type')
                                    ->label('Zdarzenie')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'sent' => 'info',
                                        'delivered' => 'success',
                                        'failed' => 'danger',
                                        'invalid_number' => 'warning',
                                        'expired' => 'gray',
                                        default => 'gray',
                                    }),

                                Infolists\Components\TextEntry::make('occurred_at')
                                    ->label('Data zdarzenia')
                                    ->dateTime('Y-m-d H:i:s'),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
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
            'index' => Pages\ListSmsSends::route('/'),
            'view' => Pages\ViewSmsSend::route('/{record}'),
        ];
    }

    /**
     * Check if user can access this resource
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('communication.view_logs') ?? false;
    }

    public static function canView($record): bool
    {
        return auth()->user()?->can('communication.view_logs') ?? false;
    }

    public static function canCreate(): bool
    {
        return false; // Read-only resource
    }

    public static function canEdit($record): bool
    {
        return false; // Read-only resource
    }

    public static function canDelete($record): bool
    {
        return false; // Read-only resource
    }
}
