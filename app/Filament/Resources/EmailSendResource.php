<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\TemplateKey;
use App\Filament\Resources\EmailSendResource\Pages;
use App\Models\EmailSend;
use App\Services\Email\EmailService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EmailSendResource extends BaseResource
{
    protected static ?string $model = EmailSend::class;

    protected static ?string $module = 'communication';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string|UnitEnum|null $navigationGroup = 'communication';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Historia Email';

    protected static ?string $modelLabel = 'Wysłany Email';

    protected static ?string $pluralModelLabel = 'Historia Email';

    public static function form(Schema $schema): Schema
    {
        // Read-only resource - no create/edit forms
        return $schema->components([
            //
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('recipient_email')
                    ->label('Odbiorca')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->icon('heroicon-o-envelope'),

                Tables\Columns\TextColumn::make('template_key')
                    ->label('Szablon')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (string $state): string => TemplateKey::tryFrom($state)?->label() ?? $state),

                Tables\Columns\TextColumn::make('subject')
                    ->label('Temat')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(function (EmailSend $record): string {
                        return $record->subject;
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'bounced' => 'warning',
                        'pending' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sent' => 'Wysłano',
                        'failed' => 'Błąd',
                        'bounced' => 'Odrzucono',
                        'pending' => 'Oczekuje',
                        default => $state,
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'sent' => 'heroicon-o-check-circle',
                        'failed' => 'heroicon-o-x-circle',
                        'bounced' => 'heroicon-o-exclamation-triangle',
                        'pending' => 'heroicon-o-clock',
                        default => 'heroicon-o-question-mark-circle',
                    }),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Wysłano o')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Oczekuje',
                        'sent' => 'Wysłano',
                        'failed' => 'Błąd',
                        'bounced' => 'Odrzucono',
                    ])
                    ->multiple(),

                Tables\Filters\SelectFilter::make('template_key')
                    ->label('Szablon')
                    ->options(TemplateKey::optionsForChannel('email'))
                    ->multiple(),

                Tables\Filters\Filter::make('sent_at')
                    ->form([
                        Forms\Components\DatePicker::make('sent_from')
                            ->label('Wysłano od'),
                        Forms\Components\DatePicker::make('sent_until')
                            ->label('Wysłano do'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['sent_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('sent_at', '>=', $date),
                            )
                            ->when(
                                $data['sent_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('sent_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                Actions\ViewAction::make(),

                Actions\Action::make('resend')
                    ->label('Wyślij ponownie')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Wyślij e-mail ponownie')
                    ->modalDescription(fn (EmailSend $record): string => "Zostanie utworzony nowy wpis wysyłki, a e-mail trafi do kolejki do adresu {$record->recipient_email}."
                    )
                    ->modalSubmitActionLabel('Wyślij ponownie')
                    ->action(function (EmailSend $record): void {
                        try {
                            // Create new EmailSend record with same data
                            $newSend = EmailSend::create([
                                'template_key' => $record->template_key,
                                'language' => $record->language,
                                'recipient_email' => $record->recipient_email,
                                'subject' => $record->subject,
                                'body_html' => $record->body_html,
                                'body_text' => $record->body_text,
                                'status' => 'pending',
                                'metadata' => array_merge($record->metadata ?? [], ['resent_from' => $record->id]),
                                'message_key' => 'resend-'.uniqid(),
                            ]);

                            // Dispatch to queue via EmailService
                            $emailService = app(EmailService::class);
                            $result = $emailService->sendEmail(
                                $newSend->recipient_email,
                                $newSend->subject,
                                $newSend->body_html,
                                $newSend->body_text
                            );

                            if ($result) {
                                $newSend->markAsSent();

                                Notification::make()
                                    ->success()
                                    ->title('E-mail wysłany ponownie')
                                    ->body("Nowy e-mail trafił do kolejki do adresu {$record->recipient_email}")
                                    ->send();
                            } else {
                                $newSend->markAsFailed('Nie udało się wysłać przez EmailService');

                                Notification::make()
                                    ->danger()
                                    ->title('Nie udało się wysłać e-maila ponownie')
                                    ->body('Sprawdź logi e-maili, aby uzyskać szczegóły')
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Błąd podczas ponownej wysyłki e-maila')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('export')
                        ->label('Eksportuj zaznaczone')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function ($records) {
                            // Export to CSV
                            $filename = 'historia-email-'.now()->format('Y-m-d-His').'.csv';
                            $headers = [
                                'Content-Type' => 'text/csv',
                                'Content-Disposition' => "attachment; filename=\"$filename\"",
                            ];

                            $callback = function () use ($records) {
                                $file = fopen('php://output', 'w');
                                fputcsv($file, ['ID', 'Szablon', 'Odbiorca', 'Temat', 'Status', 'Wysłano o', 'Utworzono']);

                                foreach ($records as $record) {
                                    fputcsv($file, [
                                        $record->id,
                                        $record->template_key,
                                        $record->recipient_email,
                                        $record->subject,
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
                        Infolists\Components\TextEntry::make('recipient_email')
                            ->label('Odbiorca')
                            ->icon('heroicon-o-envelope')
                            ->copyable(),

                        Infolists\Components\TextEntry::make('template_key')
                            ->label('Klucz szablonu')
                            ->badge()
                            ->color('info'),

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
                                'failed' => 'danger',
                                'bounced' => 'warning',
                                'pending' => 'gray',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'sent' => 'Wysłano',
                                'failed' => 'Błąd',
                                'bounced' => 'Odrzucono',
                                'pending' => 'Oczekuje',
                                default => $state,
                            }),

                        Infolists\Components\TextEntry::make('sent_at')
                            ->label('Wysłano o')
                            ->dateTime('Y-m-d H:i:s'),

                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Utworzono')
                            ->dateTime('Y-m-d H:i:s'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Treść e-maila')
                    ->schema([
                        Infolists\Components\TextEntry::make('subject')
                            ->label('Temat wiadomości')
                            ->columnSpanFull(),

                        Infolists\Components\ViewEntry::make('body_html')
                            ->label('Treść HTML')
                            ->view('filament.resources.email-send.html-preview')
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('body_text')
                            ->label('Treść tekstowa')
                            ->placeholder('Brak wersji tekstowej')
                            ->columnSpanFull()
                            ->visible(fn (EmailSend $record): bool => ! empty($record->body_text)),
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
                            ->visible(fn (EmailSend $record): bool => ! empty($record->error_message)),
                    ])
                    ->collapsed(),

                Infolists\Components\Section::make('Powiązane zdarzenia')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('emailEvents')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('event_type')
                                    ->label('Zdarzenie')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'sent' => 'success',
                                        'delivered' => 'success',
                                        'bounced' => 'danger',
                                        'complained' => 'warning',
                                        'opened' => 'info',
                                        'clicked' => 'primary',
                                        default => 'gray',
                                    })
                                    ->icon(fn (string $state): string => match ($state) {
                                        'sent' => 'heroicon-o-paper-airplane',
                                        'delivered' => 'heroicon-o-check-circle',
                                        'bounced' => 'heroicon-o-x-circle',
                                        'complained' => 'heroicon-o-exclamation-triangle',
                                        'opened' => 'heroicon-o-eye',
                                        'clicked' => 'heroicon-o-cursor-arrow-rays',
                                        default => 'heroicon-o-information-circle',
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
            'index' => Pages\ListEmailSends::route('/'),
            'view' => Pages\ViewEmailSend::route('/{record}'),
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
