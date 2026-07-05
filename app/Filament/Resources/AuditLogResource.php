<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Actions\AuditLog\ExportAuditLogsToCsv;
use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Audit Log Resource
 *
 * GDPR Compliance - Read-only resource for viewing audit logs.
 *
 * Purpose:
 * - Accountability (Art. 5.2 GDPR)
 * - Data subject access requests (Art. 15 GDPR)
 * - Breach investigation (Art. 33 GDPR)
 *
 * Access: Super-admin only - contains sensitive security information.
 */
class AuditLogResource extends BaseResource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static string|UnitEnum|null $navigationGroup = 'system';

    protected static ?int $navigationSort = 99;

    protected static ?string $navigationLabel = 'Logi audytu';

    protected static ?string $modelLabel = 'Log audytu';

    protected static ?string $pluralModelLabel = 'Logi audytu';

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
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('event')
                    ->label('Zdarzenie')
                    ->sortable()
                    ->badge()
                    ->formatStateUsing(fn (AuditLog $record): string => $record->event_label)
                    ->color(fn (string $state): string => match ($state) {
                        AuditLog::EVENT_CREATED => 'success',
                        AuditLog::EVENT_UPDATED => 'info',
                        AuditLog::EVENT_DELETED => 'danger',
                        AuditLog::EVENT_EXPORTED => 'primary',
                        AuditLog::EVENT_LOGIN => 'success',
                        AuditLog::EVENT_LOGOUT => 'gray',
                        AuditLog::EVENT_LOGIN_FAILED => 'warning',
                        AuditLog::EVENT_CONSENT_GRANTED => 'success',
                        AuditLog::EVENT_CONSENT_WITHDRAWN => 'warning',
                        AuditLog::EVENT_PASSWORD_CHANGED => 'info',
                        AuditLog::EVENT_PASSWORD_RESET => 'warning',
                        AuditLog::EVENT_ACCOUNT_ANONYMIZED => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        AuditLog::EVENT_CREATED => 'heroicon-o-plus-circle',
                        AuditLog::EVENT_UPDATED => 'heroicon-o-pencil-square',
                        AuditLog::EVENT_DELETED => 'heroicon-o-trash',
                        AuditLog::EVENT_EXPORTED => 'heroicon-o-arrow-down-tray',
                        AuditLog::EVENT_LOGIN => 'heroicon-o-arrow-right-on-rectangle',
                        AuditLog::EVENT_LOGOUT => 'heroicon-o-arrow-left-on-rectangle',
                        AuditLog::EVENT_LOGIN_FAILED => 'heroicon-o-exclamation-triangle',
                        AuditLog::EVENT_CONSENT_GRANTED => 'heroicon-o-check-circle',
                        AuditLog::EVENT_CONSENT_WITHDRAWN => 'heroicon-o-x-circle',
                        AuditLog::EVENT_PASSWORD_CHANGED => 'heroicon-o-key',
                        AuditLog::EVENT_PASSWORD_RESET => 'heroicon-o-key',
                        AuditLog::EVENT_ACCOUNT_ANONYMIZED => 'heroicon-o-user-minus',
                        default => 'heroicon-o-information-circle',
                    }),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Użytkownik')
                    ->searchable(['users.first_name', 'users.last_name'])
                    ->sortable()
                    ->default('-')
                    ->url(fn (AuditLog $record): ?string => $record->user_id
                        ? route('filament.admin.resources.users.edit', ['record' => $record->user_id])
                        : null
                    ),

                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Obiekt')
                    ->formatStateUsing(function (string $state): string {
                        return match ($state) {
                            'App\\Models\\User' => 'Użytkownik',
                            'App\\Models\\Appointment' => 'Wizyta',
                            'App\\Models\\UserAddress' => 'Adres',
                            'App\\Models\\UserVehicle' => 'Pojazd',
                            default => class_basename($state),
                        };
                    })
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('old_values')
                    ->label('Poprzednie wartości')
                    ->limit(30)
                    ->tooltip(function (AuditLog $record): ?string {
                        if (empty($record->old_values)) {
                            return null;
                        }

                        return json_encode($record->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    })
                    ->formatStateUsing(function ($state): string {
                        if (empty($state)) {
                            return '-';
                        }
                        $json = is_array($state) ? json_encode($state) : $state;

                        return \Str::limit($json, 30);
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('new_values')
                    ->label('Nowe wartości')
                    ->limit(30)
                    ->tooltip(function (AuditLog $record): ?string {
                        if (empty($record->new_values)) {
                            return null;
                        }

                        return json_encode($record->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    })
                    ->formatStateUsing(function ($state): string {
                        if (empty($state)) {
                            return '-';
                        }
                        $json = is_array($state) ? json_encode($state) : $state;

                        return \Str::limit($json, 30);
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('user_agent')
                    ->label('User Agent')
                    ->limit(30)
                    ->tooltip(fn (AuditLog $record): ?string => $record->user_agent)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->label('Typ zdarzenia')
                    ->options([
                        AuditLog::EVENT_CREATED => 'Utworzono',
                        AuditLog::EVENT_UPDATED => 'Zaktualizowano',
                        AuditLog::EVENT_DELETED => 'Usunięto',
                        AuditLog::EVENT_EXPORTED => 'Wyeksportowano',
                        AuditLog::EVENT_LOGIN => 'Logowanie',
                        AuditLog::EVENT_LOGOUT => 'Wylogowanie',
                        AuditLog::EVENT_LOGIN_FAILED => 'Nieudane logowanie',
                        AuditLog::EVENT_CONSENT_GRANTED => 'Zgoda udzielona',
                        AuditLog::EVENT_CONSENT_WITHDRAWN => 'Zgoda wycofana',
                        AuditLog::EVENT_PASSWORD_CHANGED => 'Zmiana hasła',
                        AuditLog::EVENT_PASSWORD_RESET => 'Reset hasła',
                        AuditLog::EVENT_ACCOUNT_ANONYMIZED => 'Konto zanonimizowane',
                    ])
                    ->multiple(),

                Tables\Filters\SelectFilter::make('auditable_type')
                    ->label('Typ obiektu')
                    ->options([
                        'App\\Models\\User' => 'Użytkownik',
                        'App\\Models\\Appointment' => 'Wizyta',
                        'App\\Models\\UserAddress' => 'Adres',
                        'App\\Models\\UserVehicle' => 'Pojazd',
                    ]),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('Od'),
                        Forms\Components\DatePicker::make('until')
                            ->label('Do'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),

                Tables\Filters\Filter::make('user_id')
                    ->form([
                        Forms\Components\TextInput::make('user_id')
                            ->label('ID użytkownika')
                            ->numeric(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['user_id'],
                            fn (Builder $query, $userId): Builder => $query->where('user_id', $userId),
                        );
                    }),
            ])
            ->recordActions([
                Actions\ViewAction::make()
                    ->label('Szczegóły')
                    ->modalHeading('Szczegóły zdarzenia')
                    ->modalContent(fn (AuditLog $record) => view('filament.resources.audit-log.view-modal', [
                        'record' => $record,
                    ])),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('export')
                        ->label('Eksportuj zaznaczone')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(fn ($records) => (new ExportAuditLogsToCsv)->execute($records)),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
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
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }

    /**
     * Check if user can access this resource
     *
     * SECURITY: Only super-admin should see audit logs
     * Staff members should NOT see admin activities
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canView($record): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
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
