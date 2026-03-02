<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffScheduleResource\Pages;
use App\Models\StaffSchedule;
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

class StaffScheduleResource extends Resource
{
    protected static ?string $model = StaffSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'staff';

    protected static ?string $modelLabel = 'Harmonogram';

    protected static ?string $pluralModelLabel = 'Harmonogramy';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Harmonogramy';

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
                        ->required(),

                    Forms\Components\Select::make('day_of_week')
                        ->label('Dzień tygodnia')
                        ->options([
                            0 => 'Niedziela',
                            1 => 'Poniedziałek',
                            2 => 'Wtorek',
                            3 => 'Środa',
                            4 => 'Czwartek',
                            5 => 'Piątek',
                            6 => 'Sobota',
                        ])
                        ->required(),
                ])->columns(2),

            Section::make('Godziny pracy')
                ->schema([
                    Forms\Components\TimePicker::make('start_time')
                        ->label('Od godziny')
                        ->required()
                        ->seconds(false),

                    Forms\Components\TimePicker::make('end_time')
                        ->label('Do godziny')
                        ->required()
                        ->seconds(false)
                        ->after('start_time'),
                ])->columns(2),

            Section::make('Okres obowiązywania')
                ->description('Opcjonalne: określ kiedy ten harmonogram jest aktywny')
                ->schema([
                    Forms\Components\DatePicker::make('effective_from')
                        ->label('Obowiązuje od')
                        ->native(false),

                    Forms\Components\DatePicker::make('effective_until')
                        ->label('Obowiązuje do')
                        ->native(false)
                        ->after('effective_from'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktywny')
                        ->default(true)
                        ->helperText('Możesz tymczasowo wyłączyć ten harmonogram bez usuwania')
                        ->required(),
                ])->columns(3),
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

                Tables\Columns\TextColumn::make('day_name')
                    ->label('Dzień')
                    ->sortable('day_of_week')
                    ->badge()
                    ->color(fn (StaffSchedule $record) => match ($record->day_of_week) {
                        0, 6 => 'warning',
                        default => 'primary',
                    }),

                Tables\Columns\TextColumn::make('start_time')
                    ->label('Od')
                    ->time('H:i'),

                Tables\Columns\TextColumn::make('end_time')
                    ->label('Do')
                    ->time('H:i'),

                Tables\Columns\TextColumn::make('effective_from')
                    ->label('Obowiązuje od')
                    ->date('Y-m-d')
                    ->placeholder('Zawsze')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('effective_until')
                    ->label('Obowiązuje do')
                    ->date('Y-m-d')
                    ->placeholder('Bezterminowo')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktywny')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('day_of_week')
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Pracownik')
                    ->relationship('user', 'first_name', function (Builder $query) {
                        $query->role('staff');
                    })
                    ->getOptionLabelFromRecordUsing(fn (User $record) => $record->name)
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('day_of_week')
                    ->label('Dzień tygodnia')
                    ->options([
                        0 => 'Niedziela',
                        1 => 'Poniedziałek',
                        2 => 'Wtorek',
                        3 => 'Środa',
                        4 => 'Czwartek',
                        5 => 'Piątek',
                        6 => 'Sobota',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Wszystkie')
                    ->trueLabel('Tylko aktywne')
                    ->falseLabel('Tylko nieaktywne'),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                    Actions\BulkAction::make('activate')
                        ->label('Aktywuj zaznaczone')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->action(function ($records) {
                            $records->each->update(['is_active' => true]);
                        })
                        ->deselectRecordsAfterCompletion(),
                    Actions\BulkAction::make('deactivate')
                        ->label('Dezaktywuj zaznaczone')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->action(function ($records) {
                            $records->each->update(['is_active' => false]);
                        })
                        ->deselectRecordsAfterCompletion(),
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
            'index' => Pages\ListStaffSchedules::route('/'),
            'create' => Pages\CreateStaffSchedule::route('/create'),
            'edit' => Pages\EditStaffSchedule::route('/{record}/edit'),
        ];
    }

    /**
     * Restrict access to admins and super-admins only.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }
}
