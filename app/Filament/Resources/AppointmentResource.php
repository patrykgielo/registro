<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppointmentResource\Pages;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Support\TenantFeature;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AppointmentResource extends BaseResource
{
    protected static ?string $model = Appointment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationLabel = 'Wizyty';

    protected static ?string $modelLabel = 'Wizyta';

    protected static ?string $pluralModelLabel = 'Wizyty';

    protected static string|UnitEnum|null $navigationGroup = 'appointments';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('service_id')
                    ->label('Usługa')
                    ->relationship('service', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($state, callable $set) => $set('end_time', null)),

                Forms\Components\Select::make('customer_id')
                    ->label('Klient')
                    ->relationship('customer', 'first_name', fn (Builder $query) => $query->whereHas('roles', fn ($q) => $q->where('name', 'customer'))
                    )
                    ->getOptionLabelFromRecordUsing(fn (User $record) => $record->full_name)
                    ->searchable()
                    ->preload()
                    ->required()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('first_name')
                            ->label('Imię')
                            ->required(),
                        Forms\Components\TextInput::make('last_name')
                            ->label('Nazwisko')
                            ->required(),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required(),
                        Forms\Components\TextInput::make('password')
                            ->label('Hasło')
                            ->password()
                            ->required(),
                    ]),

                Forms\Components\Select::make('staff_id')
                    ->label('Pracownik')
                    ->relationship('staff', 'first_name', fn (Builder $query) => $query->whereHas('roles', fn ($q) => $q->where('name', 'staff'))
                    )
                    ->getOptionLabelFromRecordUsing(fn (User $record) => $record->full_name)
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live(),

                Forms\Components\DatePicker::make('appointment_date')
                    ->label('Data wizyty')
                    ->required()
                    ->native(false)
                    ->minDate(fn (?Appointment $record): ?string => $record ? null : now()->toDateString())
                    ->live(),

                Forms\Components\TimePicker::make('start_time')
                    ->label('Czas rozpoczęcia')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $get, callable $set) {
                        $serviceId = $get('service_id');
                        if ($state && $serviceId) {
                            $service = Service::find($serviceId);
                            if ($service) {
                                $start = Carbon::parse($state);
                                $end = $start->copy()->addMinutes($service->duration_minutes);
                                $set('end_time', $end->format('H:i:s'));
                            }
                        }
                    }),

                Forms\Components\TimePicker::make('end_time')
                    ->label('Czas zakończenia')
                    ->required()
                    ->live(),

                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Oczekująca',
                        'confirmed' => 'Potwierdzona',
                        'cancelled' => 'Anulowana',
                        'completed' => 'Zakończona',
                    ])
                    ->default('pending')
                    ->required()
                    ->native(false),

                Forms\Components\Textarea::make('notes')
                    ->label('Notatki')
                    ->rows(3)
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('cancellation_reason')
                    ->label('Powód anulowania')
                    ->rows(3)
                    ->visible(fn (callable $get) => $get('status') === 'cancelled')
                    ->columnSpanFull(),

                Section::make('Lokalizacja')
                    ->visible(fn () => TenantFeature::active('mobile_service') || TenantFeature::active('vehicles'))
                    ->schema([
                        Forms\Components\TextInput::make('service_location_type')
                            ->label('Typ lokalizacji usługi')
                            ->readOnly(),
                        Forms\Components\TextInput::make('location_address')
                            ->label('Adres')
                            ->maxLength(500)
                            ->columnSpanFull()
                            ->readOnly(),
                        Forms\Components\TextInput::make('location_latitude')
                            ->label('Szerokość geograficzna')
                            ->numeric()
                            ->readOnly(),
                        Forms\Components\TextInput::make('location_longitude')
                            ->label('Długość geograficzna')
                            ->numeric()
                            ->readOnly(),
                        Forms\Components\TextInput::make('location_place_id')
                            ->label('Google Place ID')
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->readOnly(),
                        Forms\Components\Placeholder::make('google_maps_links')
                            ->label('Google Maps')
                            ->content(function ($record) {
                                if (! $record || ! $record->hasLocationData()) {
                                    return '—';
                                }

                                $links = [];

                                // View on Map button
                                if ($record->google_maps_link) {
                                    $links[] = sprintf(
                                        '<a href="%s" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 px-3 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                                            <svg class="w-4 h-4 flex-shrink-0" width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/>
                                            </svg>
                                            Pokaż na mapie
                                        </a>',
                                        htmlspecialchars($record->google_maps_link, ENT_QUOTES, 'UTF-8')
                                    );
                                }

                                // Navigation button
                                if ($record->google_maps_directions_link) {
                                    $links[] = sprintf(
                                        '<a href="%s" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 px-3 py-2 bg-blue-600 border border-blue-600 rounded-lg text-sm font-medium text-white hover:bg-blue-700 transition">
                                            <svg class="w-4 h-4 flex-shrink-0" width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M10 2a1 1 0 011 1v1.323l3.954 1.582 1.599-.8a1 1 0 01.894 1.79l-1.233.616 1.738 5.42a1 1 0 01-.285 1.05A3.989 3.989 0 0115 15a3.989 3.989 0 01-2.667-1.019 1 1 0 01-.285-1.05l1.715-5.349L11 6.477V16h2a1 1 0 110 2H7a1 1 0 110-2h2V6.477L6.237 7.582l1.715 5.349a1 1 0 01-.285 1.05A3.989 3.989 0 015 15a3.989 3.989 0 01-2.667-1.019 1 1 0 01-.285-1.05l1.738-5.42-1.233-.617a1 1 0 01.894-1.788l1.599.799L9 4.323V3a1 1 0 011-1z"/>
                                            </svg>
                                            Nawiguj
                                        </a>',
                                        htmlspecialchars($record->google_maps_directions_link, ENT_QUOTES, 'UTF-8')
                                    );
                                }

                                return new \Illuminate\Support\HtmlString('<div class="flex gap-2">'.implode('', $links).'</div>');
                            })
                            ->columnSpanFull()
                            ->visible(fn ($record) => $record && $record->hasLocationData()),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Section::make('Pojazd')
                    ->visible(fn () => TenantFeature::active('vehicles'))
                    ->schema([
                        Forms\Components\Select::make('vehicle_type_id')
                            ->label('Typ pojazdu')
                            ->relationship('vehicleType', 'name')
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('car_brand_id')
                            ->label('Marka')
                            ->relationship('carBrand', 'name')
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('car_model_id')
                            ->label('Model')
                            ->relationship('carModel', 'name')
                            ->searchable()
                            ->preload(),
                        Forms\Components\TextInput::make('vehicle_year')
                            ->label('Rocznik')
                            ->numeric()
                            ->minValue(1990)
                            ->maxValue(date('Y') + 1),
                        Forms\Components\TextInput::make('vehicle_custom_brand')
                            ->label('Własna marka')
                            ->maxLength(100)
                            ->readOnly()
                            ->visible(fn ($record) => $record && $record->vehicle_custom_brand),
                        Forms\Components\TextInput::make('vehicle_custom_model')
                            ->label('Własny model')
                            ->maxLength(100)
                            ->readOnly()
                            ->visible(fn ($record) => $record && $record->vehicle_custom_model),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(),

                Section::make('Dane do faktury')
                    ->schema([
                        Forms\Components\Placeholder::make('invoice_status')
                            ->label('Faktura')
                            ->content(fn ($record) => $record?->invoice_requested ? 'Tak, wymagana' : 'Nie'),
                        Forms\Components\Placeholder::make('invoice_company_display')
                            ->label('Nazwa')
                            ->content(fn ($record) => $record?->invoice_company_name ?? '—')
                            ->visible(fn ($record) => $record?->invoice_requested),
                        Forms\Components\Placeholder::make('invoice_nip_display')
                            ->label('NIP')
                            ->content(fn ($record) => $record?->invoice_nip ?: 'Brak')
                            ->visible(fn ($record) => $record?->invoice_requested),
                        Forms\Components\Placeholder::make('invoice_address_display')
                            ->label('Adres')
                            ->content(fn ($record) => $record?->invoice_requested
                                ? "{$record->invoice_street} {$record->invoice_street_number}, {$record->invoice_postal_code} {$record->invoice_city}"
                                : '—')
                            ->visible(fn ($record) => $record?->invoice_requested)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(fn ($record) => ! $record?->invoice_requested),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('service.name')
                    ->label('Usługa')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer.first_name')
                    ->label('Klient')
                    ->getStateUsing(fn ($record) => $record->customer?->full_name)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('staff.first_name')
                    ->label('Pracownik')
                    ->getStateUsing(fn ($record) => $record->staff?->full_name)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('appointment_date')
                    ->label('Data')
                    ->date('d.m.Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('start_time')
                    ->label('Od')
                    ->time('H:i'),
                Tables\Columns\TextColumn::make('end_time')
                    ->label('Do')
                    ->time('H:i'),
                Tables\Columns\TextColumn::make('location_address')
                    ->label('Lokalizacja')
                    ->searchable()
                    ->limit(50)
                    ->placeholder('—')
                    ->tooltip(function (Tables\Columns\TextColumn $column): ?string {
                        return $column->getState();
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('service_location_type')
                    ->label('Typ lokalizacji')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('vehicle_display')
                    ->label('Pojazd')
                    ->getStateUsing(fn ($record) => $record->vehicle_display)
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable()
                    ->visible(fn () => TenantFeature::active('vehicles')),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'confirmed',
                        'danger' => 'cancelled',
                        'secondary' => 'completed',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Oczekująca',
                        'confirmed' => 'Potwierdzona',
                        'cancelled' => 'Anulowana',
                        'completed' => 'Zakończona',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('appointment_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Oczekująca',
                        'confirmed' => 'Potwierdzona',
                        'cancelled' => 'Anulowana',
                        'completed' => 'Zakończona',
                    ]),
                Tables\Filters\SelectFilter::make('service')
                    ->label('Usługa')
                    ->relationship('service', 'name'),
                Tables\Filters\SelectFilter::make('staff')
                    ->label('Pracownik')
                    ->relationship('staff', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn (User $record) => $record->full_name),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            AppointmentResource\RelationManagers\AuditLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAppointments::route('/'),
            'create' => Pages\CreateAppointment::route('/create'),
            'edit' => Pages\EditAppointment::route('/{record}/edit'),
        ];
    }

    /**
     * Allow staff, admin, and super-admin to view appointments.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin', 'staff']) ?? false;
    }
}
