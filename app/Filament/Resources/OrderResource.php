<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers;
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
                        'in_progress' => 'Sprzęt u klienta',
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

                Tables\Columns\TextColumn::make('deposit_amount')
                    ->label('Kaucja')
                    ->money('PLN')
                    ->sortable()
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('deposit_status')
                    ->label('Status kaucji')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'not_required' => 'gray',
                        'pending' => 'warning',
                        'collected' => 'success',
                        'returned' => 'gray',
                        'partial_return' => 'info',
                        'forfeited' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'not_required' => 'Nie wymagana',
                        'pending' => 'Oczekuje',
                        'collected' => 'Pobrana',
                        'returned' => 'Zwrócona',
                        'partial_return' => 'Zwrot częściowy',
                        'forfeited' => 'Przepadła',
                        default => $state,
                    })
                    ->toggleable(),

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
                        'in_progress' => 'Sprzęt u klienta',
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

                Tables\Filters\SelectFilter::make('deposit_status')
                    ->label('Status kaucji')
                    ->options([
                        'not_required' => 'Nie wymagana',
                        'pending' => 'Oczekuje',
                        'collected' => 'Pobrana',
                        'returned' => 'Zwrócona',
                        'partial_return' => 'Zwrot częściowy',
                        'forfeited' => 'Przepadła',
                    ]),
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
                    ->action(function (Order $record): void {
                        try {
                            $record->status()->transitionTo('confirmed');
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Nie można potwierdzić zamówienia')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),

                Actions\Action::make('mark_in_progress')
                    ->label('Wydano klientowi')
                    ->icon('heroicon-o-truck')
                    ->color('info')
                    ->visible(fn (Order $record): bool => $record->status === 'confirmed')
                    ->requiresConfirmation()
                    ->action(function (Order $record): void {
                        try {
                            $record->status()->transitionTo('in_progress');
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Nie można zmienić statusu')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),

                Actions\Action::make('complete')
                    ->label('Sprzęt zwrócony')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->color('gray')
                    ->visible(fn (Order $record): bool => $record->status === 'in_progress')
                    ->requiresConfirmation()
                    ->action(function (Order $record): void {
                        try {
                            $record->status()->transitionTo('completed');
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Nie można zakończyć zamówienia')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),

                Actions\Action::make('cancel')
                    ->label('Anuluj')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Order $record): bool => in_array($record->status, ['pending_payment', 'paid', 'confirmed']))
                    ->form([
                        Textarea::make('reason')
                            ->label('Powód anulowania')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (Order $record, array $data): void {
                        try {
                            app(OrderService::class)->cancel($record, $data['reason']);
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Nie można anulować zamówienia')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),

                Actions\Action::make('collect_deposit')
                    ->label('Pobrano kaucję')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Order $record): bool => $record->deposit_status === 'pending')
                    ->form([
                        \Filament\Forms\Components\Textarea::make('deposit_notes')
                            ->label('Notatka (opcjonalnie)')
                            ->maxLength(500),
                    ])
                    ->action(function (Order $record, array $data): void {
                        $record->update([
                            'deposit_status' => 'collected',
                            'deposit_collected_at' => now(),
                            'deposit_notes' => $data['deposit_notes'] ?? null,
                        ]);
                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Kaucja pobrana')
                            ->send();
                    }),

                Actions\Action::make('return_deposit')
                    ->label('Zwrócono kaucję')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('info')
                    ->visible(fn (Order $record): bool => $record->deposit_status === 'collected')
                    ->form([
                        \Filament\Forms\Components\Textarea::make('deposit_notes')
                            ->label('Notatka (opcjonalnie)')
                            ->maxLength(500),
                    ])
                    ->action(function (Order $record, array $data): void {
                        $record->update([
                            'deposit_status' => 'returned',
                            'deposit_returned_at' => now(),
                            'deposit_notes' => $data['deposit_notes'] ?? null,
                        ]);
                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Kaucja zwrócona')
                            ->send();
                    }),

                Actions\Action::make('forfeit_deposit')
                    ->label('Kaucja przepadła')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Order $record): bool => $record->deposit_status === 'collected')
                    ->requiresConfirmation()
                    ->modalHeading('Kaucja przepada')
                    ->modalDescription('Czy na pewno chcesz oznaczyć, że kaucja przepadła? Ta akcja jest nieodwracalna.')
                    ->form([
                        \Filament\Forms\Components\Textarea::make('deposit_notes')
                            ->label('Powód przepadku')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (Order $record, array $data): void {
                        $record->update([
                            'deposit_status' => 'forfeited',
                            'deposit_notes' => $data['deposit_notes'],
                        ]);
                        \Filament\Notifications\Notification::make()
                            ->warning()
                            ->title('Kaucja przepadła')
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\OrderItemsRelationManager::class,
        ];
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

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }
}
