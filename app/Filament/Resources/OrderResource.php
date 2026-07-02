<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers;
use App\Models\Order;
use App\Models\User;
use App\Rules\ValidPolishNIP;
use App\Rules\ValidPolishPESEL;
use App\Rules\ValidPolishREGON;
use App\Services\Order\OrderService;
use App\Support\TenantFeature;
use BackedEnum;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
        return $schema->components([
            Section::make('Klient')
                ->columns(1)
                ->schema([
                    // KNOWN LIMITATION: scope shows only users who already have an order in this org.
                    // A brand-new user (no orders yet) will not appear — acceptable because canCreate()=false
                    // means this form is edit-only; the user_id was set at checkout time.
                    Select::make('user_id')
                        ->label('Przypisany klient')
                        ->relationship(
                            name: 'user',
                            titleAttribute: 'email',
                            modifyQueryUsing: function (Builder $query): void {
                                $tenantId = TenantFeature::currentTenant()?->id;
                                if ($tenantId === null) {
                                    // Fail-safe: no tenant context → return no users rather than all users.
                                    // Same risk class as the cross-tenant PII leak fixed in this branch.
                                    $query->whereNull('id');

                                    return;
                                }
                                $query->whereHas(
                                    'orders',
                                    fn (Builder $q) => $q->where('organization_id', $tenantId)
                                );
                            },
                        )
                        ->getOptionLabelFromRecordUsing(
                            fn (User $record) => "{$record->first_name} {$record->last_name} ({$record->email})"
                        )
                        ->searchable(['first_name', 'last_name', 'email'])
                        ->live()
                        ->helperText('Wybór klienta nadpisuje dane kontaktowe poniżej jego bieżącym profilem.')
                        ->afterStateUpdated(function (?int $state, callable $set): void {
                            if (! $state) {
                                return;
                            }
                            $tenantId = TenantFeature::currentTenant()?->id;
                            if ($tenantId === null) {
                                // Fail-safe: no tenant context → clear selection rather than risk cross-tenant autofill.
                                $set('user_id', null);

                                return;
                            }
                            $userQuery = User::query()->whereHas(
                                'orders',
                                fn (Builder $q) => $q->where('organization_id', $tenantId)
                            );
                            $user = $userQuery->find($state);
                            if (! $user) {
                                return;
                            }
                            $set('customer_first_name', $user->first_name);
                            $set('customer_last_name', $user->last_name);
                            $set('customer_email', $user->email);
                            $set('customer_phone', $user->phone_e164);
                            $set('customer_pesel', $user->pesel);
                            $set('customer_type', $user->customer_type);
                            $set('customer_street', $user->street_name);
                            $set('customer_building', $user->street_number);
                            $set('customer_city', $user->city);
                            $set('customer_postal_code', $user->postal_code);
                            $set('invoice_company_name', $user->company_name);
                            $set('invoice_nip', $user->nip);
                            $set('company_regon', $user->regon);
                            $set('company_krs', $user->krs);
                            $set('invoice_street', $user->billing_street);
                            $set('invoice_street_number', $user->billing_building_number);
                            $set('invoice_postal_code', $user->billing_postal_code);
                            $set('invoice_city', $user->billing_city);
                        }),
                ]),

            Section::make('Dane kontaktowe')
                ->columns(2)
                ->schema([
                    TextInput::make('customer_first_name')
                        ->label('Imię')
                        ->required(),

                    TextInput::make('customer_last_name')
                        ->label('Nazwisko')
                        ->required(),

                    TextInput::make('customer_email')
                        ->label('Email')
                        ->email()
                        ->required(),

                    TextInput::make('customer_phone')
                        ->label('Telefon')
                        ->nullable(),
                ]),

            Section::make('Adres')
                ->columns(3)
                ->schema([
                    TextInput::make('customer_street')
                        ->label('Ulica')
                        ->nullable(),

                    TextInput::make('customer_building')
                        ->label('Nr budynku')
                        ->nullable(),

                    TextInput::make('customer_apartment')
                        ->label('Nr lokalu')
                        ->nullable(),

                    TextInput::make('customer_city')
                        ->label('Miasto')
                        ->nullable(),

                    TextInput::make('customer_postal_code')
                        ->label('Kod pocztowy')
                        ->nullable()
                        ->mask('99-999'),
                ]),

            Section::make('Weryfikacja tożsamości')
                ->columns(2)
                ->visible(fn (Get $get): bool => $get('customer_type') === 'natural_person')
                ->schema([
                    TextInput::make('customer_pesel')
                        ->label('PESEL')
                        ->nullable()
                        ->maxLength(11)
                        ->rules(['nullable', new ValidPolishPESEL]),
                ]),

            Section::make('Dane firmy — do korekty')
                ->columns(2)
                ->visible(fn (Get $get): bool => $get('customer_type') === 'business')
                ->schema([
                    TextInput::make('company_contact_name')
                        ->label('Osoba podpisująca umowę')
                        ->nullable(),

                    TextInput::make('signatory_id_number')
                        ->label('PESEL / dowód podpisującego')
                        ->nullable()
                        ->minLength(9)
                        ->maxLength(11),

                    TextInput::make('pickup_person_name')
                        ->label('Osoba odbierająca sprzęt')
                        ->nullable()
                        ->columnSpanFull(),

                    TextInput::make('pickup_person_id_number')
                        ->label('Dowód osoby odbierającej')
                        ->nullable()
                        ->minLength(9)
                        ->maxLength(11),
                ]),

            Section::make('Dane do faktury')
                ->columns(2)
                ->visible(fn (Get $get): bool => $get('customer_type') === 'business')
                ->schema([
                    TextInput::make('invoice_company_name')
                        ->label('Nazwa firmy')
                        ->nullable(),

                    TextInput::make('invoice_nip')
                        ->label('NIP')
                        ->nullable()
                        ->rules(['nullable', new ValidPolishNIP]),

                    TextInput::make('company_regon')
                        ->label('REGON')
                        ->nullable()
                        ->rules(['nullable', new ValidPolishREGON]),

                    TextInput::make('company_krs')
                        ->label('KRS')
                        ->nullable(),

                    TextInput::make('invoice_street')
                        ->label('Ulica (faktura)')
                        ->nullable(),

                    TextInput::make('invoice_street_number')
                        ->label('Nr budynku (faktura)')
                        ->nullable(),

                    TextInput::make('invoice_postal_code')
                        ->label('Kod pocztowy (faktura)')
                        ->nullable()
                        ->mask('99-999'),

                    TextInput::make('invoice_city')
                        ->label('Miasto (faktura)')
                        ->nullable(),
                ]),

            Section::make('Notatka wewnętrzna')
                ->columns(1)
                ->schema([
                    Textarea::make('notes')
                        ->label('Notatka dla tenanta')
                        ->nullable()
                        ->helperText('Niewidoczna dla klienta')
                        ->rows(3),
                ]),
        ]);
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
                    ->toggleable(isToggledHiddenByDefault: true),

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
                    ->toggleable(isToggledHiddenByDefault: true)
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
                    ->toggleable(isToggledHiddenByDefault: true),

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
            ->recordAction('edit')
            ->recordActions([
                Actions\EditAction::make()
                    ->label('Zarządzaj'),

                Actions\ActionGroup::make([
                    Actions\Action::make('confirm')
                        ->label('Potwierdź zamówienie')
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

                    Actions\Action::make('cancel')
                        ->label('Anuluj zamówienie')
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
                ])->tooltip('Akcje'),
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
            'edit' => Pages\EditOrder::route('/{record}/edit'),
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
        if (in_array($record->status, ['completed', 'cancelled', 'refunded'])) {
            return false;
        }

        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }
}
