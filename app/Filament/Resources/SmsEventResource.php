<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SmsEventResource\Pages;
use App\Models\SmsEvent;
use App\Models\SmsSuppression;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SmsEventResource extends BaseResource
{
    protected static ?string $model = SmsEvent::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string|UnitEnum|null $navigationGroup = 'communication';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Zdarzenia SMS';

    protected static ?string $modelLabel = 'Zdarzenie SMS';

    protected static ?string $pluralModelLabel = 'Zdarzenia SMS';

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
                    ->label('Telefon')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('event_type')
                    ->label('Zdarzenie')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'info',
                        'delivered' => 'success',
                        'failed' => 'danger',
                        'invalid_number' => 'warning',
                        'expired' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sent' => 'Wysłano',
                        'delivered' => 'Dostarczono',
                        'failed' => 'Błąd',
                        'invalid_number' => 'Nieprawidłowy numer',
                        'expired' => 'Wygasło',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('Data zdarzenia')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Zarejestrowano')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event_type')
                    ->label('Typ zdarzenia')
                    ->options([
                        'sent' => 'Wysłano',
                        'delivered' => 'Dostarczono',
                        'failed' => 'Błąd',
                        'invalid_number' => 'Nieprawidłowy numer',
                        'expired' => 'Wygasło',
                    ]),

                Tables\Filters\Filter::make('occurred_at')
                    ->form([
                        Forms\Components\DatePicker::make('occurred_from')
                            ->label('Od'),
                        Forms\Components\DatePicker::make('occurred_until')
                            ->label('Do'),
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
                Actions\Action::make('viewSms')
                    ->label('Zobacz SMS')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('info')
                    ->url(fn (SmsEvent $record): string => route('filament.admin.resources.sms-sends.view', ['record' => $record->sms_send_id]))
                    ->openUrlInNewTab(false),

                // Super-admin-only for the same reason as the e-mail twin:
                // sms_suppressions is keyed by phone number with no organization_id,
                // so one tenant's suppression silences that number for all of them.
                Actions\Action::make('addToSuppression')
                    ->label('Wyklucz')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (SmsEvent $record): bool => (auth()->user()?->hasRole('super-admin') ?? false)
                        && in_array($record->event_type, ['failed', 'invalid_number'])
                        && ! SmsSuppression::isSuppressed($record->smsSend->phone_to)
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Dodaj numer do listy wykluczeń')
                    ->modalDescription(fn (SmsEvent $record): string => "Zablokuje to wysyłkę przyszłych SMS na numer {$record->smsSend->phone_to}. ".
                        'Tę akcję można cofnąć na stronie Wykluczenia SMS.'
                    )
                    ->modalSubmitActionLabel('Dodaj do listy wykluczeń')
                    ->action(function (SmsEvent $record): void {
                        try {
                            $phone = $record->smsSend->phone_to;
                            $reason = $record->event_type === 'invalid_number' ? 'invalid_number' : 'failed_repeatedly';

                            if (SmsSuppression::isSuppressed($phone)) {
                                Notification::make()
                                    ->warning()
                                    ->title('Numer już jest wykluczony')
                                    ->body("Numer {$phone} znajduje się już na liście wykluczeń.")
                                    ->send();

                                return;
                            }

                            SmsSuppression::suppress($phone, $reason);

                            Notification::make()
                                ->success()
                                ->title('Numer wykluczony')
                                ->body("Numer {$phone} został dodany do listy wykluczeń.")
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Błąd podczas wykluczania numeru')
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
                            $filename = 'zdarzenia-sms-'.now()->format('Y-m-d-His').'.csv';
                            $headers = [
                                'Content-Type' => 'text/csv',
                                'Content-Disposition' => "attachment; filename=\"$filename\"",
                            ];

                            $callback = function () use ($records) {
                                $file = fopen('php://output', 'w');
                                fputcsv($file, ['ID', 'ID wysyłki', 'Telefon', 'Typ zdarzenia', 'Data zdarzenia', 'Zarejestrowano']);

                                foreach ($records as $record) {
                                    fputcsv($file, [
                                        $record->id,
                                        $record->sms_send_id,
                                        $record->smsSend->phone_to ?? 'brak danych',
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
        ];
    }

    /**
     * SmsEvent now carries BelongsToOrganization (organization_id copied from the
     * owning SmsSend at creation time — see SmsService/SmsApiWebhookController) and
     * is scoped by the model's own global scope, so opening this to tenant admins
     * is safe: they only ever see delivery events for their own org's outbound SMS.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole(['super-admin', 'admin']) ?? false;
    }

    public static function canView($record): bool
    {
        return auth()->user()?->hasRole(['super-admin', 'admin']) ?? false;
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
