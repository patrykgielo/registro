<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\EmailEventResource\Pages;
use App\Models\EmailEvent;
use App\Models\EmailSuppression;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EmailEventResource extends Resource
{
    protected static ?string $model = EmailEvent::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|UnitEnum|null $navigationGroup = 'communication';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Email Events';

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
                Tables\Columns\TextColumn::make('emailSend.recipient_email')
                    ->label('Recipient')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->icon('heroicon-o-envelope'),

                Tables\Columns\TextColumn::make('event_type')
                    ->label('Event Type')
                    ->sortable()
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

                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('Occurred At')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('event_data')
                    ->label('Event Data')
                    ->limit(50)
                    ->tooltip(function (EmailEvent $record): ?string {
                        if (empty($record->event_data)) {
                            return null;
                        }

                        return json_encode($record->event_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    })
                    ->formatStateUsing(function ($state): string {
                        if (empty($state)) {
                            return '-';
                        }
                        $json = is_array($state) ? json_encode($state) : $state;

                        return \Str::limit($json, 50);
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Recorded At')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event_type')
                    ->label('Event Type')
                    ->options([
                        'sent' => 'Sent',
                        'delivered' => 'Delivered',
                        'bounced' => 'Bounced',
                        'complained' => 'Complained',
                        'opened' => 'Opened',
                        'clicked' => 'Clicked',
                    ])
                    ->multiple(),

                Tables\Filters\Filter::make('occurred_at')
                    ->form([
                        Forms\Components\DatePicker::make('occurred_from')
                            ->label('Occurred From'),
                        Forms\Components\DatePicker::make('occurred_until')
                            ->label('Occurred Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['occurred_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('occurred_at', '>=', $date),
                            )
                            ->when(
                                $data['occurred_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('occurred_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                Actions\Action::make('viewEmail')
                    ->label('View Email')
                    ->icon('heroicon-o-envelope-open')
                    ->color('info')
                    ->url(fn (EmailEvent $record): string => route('filament.admin.resources.email-sends.view', ['record' => $record->email_send_id])
                    )
                    ->openUrlInNewTab(false),

                Actions\Action::make('addToSuppression')
                    ->label('Suppress')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (EmailEvent $record): bool => in_array($record->event_type, ['bounced', 'complained'])
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Add Email to Suppression List')
                    ->modalDescription(fn (EmailEvent $record): string => "This will prevent future emails from being sent to {$record->emailSend->recipient_email}. ".
                        'This action can be reversed from the Email Suppressions page.'
                    )
                    ->modalSubmitActionLabel('Add to Suppression List')
                    ->action(function (EmailEvent $record): void {
                        try {
                            $email = $record->emailSend->recipient_email;
                            $reason = $record->event_type; // 'bounced' or 'complained'

                            // Check if already suppressed
                            if (EmailSuppression::isSuppressed($email)) {
                                Notification::make()
                                    ->warning()
                                    ->title('Email already suppressed')
                                    ->body("The email {$email} is already in the suppression list.")
                                    ->send();

                                return;
                            }

                            // Add to suppression list
                            EmailSuppression::suppress($email, $reason);

                            Notification::make()
                                ->success()
                                ->title('Email suppressed')
                                ->body("The email {$email} has been added to the suppression list.")
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error suppressing email')
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
                            $filename = 'email-events-'.now()->format('Y-m-d-His').'.csv';
                            $headers = [
                                'Content-Type' => 'text/csv',
                                'Content-Disposition' => "attachment; filename=\"$filename\"",
                            ];

                            $callback = function () use ($records) {
                                $file = fopen('php://output', 'w');
                                fputcsv($file, ['ID', 'Email Send ID', 'Recipient', 'Event Type', 'Occurred At', 'Created At']);

                                foreach ($records as $record) {
                                    fputcsv($file, [
                                        $record->id,
                                        $record->email_send_id,
                                        $record->emailSend->recipient_email ?? 'N/A',
                                        $record->event_type,
                                        $record->occurred_at->format('Y-m-d H:i:s'),
                                        $record->created_at->format('Y-m-d H:i:s'),
                                    ]);
                                }

                                fclose($file);
                            };

                            return response()->stream($callback, 200, $headers);
                        }),
                ]),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->poll('60s'); // Auto-refresh every 60 seconds
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
            'index' => Pages\ListEmailEvents::route('/'),
        ];
    }

    /**
     * Check if user can access this resource
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view email events') ?? false;
    }

    public static function canView($record): bool
    {
        return auth()->user()?->can('view email events') ?? false;
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
