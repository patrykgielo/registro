<?php

namespace App\Filament\Resources;

use App\Enums\RentalStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\RentalResource\Pages;
use App\Models\Rental;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class RentalResource extends BaseResource
{
    protected static ?string $model = Rental::class;

    protected static ?string $module = 'rentals';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|UnitEnum|null $navigationGroup = 'rentals';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Wypożyczenie';

    protected static ?string $pluralModelLabel = 'Wypożyczenia';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Szczegóły wypożyczenia')
                    ->schema([
                        Forms\Components\Select::make('service_id')
                            ->label('Przedmiot')
                            ->relationship(
                                name: 'service',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn ($query) => $query->where('service_type', ServiceType::ItemRental->value)
                            )
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\Select::make('customer_id')
                            ->label('Klient')
                            ->relationship('customer', 'email')
                            ->getOptionLabelFromRecordUsing(fn (\App\Models\User $record): string => "{$record->name} ({$record->email})")
                            ->searchable(['first_name', 'last_name', 'email'])
                            ->preload(),

                        Forms\Components\TextInput::make('quantity')
                            ->label('Ilość')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->default(1),

                        Forms\Components\DatePicker::make('start_date')
                            ->label('Data rozpoczęcia')
                            ->required()
                            ->minDate(fn (?Model $record): ?string => $record ? null : now()->toDateString()),

                        Forms\Components\DatePicker::make('end_date')
                            ->label('Data zakończenia')
                            ->required()
                            ->afterOrEqual('start_date'),

                        Forms\Components\Select::make('pricing_unit')
                            ->label('Jednostka cenowa')
                            ->options([
                                'hourly' => 'Godzinowa',
                                'daily' => 'Dzienna',
                                'weekly' => 'Tygodniowa',
                            ])
                            ->required()
                            ->default('daily'),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options(RentalStatus::options())
                            ->required()
                            ->default(RentalStatus::Pending->value),
                    ])
                    ->columns(2),

                Section::make('Cennik')
                    ->schema([
                        Forms\Components\TextInput::make('unit_price_at_booking')
                            ->label('Cena jednostkowa')
                            ->required()
                            ->numeric()
                            ->prefix('PLN'),

                        Forms\Components\TextInput::make('total_price')
                            ->label('Cena całkowita')
                            ->required()
                            ->numeric()
                            ->prefix('PLN'),

                        Forms\Components\TextInput::make('deposit_amount')
                            ->label('Kaucja')
                            ->numeric()
                            ->prefix('PLN'),
                    ])
                    ->columns(3),

                Section::make('Dane kontaktowe')
                    ->schema([
                        Forms\Components\TextInput::make('first_name')
                            ->label('Imię')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('last_name')
                            ->label('Nazwisko')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone')
                            ->label('Telefon')
                            ->tel()
                            ->maxLength(20),
                    ])
                    ->columns(2)
                    ->collapsed(),

                Section::make('Dane do faktury')
                    ->schema([
                        Forms\Components\Toggle::make('invoice_requested')
                            ->label('Faktura VAT')
                            ->live()
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('invoice_company_name')
                            ->label('Nazwa firmy')
                            ->maxLength(255)
                            ->visible(fn (callable $get): bool => (bool) $get('invoice_requested')),

                        Forms\Components\TextInput::make('invoice_nip')
                            ->label('NIP')
                            ->maxLength(13)
                            ->visible(fn (callable $get): bool => (bool) $get('invoice_requested')),

                        Forms\Components\TextInput::make('invoice_street')
                            ->label('Ulica')
                            ->maxLength(255)
                            ->visible(fn (callable $get): bool => (bool) $get('invoice_requested')),

                        Forms\Components\TextInput::make('invoice_street_number')
                            ->label('Nr budynku')
                            ->maxLength(20)
                            ->visible(fn (callable $get): bool => (bool) $get('invoice_requested')),

                        Forms\Components\TextInput::make('invoice_postal_code')
                            ->label('Kod pocztowy')
                            ->maxLength(10)
                            ->visible(fn (callable $get): bool => (bool) $get('invoice_requested')),

                        Forms\Components\TextInput::make('invoice_city')
                            ->label('Miasto')
                            ->maxLength(255)
                            ->visible(fn (callable $get): bool => (bool) $get('invoice_requested')),
                    ])
                    ->columns(2)
                    ->collapsed(),

                Section::make('Notatki')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label('Notatki')
                            ->rows(3)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('cancellation_reason')
                            ->label('Powód anulowania')
                            ->rows(2)
                            ->columnSpanFull()
                            ->visible(fn (?Model $record): bool => $record?->status === RentalStatus::Cancelled),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('service.name')
                    ->label('Przedmiot')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Klient')
                    ->getStateUsing(fn (Rental $record): string => $record->customer_name)
                    ->searchable(query: function ($query, string $search) {
                        $query->where(function ($q) use ($search) {
                            $q->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                    }),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('start_date')
                    ->label('Od')
                    ->date('d.m.Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('end_date')
                    ->label('Do')
                    ->date('d.m.Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Ilość')
                    ->numeric(),

                Tables\Columns\TextColumn::make('total_price')
                    ->label('Cena')
                    ->money('PLN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string|RentalStatus $state): string => $state instanceof RentalStatus ? $state->color() : (RentalStatus::tryFrom($state)?->color() ?? 'gray'))
                    ->formatStateUsing(fn (string|RentalStatus $state): string => $state instanceof RentalStatus ? $state->label() : (RentalStatus::tryFrom($state)?->label() ?? $state)),

                Tables\Columns\TextColumn::make('held_until')
                    ->label('Hold wygasa')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(RentalStatus::options()),

                Tables\Filters\SelectFilter::make('service_id')
                    ->label('Przedmiot')
                    ->relationship('service', 'name'),
            ])
            ->recordActions([
                Actions\Action::make('confirm')
                    ->label('Potwierdź')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Potwierdzić wypożyczenie?')
                    ->action(fn (Rental $record) => $record->update(['status' => RentalStatus::Confirmed]))
                    ->visible(fn (Rental $record): bool => in_array($record->status, [RentalStatus::Held, RentalStatus::Pending])),

                Actions\Action::make('markPickedUp')
                    ->label('Odebrane')
                    ->icon('heroicon-o-truck')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Oznaczyć jako odebrane?')
                    ->action(fn (Rental $record) => $record->update(['status' => RentalStatus::Active]))
                    ->visible(fn (Rental $record): bool => $record->status === RentalStatus::Confirmed),

                Actions\Action::make('markReturned')
                    ->label('Zwrócone')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Oznaczyć jako zwrócone?')
                    ->action(fn (Rental $record) => $record->update(['status' => RentalStatus::Returned]))
                    ->visible(fn (Rental $record): bool => $record->status === RentalStatus::Active),

                Actions\Action::make('cancel')
                    ->label('Anuluj')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Anulować wypożyczenie?')
                    ->form([
                        Forms\Components\Textarea::make('cancellation_reason')
                            ->label('Powód anulowania')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(fn (Rental $record, array $data) => $record->update([
                        'status' => RentalStatus::Cancelled,
                        'cancellation_reason' => $data['cancellation_reason'],
                    ]))
                    ->visible(fn (Rental $record): bool => ! in_array($record->status, [RentalStatus::Returned, RentalStatus::Cancelled, RentalStatus::Expired])),

                Actions\EditAction::make(),
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
            'index' => Pages\ListRentals::route('/'),
            'create' => Pages\CreateRental::route('/create'),
            'edit' => Pages\EditRental::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }
}
