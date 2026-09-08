<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceResource\RelationManagers;

use App\Enums\ServiceType;
use App\Enums\ServiceUnitStatus;
use App\Filament\Support\TenantScopedUniqueRule;
use App\Models\Service;
use App\Models\ServiceUnit;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Egzemplarze" — plan-wdrozenia.md Faza 3 kroki 3.4/3.5. Unlike its sibling
 * LocationStocksRelationManager (deliberately read-only, the anchor is a
 * derived mirror nobody edits by hand), this tab IS the place an owner
 * enters a unit's own mark, moves it between locations, and sends it to
 * maintenance — every write here flows through App\Observers\
 * ServiceUnitObserver, which keeps service_location_stocks and, through it,
 * Service::quantity_total in sync in the same transaction (see that
 * observer's docblock). No get*AuthorizationResponse() overrides are wired
 * here (unlike LocationStocksRelationManager, which needed them to make its
 * hardcoded canCreate()===false actually deny — filament-resources.md,
 * "Autoryzacja: can*() NIE jest punktem egzekwowania"): canViewForRecord()
 * below already gates the ENTIRE tab to admin/super-admin, the same
 * population Filament's ambient allow-by-default (no ServiceUnitPolicy
 * exists) would let through anyway — same shape as
 * CustomerResource\RelationManagers\AddressesRelationManager, a genuinely
 * full-CRUD relation manager with no policy of its own.
 */
class UnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceUnits';

    protected static ?string $title = 'Egzemplarze';

    protected static ?string $modelLabel = 'egzemplarz';

    protected static ?string $pluralModelLabel = 'egzemplarze';

    /**
     * Same gate as LocationStocksRelationManager — item_rental only,
     * admin/super-admin only. Egzemplarze answer "where does it live and is
     * it fit for use" (ServiceUnit's own docblock), a question that only
     * makes sense for the rental side of the unified Service model; a
     * time_slot service (e.g. a haircut) has no physical unit to track.
     */
    public static function canViewForRecord(Model $ownerRecord, string $panel): bool
    {
        return $ownerRecord instanceof Service
            && $ownerRecord->service_type === ServiceType::ItemRental
            && (auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('location_id')
                ->label('Oddział')
                ->relationship(
                    name: 'location',
                    titleAttribute: 'name',
                    // Excludes inactive locations from the OPTIONS list for
                    // a NEW assignment — an egzemplarz should never be newly
                    // pointed at a branch the tenant has switched off. This
                    // closure is ALSO the enforcement mechanism against a
                    // crafted cross-tenant location_id (see the ->required()
                    // comment below).
                    //
                    // The `orWhere('id', $record->location_id)` half fixes a
                    // real lockout a code reviewer caught empirically, not
                    // hypothetically: Select::getSelectedRecordUsing() (v4
                    // vendor/filament/forms/src/Components/Select.php
                    // ~805-830) re-runs THIS SAME modifyQueryUsing closure to
                    // re-resolve the CURRENTLY assigned location on every
                    // save — not just to build the dropdown's option list.
                    // Once a unit's location is later deactivated,
                    // `is_active = true` alone made that lookup return null
                    // for the unit's own unchanged value, which
                    // getInValidationRuleValues() turns into `Rule::in([])`
                    // — rejecting the ENTIRE form, including a save that
                    // only touches `status`/`notes` and never even looks at
                    // location_id. The tenant's only way out was reactivating
                    // the whole branch. `when($record, ...)` is null (skipped)
                    // on the CREATE action, so a brand new unit still can't
                    // be pointed at an inactive location — only an
                    // ALREADY-assigned one stays resolvable.
                    //
                    // Wrapping BOTH conditions in one nested closure is load-
                    // bearing, not stylistic: Eloquent's own
                    // BelongsToOrganization global scope is already applied
                    // to this query by the time this closure runs. An
                    // unwrapped `->where('is_active', true)->orWhere('id', ...)`
                    // would produce `WHERE organization_id = ? AND is_active
                    // = 1 OR id = ?` — the bare OR escapes the tenant AND
                    // entirely, which would let a unit's own location_id
                    // resolve even if it somehow pointed at ANOTHER tenant's
                    // location. Grouping keeps the whole disjunction inside
                    // the tenant scope: `WHERE organization_id = ? AND
                    // (is_active = 1 OR id = ?)`.
                    modifyQueryUsing: fn (Builder $query, ?ServiceUnit $record): Builder => $query->where(
                        fn (Builder $inner) => $inner->where('is_active', true)
                            ->when($record, fn (Builder $withRecord) => $withRecord->orWhere('id', $record->location_id))
                    ),
                )
                ->required()
                ->searchable()
                // Verified, not assumed (UnitsRelationManagerTest::
                // test_submitting_another_tenants_location_id_is_rejected_server_side):
                // unlike a `multiple()` relationship Select — the
                // "Role Escalation Guard" gap in filament-resources.md,
                // where ->options() is UI-only because dehydrated(false)
                // skips validation entirely — a SINGLE-value relationship
                // Select is self-defending. Filament\Forms\Components\
                // Select::getInValidationRuleValues() (non-multiple branch)
                // re-resolves the submitted id through this exact scoped
                // relationship query (BelongsToOrganization global scope +
                // the is_active filter above); when it can't find a match
                // it returns `[]`, and getInValidationRule() turns that into
                // `Rule::in([])`, which fails for ANY submitted value. No
                // extra ->rule(Rule::exists(...)) is needed here — adding
                // one would just be a redundant query pretending to be
                // defense-in-depth.
                ->preload(),

            Forms\Components\TextInput::make('identifier')
                ->label('Numer własny')
                ->maxLength(255)
                // Nullable by product decision (plan-wdrozenia.md, "Egzemplarze
                // powstają dla każdego produktu, numer jest opcjonalny") — a
                // unit can exist and count toward stock before anyone marks
                // it. UNIQUE(organization_id, identifier) in the migration
                // (2026_09_08_090000_create_service_units_table.php) is
                // per-tenant, not global — TenantScopedUniqueRule is the
                // established fix for the exact gap a bare ->unique() has on
                // any table shaped this way (filament-resources.md, "->unique
                // (ignoreRecord: true) bez organization_id"). Filament omits
                // 'nullable' from a field's validation rules ONLY when
                // ->required() is set (CanBeValidated::getRequiredValidationRule
                // Attribute), so an empty value here never reaches the unique
                // check at all — confirmed, not assumed.
                ->unique(ignoreRecord: true, modifyRuleUsing: TenantScopedUniqueRule::forCurrentTenant())
                ->helperText('Opcjonalnie — własne oznaczenie sprzętu, np. „KOP-04". Bez wymuszonego formatu.'),

            Forms\Components\TextInput::make('inventory_number')
                ->label('Numer inwentarzowy')
                ->maxLength(255),

            Forms\Components\Select::make('status')
                ->label('Status')
                ->options(self::selectableStatusOptions())
                ->default(ServiceUnitStatus::Available->value)
                ->required(),

            Forms\Components\DatePicker::make('acquired_at')
                ->label('Data nabycia')
                // native(false) so the field reads d.m.Y for everyone: a native
                // <input type="date"> is formatted by the BROWSER's locale, so an
                // en-US Chrome shows mm/dd/yyyy inside an otherwise Polish panel.
                // Matches the table column below, which already renders d.m.Y.
                ->native(false)
                ->displayFormat('d.m.Y'),

            Forms\Components\Textarea::make('notes')
                ->label('Notatki')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('identifier')
                    ->label('Numer własny')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('inventory_number')
                    ->label('Nr inwentarzowy')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('location.name')
                    ->label('Oddział')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ServiceUnitStatus $state): string => $state->label())
                    ->color(fn (ServiceUnitStatus $state): string => $state->color()),

                Tables\Columns\TextColumn::make('acquired_at')
                    ->label('Nabyty')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Notatki')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(ServiceUnitStatus::options()),

                Tables\Filters\SelectFilter::make('location_id')
                    ->label('Oddział')
                    ->relationship('location', 'name'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Dodaj egzemplarz')
                    // Requirement #6 (team-lead's task): the "Ilość w
                    // magazynie" field on the parent EditService form is
                    // hydrated once at mount and does not react to a sibling
                    // Livewire component's writes on its own — Livewire's
                    // dispatch() is page-wide, not parent/child-scoped, so
                    // this reaches EditService::refreshQuantityTotalField()
                    // regardless of nesting.
                    ->after(fn () => $this->dispatch('service-unit-stock-changed')),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->after(fn () => $this->dispatch('service-unit-stock-changed')),
                Actions\DeleteAction::make()
                    ->after(fn () => $this->dispatch('service-unit-stock-changed')),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->after(fn () => $this->dispatch('service-unit-stock-changed')),
                ]),
            ])
            ->emptyStateHeading('Brak egzemplarzy')
            ->emptyStateDescription('Ta usługa nie ma jeszcze żadnego zarejestrowanego egzemplarza.');
    }

    /**
     * `in_transit` is deliberately withheld from manual selection. Grepped
     * before writing this: nothing in the codebase sets or reads it today —
     * ServiceUnitObserver treats it exactly like `maintenance`/`retired`
     * (ServiceUnitStatus::countsTowardStock() excludes all three equally),
     * and `location_id` is what actually records where a unit lives; there
     * is no "in transit TO" field for this status to point at. Plan-
     * wdrozenia.md reserves the real meaning of this status for krok 7.4
     * (paired with the krok 7.2 transfer action and its krok 7.3 coverage
     * guard) — letting an admin set it by hand today would just be a
     * synonym for "unavailable, cause unknown" with a misleading label,
     * not the "moved out of A, not yet landed in B" state the enum's own
     * docblock promises. Still returned by the table's status FILTER
     * (ServiceUnitStatus::options()) and rendered correctly by the column
     * if a future step ever writes it — only the create/edit FORM excludes
     * it.
     */
    private static function selectableStatusOptions(): array
    {
        return collect(ServiceUnitStatus::cases())
            ->reject(fn (ServiceUnitStatus $status) => $status === ServiceUnitStatus::InTransit)
            ->mapWithKeys(fn (ServiceUnitStatus $status) => [$status->value => $status->label()])
            ->all();
    }
}
