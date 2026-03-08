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

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string|UnitEnum|null $navigationGroup = 'communication';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Email Logs';

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
                    ->label('Recipient')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->icon('heroicon-o-envelope'),

                Tables\Columns\TextColumn::make('template_key')
                    ->label('Template')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (string $state): string => TemplateKey::tryFrom($state)?->label() ?? $state),

                Tables\Columns\TextColumn::make('subject')
                    ->label('Subject')
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
                    ->icon(fn (string $state): string => match ($state) {
                        'sent' => 'heroicon-o-check-circle',
                        'failed' => 'heroicon-o-x-circle',
                        'bounced' => 'heroicon-o-exclamation-triangle',
                        'pending' => 'heroicon-o-clock',
                        default => 'heroicon-o-question-mark-circle',
                    }),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Sent At')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'sent' => 'Sent',
                        'failed' => 'Failed',
                        'bounced' => 'Bounced',
                    ])
                    ->multiple(),

                Tables\Filters\SelectFilter::make('template_key')
                    ->label('Template')
                    ->options(TemplateKey::optionsForChannel('email'))
                    ->multiple(),

                Tables\Filters\Filter::make('sent_at')
                    ->form([
                        Forms\Components\DatePicker::make('sent_from')
                            ->label('Sent From'),
                        Forms\Components\DatePicker::make('sent_until')
                            ->label('Sent Until'),
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
                    ->label('Resend')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Resend Email')
                    ->modalDescription(fn (EmailSend $record): string => "This will create a new email send record and queue the email to {$record->recipient_email}."
                    )
                    ->modalSubmitActionLabel('Resend Email')
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
                                    ->title('Email resent successfully')
                                    ->body("New email queued to {$record->recipient_email}")
                                    ->send();
                            } else {
                                $newSend->markAsFailed('Failed to send via EmailService');

                                Notification::make()
                                    ->danger()
                                    ->title('Failed to resend email')
                                    ->body('Check email logs for details')
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error resending email')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('export')
                        ->label('Export Selected')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function ($records) {
                            // Export to CSV
                            $filename = 'email-logs-'.now()->format('Y-m-d-His').'.csv';
                            $headers = [
                                'Content-Type' => 'text/csv',
                                'Content-Disposition' => "attachment; filename=\"$filename\"",
                            ];

                            $callback = function () use ($records) {
                                $file = fopen('php://output', 'w');
                                fputcsv($file, ['ID', 'Template', 'Recipient', 'Subject', 'Status', 'Sent At', 'Created At']);

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
                Infolists\Components\Section::make('Email Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('recipient_email')
                            ->label('Recipient')
                            ->icon('heroicon-o-envelope')
                            ->copyable(),

                        Infolists\Components\TextEntry::make('template_key')
                            ->label('Template Key')
                            ->badge()
                            ->color('info'),

                        Infolists\Components\TextEntry::make('language')
                            ->label('Language')
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
                            }),

                        Infolists\Components\TextEntry::make('sent_at')
                            ->label('Sent At')
                            ->dateTime('Y-m-d H:i:s'),

                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Created At')
                            ->dateTime('Y-m-d H:i:s'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Email Content')
                    ->schema([
                        Infolists\Components\TextEntry::make('subject')
                            ->label('Subject Line')
                            ->columnSpanFull(),

                        Infolists\Components\ViewEntry::make('body_html')
                            ->label('HTML Body')
                            ->view('filament.resources.email-send.html-preview')
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('body_text')
                            ->label('Plain Text Body')
                            ->placeholder('No plain text version')
                            ->columnSpanFull()
                            ->visible(fn (EmailSend $record): bool => ! empty($record->body_text)),
                    ]),

                Infolists\Components\Section::make('Metadata')
                    ->schema([
                        Infolists\Components\TextEntry::make('metadata')
                            ->label('Additional Data')
                            ->placeholder('No metadata')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                            ->markdown()
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('error_message')
                            ->label('Error Message')
                            ->placeholder('No errors')
                            ->color('danger')
                            ->columnSpanFull()
                            ->visible(fn (EmailSend $record): bool => ! empty($record->error_message)),
                    ])
                    ->collapsed(),

                Infolists\Components\Section::make('Related Events')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('emailEvents')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('event_type')
                                    ->label('Event')
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
                                    ->label('Occurred At')
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
        return auth()->user()?->can('view email logs') ?? false;
    }

    public static function canView($record): bool
    {
        return auth()->user()?->can('view email logs') ?? false;
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
