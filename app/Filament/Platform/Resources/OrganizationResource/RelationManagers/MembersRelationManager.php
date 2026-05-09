<?php

namespace App\Filament\Platform\Resources\OrganizationResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Członkowie';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('first_name')
                ->label('Imię')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('last_name')
                ->label('Nazwisko')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('first_name')
                    ->label('Imię')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_name')
                    ->label('Nazwisko')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('pivot.role')
                    ->label('Rola w organizacji')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'owner' => 'danger',
                        'admin' => 'warning',
                        'staff' => 'success',
                        default => 'gray',
                    }),
            ])
            ->headerActions([
                Actions\AttachAction::make()
                    ->label('Dodaj członka')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['first_name', 'last_name', 'email'])
                    ->form(fn (Actions\AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Forms\Components\Select::make('role')
                            ->label('Rola')
                            ->options([
                                'owner' => 'Owner',
                                'admin' => 'Admin',
                                'staff' => 'Staff',
                            ])
                            ->required()
                            ->default('staff'),
                    ]),
            ])
            ->recordActions([
                Actions\DetachAction::make()
                    ->label('Usuń'),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
