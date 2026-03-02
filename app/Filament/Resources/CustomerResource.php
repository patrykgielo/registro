<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers;
use App\Models\User;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|UnitEnum|null $navigationGroup = 'users';

    protected static ?string $modelLabel = 'Klient';

    protected static ?string $pluralModelLabel = 'Klienci';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('roles', function ($query) {
            $query->where('name', 'customer');
        });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dane osobowe')
                ->schema([
                    Forms\Components\TextInput::make('first_name')
                        ->label('Imię')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Jan'),
                    Forms\Components\TextInput::make('last_name')
                        ->label('Nazwisko')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Kowalski'),
                ])->columns(2),

            Section::make('Kontakt')
                ->schema([
                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255)
                        ->placeholder('jan.kowalski@example.com'),
                    Forms\Components\TextInput::make('phone_e164')
                        ->label('Telefon')
                        ->tel()
                        ->maxLength(20)
                        ->placeholder('+48501234567')
                        ->helperText('Format międzynarodowy E.164, np. +48501234567')
                        ->regex('/^\+\d{1,3}\d{6,14}$/'),
                    Forms\Components\DateTimePicker::make('email_verified_at')
                        ->label('Email zweryfikowany')
                        ->displayFormat('d.m.Y H:i'),
                ])->columns(2),

            Section::make('Adres')
                ->schema([
                    Forms\Components\TextInput::make('street_name')
                        ->label('Ulica')
                        ->maxLength(255)
                        ->placeholder('Marszałkowska'),
                    Forms\Components\TextInput::make('street_number')
                        ->label('Numer')
                        ->maxLength(20)
                        ->placeholder('12/34'),
                    Forms\Components\TextInput::make('city')
                        ->label('Miasto')
                        ->maxLength(255)
                        ->placeholder('Warszawa'),
                    Forms\Components\TextInput::make('postal_code')
                        ->label('Kod pocztowy')
                        ->maxLength(10)
                        ->placeholder('00-000')
                        ->mask('99-999')
                        ->regex('/^\d{2}-\d{3}$/'),
                    Forms\Components\Textarea::make('access_notes')
                        ->label('Informacje o dostępie')
                        ->maxLength(1000)
                        ->rows(3)
                        ->placeholder('Dodatkowe informacje o adresie, np. kod do bramy, piętro...')
                        ->columnSpanFull(),
                ])->columns(4)->collapsible(),

            Section::make('Hasło')
                ->schema([
                    Forms\Components\TextInput::make('password')
                        ->label('Hasło')
                        ->password()
                        ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn (string $context): bool => $context === 'create')
                        ->minLength(8)
                        ->maxLength(255)
                        ->revealable()
                        ->helperText('Pozostaw puste, aby zachować obecne hasło'),
                ]),

            Section::make('Rola')
                ->schema([
                    Forms\Components\Hidden::make('role')
                        ->default('customer'),
                    Forms\Components\Placeholder::make('role_info')
                        ->label('Rola użytkownika')
                        ->content('Ten użytkownik będzie miał automatycznie przypisaną rolę: Klient')
                        ->helperText('Aby zmienić rolę użytkownika, przejdź do sekcji Użytkownicy'),
                ]),

            Section::make('Limity profilu')
                ->schema([
                    Forms\Components\TextInput::make('max_vehicles')
                        ->label('Limit pojazdów')
                        ->numeric()
                        ->default(1)
                        ->minValue(1)
                        ->maxValue(10)
                        ->helperText('Ile pojazdów klient może zapisać w profilu'),
                    Forms\Components\TextInput::make('max_addresses')
                        ->label('Limit adresów')
                        ->numeric()
                        ->default(1)
                        ->minValue(1)
                        ->maxValue(10)
                        ->helperText('Ile adresów klient może zapisać w profilu'),
                ])->columns(2)->collapsible(),

            Section::make('Zgody marketingowe')
                ->schema([
                    Forms\Components\Placeholder::make('email_marketing_status')
                        ->label('Email marketing')
                        ->content(fn ($record) => $record?->hasEmailMarketingConsent()
                            ? '✅ Zgoda udzielona: '.$record->email_marketing_consent_at?->format('d.m.Y H:i')
                            : '❌ Brak zgody'),
                    Forms\Components\Placeholder::make('email_newsletter_status')
                        ->label('Email newsletter')
                        ->content(fn ($record) => $record?->hasEmailNewsletterConsent()
                            ? '✅ Zgoda udzielona: '.$record->email_newsletter_consent_at?->format('d.m.Y H:i')
                            : '❌ Brak zgody'),
                    Forms\Components\Placeholder::make('sms_consent_status')
                        ->label('SMS powiadomienia')
                        ->content(fn ($record) => $record?->hasSmsConsent()
                            ? '✅ Zgoda udzielona: '.$record->sms_consent_given_at?->format('d.m.Y H:i')
                            : '❌ Brak zgody'),
                    Forms\Components\Placeholder::make('sms_marketing_status')
                        ->label('SMS marketing')
                        ->content(fn ($record) => $record?->hasSmsMarketingConsent()
                            ? '✅ Zgoda udzielona: '.$record->sms_marketing_consent_at?->format('d.m.Y H:i')
                            : '❌ Brak zgody'),
                ])->columns(2)->collapsible()->hiddenOn('create'),

            Section::make('Status konta')
                ->schema([
                    Forms\Components\Placeholder::make('pending_deletion_status')
                        ->label('Żądanie usunięcia')
                        ->content(fn ($record) => $record?->hasPendingDeletion()
                            ? '⚠️ Klient złożył żądanie usunięcia konta: '.$record->deletion_requested_at?->format('d.m.Y H:i')
                            : '✅ Konto aktywne'),
                    Forms\Components\Placeholder::make('pending_email_status')
                        ->label('Zmiana email')
                        ->content(fn ($record) => $record?->hasPendingEmailChange()
                            ? '⚠️ Oczekuje na zmianę email na: '.$record->pending_email
                            : '✅ Brak oczekujących zmian'),
                ])->columns(2)->collapsible()->hiddenOn('create'),
        ]);
    }

    public static function table(Table $table): Table
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
                    ->sortable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('phone_e164')
                    ->label('Telefon')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('city')
                    ->label('Miasto')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('customerAppointments_count')
                    ->label('Liczba rezerwacji')
                    ->counts('customerAppointments')
                    ->sortable()
                    ->badge()
                    ->color('success'),
                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('Zweryfikowany')
                    ->boolean()
                    ->sortable()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data rejestracji')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('email_verified_at')
                    ->label('Email zweryfikowany')
                    ->nullable()
                    ->placeholder('Wszystkie')
                    ->trueLabel('Zweryfikowane')
                    ->falseLabel('Niezweryfikowane'),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->label('Edytuj'),
                Actions\DeleteAction::make()
                    ->label('Usuń'),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->label('Usuń zaznaczonych'),
                ]),
            ])
            ->emptyStateHeading('Brak klientów')
            ->emptyStateDescription('Dodaj pierwszego klienta klikając przycisk poniżej.')
            ->emptyStateIcon('heroicon-o-user-group');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\VehiclesRelationManager::class,
            RelationManagers\AddressesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getEloquentQuery()->count();
    }

    /**
     * Restrict access to admins and super-admins only.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }
}
