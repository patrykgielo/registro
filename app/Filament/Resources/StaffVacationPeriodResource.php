<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffVacationPeriodResource\Pages;
use App\Models\StaffVacationPeriod;
use App\Models\User;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StaffVacationPeriodResource extends Resource
{
    protected static ?string $model = StaffVacationPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sun';

    protected static string|UnitEnum|null $navigationGroup = 'staff';

    protected static ?string $modelLabel = 'Urlop';

    protected static ?string $pluralModelLabel = 'Urlopy';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Podstawowe informacje')
                ->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('Pracownik')
                        ->relationship('user', 'first_name', function (Builder $query) {
                            $query->role('staff');
                        })
                        ->getOptionLabelFromRecordUsing(fn (User $record) => $record->name)
                        ->searchable()
                        ->preload()
                        ->required()
                        ->default(function () {
                            // Auto-fill with current user if they are staff
                            $user = auth()->user();

                            return $user?->hasRole('staff') ? $user->id : null;
                        })
                        ->disabled(fn () => auth()->user()?->hasRole('staff') ?? false)
                        ->dehydrated(true), // CRITICAL: Ensure value is saved even when disabled
                ]),

            Section::make('Okres urlopu')
                ->schema([
                    Forms\Components\DatePicker::make('start_date')
                        ->label('Data rozpoczęcia')
                        ->native(false)
                        ->displayFormat('Y-m-d')
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, callable $get) {
                            if (! $state) {
                                return;
                            }

                            $endDate = $get('end_date');
                            if (! $endDate) {
                                return;
                            }

                            // Handle both string and Carbon instances for comparison
                            $stateDate = $state instanceof \Carbon\Carbon ? $state : \Carbon\Carbon::parse($state);
                            $endDateObj = $endDate instanceof \Carbon\Carbon ? $endDate : \Carbon\Carbon::parse($endDate);

                            if ($stateDate->gt($endDateObj)) {
                                $set('end_date', $state);
                            }
                        }),

                    Forms\Components\DatePicker::make('end_date')
                        ->label('Data zakończenia')
                        ->native(false)
                        ->displayFormat('Y-m-d')
                        ->required()
                        ->after('start_date')
                        ->live()
                        ->helperText(function (callable $get) {
                            $start = $get('start_date');
                            $end = $get('end_date');

                            if ($start && $end) {
                                // Handle both string and Carbon instances
                                $startDate = $start instanceof \Carbon\Carbon ? $start : \Carbon\Carbon::parse($start);
                                $endDate = $end instanceof \Carbon\Carbon ? $end : \Carbon\Carbon::parse($end);
                                $days = $startDate->diffInDays($endDate) + 1;

                                return "Długość urlopu: {$days} ".($days === 1 ? 'dzień' : 'dni');
                            }

                            return '';
                        }),
                ])->columns(2),

            Section::make('Szczegóły')
                ->schema([
                    Forms\Components\Textarea::make('reason')
                        ->label('Powód/Typ urlopu')
                        ->rows(3)
                        ->placeholder('np. "Urlop wypoczynkowy", "Urlop na żądanie", "Zwolnienie lekarskie"')
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_approved')
                        ->label('Zatwierdzony')
                        ->default(false)
                        ->helperText('Czy ten urlop został zatwierdzony przez managera?')
                        ->visible(fn () => auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false)
                        ->required(fn () => auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Pracownik')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_date')
                    ->label('Od')
                    ->date('Y-m-d')
                    ->sortable(),

                Tables\Columns\TextColumn::make('end_date')
                    ->label('Do')
                    ->date('Y-m-d')
                    ->sortable(),

                Tables\Columns\TextColumn::make('duration')
                    ->label('Długość')
                    ->formatStateUsing(function (StaffVacationPeriod $record) {
                        $days = $record->getDurationInDays();

                        return $days.' '.($days === 1 ? 'dzień' : 'dni');
                    })
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Powód')
                    ->limit(30)
                    ->placeholder('Brak')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_approved')
                    ->label('Zatwierdzony')
                    ->boolean()
                    ->sortable()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('warning'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(function (StaffVacationPeriod $record) {
                        $now = now()->toDateString();

                        if ($record->end_date->toDateString() < $now) {
                            return 'Zakończony';
                        } elseif ($record->start_date->toDateString() <= $now && $record->end_date->toDateString() >= $now) {
                            return 'Trwa';
                        } else {
                            return 'Zaplanowany';
                        }
                    })
                    ->color(function (StaffVacationPeriod $record) {
                        $now = now()->toDateString();

                        if ($record->end_date->toDateString() < $now) {
                            return 'gray';
                        } elseif ($record->start_date->toDateString() <= $now && $record->end_date->toDateString() >= $now) {
                            return 'success';
                        } else {
                            return 'info';
                        }
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('start_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Pracownik')
                    ->relationship('user', 'first_name', function (Builder $query) {
                        $query->role('staff');
                    })
                    ->getOptionLabelFromRecordUsing(fn (User $record) => $record->name)
                    ->searchable()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('is_approved')
                    ->label('Status zatwierdzenia')
                    ->placeholder('Wszystkie')
                    ->trueLabel('Tylko zatwierdzone')
                    ->falseLabel('Tylko oczekujące'),

                Tables\Filters\Filter::make('active')
                    ->label('Trwające teraz')
                    ->query(function (Builder $query) {
                        $today = now()->toDateString();

                        return $query->where('start_date', '<=', $today)
                            ->where('end_date', '>=', $today);
                    }),

                Tables\Filters\Filter::make('upcoming')
                    ->label('Nadchodzące')
                    ->query(fn (Builder $query) => $query->where('start_date', '>', now()->toDateString())),

                Tables\Filters\Filter::make('past')
                    ->label('Zakończone')
                    ->query(fn (Builder $query) => $query->where('end_date', '<', now()->toDateString())),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
                Actions\Action::make('approve')
                    ->label('Zatwierdź')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->hidden(fn (StaffVacationPeriod $record) => $record->is_approved)
                    ->action(fn (StaffVacationPeriod $record) => $record->update(['is_approved' => true]))
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                    Actions\BulkAction::make('approve')
                        ->label('Zatwierdź zaznaczone')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->action(function ($records) {
                            $records->each->update(['is_approved' => true]);
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation(),
                    Actions\BulkAction::make('unapprove')
                        ->label('Cofnij zatwierdzenie')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->action(function ($records) {
                            $records->each->update(['is_approved' => false]);
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation(),
                ]),
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
            'index' => Pages\ListStaffVacationPeriods::route('/'),
            'create' => Pages\CreateStaffVacationPeriod::route('/create'),
            'edit' => Pages\EditStaffVacationPeriod::route('/{record}/edit'),
        ];
    }

    /**
     * Determine who can view the vacation periods list.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin', 'staff']) ?? false;
    }

    /**
     * Determine who can create new vacation periods.
     */
    public static function canCreate(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin', 'staff']) ?? false;
    }

    /**
     * Determine who can edit a specific vacation period.
     * Staff can only edit their own pending vacations.
     */
    public static function canEdit($record): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        // Admins can edit any vacation
        if ($user->hasAnyRole(['admin', 'super-admin'])) {
            return true;
        }

        // Staff can only edit their own pending vacations
        return $record->user_id === $user->id && ! $record->is_approved;
    }

    /**
     * Determine who can delete a specific vacation period.
     */
    public static function canDelete($record): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        // Admins can delete any vacation
        if ($user->hasAnyRole(['admin', 'super-admin'])) {
            return true;
        }

        // Staff can only delete their own pending vacations
        return $record->user_id === $user->id && ! $record->is_approved;
    }

    /**
     * Filter table query - staff see only their own vacations.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Staff can only see their own vacation periods
        if (auth()->user()?->hasRole('staff')) {
            return $query->where('user_id', auth()->id());
        }

        // Admins see all vacation periods
        return $query;
    }
}
