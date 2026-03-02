<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Models\StaffDateException;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class DateExceptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'dateExceptions';

    protected static ?string $title = 'Wyjątki';

    protected static ?string $modelLabel = 'Wyjątek';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\DatePicker::make('exception_date')
                ->label('Data')
                ->native(false)
                ->required(),

            Forms\Components\Radio::make('exception_type')
                ->label('Typ')
                ->options([
                    StaffDateException::TYPE_UNAVAILABLE => 'Niedostępny',
                    StaffDateException::TYPE_AVAILABLE => 'Dostępny',
                ])
                ->default(StaffDateException::TYPE_UNAVAILABLE)
                ->required(),

            Forms\Components\TimePicker::make('start_time')
                ->label('Od godziny')
                ->seconds(false),

            Forms\Components\TimePicker::make('end_time')
                ->label('Do godziny')
                ->seconds(false)
                ->after('start_time'),

            Forms\Components\Textarea::make('reason')
                ->label('Powód')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
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
                    ->placeholder('Cały dzień'),

                Tables\Columns\TextColumn::make('end_time')
                    ->label('Do')
                    ->time('H:i')
                    ->placeholder('Cały dzień'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Powód')
                    ->limit(30)
                    ->placeholder('Brak'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('exception_type')
                    ->options([
                        StaffDateException::TYPE_UNAVAILABLE => 'Niedostępny',
                        StaffDateException::TYPE_AVAILABLE => 'Dostępny',
                    ]),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Dodaj wyjątek'),
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
            ->defaultSort('exception_date', 'desc');
    }
}
