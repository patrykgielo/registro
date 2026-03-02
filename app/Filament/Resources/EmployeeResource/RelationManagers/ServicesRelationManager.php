<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'services';

    protected static ?string $title = 'Usługi';

    protected static ?string $modelLabel = 'Usługa';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('service_id')
                ->label('Usługa')
                ->relationship('services', 'name')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nazwa usługi')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('formatted_duration')
                    ->label('Czas trwania')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('price')
                    ->label('Cena')
                    ->money('PLN')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktywna')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Actions\AttachAction::make()
                    ->label('Przypisz usługę')
                    ->preloadRecordSelect(),
            ])
            ->recordActions([
                Actions\DetachAction::make()
                    ->label('Odepnij'),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DetachBulkAction::make()
                        ->label('Odepnij zaznaczone'),
                ]),
            ]);
    }
}
