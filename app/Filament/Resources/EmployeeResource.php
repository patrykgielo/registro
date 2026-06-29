<?php

namespace App\Filament\Resources;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Resources\EmployeeResource\RelationManagers;
use App\Models\Appointment;
use App\Models\User;
use App\Support\TenantFeature;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use UnitEnum;

class EmployeeResource extends BaseResource
{
    protected static ?string $model = User::class;

    protected static ?string $module = 'staff';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static string|UnitEnum|null $navigationGroup = 'staff';

    protected static ?string $modelLabel = 'Pracownik';

    protected static ?string $pluralModelLabel = 'Pracownicy';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $tenant = TenantFeature::currentTenant();

        return parent::getEloquentQuery()
            ->whereHas('roles', fn ($q) => $q->where('name', 'staff'))
            ->when($tenant, fn ($q) => $q->whereHas(
                'organizations',
                fn ($q2) => $q2->where('organizations.id', $tenant->id)
            ));
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
                        ->default('staff'),
                    Forms\Components\Placeholder::make('role_info')
                        ->label('Rola użytkownika')
                        ->content('Ten użytkownik będzie miał automatycznie przypisaną rolę: Pracownik (Staff)')
                        ->helperText('Aby zmienić rolę użytkownika, przejdź do sekcji Użytkownicy'),
                ]),
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
                Tables\Columns\TextColumn::make('staffAppointments_count')
                    ->label('Liczba wizyt')
                    ->counts('staffAppointments')
                    ->sortable()
                    ->badge()
                    ->color('primary'),
                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('Zweryfikowany')
                    ->boolean()
                    ->sortable()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data dodania')
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
                    ->label('Usuń')
                    ->before(function (User $record, Actions\DeleteAction $action): void {
                        if (self::hasFutureActiveAppointments($record)) {
                            Notification::make()
                                ->title('Nie można usunąć pracownika')
                                ->body('Pracownik ma przyszłe wizyty — przypisz je ponownie przed usunięciem.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->label('Usuń zaznaczonych')
                        ->before(function (Collection $records, Actions\DeleteBulkAction $action): void {
                            $blocked = $records->filter(fn (User $user) => self::hasFutureActiveAppointments($user));

                            if ($blocked->isNotEmpty()) {
                                Notification::make()
                                    ->title('Nie można usunąć pracowników')
                                    ->body('Następujący pracownicy mają przyszłe wizyty — przypisz je ponownie przed usunięciem: '
                                        .$blocked->map(fn (User $u) => $u->name)->implode(', ').'.')
                                    ->danger()
                                    ->send();

                                $action->halt();
                            }
                        }),
                ]),
            ])
            ->emptyStateHeading('Brak pracowników')
            ->emptyStateDescription('Dodaj pierwszego pracownika klikając przycisk poniżej.')
            ->emptyStateIcon('heroicon-o-user-circle');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ServicesRelationManager::class,
            RelationManagers\StaffSchedulesRelationManager::class,
            RelationManagers\DateExceptionsRelationManager::class,
            RelationManagers\VacationPeriodsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
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

    /**
     * Checks whether the given staff member has future active (pending/confirmed) appointments
     * within the current tenant scope. Used to guard delete actions.
     *
     * Faza 5.7 will add a ReassignStaffAppointments flow; for now, deletion is blocked
     * until the admin manually reassigns all future appointments.
     */
    private static function hasFutureActiveAppointments(User $staff): bool
    {
        $tenant = TenantFeature::currentTenant();

        if ($tenant === null) {
            return false;
        }

        return Appointment::withoutGlobalScope('organization')
            ->where('staff_id', $staff->id)
            ->where('organization_id', $tenant->id)
            ->whereIn('status', [
                AppointmentStatus::Pending->value,
                AppointmentStatus::Confirmed->value,
            ])
            ->where('appointment_date', '>=', now()->toDateString())
            ->exists();
    }
}
