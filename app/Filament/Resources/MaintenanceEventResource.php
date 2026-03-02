<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\MaintenanceType;
use App\Filament\Resources\MaintenanceEventResource\Pages;
use App\Models\MaintenanceEvent;
use BackedEnum;
use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class MaintenanceEventResource extends Resource
{
    protected static ?string $model = MaintenanceEvent::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|UnitEnum|null $navigationGroup = 'system';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Maintenance Log';

    protected static ?string $modelLabel = 'Maintenance Event';

    protected static ?string $pluralModelLabel = 'Maintenance Events';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Event Details')
                ->schema([
                    \Filament\Forms\Components\Select::make('type')
                        ->label('Type')
                        ->options([
                            MaintenanceType::DEPLOYMENT->value => MaintenanceType::DEPLOYMENT->label(),
                            MaintenanceType::PRELAUNCH->value => MaintenanceType::PRELAUNCH->label(),
                            MaintenanceType::SCHEDULED->value => MaintenanceType::SCHEDULED->label(),
                            MaintenanceType::EMERGENCY->value => MaintenanceType::EMERGENCY->label(),
                        ])
                        ->required()
                        ->disabled(),

                    \Filament\Forms\Components\Select::make('action')
                        ->label('Action')
                        ->options([
                            'enabled' => 'Enabled',
                            'disabled' => 'Disabled',
                        ])
                        ->required()
                        ->disabled(),

                    \Filament\Forms\Components\Select::make('user_id')
                        ->label('User')
                        ->relationship('user', 'email')
                        ->disabled(),

                    \Filament\Forms\Components\TextInput::make('ip_address')
                        ->label('IP Address')
                        ->disabled(),

                    \Filament\Forms\Components\Textarea::make('message')
                        ->label('Message')
                        ->rows(2)
                        ->disabled()
                        ->columnSpanFull(),

                    \Filament\Forms\Components\KeyValue::make('metadata')
                        ->label('Metadata')
                        ->disabled()
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Timestamps')
                ->schema([
                    \Filament\Forms\Components\Placeholder::make('created_at')
                        ->label('Created At')
                        ->content(fn ($record) => $record?->created_at?->format('Y-m-d H:i:s')),

                    \Filament\Forms\Components\Placeholder::make('updated_at')
                        ->label('Updated At')
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
                    ->label('Type')
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
                    ->label('Action')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'enabled' => 'danger',
                        'disabled' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.email')
                    ->label('User')
                    ->searchable()
                    ->sortable()
                    ->default('System')
                    ->icon('heroicon-o-user'),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('message')
                    ->label('Message')
                    ->searchable()
                    ->limit(50)
                    ->toggleable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date/Time')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        MaintenanceType::DEPLOYMENT->value => MaintenanceType::DEPLOYMENT->label(),
                        MaintenanceType::PRELAUNCH->value => MaintenanceType::PRELAUNCH->label(),
                        MaintenanceType::SCHEDULED->value => MaintenanceType::SCHEDULED->label(),
                        MaintenanceType::EMERGENCY->value => MaintenanceType::EMERGENCY->label(),
                    ])
                    ->multiple(),

                Tables\Filters\SelectFilter::make('action')
                    ->label('Action')
                    ->options([
                        'enabled' => 'Enabled',
                        'disabled' => 'Disabled',
                    ])
                    ->multiple(),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from')
                            ->label('From Date'),
                        \Filament\Forms\Components\DatePicker::make('created_until')
                            ->label('Until Date'),
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
            ->emptyStateHeading('No maintenance events yet')
            ->emptyStateDescription('Maintenance events will appear here when maintenance mode is enabled or disabled.')
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
     * Restrict access to admins and super-admins only.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }
}
