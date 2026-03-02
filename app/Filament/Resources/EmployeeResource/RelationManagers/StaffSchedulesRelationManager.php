<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Models\StaffSchedule;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class StaffSchedulesRelationManager extends RelationManager
{
    protected static string $relationship = 'staffSchedules';

    protected static ?string $title = 'Harmonogramy';

    protected static ?string $modelLabel = 'Harmonogram';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
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

            Forms\Components\TimePicker::make('start_time')
                ->label('Od godziny')
                ->required()
                ->seconds(false),

            Forms\Components\TimePicker::make('end_time')
                ->label('Do godziny')
                ->required()
                ->seconds(false)
                ->after('start_time'),

            Forms\Components\DatePicker::make('effective_from')
                ->label('Obowiązuje od')
                ->native(false),

            Forms\Components\DatePicker::make('effective_until')
                ->label('Obowiązuje do')
                ->native(false)
                ->after('effective_from'),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktywny')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('day_name')
                    ->label('Dzień')
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
                    ->label('Od daty')
                    ->date('Y-m-d')
                    ->placeholder('Zawsze'),

                Tables\Columns\TextColumn::make('effective_until')
                    ->label('Do daty')
                    ->date('Y-m-d')
                    ->placeholder('Bezterminowo'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktywny')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Tylko aktywne'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Dodaj harmonogram'),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('day_of_week');
    }
}
