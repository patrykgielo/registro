<?php

namespace App\Filament\Resources;

use App\Actions\Inventory\RouteQuantityFieldToPrimaryLocationStock;
use App\Enums\ServiceType;
use App\Filament\Resources\ServiceResource\Pages;
use App\Filament\Resources\ServiceResource\RelationManagers\LocationStocksRelationManager;
use App\Filament\Resources\ServiceResource\RelationManagers\UnitsRelationManager;
use App\Filament\Support\BuilderBlocks;
use App\Filament\Support\BuilderBlockSummary;
use App\Filament\Support\TenantScopedUniqueRule;
use App\Models\Service;
use App\Support\TenantFeature;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

class ServiceResource extends BaseResource
{
    protected static ?string $model = Service::class;

    protected static ?string $module = 'services';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 1;

    /** `excerpt` limit: the field's maxLength and the "N / 500" counter below it read the same number. */
    private const EXCERPT_MAX_LENGTH = 500;

    protected static ?string $modelLabel = 'Usługa';

    protected static ?string $pluralModelLabel = 'Usługi';

    /**
     * Pure rental tenants (booking_type === 'item_rental') never store
     * time-slot services, so this resource presents as their product
     * catalogue ("Produkty") grouped under Wypożyczenia. Mixed tenants
     * (booking_type === 'both') still use this resource for actual
     * time-slot services alongside RentalResource for rental items —
     * they must keep the default "Usługi"/content label, otherwise their
     * time-slot services would be mislabeled as rental products.
     *
     * Known narrow gap: a tenant with booking_type = 'item_rental' but
     * industry left null (reachable only via a manual super-admin edit in
     * /platform, never through normal onboarding — Industry::EquipmentRental's
     * defaultModules() always includes 'services') has the 'services' module
     * disabled by Organization::MODULE_DEFAULTS['item_rental'], so this
     * resource — while correctly labeled "Produkt" if visited directly —
     * never registers in the sidebar for that tenant shape. Not fixed here;
     * out of scope for this label/grouping change.
     */
    private static function isPureRentalTenant(): bool
    {
        return TenantFeature::currentTenant()?->booking_type === 'item_rental';
    }

    public static function getModelLabel(): string
    {
        return static::isPureRentalTenant() ? 'Produkt' : static::$modelLabel;
    }

    public static function getPluralModelLabel(): string
    {
        return static::isPureRentalTenant() ? 'Produkty' : static::$pluralModelLabel;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return static::isPureRentalTenant() ? 'rentals' : static::$navigationGroup;
    }

    public static function getNavigationSort(): ?int
    {
        return static::isPureRentalTenant() ? 2 : static::$navigationSort;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('Formularz produktu')
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Podstawowe')
                            ->schema([
                                // Sekcja 1: Podstawowe Informacje
                                Section::make('Podstawowe informacje')
                                    ->schema([
                                        Forms\Components\Select::make('service_type')
                                            ->label('Typ usługi')
                                            ->options(ServiceType::class)
                                            ->required()
                                            ->default(ServiceType::TimeSlot)
                                            ->live()
                                            ->disabled(fn (?Model $record): bool => $record !== null)
                                            ->dehydrated()
                                            ->helperText('Typ usługi nie może być zmieniony po utworzeniu')
                                            ->columnSpanFull(),

                                        Forms\Components\TextInput::make('name')
                                            ->label('Nazwa usługi')
                                            ->required()
                                            ->maxLength(255)
                                            ->live(onBlur: true)
                                            // Slug follows the name only on CREATE: on edit a typo fix in the
                                            // name must not silently change the public URL.
                                            ->afterStateUpdated(function (string $state, callable $set, ?Model $record) {
                                                if ($record === null) {
                                                    $set('slug', Str::slug($state));
                                                }
                                            })
                                            ->columnSpanFull(),

                                        Forms\Components\TextInput::make('slug')
                                            ->label('Slug (URL)')
                                            ->required()
                                            ->maxLength(255)
                                            ->unique(
                                                ignoreRecord: true,
                                                modifyRuleUsing: TenantScopedUniqueRule::forCurrentTenant(),
                                            )
                                            ->helperText(fn (?Model $record): string => $record === null
                                                ? 'Automatycznie generowany z nazwy'
                                                : 'Adres strony. Zmiana nazwy nie zmienia adresu — zmień go tutaj ręcznie, jeśli chcesz.'),

                                        Forms\Components\Select::make('icon')
                                            ->label('Ikona usługi')
                                            ->options(fn () => self::getHeroiconOptions())
                                            ->searchable()
                                            ->default('sparkles')
                                            ->helperText('Ikona wyświetlana na karcie usługi'),

                                        // MUST stay here (always visible): it dehydrates for EVERY type (a rental create writes the
                                        // default 60 - pinned by test_c3_known_gap_a_rental_create_writes_duration_minutes_60) and Filament
                                        // skips fields inside a hidden Tab/Section. The hours/minutes inputs on the time_slot-only
                                        // "Cena i czas trwania" tab write into it via $set('duration_minutes') (same state path).
                                        Forms\Components\Hidden::make('duration_minutes')
                                            ->default(60),

                                        Forms\Components\Toggle::make('is_active')
                                            ->label('Aktywna')
                                            ->default(true)
                                            ->required(),

                                        Forms\Components\TextInput::make('sort_order')
                                            ->label('Kolejność sortowania')
                                            ->required()
                                            ->numeric()
                                            ->default(0)
                                            ->helperText('Niższe wartości = wyżej na liście'),
                                    ])
                                    ->columns(2),

                                // Sekcja 2: Zdjęcie wyróżniające
                                Section::make('Zdjęcie wyróżniające')
                                    ->schema([
                                        Forms\Components\FileUpload::make('featured_image')
                                            ->label('Zdjęcie wyróżniające')
                                            ->disk('public')
                                            ->directory('services/featured')
                                            ->visibility('public')
                                            ->image()
                                            ->imageEditor()
                                            ->imageEditorAspectRatios(['16:9'])
                                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                            ->maxSize(6144)
                                            ->imageResizeMode('cover')
                                            ->imageResizeTargetWidth('1920')
                                            ->imageResizeTargetHeight('1080')
                                            ->imagePreviewHeight('200')
                                            ->extraAttributes(['class' => 'no-edit-icon'])
                                            ->helperText('Zalecany format: 16:9, max 6MB'),

                                        Forms\Components\ColorPicker::make('hero_overlay_color')
                                            ->label('Kolor nakładki hero')
                                            ->default('#000000'),

                                        Forms\Components\Select::make('hero_overlay_opacity')
                                            ->label('Przezroczystość nakładki')
                                            ->options([
                                                '10' => '10%',
                                                '20' => '20%',
                                                '30' => '30%',
                                                '40' => '40%',
                                                '50' => '50%',
                                                '60' => '60%',
                                                '70' => '70%',
                                                '80' => '80%',
                                                '85' => '85%',
                                                '90' => '90%',
                                                '95' => '95%',
                                            ])
                                            ->default('85')
                                            ->selectablePlaceholder(false),
                                    ]),
                            ]),

                        // time_slot only (the mirror of the rental tabs below): same condition as its one section, so a
                        // hidden tab is hidden like a hidden section - its fields are neither validated nor saved.
                        // A rental's `price` is not editable: the model copies `price_per_day` into it on every save
                        // (SyncsRentalPriceWithDailyRate). A time_slot's `price` is NOT NULL in the DB, so it is required
                        // here (stage B) - an emptied field is a validation error, not a QueryException.
                        Tab::make('Cena i czas trwania')
                            ->visible(fn (callable $get): bool => ! self::isRentalType($get('service_type')))
                            ->schema([
                                Section::make('Cena i czas trwania')
                                    ->schema([
                                        Forms\Components\TextInput::make('price')
                                            ->label('Cena')
                                            ->required(fn (callable $get): bool => ! self::isRentalType($get('service_type')))
                                            ->numeric()
                                            ->default(0.00)
                                            ->prefix('PLN')
                                            ->helperText('Podstawowa cena usługi')
                                            ->visible(fn (callable $get): bool => ! self::isRentalType($get('service_type'))),

                                        Forms\Components\TextInput::make('price_from')
                                            ->label('Cena "od" (opcjonalnie)')
                                            ->numeric()
                                            ->prefix('PLN')
                                            ->helperText('Jeśli cena jest zmienna, podaj cenę minimalną (np. od 150 PLN)')
                                            ->visible(fn (callable $get): bool => ! self::isRentalType($get('service_type'))),

                                        // Duration inputs (display-only, never dehydrated): they write the Hidden `duration_minutes`
                                        // that lives in "Podstawowe" (see the note there).
                                        Grid::make(3)
                                            ->schema([
                                                Forms\Components\TextInput::make('duration_days')
                                                    ->label('Dni')
                                                    ->numeric()
                                                    ->minValue(0)
                                                    ->maxValue(0)
                                                    ->default(0)
                                                    ->suffix('dni')
                                                    ->helperText('Usługi wielodniowe nie są obsługiwane')
                                                    ->disabled()
                                                    ->dehydrated(false),
                                                Forms\Components\TextInput::make('duration_hours')
                                                    ->label('Godziny')
                                                    ->numeric()
                                                    ->minValue(0)
                                                    ->maxValue(23)
                                                    ->suffix('godz')
                                                    ->live(onBlur: true)
                                                    ->afterStateHydrated(function ($state, $set, $get, $record) {
                                                        if ($record && $record->duration_minutes) {
                                                            $set('duration_hours', floor($record->duration_minutes / 60));
                                                        }
                                                    })
                                                    ->afterStateUpdated(function ($state, $set, $get) {
                                                        $hours = (int) ($state ?? 0);
                                                        $minutes = (int) ($get('duration_mins') ?? 0);
                                                        $set('duration_minutes', ($hours * 60) + $minutes);
                                                    })
                                                    ->dehydrated(false),
                                                Forms\Components\TextInput::make('duration_mins')
                                                    ->label('Minuty')
                                                    ->numeric()
                                                    ->minValue(0)
                                                    ->maxValue(59)
                                                    ->step(15)
                                                    ->suffix('min')
                                                    ->live(onBlur: true)
                                                    ->afterStateHydrated(function ($state, $set, $get, $record) {
                                                        if ($record && $record->duration_minutes) {
                                                            $set('duration_mins', $record->duration_minutes % 60);
                                                        }
                                                    })
                                                    ->afterStateUpdated(function ($state, $set, $get) {
                                                        $hours = (int) ($get('duration_hours') ?? 0);
                                                        $minutes = (int) ($state ?? 0);
                                                        $set('duration_minutes', ($hours * 60) + $minutes);
                                                    })
                                                    ->dehydrated(false),
                                            ])
                                            ->columnSpanFull()
                                            ->visible(fn (callable $get): bool => ! self::isRentalType($get('service_type'))),
                                    ])
                                    ->columns(2)
                                    ->visible(fn (callable $get): bool => ! self::isRentalType($get('service_type'))),
                            ]),

                        // Both sections are item_rental only: a tab with no visible section must not render empty.
                        Tab::make('Cena i magazyn')
                            ->visible(fn (callable $get): bool => self::isRentalType($get('service_type')))
                            ->schema([
                                // Sekcja: Cennik wypożyczenia (item_rental only)
                                Section::make('Cennik wypożyczenia')
                                    ->schema([
                                        Forms\Components\TextInput::make('price_per_day')
                                            ->label('Cena za dzień')
                                            ->required()
                                            ->numeric()
                                            ->prefix('PLN'),

                                        Forms\Components\TextInput::make('price_per_hour')
                                            ->label('Cena za godzinę')
                                            ->numeric()
                                            ->prefix('PLN'),

                                        Forms\Components\TextInput::make('price_per_week')
                                            ->label('Cena za tydzień')
                                            ->numeric()
                                            ->prefix('PLN'),

                                        Forms\Components\TextInput::make('price_per_day_long')
                                            ->label('Cena po przekroczeniu progu')
                                            ->numeric()
                                            ->prefix('PLN')
                                            ->requiredWith('price_threshold_days')
                                            ->lte('price_per_day')
                                            ->helperText('Niższa stawka dzienna po przekroczeniu progu dni (wymagane razem z progiem)'),

                                        Forms\Components\TextInput::make('price_threshold_days')
                                            ->label('Próg dni')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(365)
                                            ->suffix('dni')
                                            ->requiredWith('price_per_day_long')
                                            ->helperText('Po ilu dniach obowiązuje niższa stawka (wymagane razem z ceną)'),

                                        Forms\Components\TextInput::make('deposit_amount')
                                            ->label('Kaucja')
                                            ->numeric()
                                            ->prefix('PLN'),

                                        Forms\Components\Toggle::make('price_on_request')
                                            ->label('Cena do potwierdzenia')
                                            ->helperText('Ukrywa cenę i wyświetla przycisk "Zapytaj o cenę" zamiast koszyka')
                                            ->default(false)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(3)
                                    ->visible(fn (callable $get): bool => self::isRentalType($get('service_type'))),

                                // Sekcja: Magazyn (item_rental only)
                                Section::make('Magazyn i identyfikacja')
                                    ->schema([
                                        Forms\Components\TextInput::make('quantity_total')
                                            ->label('Ilość w magazynie')
                                            ->required(fn (?Model $record): bool => self::tenantEligibleForDirectQuantityField($record))
                                            ->numeric()
                                            ->minValue(1)
                                            ->default(1)
                                            // plan-wdrozenia.md Krok 2.5: stays editable — and the
                                            // ONLY writer of stock — for a tenant with exactly one
                                            // active location (afterCreate()/afterSave() on the
                                            // Create/Edit pages route the value into that location's
                                            // service_location_stocks row via
                                            // RouteQuantityFieldToPrimaryLocationStock). For any
                                            // other tenant shape — including THIS record having a
                                            // stock row orphaned outside the primary location
                                            // (code-reviewer BLOKER 1: happens when a tenant that
                                            // used to have two active locations deactivates one,
                                            // "looking" single-location again while the old split
                                            // still exists in the DB) — it is disabled AND
                                            // un-dehydrated (Filament dehydrates disabled fields by
                                            // DEFAULT unless told not to — the second call is not
                                            // redundant), so a save can never silently overwrite a
                                            // deliberately per-location split with the stale
                                            // aggregate this field would otherwise submit, NOR let
                                            // quantity_total drift away from
                                            // SUM(service_location_stocks.quantity) when handle()
                                            // refuses to route it. Those tenants edit exclusively
                                            // through LocationStocksRelationManager below.
                                            ->disabled(fn (?Model $record): bool => ! self::tenantEligibleForDirectQuantityField($record))
                                            ->dehydrated(fn (?Model $record): bool => self::tenantEligibleForDirectQuantityField($record))
                                            ->helperText(fn (?Model $record): ?string => self::quantityFieldHelperText($record)),

                                        Forms\Components\Select::make('rental_category_id')
                                            ->label('Kategoria')
                                            ->relationship('category', 'name')
                                            ->searchable()
                                            ->preload(),

                                        Forms\Components\TextInput::make('brand')
                                            ->label('Marka / producent')
                                            ->maxLength(255),
                                    ])
                                    ->columns(3)
                                    ->visible(fn (callable $get): bool => self::isRentalType($get('service_type'))),
                            ]),

                        // The only section is item_rental only (same condition as the section itself).
                        Tab::make('Specyfikacja')
                            ->visible(fn (callable $get): bool => self::isRentalType($get('service_type')))
                            ->schema([
                                // Sekcja: Specyfikacja techniczna (item_rental only)
                                Section::make('Specyfikacja techniczna')
                                    ->schema([
                                        Forms\Components\Repeater::make('metadata.specs')
                                            ->label('Parametry techniczne')
                                            ->schema([
                                                Forms\Components\TextInput::make('label')
                                                    ->label('Parametr')
                                                    ->placeholder('np. Moc')
                                                    ->maxLength(100),
                                                Forms\Components\TextInput::make('value')
                                                    ->label('Wartość')
                                                    ->placeholder('np. 800')
                                                    ->maxLength(100),
                                                Forms\Components\TextInput::make('unit')
                                                    ->label('Jednostka')
                                                    ->placeholder('np. W')
                                                    ->maxLength(20),
                                            ])
                                            // Stage 3: one compact row per parameter. Column headings sit once above the rows;
                                            // below Filament's container breakpoint a row stacks and shows per-field labels.
                                            ->table([
                                                Forms\Components\Repeater\TableColumn::make('Parametr'),
                                                Forms\Components\Repeater\TableColumn::make('Wartość'),
                                                Forms\Components\Repeater\TableColumn::make('Jednostka')->width('9rem'),
                                            ])
                                            ->compact()
                                            ->reorderable()
                                            ->addActionLabel('Dodaj parametr')
                                            ->columnSpanFull()
                                            ->defaultItems(0)
                                            ->helperText('Wyświetlane na stronie produktowej jako tabela specyfikacji'),
                                    ])
                                    ->headerActions([
                                        Actions\Action::make('loadSpecTemplate')
                                            ->label('Wstaw z szablonu branżowego')
                                            ->icon('heroicon-o-clipboard-document-list')
                                            ->color('gray')
                                            ->action(function (callable $set) {
                                                $tenant = \App\Support\TenantFeature::currentTenant();
                                                $industry = $tenant?->industry;
                                                if (! $industry) {
                                                    return;
                                                }
                                                $template = $industry->defaultSpecDefinitions();
                                                $specs = array_map(fn ($def) => [
                                                    'label' => $def['label'],
                                                    'value' => '',
                                                    'unit' => $def['unit'],
                                                ], $template);
                                                $set('metadata.specs', $specs);
                                            })
                                            ->requiresConfirmation()
                                            ->modalHeading('Wstawić szablon specyfikacji?')
                                            ->modalDescription('Obecne parametry zostaną zastąpione szablonem branżowym. Uzupełnij wartości po wstawieniu.'),
                                    ])
                                    ->visible(fn (callable $get): bool => self::isRentalType($get('service_type'))),
                            ]),

                        Tab::make('Treść')
                            ->schema([
                                // Krótki opis. As with `body` below, the heading names the field, so its label is hidden
                                // visually (kept for screen readers). The "N / 500" counter is client-side only: `$wire.$get()`
                                // reads the already-entangled state, so typing causes no server round-trip (Filament 4.11.5 has
                                // no built-in counter for a Textarea). JS length counts code points, like PHP's mb_strlen.
                                Section::make('Krótki opis')
                                    ->description('Na kafelku (2 linie) i w Google, gdy nie ma meta opisu.')
                                    ->schema([
                                        Forms\Components\Textarea::make('excerpt')
                                            ->label('Krótki opis')
                                            ->hiddenLabel()
                                            ->maxLength(self::EXCERPT_MAX_LENGTH)
                                            ->rows(3)
                                            ->belowContent(
                                                Text::make('[...String($wire.$get(\'data.excerpt\') ?? \'\')].length + \' / '.self::EXCERPT_MAX_LENGTH.'\'')
                                                    ->js()
                                                    ->size(TextSize::Small),
                                            )
                                            ->columnSpanFull(),
                                    ]),

                                // Sekcja 3: Opis produktu. The heading already names the field, so its label is hidden
                                // visually (kept for screen readers) and the helper text - which said the same as the
                                // section description - is gone.
                                Section::make('Opis produktu')
                                    ->description('Główny tekst strony produktu. Obrazy i galerie dodajesz blokami poniżej.')
                                    ->schema([
                                        Forms\Components\RichEditor::make('body')
                                            ->label('Opis produktu')
                                            ->hiddenLabel()
                                            ->toolbarButtons([
                                                'bold', 'italic', 'underline',
                                                'link', 'bulletList', 'orderedList',
                                                'h2', 'h3', 'blockquote',
                                            ])
                                            ->columnSpanFull()
                                            ->extraInputAttributes(['style' => 'min-height: 30rem;']),
                                    ]),

                                // Sekcja 4: Zaawansowane bloki. NOT collapsed - inside a tab collapsing only hides content.
                                // The heading carries the block count, so it changes with the state: the key is therefore
                                // fixed (Section derives its default key from the heading).
                                Section::make(fn (callable $get): string => 'Zaawansowane bloki ('.self::countBlocks($get('content')).')')
                                    ->key('advanced-blocks-section', isInheritable: false)
                                    ->description('Wyświetlane pod opisem, w tej kolejności. Zapisują się razem z opisem.')
                                    ->schema([
                                        Forms\Components\Builder::make('content')
                                            ->label('Dodatkowe bloki')
                                            ->blocks(BuilderBlockSummary::apply([
                                                ...BuilderBlocks::allBlocks('services'),
                                                Forms\Components\Builder\Block::make('service_features')
                                                    ->label('Cechy usługi (automatyczne)')
                                                    ->icon('heroicon-o-check-badge')
                                                    ->schema([
                                                        Forms\Components\Placeholder::make('info')
                                                            ->content('Automatycznie wyświetla listę "Co zawiera usługa" z danych tej usługi.'),

                                                        Forms\Components\TextInput::make('heading')
                                                            ->label('Nagłówek')
                                                            ->default('Co zawiera usługa'),

                                                        Forms\Components\Select::make('layout')
                                                            ->label('Układ')
                                                            ->options([
                                                                'simple' => 'Lista',
                                                                'grid' => 'Siatka 2 kolumny',
                                                            ])
                                                            ->default('simple'),
                                                    ]),
                                            ]))
                                            ->collapsible()
                                            ->collapsed()
                                            ->blockNumbers(false)
                                            ->reorderable()
                                            ->reorderableWithButtons()
                                            ->blockPickerColumns(['default' => 1, 'sm' => 2, 'lg' => 3])
                                            // The section heading shows the count: re-render the whole form after
                                            // add / delete / move, not just this Builder.
                                            ->partiallyRenderAfterActionsCalled(false)
                                            ->addActionLabel('Dodaj blok')
                                            ->columnSpanFull()
                                            ->helperText('Opcjonalne: dodaj galerie, wideo, przyciski CTA, hero sections i więcej'),
                                    ]),

                                // Moved from "Optymalizacja konwersji", unchanged and visible for both types (stage C reworks it
                                // for rentals). The section description replaces the old helper text, so that is dropped and
                                // the label is hidden visually like `body`'s.
                                Section::make('Co zawiera usługa')
                                    ->description('Do 4 punktów z ✓ na kafelku produktu na liście.')
                                    ->schema([
                                        Forms\Components\Repeater::make('features')
                                            ->label('Co zawiera usługa')
                                            ->hiddenLabel()
                                            ->simple(
                                                Forms\Components\TextInput::make('feature')
                                                    ->label('Cecha')
                                                    ->required()
                                                    ->maxLength(100)
                                            )
                                            ->minItems(0)
                                            ->maxItems(4)
                                            ->columnSpanFull()
                                            ->defaultItems(0),
                                    ]),
                            ]),

                        Tab::make('SEO i konwersja')
                            ->schema([
                                // Sekcja 5: SEO i Publikacja (collapsed)
                                Section::make('SEO i publikacja')
                                    ->schema([
                                        Forms\Components\TextInput::make('meta_title')
                                            ->label('Meta tytuł')
                                            ->maxLength(60)
                                            ->helperText('Zalecane: do 60 znaków'),

                                        Forms\Components\Textarea::make('meta_description')
                                            ->label('Meta opis')
                                            ->rows(3)
                                            ->maxLength(160)
                                            ->helperText('Zalecane: do 160 znaków'),

                                        Forms\Components\TextInput::make('area_served')
                                            ->label('Obszar obsługi (Local SEO)')
                                            ->default('Poznań')
                                            ->maxLength(255)
                                            ->helperText('Miasto/region obsługi usługi'),

                                        Forms\Components\DateTimePicker::make('published_at')
                                            ->label('Data publikacji')
                                            ->default(now())
                                            ->helperText('Pozostaw puste dla wersji roboczej'),
                                    ])
                                    ->columns(2)
                                    ->collapsed(),

                                // Sekcja 6: Conversion Optimization
                                Section::make('Optymalizacja konwersji')
                                    ->description('Social proof, popularność i wskaźniki pilności dla zwiększenia konwersji')
                                    ->schema([
                                        Grid::make(3)
                                            ->schema([
                                                Forms\Components\TextInput::make('average_rating')
                                                    ->label('Średnia ocena')
                                                    ->numeric()
                                                    ->minValue(0)
                                                    ->maxValue(5)
                                                    ->step(0.1)
                                                    ->suffix('★')
                                                    ->default(0)
                                                    ->helperText('Ocena 0-5 gwiazdek'),

                                                Forms\Components\TextInput::make('total_reviews')
                                                    ->label('Liczba opinii')
                                                    ->numeric()
                                                    ->minValue(0)
                                                    ->default(0)
                                                    ->helperText('Całkowita liczba opinii'),

                                                Forms\Components\Toggle::make('is_popular')
                                                    ->label('Usługa popularna')
                                                    ->helperText('Pokaż badge "Najpopularniejsze"')
                                                    ->default(false),
                                            ]),

                                        Forms\Components\TextInput::make('booking_count_week')
                                            ->label('Rezerwacje w tym tygodniu')
                                            ->numeric()
                                            ->minValue(0)
                                            ->helperText('Dla wiadomości pilności: "Zarezerwowano X razy w tym tygodniu"')
                                            ->default(0),
                                    ])
                                    ->columns(2)
                                    ->collapsed(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nazwa usługi')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('service_type')
                    ->label('Typ')
                    ->badge()
                    ->formatStateUsing(fn (ServiceType $state): string => $state->label())
                    ->color(fn (ServiceType $state): string => match ($state) {
                        ServiceType::TimeSlot => 'info',
                        ServiceType::ItemRental => 'warning',
                    }),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('formatted_duration')
                    ->label('Czas trwania')
                    ->sortable(query: function ($query, $direction) {
                        return $query->orderBy('duration_minutes', $direction);
                    })
                    ->formatStateUsing(fn ($state, Service $record) => $record->service_type === ServiceType::ItemRental ? '—' : $state)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('price')
                    ->label('Cena')
                    ->money('PLN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('published_at')
                    ->label('Status')
                    ->badge()
                    ->dateTime('Y-m-d H:i')
                    ->color(fn ($state) => $state && $state->isPast() ? 'success' : 'warning')
                    ->formatStateUsing(fn ($state) => $state
                            ? ($state->isPast() ? 'Opublikowano' : 'Zaplanowano')
                            : 'Wersja robocza'
                    )
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktywna')
                    ->boolean(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Kolejność')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Ostatnia aktualizacja')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('service_type')
                    ->label('Typ')
                    ->options(ServiceType::class),

                Tables\Filters\Filter::make('published')
                    ->label('Opublikowane')
                    ->query(fn ($query) => $query->published()),

                Tables\Filters\Filter::make('draft')
                    ->label('Wersje robocze')
                    ->query(fn ($query) => $query->draft()),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Aktywne')
                    ->boolean(),
            ])
            ->recordActions([
                Actions\Action::make('preview')
                    ->label('Podgląd')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Service $record) => route('service.show', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Service $record) => $record->service_type === ServiceType::ItemRental
                        ? $record->is_active
                        : ($record->published_at && $record->published_at->isPast())),
                Actions\EditAction::make()
                    ->label('Edytuj'),
                Actions\DeleteAction::make()
                    ->label('Usuń'),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order', 'asc');
    }

    public static function getRelations(): array
    {
        return [
            LocationStocksRelationManager::class,
            UnitsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServices::route('/'),
            'create' => Pages\CreateService::route('/create'),
            'edit' => Pages\EditService::route('/{record}/edit'),
        ];
    }

    /**
     * Check if service_type is ItemRental (handles both enum and string from $get()).
     */
    /**
     * Thin delegate to
     * App\Actions\Inventory\RouteQuantityFieldToPrimaryLocationStock::eligibleForDirectRouting()
     * — THAT method is the single source of truth shared with handle(),
     * both MUST agree on the same tenant/service shape, or the field could
     * stay enabled while the action silently refuses to route its value (or
     * vice versa).
     */
    private static function tenantEligibleForDirectQuantityField(?Model $record): bool
    {
        return RouteQuantityFieldToPrimaryLocationStock::eligibleForDirectRouting(
            TenantFeature::currentTenant()?->id,
            $record instanceof Service ? $record : null,
        );
    }

    /**
     * ClickUp 123k99cvc53/123k99cvc54: null when the field is directly
     * editable (nothing to explain); otherwise tells the admin WHY it is
     * disabled and where the number actually lives now — three distinct
     * causes, each pointing somewhere different, so a single generic
     * message would be actively wrong for at least one of them (pointing to
     * "Stany magazynowe" when the org has zero locations sends the admin to
     * a tab that can only ever say "Ta organizacja nie ma jeszcze żadnego
     * aktywnego oddziału" — LocationStocksRelationManager's own
     * emptyStateDescription).
     */
    private static function quantityFieldHelperText(?Model $record): ?string
    {
        if (self::tenantEligibleForDirectQuantityField($record)) {
            return null;
        }

        $organizationId = TenantFeature::currentTenant()?->id;

        if (RouteQuantityFieldToPrimaryLocationStock::activeLocationCount($organizationId) === 0) {
            return 'Brak aktywnego oddziału — dodaj oddział w sekcji "Lokalizacje", zanim ustawisz ilość w magazynie.';
        }

        if ($record instanceof Service && $record->exists && $record->serviceUnits()->exists()) {
            return 'Ten produkt ma zarejestrowane egzemplarze — ustaw ilość w zakładce "Egzemplarze" poniżej.';
        }

        return 'Więcej niż jeden aktywny oddział, albo istnieje stan magazynowy na innym oddziale — ustaw ilości w zakładce "Stany magazynowe" poniżej.';
    }

    /** Number of blocks in the `content` Builder state, for the "Zaawansowane bloki (N)" heading. */
    private static function countBlocks(mixed $state): int
    {
        return is_array($state) ? count($state) : 0;
    }

    private static function isRentalType(mixed $value): bool
    {
        if ($value instanceof ServiceType) {
            return $value === ServiceType::ItemRental;
        }

        return $value === ServiceType::ItemRental->value;
    }

    /**
     * Restrict access to admins and super-admins only.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }
}
