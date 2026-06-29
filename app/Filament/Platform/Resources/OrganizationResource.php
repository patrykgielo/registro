<?php

namespace App\Filament\Platform\Resources;

use App\Enums\Industry;
use App\Enums\OrganizationLifecycleState;
use App\Filament\Platform\Resources\OrganizationResource\Pages;
use App\Filament\Platform\Resources\OrganizationResource\RelationManagers;
use App\Models\Organization;
use App\Rules\ValidOrganizationSlug;
use App\Services\TenantObligationService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Organization Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(63)
                            ->unique(ignoreRecord: true)
                            ->rules([new ValidOrganizationSlug])
                            ->helperText('Used for subdomain: {slug}.registro.app'),

                        Forms\Components\Select::make('booking_type')
                            ->options([
                                'time_slot' => 'Time-Slot Booking (salon, klinika)',
                                'item_rental' => 'Item Rental (wypożyczalnia)',
                                'both' => 'Both',
                            ])
                            ->required()
                            ->default('time_slot'),

                        Forms\Components\Select::make('industry')
                            ->options(Industry::class)
                            ->helperText('Zmiana branży NIE resetuje seed data'),

                        Forms\Components\Select::make('owner_id')
                            ->relationship('owner', 'email')
                            ->searchable()
                            ->preload()
                            ->required(),

                        // is_active is derived from lifecycle_state — managed via lifecycle actions
                        // lifecycle_state is read-only here; use Suspend/Reactivate/Initiate Closing actions
                        Forms\Components\Placeholder::make('lifecycle_state')
                            ->label('Lifecycle State')
                            ->content(fn (?Organization $record) => $record?->lifecycle_state?->label() ?? 'Active')
                            ->helperText('Use the row actions (Suspend / Reactivate / Initiate Closing) to change state.'),

                        Forms\Components\DateTimePicker::make('trial_ends_at')
                            ->label('Trial Ends At')
                            ->nullable(),
                    ])
                    ->columns(2),

                Section::make('Moduły')
                    ->description('Włączanie/wyłączanie modułów nadpisuje domyślne ustawienia branży')
                    ->schema([
                        Forms\Components\Toggle::make('settings.modules.services')
                            ->label('Usługi'),
                        Forms\Components\Toggle::make('settings.modules.bookings')
                            ->label('Rezerwacje'),
                        Forms\Components\Toggle::make('settings.modules.rentals')
                            ->label('Wypożyczenia'),
                        Forms\Components\Toggle::make('settings.modules.staff')
                            ->label('Kadra'),
                        Forms\Components\Toggle::make('settings.modules.customers')
                            ->label('Klienci'),
                        Forms\Components\Toggle::make('settings.modules.vehicles')
                            ->label('Pojazdy'),
                        Forms\Components\Toggle::make('settings.modules.communication')
                            ->label('Komunikacja'),
                        Forms\Components\Toggle::make('settings.modules.website')
                            ->label('Strona WWW'),
                        Forms\Components\Toggle::make('settings.modules.service_area')
                            ->label('Obszary usług'),
                    ])
                    ->columns(3),

                Section::make('Feature Flags')
                    ->description('Feature flags within modules')
                    ->schema([
                        Forms\Components\Toggle::make('settings.features.vehicles')
                            ->label('Vehicle Catalog')
                            ->helperText('Vehicle type selection, brand/model catalog'),

                        Forms\Components\Toggle::make('settings.features.mobile_service')
                            ->label('Mobile Service (Location/Address)')
                            ->helperText('Location picker, address input, Google Maps'),

                        Forms\Components\Toggle::make('settings.features.service_area')
                            ->label('Service Area Restrictions')
                            ->helperText('Validate customer location is within service area'),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('booking_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'time_slot' => 'info',
                        'item_rental' => 'warning',
                        'both' => 'success',
                    }),

                Tables\Columns\TextColumn::make('industry')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->color(fn ($state) => match ($state) {
                        Industry::EquipmentRental => 'warning',
                        Industry::AutoDetailing => 'info',
                        Industry::GeneralServices => 'gray',
                        default => 'gray',
                    })
                    ->toggleable(),

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

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('trial_ends_at')
                    ->dateTime()
                    ->sortable(),

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
            ])
            ->actions([
                Actions\EditAction::make(),

                Actions\Action::make('extendTrial')
                    ->label('+14 dni trial')
                    ->icon('heroicon-o-clock')
                    ->action(fn (Organization $record) => $record->update([
                        'trial_ends_at' => ($record->trial_ends_at ?? now())->addDays(14),
                    ]))
                    ->requiresConfirmation(),

                // Lifecycle state actions — go through the state machine via OrganizationObserver
                Actions\Action::make('suspend')
                    ->label('Zawieś')
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Organization $record) => auth()->user()?->hasRole('super-admin')
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
                    ->visible(fn (Organization $record) => auth()->user()?->hasRole('super-admin')
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
                    ->label('Zamknij')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Organization $record) => auth()->user()?->hasRole('super-admin')
                        && in_array(
                            $record->lifecycle_state,
                            [OrganizationLifecycleState::Active, OrganizationLifecycleState::Suspended],
                            true
                        ))
                    ->before(function (Organization $record, Actions\Action $action): void {
                        $service = app(TenantObligationService::class);
                        $counts = $service->activeObligations($record);

                        if ($counts['total'] > 0) {
                            Notification::make()
                                ->title('Nie można zainicjować zamknięcia')
                                ->body(sprintf(
                                    'Aktywne zobowiązania uniemożliwiają zamknięcie: %d wizyt, %d zamówień, %d wypożyczeń. Rozwiąż je najpierw.',
                                    $counts['appointments'],
                                    $counts['orders'],
                                    $counts['rentals'],
                                ))
                                ->danger()
                                ->persistent()
                                ->send();
                            $action->halt();
                        }
                    })
                    ->action(function (Organization $record): void {
                        // Obligations already verified in before(); bypass the observer's double-check
                        $record->forceLifecycleTransition = true;
                        $record->lifecycle_state = OrganizationLifecycleState::Closing;
                        $record->save();
                        Notification::make()->title('Proces zamknięcia zainicjowany')->danger()->send();
                    }),
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
