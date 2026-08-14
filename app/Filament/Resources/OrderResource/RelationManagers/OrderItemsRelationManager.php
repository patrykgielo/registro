<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class OrderItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Pozycje zamówienia';

    protected static ?string $modelLabel = 'Pozycja';

    protected static ?string $pluralModelLabel = 'Pozycje zamówienia';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('service_name')
                    ->label('Usługa')
                    ->searchable(),

                Tables\Columns\TextColumn::make('start_date')
                    ->label('Data od')
                    ->date('d.m.Y'),

                Tables\Columns\TextColumn::make('end_date')
                    ->label('Data do')
                    ->date('d.m.Y'),

                Tables\Columns\TextColumn::make('rental_days')
                    ->label('Dni')
                    ->numeric()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Ilość')
                    ->numeric()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('unit_price')
                    ->label('Cena/dzień')
                    ->money('PLN')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('total_price')
                    ->label('Razem')
                    ->money('PLN')
                    ->alignEnd()
                    ->weight('bold'),
            ])
            ->filters([])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Brak pozycji')
            ->emptyStateDescription('To zamówienie nie zawiera żadnych pozycji.');
    }

    public function canCreate(): bool
    {
        return false;
    }

    public function canEdit($record): bool
    {
        return false;
    }

    public function canDelete($record): bool
    {
        return false;
    }

    /**
     * No CreateAction/EditAction/DeleteAction is registered on this table today
     * (recordActions/headerActions/toolbarActions are all empty — line items are
     * read-only snapshots of what was ordered), so this is currently unreachable
     * in practice. But it's the same class of hole App\Filament\Resources\BaseResource
     * closes for Resources: RelationManager's getDeleteAuthorizationResponse()
     * (what a future DeleteAction would actually call) does not consult
     * canDelete() above by default — it falls through to Gate/policy resolution,
     * which allows by default with no OrderItemPolicy. Wiring it now means a
     * later "let staff void a line item" feature can't silently reopen this.
     */
    public function getCreateAuthorizationResponse(): Response
    {
        return $this->canCreate() ? Response::allow() : Response::deny();
    }

    public function getEditAuthorizationResponse(Model $record): Response
    {
        return $this->canEdit($record) ? Response::allow() : Response::deny();
    }

    public function getDeleteAuthorizationResponse(Model $record): Response
    {
        return $this->canDelete($record) ? Response::allow() : Response::deny();
    }
}
