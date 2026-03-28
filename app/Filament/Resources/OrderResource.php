<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Services\Order\OrderService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class OrderResource extends BaseResource
{
    protected static ?string $model = Order::class;

    protected static ?string $module = 'rentals';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|UnitEnum|null $navigationGroup = 'rentals';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'Zamówienie';

    protected static ?string $pluralModelLabel = 'Zamówienia';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('Nr zamówienia')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Klient')
                    ->getStateUsing(fn (Order $record): string => trim("{$record->customer_first_name} {$record->customer_last_name}"))
                    ->searchable(query: function ($query, string $search): void {
                        $query->where(function ($q) use ($search): void {
                            $q->where('customer_first_name', 'like', "%{$search}%")
                                ->orWhere('customer_last_name', 'like', "%{$search}%");
                        });
                    }),

                Tables\Columns\TextColumn::make('customer_email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending_payment' => 'warning',
                        'paid', 'confirmed' => 'success',
                        'in_progress' => 'info',
                        'completed' => 'gray',
                        'cancelled' => 'danger',
                        'refunded' => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending_payment' => 'Oczekuje na płatność',
                        'paid' => 'Opłacone',
                        'confirmed' => 'Potwierdzone',
                        'in_progress' => 'W trakcie',
                        'completed' => 'Zakończone',
                        'cancelled' => 'Anulowane',
                        'refunded' => 'Zwrócone',
                        default => $state,
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Kwota')
                    ->money('PLN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data zamówienia')
                    ->date('d.m.Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending_payment' => 'Oczekuje na płatność',
                        'paid' => 'Opłacone',
                        'confirmed' => 'Potwierdzone',
                        'in_progress' => 'W trakcie',
                        'completed' => 'Zakończone',
                        'cancelled' => 'Anulowane',
                        'refunded' => 'Zwrócone',
                    ]),

                Tables\Filters\Filter::make('created_from')
                    ->label('Data od')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from')
                            ->label('Od')
                            ->native(false),
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        $data['created_from'],
                        fn ($q, $date) => $q->whereDate('created_at', '>=', $date)
                    )),

                Tables\Filters\Filter::make('created_until')
                    ->label('Data do')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_until')
                            ->label('Do')
                            ->native(false),
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        $data['created_until'],
                        fn ($q, $date) => $q->whereDate('created_at', '<=', $date)
                    )),
            ])
            ->recordAction('view')
            ->recordActions([
                Actions\ViewAction::make(),

                Actions\Action::make('confirm')
                    ->label('Potwierdź')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Order $record): bool => $record->status === 'paid')
                    ->requiresConfirmation()
                    ->action(fn (Order $record) => $record->status()->transitionTo('confirmed')),

                Actions\Action::make('mark_in_progress')
                    ->label('Wydano klientowi')
                    ->icon('heroicon-o-truck')
                    ->color('info')
                    ->visible(fn (Order $record): bool => $record->status === 'confirmed')
                    ->requiresConfirmation()
                    ->action(fn (Order $record) => $record->status()->transitionTo('in_progress')),

                Actions\Action::make('complete')
                    ->label('Sprzęt zwrócony')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->color('gray')
                    ->visible(fn (Order $record): bool => $record->status === 'in_progress')
                    ->requiresConfirmation()
                    ->action(fn (Order $record) => $record->status()->transitionTo('completed')),

                Actions\Action::make('cancel')
                    ->label('Anuluj')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Order $record): bool => in_array($record->status, ['pending_payment', 'paid']))
                    ->form([
                        Textarea::make('reason')
                            ->label('Powód anulowania')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(fn (Order $record, array $data) => app(OrderService::class)->cancel($record, $data['reason'])),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }
}
