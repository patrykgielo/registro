<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Models\StaffVacationPeriod;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class VacationPeriodsRelationManager extends RelationManager
{
    protected static string $relationship = 'vacationPeriods';

    protected static ?string $title = 'Urlopy';

    protected static ?string $modelLabel = 'Urlop';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\DatePicker::make('start_date')
                ->label('Data rozpoczęcia')
                ->native(false)
                ->required(),

            Forms\Components\DatePicker::make('end_date')
                ->label('Data zakończenia')
                ->native(false)
                ->required()
                ->after('start_date'),

            Forms\Components\Textarea::make('reason')
                ->label('Powód')
                ->rows(2)
                ->columnSpanFull(),

            Forms\Components\Toggle::make('is_approved')
                ->label('Zatwierdzony')
                ->default(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
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
                    ->limit(20)
                    ->placeholder('Brak'),

                Tables\Columns\IconColumn::make('is_approved')
                    ->label('Zatwierdzony')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('warning'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_approved')
                    ->label('Zatwierdzony')
                    ->placeholder('Wszystkie')
                    ->trueLabel('Tylko zatwierdzone')
                    ->falseLabel('Tylko oczekujące'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Dodaj urlop'),
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
                ]),
            ])
            ->defaultSort('start_date', 'desc');
    }
}
