<?php

namespace App\Filament\Platform\Resources;

use App\Actions\Offboarding\StartOrganizationOffboarding;
use App\Enums\Industry;
use App\Enums\OrganizationLifecycleState;
use App\Filament\Platform\Resources\OrganizationResource\Pages;
use App\Filament\Platform\Resources\OrganizationResource\RelationManagers;
use App\Models\Organization;
use App\Models\OrganizationLifecycleLog;
use App\Models\Payment;
use App\Models\TenantPayment;
use App\Rules\ValidOrganizationSlug;
use App\Services\TenantObligationService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Organizations';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        // Hide the delete button for non-Closed organisations. The observer's deleting()
        // hook remains as a backstop, but surfacing the button only when deletion is
        // actually permissible reduces confusion and accidental clicks.
        return (auth()->user()?->hasRole('super-admin') ?? false)
            && $record instanceof Organization
            && $record->lifecycle_state === OrganizationLifecycleState::Closed;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(3)
                    ->columnSpanFull()
                    ->schema([
                        Tabs::make('Organizacja')
                            ->columnSpan(2)
                            ->persistTabInQueryString()
                            ->tabs([
                                Tab::make('Dane')
                                    ->icon('heroicon-o-identification')
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Nazwa')
                                            ->required()
                                            ->maxLength(255)
                                            ->helperText('Wyświetlana nazwa organizacji, widoczna w panelu i na fakturach.'),

                                        Forms\Components\TextInput::make('slug')
                                            ->label('Slug')
                                            ->required()
                                            ->maxLength(63)
                                            ->unique(ignoreRecord: true)
                                            ->rules([new ValidOrganizationSlug])
                                            ->helperText('Używany jako subdomena: {slug}.registro.app — zmiana wymaga aktualizacji DNS.'),

                                        Forms\Components\Select::make('booking_type')
                                            ->label('Typ rezerwacji')
                                            ->options([
                                                'time_slot' => 'Rezerwacje terminowe (salon, klinika)',
                                                'item_rental' => 'Wypożyczenia (wypożyczalnia sprzętu)',
                                                'both' => 'Oba',
                                            ])
                                            ->required()
                                            ->default('time_slot')
                                            ->helperText('Determinuje domyślne moduły, jeśli branża nie jest ustawiona.'),

                                        Forms\Components\Select::make('industry')
                                            ->label('Branża')
                                            ->options(Industry::class)
                                            ->native(false)
                                            ->live()
                                            ->helperText('Zmiana branży NIE resetuje seed data ani ręcznych override modułów.'),

                                        Forms\Components\Select::make('owner_id')
                                            ->label('Właściciel')
                                            ->relationship('owner', 'email')
                                            ->searchable()
                                            ->preload()
                                            ->required()
                                            ->helperText('Konto, które ma rolę właściciela tej organizacji.'),
                                    ])
                                    ->columns(1),

                                Tab::make('Moduły')
                                    ->icon('heroicon-o-squares-2x2')
                                    ->schema([
                                        Forms\Components\Placeholder::make('module_defaults_note')
                                            ->label('Domyślny zestaw dla branży')
                                            ->content(fn (?Organization $record, Get $get) => self::industryDefaultsNote($record, $get('industry')))
                                            ->helperText('Każdy przełącznik poniżej zapisuje ręczny override w settings.modules — nadpisuje domyślne ustawienia branży tylko dla TEJ organizacji.'),

                                        Forms\Components\Toggle::make('settings.modules.services')
                                            ->label('Usługi')
                                            ->helperText('Katalog usług + rezerwacje time-slot.'),

                                        Forms\Components\Toggle::make('settings.modules.bookings')
                                            ->label('Rezerwacje')
                                            ->helperText('Kalendarz rezerwacji terminowych (wymaga modułu Usługi).'),

                                        Forms\Components\Toggle::make('settings.modules.rentals')
                                            ->label('Wypożyczenia')
                                            ->helperText('Katalog sprzętu + wypożyczenia (wymaga modułu Pojazdy dla branż z flotą).'),

                                        Forms\Components\Toggle::make('settings.modules.staff')
                                            ->label('Kadra')
                                            ->helperText('Pracownicy, grafiki, przypisania wizyt.'),

                                        Forms\Components\Toggle::make('settings.modules.customers')
                                            ->label('Klienci')
                                            ->helperText('Baza klientów.'),

                                        Forms\Components\Toggle::make('settings.modules.vehicles')
                                            ->label('Pojazdy')
                                            ->helperText('Katalog pojazdów/sprzętu (marki, modele, typy).'),

                                        Forms\Components\Toggle::make('settings.modules.communication')
                                            ->label('Komunikacja')
                                            ->helperText('Powiadomienia e-mail/SMS.'),

                                        Forms\Components\Toggle::make('settings.modules.website')
                                            ->label('Strona WWW')
                                            ->helperText('Publiczna witryna tenanta.'),

                                        Forms\Components\Toggle::make('settings.modules.service_area')
                                            ->label('Obszary usług')
                                            ->helperText('Ograniczenia obszaru obsługi (definiowanie stref dojazdu).'),

                                        Fieldset::make('Funkcje dodatkowe')
                                            ->columns(1)
                                            ->schema([
                                                Forms\Components\Placeholder::make('features_note')
                                                    ->hiddenLabel()
                                                    ->content('To osobny system od modułów powyżej — moduł włącza CAŁY zasób w panelu, funkcja włącza konkretne pole/zachowanie w istniejącym formularzu.'),

                                                Forms\Components\Toggle::make('settings.features.vehicles')
                                                    ->label('Katalog pojazdów (pola formularza)')
                                                    ->helperText('Wybór typu pojazdu, marki/modelu w formularzu usługi/zamówienia.'),

                                                Forms\Components\Toggle::make('settings.features.mobile_service')
                                                    ->label('Usługa mobilna (dojazd)')
                                                    ->helperText('Wybór lokalizacji, adres, Google Maps w checkout.'),

                                                Forms\Components\Toggle::make('settings.features.service_area')
                                                    ->label('Walidacja obszaru usług')
                                                    ->helperText('Sprawdza, czy lokalizacja klienta mieści się w obszarze obsługi (wymaga modułu Obszary usług).'),
                                            ]),
                                    ])
                                    ->columns(1),

                                Tab::make('Rozliczenia')
                                    ->icon('heroicon-o-credit-card')
                                    ->schema([
                                        Forms\Components\DateTimePicker::make('trial_ends_at')
                                            ->label('Koniec okresu próbnego')
                                            ->nullable()
                                            ->helperText('Po tej dacie konto wymaga aktywnej subskrypcji.'),

                                        Forms\Components\Placeholder::make('subscription_status')
                                            ->label('Status subskrypcji')
                                            ->content(fn (?Organization $record) => $record?->subscription_status ?? '—'),

                                        Forms\Components\Placeholder::make('monthly_fee')
                                            ->label('Opłata miesięczna')
                                            ->content(fn (?Organization $record) => $record?->monthly_fee !== null
                                                ? number_format((float) $record->monthly_fee, 2, ',', ' ').' zł'
                                                : '—'),

                                        Forms\Components\Placeholder::make('subscribed_at')
                                            ->label('Data aktywacji subskrypcji')
                                            ->content(fn (?Organization $record) => $record?->subscribed_at?->format('d.m.Y H:i') ?? '—'),
                                    ])
                                    ->columns(2),
                            ]),

                        Section::make('Stan')
                            ->columnSpan(1)
                            ->extraAttributes(['class' => 'lg:sticky lg:top-6'])
                            ->headerActions([
                                Actions\ActionGroup::make(self::lifecycleActions())
                                    ->label('Akcje')
                                    ->icon('heroicon-m-ellipsis-vertical')
                                    ->color('gray'),
                            ])
                            ->schema([
                                Forms\Components\Placeholder::make('lifecycle_state')
                                    ->label('Stan cyklu życia')
                                    ->content(fn (?Organization $record) => $record?->lifecycle_state?->label() ?? 'Aktywna'),

                                Forms\Components\Placeholder::make('closure_requested_at')
                                    ->label('Wniosek o zamknięcie')
                                    ->content(fn (?Organization $record) => $record?->closure_requested_at?->format('d.m.Y H:i') ?? '—'),

                                Forms\Components\Placeholder::make('members_count_display')
                                    ->label('Liczba członków')
                                    ->content(fn (?Organization $record) => $record ? (string) $record->members()->count() : '—'),

                                Forms\Components\Placeholder::make('owner_email_display')
                                    ->label('E-mail właściciela')
                                    ->content(fn (?Organization $record) => $record?->owner?->email ?? '—'),
                            ])
                            ->columns(1),
                    ]),
            ]);
    }

    /**
     * Lifecycle-state actions (Zawieś/Reaktywuj/Zamknij/Odrzuć wniosek) — shared between
     * the table row actions and the Edit form "Stan" sidebar so the rules for each
     * transition live in exactly one place.
     *
     * @return array<\Filament\Actions\Action>
     */
    protected static function lifecycleActions(): array
    {
        return [
            Actions\Action::make('suspend')
                ->label('Zawieś')
                ->icon('heroicon-o-pause-circle')
                ->color('warning')
                ->requiresConfirmation()
                ->authorize(fn () => auth()->user()?->hasRole('super-admin') ?? false)
                ->visible(fn (?Organization $record) => $record instanceof Organization
                    && (auth()->user()?->hasRole('super-admin') ?? false)
                    && $record->lifecycle_state === OrganizationLifecycleState::Active)
                ->action(function (Organization $record): void {
                    $record->lifecycle_state = OrganizationLifecycleState::Suspended;
                    $record->save();
                    Notification::make()->title('Organizacja zawieszona')->warning()->send();
                }),

            Actions\Action::make('reactivate')
                ->label('Reaktywuj')
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->requiresConfirmation()
                ->authorize(fn () => auth()->user()?->hasRole('super-admin') ?? false)
                ->visible(fn (?Organization $record) => $record instanceof Organization
                    && (auth()->user()?->hasRole('super-admin') ?? false)
                    && in_array(
                        $record->lifecycle_state,
                        [OrganizationLifecycleState::Suspended, OrganizationLifecycleState::Closing],
                        true
                    ))
                ->action(function (Organization $record): void {
                    $record->lifecycle_state = OrganizationLifecycleState::Active;
                    $record->save();
                    Notification::make()->title('Organizacja reaktywowana')->success()->send();
                }),

            Actions\Action::make('initiateClosing')
                ->label('Zamknij (Graceful)')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Graceful Offboarding — Zamknij organizację')
                ->modalDescription(function (Organization $record): HtmlString {
                    $counts = app(TenantObligationService::class)->activeObligations($record);
                    $graceDays = (int) config('retention.closing_grace_days', 14);

                    $parts = ["Proces zamknięcia organizacji **{$record->name}** zostanie zainicjowany."];

                    if ($counts['total'] > 0) {
                        $parts[] = sprintf(
                            'Aktywne zobowiązania (%d wizyt, %d zamówień, %d wypożyczeń) zostaną **automatycznie anulowane**, a klienci powiadomieni emailem.',
                            $counts['appointments'],
                            $counts['orders'],
                            $counts['rentals'],
                        );
                    }

                    $parts[] = "Okno przywrócenia: **{$graceDays} dni** od teraz (akcja Reaktywuj). Po tym czasie organizacja przejdzie automatycznie w stan Zamknięta.";
                    $parts[] = 'Refundy za opłacone zamówienia i kaucje za wypożyczenia wymagają ręcznego przetworzenia (brak automatycznej integracji z Przelewy24).';

                    return new HtmlString(Str::markdown(implode("\n\n", $parts)));
                })
                ->authorize(fn () => auth()->user()?->hasRole('super-admin') ?? false)
                ->visible(fn (?Organization $record) => $record instanceof Organization
                    && (auth()->user()?->hasRole('super-admin') ?? false)
                    && in_array(
                        $record->lifecycle_state,
                        [OrganizationLifecycleState::Active, OrganizationLifecycleState::Suspended],
                        true
                    ))
                ->action(function (Organization $record): void {
                    app(StartOrganizationOffboarding::class)->execute($record);
                    Notification::make()
                        ->title('Offboarding zainicjowany')
                        ->body('Klienci zostaną powiadomieni o anulowaniu. Okno przywrócenia: '.config('retention.closing_grace_days', 14).' dni.')
                        ->danger()
                        ->send();
                }),

            Actions\Action::make('clearClosureRequest')
                ->label('Odrzuć wniosek')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Odrzuć wniosek o zamknięcie')
                ->modalDescription(fn (Organization $record) => $record->lifecycle_state === OrganizationLifecycleState::Active
                    ? 'Wniosek zostanie odrzucony — konto pozostanie aktywne. Tenant zostanie poinformowany osobnym kanałem.'
                    : 'UWAGA: organizacja jest już w stanie „'.$record->lifecycle_state->value.'". Odrzucenie wniosku usunie tylko znacznik wniosku — NIE cofa rozpoczętego procesu zamykania (użyj Reaktywuj).')
                ->authorize(fn () => auth()->user()?->hasRole('super-admin') ?? false)
                ->visible(fn (?Organization $record) => $record instanceof Organization && $record->closure_requested_at !== null)
                ->action(function (Organization $record): void {
                    $record->closure_requested_at = null;
                    $record->save();
                    OrganizationLifecycleLog::record($record, 'closure_request_dismissed', auth()->user());
                    Notification::make()->title('Wniosek odrzucony')->success()->send();
                }),
        ];
    }

    /**
     * Short PL note explaining which modules are on by default for the selected industry
     * (or booking_type, when no industry is set). Read from Industry::defaultModules() —
     * single source of truth, no static copy to keep in sync.
     */
    private static function industryDefaultsNote(?Organization $record, mixed $industryValue): string
    {
        $industry = $industryValue instanceof Industry
            ? $industryValue
            : (is_string($industryValue) ? Industry::tryFrom($industryValue) : null);

        $industry ??= $record?->industry;

        if ($industry instanceof Industry) {
            $modules = collect($industry->defaultModules())
                ->map(fn (string $module) => self::moduleLabel($module))
                ->implode(', ');

            return "Dla branży „{$industry->label()}” domyślnie włączone: {$modules}.";
        }

        return 'Brak ustawionej branży — moduły dziedziczą domyślne ustawienia typu rezerwacji (zakładka „Dane”).';
    }

    private static function moduleLabel(string $module): string
    {
        return match ($module) {
            'services' => 'Usługi',
            'bookings' => 'Rezerwacje',
            'rentals' => 'Wypożyczenia',
            'staff' => 'Kadra',
            'customers' => 'Klienci',
            'vehicles' => 'Pojazdy',
            'communication' => 'Komunikacja',
            'website' => 'Strona WWW',
            'service_area' => 'Obszary usług',
            default => $module,
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('owner.email')
                    ->label('Owner')
                    ->searchable(),

                Tables\Columns\TextColumn::make('members_count')
                    ->counts('members')
                    ->label('Members'),

                Tables\Columns\TextColumn::make('lifecycle_state')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->color(fn ($state) => match ($state) {
                        OrganizationLifecycleState::Active => 'success',
                        OrganizationLifecycleState::Suspended => 'warning',
                        OrganizationLifecycleState::Closing => 'danger',
                        OrganizationLifecycleState::Closed => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('booking_type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'time_slot' => 'Rezerwacje',
                        'item_rental' => 'Wypożyczenia',
                        'both' => 'Oba',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'time_slot' => 'info',
                        'item_rental' => 'warning',
                        'both' => 'success',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('industry')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->color(fn ($state) => match ($state) {
                        Industry::EquipmentRental => 'warning',
                        Industry::AutoDetailing => 'info',
                        Industry::GeneralServices => 'gray',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('trial_ends_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('closure_requested_at')
                    ->label('Closure Request')
                    ->dateTime()
                    ->sortable()
                    ->badge()
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('booking_type')
                    ->options([
                        'time_slot' => 'Time-Slot',
                        'item_rental' => 'Item Rental',
                        'both' => 'Both',
                    ]),
                Tables\Filters\SelectFilter::make('industry')
                    ->options(Industry::class),
                Tables\Filters\SelectFilter::make('lifecycle_state')
                    ->options(OrganizationLifecycleState::class)
                    ->label('Lifecycle State'),
                Tables\Filters\TernaryFilter::make('is_active'),
                Tables\Filters\TernaryFilter::make('closure_requested_at')
                    ->label('Pending Closure Request')
                    ->nullable()
                    ->trueLabel('With closure request')
                    ->falseLabel('Without closure request'),
            ])
            ->actions([
                Actions\ActionGroup::make([
                    Actions\EditAction::make(),

                    Actions\Action::make('extendTrial')
                        ->label('+14 dni trial')
                        ->icon('heroicon-o-clock')
                        ->action(fn (Organization $record) => $record->update([
                            'trial_ends_at' => ($record->trial_ends_at ?? now())->addDays(14),
                        ]))
                        ->requiresConfirmation(),

                    // Lifecycle state actions — go through the state machine via OrganizationObserver.
                    // Shared with the Edit form "Stan" sidebar via self::lifecycleActions().
                    ...self::lifecycleActions(),
                ]),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->before(function (Collection $records, Actions\DeleteBulkAction $action): void {
                            $service = app(TenantObligationService::class);
                            $blocked = [];

                            foreach ($records as $record) {
                                if ($record->lifecycle_state !== OrganizationLifecycleState::Closed) {
                                    $blocked[] = sprintf(
                                        '%s (stan: %s — wymagany: Zamknięta)',
                                        $record->name,
                                        $record->lifecycle_state?->label() ?? '?',
                                    );

                                    continue;
                                }

                                $counts = $service->activeObligations($record);
                                if ($counts['total'] > 0) {
                                    $blocked[] = sprintf(
                                        '%s (%d wizyt, %d zamówień, %d wypożyczeń)',
                                        $record->name,
                                        $counts['appointments'],
                                        $counts['orders'],
                                        $counts['rentals'],
                                    );

                                    continue;
                                }

                                // Mirror OrganizationObserver Guard 4: legal records must be
                                // anonymised/archived before deletion (Art. 112 VAT / Art. 70 Ordynacja).
                                $legalOrders = $record->orders()->withoutGlobalScope('organization')->count();
                                $legalPayments = Payment::withoutGlobalScope('organization')
                                    ->where('organization_id', $record->id)->count();
                                $legalRentals = $record->rentals()->withoutGlobalScope('organization')->count();
                                $legalTenantPayments = TenantPayment::where('organization_id', $record->id)->count();
                                $totalLegal = $legalOrders + $legalPayments + $legalRentals + $legalTenantPayments;

                                if ($totalLegal > 0) {
                                    $blocked[] = sprintf(
                                        '%s (rekordy prawne: %d zam., %d płat., %d wyp., %d SaaS — wymagana archiwizacja)',
                                        $record->name,
                                        $legalOrders,
                                        $legalPayments,
                                        $legalRentals,
                                        $legalTenantPayments,
                                    );
                                }
                            }

                            if (! empty($blocked)) {
                                Notification::make()
                                    ->title('Nie można usunąć organizacji')
                                    ->body('Blokady uniemożliwiają usunięcie: '.implode('; ', $blocked).'. Zamknij organizacje przed usunięciem.')
                                    ->danger()
                                    ->persistent()
                                    ->send();

                                $action->halt();
                            }
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizations::route('/'),
            'create' => Pages\CreateOrganization::route('/create'),
            'edit' => Pages\EditOrganization::route('/{record}/edit'),
        ];
    }
}
