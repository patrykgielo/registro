<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ServiceType;
use App\Filament\Resources\ServiceResource;
use App\Filament\Resources\ServiceResource\Pages\CreateService;
use App\Models\Organization;
use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TypeError;

/**
 * STAGE 0 "safety net" for the admin product form (ServiceResource + CreateService), companion of
 * ServiceFormEditCharacterizationTest: pins what CREATE writes TODAY - in particular the exact defaults of a
 * minimal create (C3), which a future short "create" form must reproduce. Known defects are pinned in their own
 * methods, marked `// KNOWN GAP (stage 1 will change this): ...`.
 *
 * Columns the form never binds (description, booking_count_month, ...) are asserted too: they define what
 * "create" leaves behind for the rest of the system.
 */
class ServiceFormCreateCharacterizationTest extends TestCase
{
    use RefreshDatabase, ServiceFormTestSupport;

    /** Columns no form field binds, as a create leaves them. */
    private const NON_FORM_COLUMNS_AT_CREATE = [
        'description' => null,
        'booking_count_month' => 0,
        'booking_count_total' => 0,
        'view_count_today' => 0,
        'view_count_week' => 0,
        'stats_reset_daily' => null,
        'stats_reset_weekly' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpServiceFormTenant();
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    /**
     * Every field visible for a rental, each set to a non-default value. Order matters: `name` fires
     * afterStateUpdated (rewrites `slug`), so `slug` comes after it.
     *
     * @return array<string, mixed>
     */
    private function fullRentalFormData(): array
    {
        return [
            'service_type' => ServiceType::ItemRental->value,
            'name' => 'Wiertarka pełna',
            'slug' => 'wiertarka-pelna',
            'icon' => 'star',
            'excerpt' => 'Krótki opis produktu',
            'price' => 12.5,
            'is_active' => false,
            'sort_order' => 9,
            'price_per_day' => 100,
            'price_per_hour' => 15.5,
            'price_per_week' => 450,
            'price_per_day_long' => 80,
            'price_threshold_days' => 5,
            'deposit_amount' => 300,
            'price_on_request' => true,
            'quantity_total' => 7,
            'rental_category_id' => $this->category()->id,
            'brand' => 'Bosch',
            'metadata' => ['specs' => [
                ['label' => 'Moc', 'value' => '800', 'unit' => 'W'],
                ['label' => 'Rodzaj paliwa', 'value' => 'benzyna', 'unit' => null],
            ]],
            'featured_image' => ['f1' => 'services/featured/a.jpg'],
            'hero_overlay_color' => '#112233',
            'hero_overlay_opacity' => '70',
            'body' => '<p>Opis <strong>pogrubiony</strong></p>',
            'content' => [
                ['type' => 'text_block', 'data' => ['content' => '<p>Blok tekstowy</p>']],
                ['type' => 'image', 'data' => ['image' => ['i1' => 'services/images/x.jpg'], 'alt' => 'Zdjęcie']],
                ['type' => 'cta', 'data' => ['heading' => 'Zadzwoń', 'button_url' => 'https://example.com']],
                ['type' => 'service_features', 'data' => ['heading' => 'Co zawiera', 'layout' => 'grid']],
            ],
            'meta_title' => 'Meta tytuł',
            'meta_description' => 'Meta opis',
            'area_served' => 'Kraków',
            'published_at' => '2026-01-02 03:04:05',
            'average_rating' => 4.5,
            'total_reviews' => 12,
            'is_popular' => true,
            'booking_count_week' => 3,
            'features' => array_map(fn (string $text): array => ['feature' => $text], ['Cecha 1', 'Cecha 2', 'Cecha 3', 'Cecha 4']),
        ];
    }

    /**
     * What fullRentalFormData() must leave in the row, as the model reads it.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function expectedFullRentalColumns(array $extra = []): array
    {
        // Deliberately absent: `duration_minutes` (test_c3_known_gap_a_rental_create_writes_duration_minutes_60) and
        // `quantity_total` (depends on the tenant's locations: c1 one location / c4 / c5) - each KNOWN GAP is pinned
        // in exactly one test.
        return $extra + [
            'organization_id' => $this->tenant->id,
            'service_type' => 'item_rental',
            'name' => 'Wiertarka pełna',
            'slug' => 'wiertarka-pelna',
            'icon' => 'star',
            'excerpt' => 'Krótki opis produktu',
            'price' => '12.50',
            'price_from' => null,
            'is_active' => false,
            'sort_order' => 9,
            'price_per_day' => '100.00',
            'price_per_hour' => '15.50',
            'price_per_week' => '450.00',
            'price_per_day_long' => '80.00',
            'price_threshold_days' => 5,
            'deposit_amount' => '300.00',
            'price_on_request' => true,
            'rental_category_id' => $this->category()->id,
            'brand' => 'Bosch',
            'metadata' => ['specs' => [
                ['label' => 'Moc', 'value' => '800', 'unit' => 'W'],
                ['label' => 'Rodzaj paliwa', 'value' => 'benzyna', 'unit' => null],
            ]],
            'featured_image' => 'services/featured/a.jpg',
            'hero_overlay_color' => '#112233',
            'hero_overlay_opacity' => 70,
            'body' => '<p>Opis <strong>pogrubiony</strong></p>',
            'meta_title' => 'Meta tytuł',
            'meta_description' => 'Meta opis',
            'area_served' => 'Kraków',
            'average_rating' => '4.5',
            'total_reviews' => 12,
            'is_popular' => true,
            'booking_count_week' => 3,
            'features' => ['Cecha 1', 'Cecha 2', 'Cecha 3', 'Cecha 4'],
        ] + self::NON_FORM_COLUMNS_AT_CREATE;
    }

    private function created(string $slug): Service
    {
        return Service::withoutGlobalScopes()
            ->where('organization_id', $this->tenant->id)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertColumns(array $expected, array $actual): void
    {
        foreach ($expected as $column => $value) {
            $this->assertArrayHasKey($column, $actual);
            // JSON object key order carries no meaning (and MySQL reorders it) - compare with sorted keys.
            $this->assertSame(
                is_array($value) ? $this->ksortDeep($value) : $value,
                is_array($actual[$column]) ? $this->ksortDeep($actual[$column]) : $actual[$column],
                "column `{$column}`",
            );
        }
    }

    private function submit(array $data): Testable
    {
        $component = Livewire::test(CreateService::class);

        // fillForm([]) would RESET the form (dropping its defaults), so an empty payload must not call it.
        if ($data !== []) {
            $component->fillForm($data);
        }

        return $component->call('create');
    }

    // ---------------------------------------------------------------------------------------------
    // C1 - full create
    // ---------------------------------------------------------------------------------------------

    public function test_c1_a_full_rental_create_with_two_active_locations_persists_every_column_and_one_stock_row_per_location(): void
    {
        $first = $this->addLocation('Siedziba');
        $second = $this->addLocation('Oddział Północ');

        $this->submit($this->fullRentalFormData())->assertHasNoFormErrors();

        $service = $this->created('wiertarka-pelna');
        $snapshot = $this->snapshot($service);

        // quantity_total is deliberately NOT asserted here: it is typed (7) but dropped for a multi-location tenant -
        // that KNOWN GAP is pinned only in test_c4_known_gap_...
        $this->assertColumns($this->expectedFullRentalColumns(), $snapshot);
        $this->assertSame('2026-01-02 03:04:05', $service->published_at->format('Y-m-d H:i:s'));

        $this->assertBlocksKeepTheirData([
            ['type' => 'text_block', 'data' => ['content' => '<p>Blok tekstowy</p>']],
            ['type' => 'image', 'data' => ['image' => 'services/images/x.jpg', 'alt' => 'Zdjęcie']],
            ['type' => 'cta', 'data' => ['heading' => 'Zadzwoń', 'button_url' => 'https://example.com']],
            ['type' => 'service_features', 'data' => ['heading' => 'Co zawiera', 'layout' => 'grid']],
        ], $snapshot['content']);

        $this->assertSame([
            ['location_id' => $first->id, 'quantity' => 0, 'is_active' => true],
            ['location_id' => $second->id, 'quantity' => 0, 'is_active' => true],
        ], $this->stockRows($service));
    }

    public function test_c1_a_full_rental_create_with_one_active_location_routes_the_typed_quantity_into_its_stock_row(): void
    {
        $primary = $this->addLocation();

        $this->submit($this->fullRentalFormData())->assertHasNoFormErrors();

        $service = $this->created('wiertarka-pelna');
        $this->assertColumns($this->expectedFullRentalColumns(['quantity_total' => 7]), $this->snapshot($service));
        $this->assertSame([['location_id' => $primary->id, 'quantity' => 7, 'is_active' => true]], $this->stockRows($service));
    }

    public function test_c1_create_redirects_to_the_edit_page_of_the_new_service(): void
    {
        $this->addLocation();

        $component = $this->submit($this->fullRentalFormData())->assertHasNoFormErrors();

        $component->assertRedirect(ServiceResource::getUrl('edit', ['record' => $this->created('wiertarka-pelna')]));
    }

    // ---------------------------------------------------------------------------------------------
    // C2 - time_slot create
    // ---------------------------------------------------------------------------------------------

    public function test_c2_a_time_slot_create_writes_the_duration_and_leaves_every_rental_column_empty(): void
    {
        $this->addLocation();

        Livewire::test(CreateService::class)
            ->fillForm([
                'service_type' => ServiceType::TimeSlot->value,
                'name' => 'Pełne mycie',
                'slug' => 'pelne-mycie',
                'price' => 250,
                'price_from' => 199.5,
            ])
            ->set('data.duration_hours', 1)
            ->set('data.duration_mins', 30)
            ->call('create')
            ->assertHasNoFormErrors();

        $service = $this->created('pelne-mycie');

        $this->assertColumns([
            'service_type' => 'time_slot',
            'duration_minutes' => 90,
            'price' => '250.00',
            'price_from' => '199.50',
            'price_on_request' => false,
            'rental_category_id' => null,
            'quantity_total' => null,
            'price_per_day' => null,
            'price_per_hour' => null,
            'price_per_week' => null,
            'price_per_day_long' => null,
            'price_threshold_days' => null,
            'deposit_amount' => null,
            'brand' => null,
            'metadata' => null,
        ], $this->snapshot($service));
        $this->assertSame([], $this->stockRows($service), 'a time_slot service gets no stock rows');
    }

    public function test_c2_a_time_slot_create_does_not_need_the_rental_only_required_fields(): void
    {
        $this->addLocation();

        $this->submit(['service_type' => ServiceType::TimeSlot->value, 'name' => 'Mycie', 'slug' => 'mycie'])
            ->assertHasNoFormErrors();

        $this->assertSame(1, Service::withoutGlobalScopes()->where('slug', 'mycie')->count());
    }

    // ---------------------------------------------------------------------------------------------
    // C3 - minimal create: the exact defaults written today
    // ---------------------------------------------------------------------------------------------

    public function test_c3_the_blank_form_carries_these_defaults(): void
    {
        $this->addLocation();

        Livewire::test(CreateService::class)
            ->assertFormFieldIsEnabled('service_type')
            ->assertSet('data.service_type', ServiceType::TimeSlot->value)
            ->assertSet('data.icon', 'sparkles')
            ->assertSet('data.price', 0)
            ->assertSet('data.is_active', true)
            ->assertSet('data.sort_order', 0)
            ->assertSet('data.duration_minutes', 60)
            ->assertSet('data.duration_days', 0)
            ->assertSet('data.quantity_total', 1)
            ->assertSet('data.price_on_request', false)
            ->assertSet('data.hero_overlay_color', '#000000')
            ->assertSet('data.hero_overlay_opacity', '85')
            ->assertSet('data.area_served', 'Poznań')
            ->assertSet('data.average_rating', 0)
            ->assertSet('data.total_reviews', 0)
            ->assertSet('data.is_popular', false)
            ->assertSet('data.booking_count_week', 0)
            ->assertSet('data.features', [])
            ->assertSet('data.metadata.specs', []);
    }

    public function test_c3_a_minimal_rental_create_writes_exactly_these_columns(): void
    {
        $primary = $this->addLocation();
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        $this->submit([
            'service_type' => ServiceType::ItemRental->value,
            'name' => 'Minimalna wiertarka',
            'slug' => 'minimalna-wiertarka',
            'price_per_day' => 10,
        ])->assertHasNoFormErrors();

        $service = $this->created('minimalna-wiertarka');
        $snapshot = $this->snapshot($service);

        $expected = [
            'organization_id' => $this->tenant->id,
            'service_type' => 'item_rental',
            'name' => 'Minimalna wiertarka',
            'slug' => 'minimalna-wiertarka',
            'icon' => 'sparkles',
            'excerpt' => null,
            'price' => '0.00',
            'price_from' => null,
            'is_active' => true,
            'sort_order' => 0,
            'price_per_day' => '10.00',
            'price_per_hour' => null,
            'price_per_week' => null,
            'price_per_day_long' => null,
            'price_threshold_days' => null,
            'deposit_amount' => null,
            'price_on_request' => false,
            'quantity_total' => 1,
            'rental_category_id' => null,
            'brand' => null,
            'featured_image' => null,
            'hero_overlay_color' => '#000000',
            'hero_overlay_opacity' => 85,
            'body' => null,
            'content' => null,
            'meta_title' => null,
            'meta_description' => null,
            'area_served' => 'Poznań',
            'average_rating' => '0.0',
            'total_reviews' => 0,
            'is_popular' => false,
            'booking_count_week' => 0,
            'features' => null,
        ] + self::NON_FORM_COLUMNS_AT_CREATE;

        // published_at defaults to "now" (the form default) - a published service, not a draft.
        $this->assertSame('2026-06-15 12:00:00', $service->published_at->format('Y-m-d H:i:s'));

        // `duration_minutes` and `metadata` are pinned in their own KNOWN GAP tests below, not here.
        $pinnedElsewhere = ['duration_minutes', 'metadata'];
        $columnsAccountedFor = [...array_keys($expected), ...$pinnedElsewhere, 'id', 'created_at', 'published_at'];
        $this->assertEqualsCanonicalizing(
            $columnsAccountedFor,
            array_keys($snapshot),
            'the services table gained or lost a column: decide what a create must write for it, then add it to $expected (or to a KNOWN GAP test)',
        );

        $this->assertColumns($expected, $snapshot);
        $this->assertSame([['location_id' => $primary->id, 'quantity' => 1, 'is_active' => true]], $this->stockRows($service));
    }

    private function createMinimalRental(): Service
    {
        $this->addLocation();

        $this->submit([
            'service_type' => ServiceType::ItemRental->value,
            'name' => 'Minimalna wiertarka',
            'slug' => 'minimalna-wiertarka',
            'price_per_day' => 10,
        ])->assertHasNoFormErrors();

        return $this->created('minimalna-wiertarka');
    }

    public function test_c3_known_gap_a_rental_create_writes_duration_minutes_60(): void
    {
        // KNOWN GAP (stage 1 will change this): the Hidden `duration_minutes` field defaults to 60 and is
        // dehydrated for every type, so a rental - which has no duration - is created with 60 minutes (the factory
        // and the importer write 0).
        $this->assertSame(60, $this->createMinimalRental()->duration_minutes);
    }

    public function test_c3_known_gap_a_rental_create_writes_empty_specs_metadata(): void
    {
        // KNOWN GAP (stage 1 will change this): the specs Repeater dehydrates to [] even untouched, so a new
        // rental gets `metadata` = {"specs": []} instead of NULL (same mechanism as the e11 no-op save).
        $this->assertSame(['specs' => []], $this->createMinimalRental()->metadata);
    }

    public function test_c3_a_minimal_time_slot_create_writes_exactly_these_columns(): void
    {
        $this->addLocation();

        $this->submit(['service_type' => ServiceType::TimeSlot->value, 'name' => 'Minimalne mycie', 'slug' => 'minimalne-mycie'])
            ->assertHasNoFormErrors();

        $service = $this->created('minimalne-mycie');

        $this->assertColumns([
            'organization_id' => $this->tenant->id,
            'service_type' => 'time_slot',
            'icon' => 'sparkles',
            'duration_minutes' => 60,
            'price' => '0.00',
            'price_from' => null,
            'is_active' => true,
            'sort_order' => 0,
            'price_per_day' => null,
            'price_on_request' => false,
            'quantity_total' => null,
            'rental_category_id' => null,
            'brand' => null,
            'metadata' => null,
            'hero_overlay_color' => '#000000',
            'hero_overlay_opacity' => 85,
            'area_served' => 'Poznań',
            'average_rating' => '0.0',
            'features' => null,
            'content' => null,
            'body' => null,
        ] + self::NON_FORM_COLUMNS_AT_CREATE, $this->snapshot($service));
        $this->assertNotNull($service->published_at);
        $this->assertSame([], $this->stockRows($service));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<int, string>}> */
    public static function requiredFieldCases(): array
    {
        return [
            'time_slot with nothing filled in' => [[], ['name', 'slug']],
            'rental with nothing filled in' => [['service_type' => ServiceType::ItemRental->value], ['name', 'slug', 'price_per_day']],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $errorFields
     */
    #[DataProvider('requiredFieldCases')]
    public function test_c3_required_fields_on_create(array $data, array $errorFields): void
    {
        $this->addLocation();

        $component = $this->submit($data);

        $this->assertEqualsCanonicalizing(
            array_map(fn (string $field): string => 'data.'.$field, $errorFields),
            $component->errors()->keys(),
        );
        $this->assertSame(0, Service::withoutGlobalScopes()->where('organization_id', $this->tenant->id)->count());
    }

    // ---------------------------------------------------------------------------------------------
    // C4 / C5 - stock on create for the three tenant shapes
    // ---------------------------------------------------------------------------------------------

    public function test_c4_a_multi_location_create_ignores_the_typed_quantity_and_seeds_a_zero_row_per_location(): void
    {
        $first = $this->addLocation('Siedziba');
        $second = $this->addLocation('Oddział Północ');

        Livewire::test(CreateService::class)
            ->fillForm(['service_type' => ServiceType::ItemRental->value, 'name' => 'Młot', 'slug' => 'mlot', 'price_per_day' => 5, 'quantity_total' => 4])
            ->assertFormFieldIsDisabled('quantity_total')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame([
            ['location_id' => $first->id, 'quantity' => 0, 'is_active' => true],
            ['location_id' => $second->id, 'quantity' => 0, 'is_active' => true],
        ], $this->stockRows($this->created('mlot')));
    }

    public function test_c4_known_gap_a_multi_location_create_leaves_quantity_total_null_although_stock_rows_exist(): void
    {
        // KNOWN GAP (stage 1 will change this): the mirror is never recalculated at create for a multi-location
        // tenant - quantity_total stays NULL (not 0) until a stock row is edited in "Stany magazynowe".
        $this->addLocation('Siedziba');
        $this->addLocation('Oddział Północ');

        $this->submit(['service_type' => ServiceType::ItemRental->value, 'name' => 'Młot', 'slug' => 'mlot', 'price_per_day' => 5, 'quantity_total' => 4])
            ->assertHasNoFormErrors();

        $this->assertNull($this->created('mlot')->quantity_total);
    }

    public function test_c5_a_create_without_any_active_location_drops_the_typed_quantity_and_creates_no_stock_rows(): void
    {
        Livewire::test(CreateService::class)
            ->fillForm(['service_type' => ServiceType::ItemRental->value, 'name' => 'Młot', 'slug' => 'mlot', 'price_per_day' => 5, 'quantity_total' => 4])
            ->assertFormFieldIsDisabled('quantity_total')
            ->assertSee('Brak aktywnego oddziału — dodaj oddział w sekcji')
            ->call('create')
            ->assertHasNoFormErrors();

        $service = $this->created('mlot');
        $this->assertNull($service->quantity_total);
        $this->assertSame([], $this->stockRows($service));
    }

    public function test_c5_an_inactive_only_location_counts_as_no_location(): void
    {
        $this->addLocation('Zamknięty', active: false);

        $this->submit(['service_type' => ServiceType::ItemRental->value, 'name' => 'Młot', 'slug' => 'mlot', 'price_per_day' => 5, 'quantity_total' => 4])
            ->assertHasNoFormErrors();

        $this->assertNull($this->created('mlot')->quantity_total);
        $this->assertSame([], $this->stockRows($this->created('mlot')));
    }

    // ---------------------------------------------------------------------------------------------
    // C6 - name -> slug
    // ---------------------------------------------------------------------------------------------

    public function test_c6_typing_a_name_on_create_fills_the_slug(): void
    {
        Livewire::test(CreateService::class)
            ->set('data.name', 'Wiertarka udarowa Ęłąć')
            ->assertSet('data.slug', 'wiertarka-udarowa-elac');
    }

    public function test_c6_known_gap_editing_the_name_of_an_existing_service_rewrites_its_slug_and_so_its_public_url(): void
    {
        // KNOWN GAP (stage 1 will change this): ->afterStateUpdated() on `name` runs on EDIT too, so a typo fix in
        // the name silently changes the slug (and the public URL) when the form is saved.
        $service = $this->rentalService(['name' => 'Stara nazwa', 'slug' => 'stary-slug']);

        $this->saveForm($this->openEdit($service)->set('data.name', 'Nowa nazwa')->assertSet('data.slug', 'nowa-nazwa'))
            ->assertHasNoFormErrors();

        $this->assertSame('nowa-nazwa', $this->reload($service)->slug);
    }

    public function test_c6_a_slug_set_after_the_name_survives_and_a_noop_save_never_touches_the_slug(): void
    {
        $service = $this->rentalService(['name' => 'Stara nazwa', 'slug' => 'stary-slug']);

        $this->saveUnchanged($service);
        $this->assertSame('stary-slug', $this->reload($service)->slug);

        $this->saveForm($this->openEdit($service)->fillForm(['name' => 'Nowa nazwa', 'slug' => 'stary-slug']))->assertHasNoFormErrors();

        $service = $this->reload($service);
        $this->assertSame('Nowa nazwa', $service->name);
        $this->assertSame('stary-slug', $service->slug);
    }

    /** @return array<string, array{0: string|null}> */
    public static function clearedNames(): array
    {
        return ['empty string' => [''], 'null' => [null]];
    }

    #[DataProvider('clearedNames')]
    public function test_c6_known_gap_clearing_the_name_on_create_throws_a_type_error_instead_of_a_validation_message(?string $cleared): void
    {
        // KNOWN GAP (stage 1 will change this): the afterStateUpdated closure is typed `string $state`.
        $this->expectException(TypeError::class);

        Livewire::test(CreateService::class)->set('data.name', $cleared);
    }

    public function test_c6_known_gap_clearing_the_name_on_edit_throws_a_type_error_instead_of_a_validation_message(): void
    {
        // KNOWN GAP (stage 1 will change this): same closure, same TypeError, on the edit page.
        $service = $this->rentalService();
        $this->expectException(TypeError::class);

        $this->openEdit($service)->set('data.name', '');
    }

    // ---------------------------------------------------------------------------------------------
    // C7 / C8 - database errors the form does not catch
    // ---------------------------------------------------------------------------------------------

    public function test_c7_known_gap_creating_a_service_with_a_name_already_used_in_the_tenant_throws_a_database_exception(): void
    {
        // KNOWN GAP (stage 1 will change this): UNIQUE(organization_id, name) exists in the DB but the form has no
        // matching validation rule (only the slug has one), so the admin gets an unhandled exception, not a message.
        $this->addLocation();
        $this->rentalService(['name' => 'Młot udarowy', 'slug' => 'mlot-udarowy']);

        try {
            $this->submit(['service_type' => ServiceType::ItemRental->value, 'name' => 'Młot udarowy', 'slug' => 'inny-slug', 'price_per_day' => 5]);
            $this->fail('expected a unique-constraint exception');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, Service::withoutGlobalScopes()->where('organization_id', $this->tenant->id)->where('name', 'Młot udarowy')->count());
        }
    }

    public function test_c7_known_gap_renaming_a_service_to_a_name_used_by_another_throws_a_database_exception(): void
    {
        // KNOWN GAP (stage 1 will change this): same as above, on edit.
        $this->addLocation();
        $this->rentalService(['name' => 'Młot udarowy', 'slug' => 'mlot-udarowy']);
        $other = $this->rentalService(['name' => 'Wiertarka', 'slug' => 'wiertarka']);

        try {
            $this->saveForm($this->openEdit($other)->fillForm(['name' => 'Młot udarowy', 'slug' => 'wiertarka']));
            $this->fail('expected a unique-constraint exception');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame('Wiertarka', $this->reload($other)->name);
        }
    }

    public function test_c7_the_same_name_in_another_tenant_is_fine(): void
    {
        $this->addLocation();
        $otherTenant = Organization::factory()->equipmentRental()->create();
        Service::factory()->create(['organization_id' => $otherTenant->id, 'name' => 'Młot udarowy', 'slug' => 'mlot-udarowy']);

        $this->submit(['service_type' => ServiceType::ItemRental->value, 'name' => 'Młot udarowy', 'slug' => 'mlot-udarowy', 'price_per_day' => 5])
            ->assertHasNoFormErrors();

        $this->assertSame($this->tenant->id, $this->created('mlot-udarowy')->organization_id);
    }

    public function test_c8_known_gap_clearing_the_base_price_on_create_throws_a_database_exception(): void
    {
        // KNOWN GAP (stage 1 will change this): `price` is NOT NULL in the DB, optional in the form (no ->required()),
        // so an emptied "Cena bazowa" reaches the INSERT as NULL.
        $this->addLocation();

        try {
            $this->submit(['service_type' => ServiceType::ItemRental->value, 'name' => 'Młot', 'slug' => 'mlot', 'price_per_day' => 5, 'price' => null]);
            $this->fail('expected a QueryException');
        } catch (QueryException) {
            $this->assertSame(0, Service::withoutGlobalScopes()->where('slug', 'mlot')->count());
        }
    }

    public function test_c8_known_gap_clearing_the_base_price_on_edit_throws_a_database_exception(): void
    {
        // KNOWN GAP (stage 1 will change this): same, on UPDATE - the stored price is kept.
        $this->addLocation();
        $service = $this->rentalService(['price' => 100]);

        try {
            $this->saveForm($this->openEdit($service)->set('data.price', null));
            $this->fail('expected a QueryException');
        } catch (QueryException) {
            $this->assertSame('100.00', $this->reload($service)->price);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // C9 - the type switch on the create form
    // ---------------------------------------------------------------------------------------------

    public function test_c9_switching_the_type_on_create_swaps_which_fields_are_visible(): void
    {
        $this->addLocation();
        $rentalOnly = ['price_per_day', 'price_per_hour', 'price_per_week', 'price_per_day_long', 'price_threshold_days', 'deposit_amount', 'price_on_request', 'quantity_total', 'rental_category_id', 'brand', 'metadata.specs'];
        $timeSlotOnly = ['duration_days', 'duration_hours', 'duration_mins', 'price_from'];

        $component = Livewire::test(CreateService::class);

        foreach ($timeSlotOnly as $field) {
            $component->assertFormFieldIsVisible($field);
        }
        foreach ($rentalOnly as $field) {
            $component->assertFormFieldIsHidden($field);
        }

        $component->set('data.service_type', ServiceType::ItemRental->value);

        foreach ($rentalOnly as $field) {
            $component->assertFormFieldIsVisible($field);
        }
        foreach ($timeSlotOnly as $field) {
            $component->assertFormFieldIsHidden($field);
        }
    }
}
