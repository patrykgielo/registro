<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\MaintenanceType;
use App\Filament\Resources\MaintenanceEventResource\Pages;
use App\Models\MaintenanceEvent;
use BackedEnum;
use Filament\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MaintenanceEventResource extends BaseResource
{
    protected static ?string $model = MaintenanceEvent::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|UnitEnum|null $navigationGroup = 'system';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Log konserwacji';

    protected static ?string $modelLabel = 'Zdarzenie konserwacji';

    protected static ?string $pluralModelLabel = 'Zdarzenia konserwacji';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Szczegóły zdarzenia')
                ->schema([
                    \Filament\Forms\Components\Select::make('type')
                        ->label('Typ')
                        ->options([
                            MaintenanceType::DEPLOYMENT->value => MaintenanceType::DEPLOYMENT->label(),
                            MaintenanceType::PRELAUNCH->value => MaintenanceType::PRELAUNCH->label(),
                            MaintenanceType::SCHEDULED->value => MaintenanceType::SCHEDULED->label(),
                            MaintenanceType::EMERGENCY->value => MaintenanceType::EMERGENCY->label(),
                        ])
                        ->required()
                        ->disabled(),

                    \Filament\Forms\Components\Select::make('action')
                        ->label('Akcja')
                        ->options([
                            'enabled' => 'Włączono',
                            'disabled' => 'Wyłączono',
                        ])
                        ->required()
                        ->disabled(),

                    \Filament\Forms\Components\Select::make('user_id')
                        ->label('Użytkownik')
                        ->relationship('user', 'email')
                        ->disabled(),

                    \Filament\Forms\Components\TextInput::make('ip_address')
                        ->label('Adres IP')
                        ->disabled(),

                    \Filament\Forms\Components\Textarea::make('message')
                        ->label('Wiadomość')
                        ->rows(2)
                        ->disabled()
                        ->columnSpanFull(),

                    \Filament\Forms\Components\KeyValue::make('metadata')
                        ->label('Metadane')
                        ->disabled()
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Znaczniki czasu')
                ->schema([
                    \Filament\Forms\Components\Placeholder::make('created_at')
                        ->label('Utworzono')
                        ->content(fn ($record) => $record?->created_at?->format('Y-m-d H:i:s')),

                    \Filament\Forms\Components\Placeholder::make('updated_at')
                        ->label('Zaktualizowano')
                        ->content(fn ($record) => $record?->updated_at?->format('Y-m-d H:i:s')),
                ])
                ->columns(2)
                ->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Typ')
                    ->badge()
                    ->color(fn (MaintenanceType $state): string => match ($state) {
                        MaintenanceType::DEPLOYMENT => 'info',
                        MaintenanceType::PRELAUNCH => 'danger',
                        MaintenanceType::SCHEDULED => 'warning',
                        MaintenanceType::EMERGENCY => 'danger',
                    })
                    ->formatStateUsing(fn (MaintenanceType $state): string => $state->label())
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('action')
                    ->label('Akcja')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'enabled' => 'danger',
                        'disabled' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'enabled' => 'Włączono',
                        'disabled' => 'Wyłączono',
                        default => ucfirst($state),
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.email')
                    ->label('Użytkownik')
                    ->searchable()
                    ->sortable()
                    ->default('System')
                    ->icon('heroicon-o-user'),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('Adres IP')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('message')
                    ->label('Wiadomość')
                    ->searchable()
                    ->limit(50)
                    ->toggleable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data/godzina')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Typ')
                    ->options([
                        MaintenanceType::DEPLOYMENT->value => MaintenanceType::DEPLOYMENT->label(),
                        MaintenanceType::PRELAUNCH->value => MaintenanceType::PRELAUNCH->label(),
                        MaintenanceType::SCHEDULED->value => MaintenanceType::SCHEDULED->label(),
                        MaintenanceType::EMERGENCY->value => MaintenanceType::EMERGENCY->label(),
                    ])
                    ->multiple(),

                Tables\Filters\SelectFilter::make('action')
                    ->label('Akcja')
                    ->options([
                        'enabled' => 'Włączono',
                        'disabled' => 'Wyłączono',
                    ])
                    ->multiple(),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from')
                            ->label('Od daty'),
                        \Filament\Forms\Components\DatePicker::make('created_until')
                            ->label('Do daty'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->emptyStateHeading('Brak zdarzeń konserwacji')
            ->emptyStateDescription('Zdarzenia pojawią się tutaj po włączeniu lub wyłączeniu trybu konserwacji.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaintenanceEvents::route('/'),
            'view' => Pages\ViewMaintenanceEvent::route('/{record}'),
        ];
    }

    /**
     * Disable create/edit actions - events are created automatically by the system
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    /**
     * Restrict access to super-admins only (global model, not tenant-scoped).
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    /**
     * Bulk delete exists in the table (cleanup of old logs); BaseResource's
     * default is admin/super-admin — narrowed to match canViewAny() above so
     * an admin who could never reach this page can't reach it through a more
     * generic path either.
     */
    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    /**
     * The singular counterpart, for the same reason. Unreachable today — this
     * resource registers no row-level DeleteAction and canViewAny() blocks the
     * page mount for anyone but a super-admin — but leaving it on BaseResource's
     * wider admin default would mean the next embed or row action silently
     * inherits a looser rule than the rest of the class states.
     */
    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }
}
