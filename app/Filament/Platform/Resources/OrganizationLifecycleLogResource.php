<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\OrganizationLifecycleLogResource\Pages;
use App\Models\OrganizationLifecycleLog;
use BackedEnum;
use Filament\Actions;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only Filament resource for the durable organization lifecycle audit log.
 *
 * The underlying table has no updated_at column (UPDATED_AT = null on the model)
 * — do not reference updated_at anywhere in this resource.
 *
 * Security: super-admin only; create/edit/delete are permanently disabled.
 */
class OrganizationLifecycleLogResource extends Resource
{
    protected static ?string $model = OrganizationLifecycleLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'system';

    protected static ?string $navigationLabel = 'Audyt lifecycle';

    protected static ?string $pluralLabel = 'Dziennik cyklu życia';

    protected static ?string $modelLabel = 'Wpis';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Single source of truth for event display labels (Polish).
     *
     * @return array<string, string>
     */
    private static function eventLabels(): array
    {
        return [
            'offboarding_started' => 'Rozpoczęto zamknięcie',
            'data_export_queued' => 'Eksport danych w kolejce',
            'data_export_downloaded' => 'Pobrano eksport danych',
            'closure_requested' => 'Wniosek o zamknięcie',
            'closure_request_dismissed' => 'Wniosek odrzucony',
            'closed' => 'Zamknięte',
            'suspended' => 'Zawieszone',
            'reactivated' => 'Reaktywowane',
        ];
    }

    private static function eventColor(string $event): string
    {
        return match ($event) {
            'offboarding_started', 'closed' => 'danger',
            'data_export_queued', 'data_export_downloaded' => 'info',
            'closure_requested', 'suspended' => 'warning',
            'reactivated' => 'success',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable()
                    ->searchable(false),

                Tables\Columns\TextColumn::make('event')
                    ->label('Zdarzenie')
                    ->badge()
                    ->color(fn (string $state): string => self::eventColor($state))
                    ->formatStateUsing(fn (string $state): string => self::eventLabels()[$state] ?? $state)
                    ->searchable(),

                Tables\Columns\TextColumn::make('organization_name')
                    ->label('Organizacja')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('actor_label')
                    ->label('Wykonawca')
                    ->searchable()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('Zdarzenie')
                    ->options(self::eventLabels()),

                Filter::make('created_at')
                    ->label('Zakres dat')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label('Od'),
                        \Filament\Forms\Components\DatePicker::make('until')
                            ->label('Do'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
                            ->when($data['until'], fn ($q, $until) => $q->whereDate('created_at', '<=', $until));
                    }),
            ])
            ->actions([
                Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Infolists\Components\Section::make('Szczegóły zdarzenia')
                    ->schema([
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Data')
                            ->dateTime('d.m.Y H:i:s'),

                        Infolists\Components\TextEntry::make('event')
                            ->label('Zdarzenie')
                            ->badge()
                            ->color(fn (string $state): string => self::eventColor($state))
                            ->formatStateUsing(fn (string $state): string => self::eventLabels()[$state] ?? $state),

                        Infolists\Components\TextEntry::make('organization_name')
                            ->label('Organizacja (snapshot)'),

                        Infolists\Components\TextEntry::make('organization_id')
                            ->label('ID organizacji'),

                        Infolists\Components\TextEntry::make('actor_label')
                            ->label('Wykonawca')
                            ->placeholder('—'),

                        Infolists\Components\TextEntry::make('actor_id')
                            ->label('ID wykonawcy')
                            ->placeholder('—'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Kontekst')
                    ->schema([
                        Infolists\Components\TextEntry::make('context')
                            ->label('Dane kontekstu')
                            ->formatStateUsing(fn (mixed $state): string => $state
                                ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                : 'Brak dodatkowego kontekstu')
                            ->extraAttributes(['class' => 'font-mono text-sm whitespace-pre-wrap'])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizationLifecycleLogs::route('/'),
            'view' => Pages\ViewOrganizationLifecycleLog::route('/{record}'),
        ];
    }
}
