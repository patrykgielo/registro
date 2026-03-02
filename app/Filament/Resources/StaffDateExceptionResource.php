<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffDateExceptionResource\Pages;
use App\Models\StaffDateException;
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

class StaffDateExceptionResource extends Resource
{
    protected static ?string $model = StaffDateException::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'staff';

    protected static ?string $modelLabel = 'Wyjątek';

    protected static ?string $pluralModelLabel = 'Wyjątki od harmonogramu';

    protected static ?int $navigationSort = 3;

    // Hide from main navigation - accessible via StaffScheduleResource header actions
    // This reduces cognitive load by consolidating schedule management
    protected static bool $shouldRegisterNavigation = false;

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

                    Forms\Components\DatePicker::make('exception_date')
                        ->label('Data wyjątku')
                        ->native(false)
                        ->displayFormat('Y-m-d')
                        ->required()
                        ->helperText('Dzień, na który chcesz zastosować wyjątek'),
                ])->columns(2),

            Section::make('Typ wyjątku')
                ->schema([
                    Forms\Components\Radio::make('exception_type')
                        ->label('Co się dzieje w tym dniu?')
                        ->options([
                            StaffDateException::TYPE_UNAVAILABLE => 'Niedostępny (dzień wolny, wizyta lekarska, choroba)',
                            StaffDateException::TYPE_AVAILABLE => 'Dostępny (pracuje w normalnie wolny dzień)',
                        ])
                        ->descriptions([
                            StaffDateException::TYPE_UNAVAILABLE => 'Pracownik nie będzie dostępny w tym dniu',
                            StaffDateException::TYPE_AVAILABLE => 'Pracownik będzie dostępny mimo że normalnie nie pracuje',
                        ])
                        ->required()
                        ->live()
                        ->default(StaffDateException::TYPE_UNAVAILABLE),
                ]),

            Section::make('Zakres czasowy')
                ->description('Opcjonalne: jeśli nie wypełnisz, wyjątek dotyczy całego dnia')
                ->schema([
                    Forms\Components\TimePicker::make('start_time')
                        ->label('Od godziny')
                        ->seconds(false)
                        ->helperText('Zostaw puste jeśli dotyczy całego dnia'),

                    Forms\Components\TimePicker::make('end_time')
                        ->label('Do godziny')
                        ->seconds(false)
                        ->after('start_time')
                        ->helperText('Zostaw puste jeśli dotyczy całego dnia'),
                ])->columns(2),

            Section::make('Dodatkowe informacje')
                ->schema([
                    Forms\Components\Textarea::make('reason')
                        ->label('Powód (opcjonalnie)')
                        ->rows(3)
                        ->placeholder('np. "Wizyta u lekarza", "Dzień wolny", "Święto"')
                        ->columnSpanFull(),
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

                Tables\Columns\TextColumn::make('exception_date')
                    ->label('Data')
                    ->date('Y-m-d')
                    ->sortable()
                    ->badge()
                    ->color(fn (StaffDateException $record) => $record->exception_date->isPast() ? 'gray' : 'info'
                    ),

                Tables\Columns\TextColumn::make('exception_type')
                    ->label('Typ')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        StaffDateException::TYPE_UNAVAILABLE => 'Niedostępny',
                        StaffDateException::TYPE_AVAILABLE => 'Dostępny',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        StaffDateException::TYPE_UNAVAILABLE => 'danger',
                        StaffDateException::TYPE_AVAILABLE => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('start_time')
                    ->label('Od')
                    ->time('H:i')
                    ->placeholder('Cały dzień')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('end_time')
                    ->label('Do')
                    ->time('H:i')
                    ->placeholder('Cały dzień')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Powód')
                    ->limit(40)
                    ->placeholder('Brak')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('exception_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Pracownik')
                    ->relationship('user', 'first_name', function (Builder $query) {
                        $query->role('staff');
                    })
                    ->getOptionLabelFromRecordUsing(fn (User $record) => $record->name)
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('exception_type')
                    ->label('Typ wyjątku')
                    ->options([
                        StaffDateException::TYPE_UNAVAILABLE => 'Niedostępny',
                        StaffDateException::TYPE_AVAILABLE => 'Dostępny',
                    ]),

                Tables\Filters\Filter::make('future')
                    ->label('Tylko przyszłe')
                    ->query(fn (Builder $query) => $query->where('exception_date', '>=', now()->toDateString())),

                Tables\Filters\Filter::make('past')
                    ->label('Tylko przeszłe')
                    ->query(fn (Builder $query) => $query->where('exception_date', '<', now()->toDateString())),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListStaffDateExceptions::route('/'),
            'create' => Pages\CreateStaffDateException::route('/create'),
            'edit' => Pages\EditStaffDateException::route('/{record}/edit'),
        ];
    }
}
