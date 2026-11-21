<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Actions\Inventory\SyncServiceLocationStock;
use App\Enums\ServiceType;
use App\Filament\Resources\ServiceResource\Pages\EditService;
use App\Filament\Resources\ServiceResource\RelationManagers\LocationStocksRelationManager;
use App\Filament\Support\BuilderBlocks;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\ServiceUnit;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * STAGE 0 "safety net" for the admin product form (ServiceResource + EditService).
 *
 * The form is about to be refactored into tabs with per-tab save, and nothing may be lost. These tests
 * pin what the EDIT page does TODAY. Where today's behaviour is a known defect it is pinned anyway, in
 * its own method, marked `// KNOWN GAP (stage 1 will change this): ...`, so a later deliberate fix flips
 * exactly that test and nothing else. A KNOWN GAP is never asserted inside a test that is not about it.
 *
 * Reading guide - where the architect's analysis and reality differ (all pinned as reality):
 * - a "no-op save" is NOT byte-identical for `content` (Builder blocks gain their default keys on the
 *   first save) nor for `metadata` (unbound keys are dropped) - see the first-save / second-save split.
 */
class ServiceFormEditCharacterizationTest extends TestCase
{
    use RefreshDatabase, ServiceFormTestSupport;

    // E7 classification. Hand lists live ONLY here; test_every_dehydrated_form_field_... proves they match the form.
    private const FIELDS_FOR_BOTH_TYPES = [
        'service_type', 'name', 'slug', 'icon', 'excerpt', 'price', 'is_active', 'sort_order',
        'featured_image', 'hero_overlay_color', 'hero_overlay_opacity', 'body', 'content',
        'meta_title', 'meta_description', 'area_served', 'published_at',
        'average_rating', 'total_reviews', 'is_popular', 'booking_count_week', 'features',
    ];

    private const RENTAL_ONLY_FIELDS = [
        'price_per_day', 'price_per_hour', 'price_per_week', 'price_per_day_long', 'price_threshold_days',
        'deposit_amount', 'price_on_request', 'quantity_total', 'rental_category_id', 'brand', 'metadata.specs',
    ];

    private const TIME_SLOT_ONLY_FIELDS = ['duration_days', 'duration_hours', 'duration_mins', 'price_from'];

    /** Hidden field, computed from the hours/minutes inputs; visible for neither type. */
    private const COMPUTED_FIELDS = ['duration_minutes'];

    /** Display-only inputs: `dehydrated(false)`, they never reach the model. */
    private const NOT_DEHYDRATED_FIELDS = ['duration_days', 'duration_hours', 'duration_mins'];

    private Location $primary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpServiceFormTenant();
        $this->primary = $this->addLocation();
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    /**
     * Every dehydrated form column except the time_slot-only `price_from`, each set to a NON-default value
     * (form defaults: icon sparkles, overlay #000000/85, area_served Poznań, price 0, is_active true, ...).
     *
     * @return array<string, mixed>
     */
    private function fullRentalAttributes(): array
    {
        return [
            'name' => 'Wiertarka pełna',
            'slug' => 'wiertarka-pelna',
            'icon' => 'star',
            'excerpt' => 'Krótki opis produktu',
            'duration_minutes' => 0,
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
            'brand' => 'Bosch',
            'rental_category_id' => $this->category()->id,
            'metadata' => ['specs' => [
                ['label' => 'Moc', 'value' => '800', 'unit' => 'W'],
                ['label' => 'Rodzaj paliwa', 'value' => 'benzyna', 'unit' => null],
            ]],
            'featured_image' => 'services/featured/a.jpg',
            'hero_overlay_color' => '#112233',
            'hero_overlay_opacity' => 70,
            'body' => '<p>Opis <strong>pogrubiony</strong></p>',
            'content' => [
                ['type' => 'text_block', 'data' => ['content' => '<p>Blok tekstowy</p>']],
                ['type' => 'image', 'data' => ['image' => 'services/images/x.jpg', 'alt' => 'Zdjęcie']],
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
            'features' => ['Cecha 1', 'Cecha 2', 'Cecha 3', 'Cecha 4'],
        ];
    }

    /**
     * Every dehydrated form column except `price_on_request` (forced to false for a non-rental by the model),
     * including the rental columns a time_slot row can hold although the form hides them.
     *
     * @return array<string, mixed>
     */
    private function fullTimeSlotAttributes(): array
    {
        return [
            'name' => 'Pełne mycie',
            'slug' => 'pelne-mycie',
            'icon' => 'star',
            'excerpt' => 'Krótki opis usługi',
            'duration_minutes' => 90,
            'price' => 250,
            'price_from' => 199.5,
            'is_active' => false,
            'sort_order' => 9,
            'featured_image' => 'services/featured/a.jpg',
            'hero_overlay_color' => '#112233',
            'hero_overlay_opacity' => 70,
            'body' => '<p>Opis <em>usługi</em></p>',
            'content' => [['type' => 'service_features', 'data' => ['heading' => 'Co zawiera', 'layout' => 'grid']]],
            'meta_title' => 'Meta tytuł',
            'meta_description' => 'Meta opis',
            'area_served' => 'Kraków',
            'published_at' => '2026-01-02 03:04:05',
            'average_rating' => 4.5,
            'total_reviews' => 12,
            'is_popular' => true,
            'booking_count_week' => 3,
            'features' => ['Cecha 1', 'Cecha 2', 'Cecha 3', 'Cecha 4'],
            'metadata' => [
                'prices_by_size' => ['s' => 100, 'm' => 150],
                'durations_by_size' => ['s' => 30, 'm' => 45],
                'available_for_mobile' => true,
            ],
            'rental_category_id' => $this->category()->id,
            'quantity_total' => 4,
            'price_per_day' => 11,
            'price_per_hour' => 2,
            'price_per_week' => 50,
            'price_per_day_long' => 9,
            'price_threshold_days' => 3,
            'deposit_amount' => 20,
            'brand' => 'Marka',
        ];
    }

    private function actAsSuperAdmin(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');
        $superAdmin->organizations()->attach($this->tenant->id, ['role' => 'admin']);
        $this->actingAs($superAdmin);
    }

    // ---------------------------------------------------------------------------------------------
    // Completeness guard - derived from the mounted form, not from a hand list
    // ---------------------------------------------------------------------------------------------

    public function test_every_dehydrated_form_field_is_in_both_full_fixtures_and_classified_in_the_visibility_matrix(): void
    {
        $inventory = $this->formFieldInventory();

        $this->assertEqualsCanonicalizing(
            self::NOT_DEHYDRATED_FIELDS,
            array_values(array_diff($inventory['all'], $inventory['dehydrated'])),
            'the set of display-only (not dehydrated) fields changed: decide whether the new field must be in the full fixtures',
        );

        $this->assertEqualsCanonicalizing(
            [...self::FIELDS_FOR_BOTH_TYPES, ...self::RENTAL_ONLY_FIELDS, ...self::TIME_SLOT_ONLY_FIELDS, ...self::COMPUTED_FIELDS],
            $inventory['all'],
            'the form gained or lost a field: classify it in FIELDS_FOR_BOTH_TYPES / RENTAL_ONLY_FIELDS / TIME_SLOT_ONLY_FIELDS (E7 visibility matrix)',
        );

        $rental = $this->fullRentalAttributes();
        $slot = $this->fullTimeSlotAttributes();

        foreach ($inventory['dehydrated'] as $name) {
            $column = explode('.', $name)[0];

            if ($column === 'service_type') {
                continue; // chosen by the factory state, immutable after creation
            }

            if (! in_array($name, self::TIME_SLOT_ONLY_FIELDS, true)) {
                $this->assertArrayHasKey($column, $rental, "`{$name}` is dehydrated by the form but missing from fullRentalAttributes(): without it the no-op-save test cannot see it being lost");
            }

            if ($name !== 'price_on_request') {
                $this->assertArrayHasKey($column, $slot, "`{$name}` is dehydrated by the form but missing from fullTimeSlotAttributes()");
            }
        }
    }

    public function test_the_unbound_columns_are_the_ones_no_form_field_writes(): void
    {
        $this->assertEqualsCanonicalizing(
            ['description', 'booking_count_month', 'booking_count_total', 'view_count_today', 'view_count_week', 'stats_reset_daily', 'stats_reset_weekly'],
            $this->unboundColumns(),
            'the services table gained or lost a column the form does not bind: the no-op-save tests fill and compare it automatically, review that it should survive a save',
        );
    }

    // ---------------------------------------------------------------------------------------------
    // E1 / E2 / E11 - a save with zero changes
    // ---------------------------------------------------------------------------------------------

    public function test_e1_noop_save_of_a_fully_populated_rental_changes_no_column(): void
    {
        $service = $this->rentalService($this->fullRentalAttributes());
        $this->fillUnboundColumns($service);
        SyncServiceLocationStock::forService($this->reload($service));
        $original = $this->snapshot($service);
        $stocksBefore = $this->stockRows($service);
        $this->assertSame([['location_id' => $this->primary->id, 'quantity' => 7, 'is_active' => true]], $stocksBefore);

        // First save: every column but `content` is byte-identical (unbound columns included).
        // `content` may gain default keys - pinned in test_e1_known_gap_builder_blocks_gain_default_keys...
        $this->saveUnchanged($service);
        $afterFirst = $this->snapshot($service);

        $this->assertSame($this->except($original, ['content']), $this->except($afterFirst, ['content']));
        $this->assertBlocksKeepTheirData($original['content'], $afterFirst['content']);

        // Second save: now a true no-op for the WHOLE row, content included.
        $this->saveUnchanged($service);

        $this->assertSame($afterFirst, $this->snapshot($service));
        $this->assertSame($stocksBefore, $this->stockRows($service));
    }

    public function test_e1_noop_save_keeps_the_metadata_keys_the_form_does_not_bind(): void
    {
        // Fixed in stage 1: the form binds only metadata.specs, but EditService::mutateFormDataBeforeSave()
        // merges the form's keys over the stored metadata, so `image_sources` (catalog-import credits) and
        // any other key survive a save.
        $specs = [['label' => 'Moc', 'value' => '800', 'unit' => 'W']];
        $metadata = [
            'specs' => $specs,
            'image_sources' => [['file' => 'services/featured/a.jpg', 'credit' => 'Jan Kowalski']],
            'extra_key' => 'keep me',
        ];
        $service = $this->rentalService(['metadata' => $metadata]);

        $this->saveUnchanged($service);

        $this->assertSame($this->ksortDeep($metadata), $this->ksortDeep($this->reload($service)->metadata));
    }

    public function test_e1_metadata_nulled_in_the_db_while_the_form_is_open_is_not_resurrected_from_the_stale_record(): void
    {
        $specs = [['label' => 'Moc', 'value' => '800', 'unit' => 'W']];
        $service = $this->rentalService(['metadata' => [
            'specs' => $specs,
            'image_sources' => [['file' => 'services/featured/a.jpg', 'credit' => 'Jan Kowalski']],
        ]]);

        $component = $this->openEdit($service);
        Service::withoutGlobalScope('organization')->whereKey($service->id)->update(['metadata' => null]);

        $this->saveForm($component)->assertHasNoFormErrors();

        $this->assertSame(['specs' => $specs], $this->reload($service)->metadata, 'only the form keys; the stale image_sources must not come back');
    }

    public function test_e1_the_merge_ignores_a_stale_in_memory_record_when_the_stored_metadata_is_null(): void
    {
        // The end-to-end test above cannot tell the two fallbacks apart: Livewire re-hydrates $record from the DB on
        // every request, so the in-memory record is never stale there. Hence the direct call with a stale instance.
        $service = $this->rentalService(['metadata' => ['specs' => [], 'image_sources' => [['file' => 'a.jpg', 'credit' => 'Jan']]]]);
        $stale = $this->reload($service);
        Service::withoutGlobalScope('organization')->whereKey($service->id)->update(['metadata' => null]);

        $page = $this->openEdit($service)->instance();
        $page->record = $stale;
        $merge = new \ReflectionMethod($page, 'mutateFormDataBeforeSave');

        $this->assertSame(['metadata' => ['specs' => []]], $merge->invoke($page, ['metadata' => ['specs' => []]]));
    }

    public function test_e1_the_form_wins_over_stored_metadata_so_clearing_or_replacing_specs_still_works(): void
    {
        $extra = ['image_sources' => [['file' => 'services/featured/a.jpg', 'credit' => 'Jan Kowalski']], 'extra_key' => 'keep me'];
        $service = $this->rentalService(['metadata' => ['specs' => [['label' => 'Moc', 'value' => '800', 'unit' => 'W']]] + $extra]);

        $this->saveForm($this->openEdit($service)->fillForm(['metadata.specs' => []]))->assertHasNoFormErrors();
        $this->assertSame($this->ksortDeep(['specs' => []] + $extra), $this->ksortDeep($this->reload($service)->metadata), 'cleared specs stay cleared, other keys stay');

        $new = [['label' => 'Waga', 'value' => '5', 'unit' => 'kg']];
        $this->saveForm($this->openEdit($service)->fillForm(['metadata.specs' => $new]))->assertHasNoFormErrors();
        $this->assertSame($this->ksortDeep(['specs' => $new] + $extra), $this->ksortDeep($this->reload($service)->metadata), 'replaced specs win, other keys stay');
    }

    public function test_e1_known_gap_noop_save_nulls_a_featured_image_whose_file_is_missing_on_disk(): void
    {
        // KNOWN GAP (stage 1 will change this): FileUpload drops a hydrated path that does not exist on the
        // disk, so a record pointing at a deleted/never-copied file silently loses the column on ANY save.
        $service = $this->rentalService(['featured_image' => 'services/featured/deleted-long-ago.jpg']);

        $this->saveUnchanged($service);

        $this->assertNull($this->reload($service)->featured_image);
    }

    public function test_e2_noop_save_of_a_fully_populated_time_slot_changes_nothing_including_the_hidden_rental_columns(): void
    {
        $service = $this->timeSlotService($this->fullTimeSlotAttributes());
        $this->fillUnboundColumns($service);
        $original = $this->snapshot($service);

        $this->saveUnchanged($service);

        // Hidden sections are skipped by dehydration, not nulled: rental columns, metadata (prices_by_size,
        // durations_by_size, available_for_mobile), duration_minutes and every unbound column come out identical.
        $this->assertSame($original, $this->snapshot($service));
        $this->assertSame([], $this->stockRows($service), 'a time_slot save must not create stock rows');
        $this->assertSame(4, $this->reload($service)->quantity_total);
    }

    public function test_e11_noop_save_with_every_json_column_null_keeps_content_features_and_body_null(): void
    {
        $rental = $this->rentalService(['metadata' => null, 'content' => null, 'features' => null, 'body' => null]);
        $slot = $this->timeSlotService(['metadata' => null, 'content' => null, 'features' => null, 'body' => null]);

        $this->saveUnchanged($rental);
        $this->saveUnchanged($slot);

        foreach ([$rental, $slot] as $service) {
            $fresh = $this->reload($service);
            $this->assertNull($fresh->content, 'content');
            $this->assertNull($fresh->features, 'features');
            $this->assertNull($fresh->body, 'body');
        }

        $this->assertNull($this->reload($slot)->metadata, 'a time_slot never binds metadata, so NULL stays NULL');
    }

    public function test_e11_known_gap_noop_save_of_a_rental_with_null_metadata_writes_empty_specs(): void
    {
        // KNOWN GAP (stage 1 will change this): the specs Repeater dehydrates to [] even untouched, so
        // `metadata` NULL becomes {"specs": []} - the only column a no-op save of this rental changes.
        $rental = $this->rentalService(['metadata' => null, 'content' => null, 'features' => null, 'body' => null]);
        $original = $this->snapshot($rental);

        $this->saveUnchanged($rental);

        $after = $this->snapshot($rental);
        $this->assertSame(['specs' => []], $after['metadata']);
        $this->assertSame($this->except($original, ['metadata']), $this->except($after, ['metadata']));
    }

    public function test_save_keeps_the_admin_on_the_page_and_notifies(): void
    {
        $service = $this->rentalService();

        $this->saveForm($this->openEdit($service))
            ->assertNoRedirect()
            ->assertNotified('Zapisano pomyślnie');
    }

    public function test_the_service_type_cannot_be_changed_after_creation(): void
    {
        $service = $this->rentalService();

        $this->saveForm(
            $this->openEdit($service)
                ->assertFormFieldIsDisabled('service_type')
                ->set('data.service_type', ServiceType::TimeSlot->value)
        );

        $this->assertSame(ServiceType::ItemRental, $this->reload($service)->service_type);
    }

    public function test_two_saves_on_the_same_mounted_page_both_persist(): void
    {
        $service = $this->rentalService();
        $page = $this->openEdit($service);

        $this->saveForm($page->set('data.sort_order', 11))->assertHasNoFormErrors();
        $this->saveForm($page->set('data.brand', 'Makita'))->assertHasNoFormErrors();

        $fresh = $this->reload($service);
        $this->assertSame(11, $fresh->sort_order);
        $this->assertSame('Makita', $fresh->brand);
    }

    public function test_a_rental_without_a_category_saves_unchanged(): void
    {
        $service = $this->rentalService(['rental_category_id' => null]);
        $original = $this->snapshot($service);

        $this->saveUnchanged($service);

        $this->assertSame($original, $this->snapshot($service));
    }

    public function test_a_category_of_another_tenant_is_rejected_and_nothing_is_saved(): void
    {
        $foreign = RentalCategory::factory()->for(Organization::factory()->equipmentRental()->create(), 'organization')->create(['name' => 'Obca', 'slug' => 'obca']);
        $service = $this->rentalService();
        $original = $this->snapshot($service);

        $component = $this->saveForm($this->openEdit($service)->set('data.rental_category_id', $foreign->id)->set('data.sort_order', 88));

        $this->assertTrue($this->hasErrorOn($component, 'data.rental_category_id'));
        $this->assertSame($original, $this->snapshot($service), 'a rejected save persists none of its fields');
    }

    // ---------------------------------------------------------------------------------------------
    // Stored values the form cannot represent
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function storedValuesOutsideTheFormOptions(): array
    {
        return [
            'hero_overlay_opacity 75 (options are 10..95 in steps)' => ['hero_overlay_opacity', 75],
            'an icon that is not in the icon list' => ['icon', 'no-such-icon'],
        ];
    }

    #[DataProvider('storedValuesOutsideTheFormOptions')]
    public function test_known_gap_a_stored_value_outside_the_select_options_makes_the_whole_record_unsaveable(string $column, mixed $stored): void
    {
        // KNOWN GAP (stage 1 will change this): the Select's `in` rule rejects the stored value, so EVERY save of
        // such a record fails on that field - even one that never touched it - and none of its edits persist.
        $service = $this->rentalService([$column => $stored]);

        $component = $this->saveForm($this->openEdit($service)->set('data.sort_order', 88));

        $this->assertTrue($this->hasErrorOn($component, 'data.'.$column));
        $this->assertSame(5, $this->reload($service)->sort_order);
    }

    // ---------------------------------------------------------------------------------------------
    // E3 - per-field round trip
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: mixed, 3: mixed}> [service type, form field, value set, attribute as the model reads it]
     */
    public static function scalarFieldCases(): array
    {
        $common = [
            'price' => ['price', 12.34, '12.34'],
            'icon' => ['icon', 'star', 'star'],
            'excerpt' => ['excerpt', 'Nowy krótki opis', 'Nowy krótki opis'],
            'sort_order' => ['sort_order', 7, 7],
            'is_active' => ['is_active', false, false],
            'hero_overlay_color' => ['hero_overlay_color', '#abcdef', '#abcdef'],
            'hero_overlay_opacity' => ['hero_overlay_opacity', '70', 70],
            'body' => ['body', '<p>Nowy opis</p>', '<p>Nowy opis</p>'],
            'meta_title' => ['meta_title', 'Nowy meta tytuł', 'Nowy meta tytuł'],
            'meta_description' => ['meta_description', 'Nowy meta opis', 'Nowy meta opis'],
            'area_served' => ['area_served', 'Wrocław', 'Wrocław'],
            'average_rating' => ['average_rating', 4.5, '4.5'],
            'total_reviews' => ['total_reviews', 12, 12],
            'is_popular' => ['is_popular', true, true],
            'booking_count_week' => ['booking_count_week', 5, 5],
        ];

        $rentalOnly = [
            'price_per_day' => ['price_per_day', 150, '150.00'],
            'price_per_hour' => ['price_per_hour', 20.5, '20.50'],
            'price_per_week' => ['price_per_week', 500, '500.00'],
            'price_per_day_long' => ['price_per_day_long', 70.5, '70.50'],
            'price_threshold_days' => ['price_threshold_days', 10, 10],
            'deposit_amount' => ['deposit_amount', 250, '250.00'],
            'price_on_request' => ['price_on_request', true, true],
            'quantity_total' => ['quantity_total', 12, 12],
            'brand' => ['brand', 'Makita', 'Makita'],
        ];

        $slotOnly = [
            'price_from' => ['price_from', 80, '80.00'],
        ];

        $cases = [];
        foreach ([ServiceType::ItemRental->value => $rentalOnly, ServiceType::TimeSlot->value => $slotOnly] as $type => $specific) {
            foreach ($common + $specific as $name => [$field, $value, $expected]) {
                $cases["{$type}: {$name}"] = [$type, $field, $value, $expected];
            }
        }

        return $cases;
    }

    #[DataProvider('scalarFieldCases')]
    public function test_e3_each_field_round_trips_and_touches_nothing_else(string $type, string $field, mixed $value, mixed $expected): void
    {
        $service = $type === ServiceType::ItemRental->value ? $this->rentalService() : $this->timeSlotService();
        $before = $this->snapshot($service);

        $this->saveForm($this->openEdit($service)->set('data.'.$field, $value))->assertHasNoFormErrors();

        $after = $this->snapshot($service);
        $this->assertSame($expected, $after[$field]);
        $this->assertSame($this->except($before, [$field]), $this->except($after, [$field]), "saving `{$field}` changed another column");
    }

    public function test_e3_changing_the_category_round_trips(): void
    {
        $service = $this->rentalService();
        $other = $this->category('Sprzęt ogrodniczy');

        $this->saveForm($this->openEdit($service)->set('data.rental_category_id', $other->id))->assertHasNoFormErrors();

        $this->assertSame($other->id, $this->reload($service)->rental_category_id);
    }

    public function test_e3_featured_image_can_be_replaced_and_cleared(): void
    {
        $service = $this->rentalService(['featured_image' => 'services/featured/a.jpg']);

        $this->saveForm($this->openEdit($service)->set('data.featured_image', ['file-1' => 'services/featured/b.jpg']))->assertHasNoFormErrors();
        $this->assertSame('services/featured/b.jpg', $this->reload($service)->featured_image);

        $this->saveForm($this->openEdit($service)->set('data.featured_image', []))->assertHasNoFormErrors();
        $this->assertNull($this->reload($service)->featured_image);
    }

    public function test_e3_real_uploads_are_stored_on_the_public_disk_in_the_documented_directories(): void
    {
        // The image block starts empty: a first upload. (Replacing an existing file via set() keeps the old path - the real UI removes it first.)
        $service = $this->rentalService(['featured_image' => null, 'content' => [['type' => 'image', 'data' => ['alt' => 'Zdjęcie']]]]);
        $page = $this->openEdit($service);
        $blockKey = array_key_first($page->get('data.content'));

        $page->set('data.featured_image', UploadedFile::fake()->image('hero.jpg', 1920, 1080))
            ->set("data.content.{$blockKey}.data.image", UploadedFile::fake()->image('block.jpg'));
        $this->saveForm($page)->assertHasNoFormErrors();

        $fresh = $this->reload($service);
        $this->assertMatchesRegularExpression('#^services/featured/[^/]+\.jpg$#', (string) $fresh->featured_image);
        Storage::disk('public')->assertExists($fresh->featured_image);

        $blockImage = $fresh->content[0]['data']['image'];
        $this->assertMatchesRegularExpression('#^services/images/[^/]+\.jpg$#', $blockImage);
        Storage::disk('public')->assertExists($blockImage);
    }

    public function test_e3_published_at_can_be_set_and_cleared_to_make_a_draft(): void
    {
        $service = $this->timeSlotService(['published_at' => '2026-01-02 03:04:05']);

        $this->saveForm($this->openEdit($service)->set('data.published_at', '2026-03-04 10:20:00'))->assertHasNoFormErrors();
        $this->assertSame('2026-03-04 10:20:00', $this->reload($service)->published_at->format('Y-m-d H:i:s'));

        $this->saveForm($this->openEdit($service)->set('data.published_at', null))->assertHasNoFormErrors();
        $this->assertNull($this->reload($service)->published_at);
    }

    public function test_e3_specs_keep_order_and_accept_the_boundary_lengths(): void
    {
        $service = $this->rentalService();
        $specs = [
            ['label' => str_repeat('a', 100), 'value' => str_repeat('b', 100), 'unit' => str_repeat('c', 20)],
            ['label' => 'Waga', 'value' => '12', 'unit' => 'kg'],
            ['label' => 'Rodzaj paliwa', 'value' => 'benzyna', 'unit' => null],
        ];

        $this->saveForm($this->openEdit($service)->set('data.metadata.specs', $specs))->assertHasNoFormErrors();

        $this->assertSame($this->ksortDeep($specs), $this->ksortDeep($this->reload($service)->metadata['specs']));
    }

    public function test_e3_the_spec_template_action_replaces_the_existing_specs_with_the_industry_template(): void
    {
        $service = $this->rentalService(['metadata' => ['specs' => [['label' => 'Moja własna', 'value' => '1', 'unit' => 'x']]]]);
        $template = $this->tenant->industry->defaultSpecDefinitions();
        $this->assertNotEmpty($template);

        $page = $this->openEdit($service)->callAction(TestAction::make('loadSpecTemplate')->schemaComponent('specyfikacja-techniczna::data::section'));
        $this->saveForm($page)->assertHasNoFormErrors();

        // The modal says it: the current specs are REPLACED. Values come back empty (stored as null), units from the template.
        $expected = array_map(fn (array $definition): array => ['label' => $definition['label'], 'value' => null, 'unit' => $definition['unit']], array_values($template));
        $this->assertSame($this->ksortDeep($expected), $this->ksortDeep($this->reload($service)->metadata['specs']));
    }

    // ---------------------------------------------------------------------------------------------
    // E3 - validation (independent rows are checked in one mount; interacting rules stay separate)
    // ---------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $sets
     */
    private function setAll(Testable $component, array $sets): Testable
    {
        foreach ($sets as $field => $value) {
            $component->set('data.'.$field, $value);
        }

        return $component;
    }

    public function test_e3_every_independent_rule_rejects_its_violation_and_nothing_is_saved(): void
    {
        $service = $this->rentalService();
        $original = $this->snapshot($service);
        $chars = fn (int $n): string => str_repeat('a', $n);
        $feature = fn (string $text): array => ['feature' => $text];

        $component = $this->setAll($this->openEdit($service), [
            'name' => $chars(256),
            'slug' => '', // after `name`: that field rewrites the slug
            'excerpt' => $chars(501),
            'meta_title' => $chars(61),
            'meta_description' => $chars(161),
            'brand' => $chars(256),
            'area_served' => $chars(256),
            'price_per_day' => null,
            'price' => 'abc',
            'sort_order' => null,
            'price_threshold_days' => 366,
            'quantity_total' => 0,
            'average_rating' => 5.1,
            'total_reviews' => -1,
            'booking_count_week' => -1,
            'features' => [$feature($chars(101)), $feature(''), $feature('c'), $feature('d'), $feature('e')],
            'metadata.specs' => [
                ['label' => $chars(101), 'value' => 'v', 'unit' => 'u'],
                ['label' => 'l', 'value' => $chars(101), 'unit' => 'u'],
                ['label' => 'l', 'value' => 'v', 'unit' => $chars(21)],
            ],
        ]);
        $this->saveForm($component);

        foreach (['name', 'slug', 'excerpt', 'meta_title', 'meta_description', 'brand', 'area_served', 'price_per_day', 'price', 'sort_order', 'price_threshold_days', 'quantity_total', 'average_rating', 'total_reviews', 'booking_count_week', 'features'] as $field) {
            $this->assertTrue($this->hasErrorOn($component, 'data.'.$field), "no validation error on data.{$field}; got: ".implode(', ', $component->errors()->keys()));
        }

        $keys = $component->errors()->keys();
        $nested = fn (string $prefix, string $suffix): int => collect($keys)->filter(fn (string $key): bool => str_starts_with($key, $prefix) && str_ends_with($key, $suffix))->count();
        $this->assertSame(2, $nested('data.features.', '.feature'), 'the 101-char and the blank feature items each carry their own error');
        $this->assertSame(1, $nested('data.metadata.specs.', '.label'), 'spec label 101');
        $this->assertSame(1, $nested('data.metadata.specs.', '.value'), 'spec value 101');
        $this->assertSame(1, $nested('data.metadata.specs.', '.unit'), 'spec unit 21');

        $this->assertSame($original, $this->snapshot($service), 'a rejected save persists none of its fields');
    }

    public function test_e3_every_boundary_value_is_accepted_and_persisted(): void
    {
        $service = $this->rentalService();
        $chars = fn (int $n): string => str_repeat('a', $n);
        $specs = [['label' => $chars(100), 'value' => $chars(100), 'unit' => $chars(20)]];
        $features = ['a', 'b', 'c', $chars(100)];

        $component = $this->setAll($this->openEdit($service), [
            'name' => $chars(255),
            'slug' => $chars(255), // the name no longer rewrites the slug on edit (stage 1, C6), so the slug boundary is set explicitly
            'excerpt' => $chars(500),
            'meta_title' => $chars(60),
            'meta_description' => $chars(160),
            'brand' => $chars(255),
            'area_served' => $chars(255),
            'price_per_day_long' => 100, // equal to price_per_day: lte
            'price_threshold_days' => 365,
            'average_rating' => 5,
            'features' => array_map(fn (string $text): array => ['feature' => $text], $features),
            'metadata.specs' => $specs,
        ]);
        $this->saveForm($component)->assertHasNoFormErrors();

        $fresh = $this->reload($service);
        $this->assertSame($chars(255), $fresh->name);
        $this->assertSame($chars(255), $fresh->slug);
        $this->assertSame($chars(500), $fresh->excerpt);
        $this->assertSame($chars(60), $fresh->meta_title);
        $this->assertSame($chars(160), $fresh->meta_description);
        $this->assertSame($chars(255), $fresh->brand);
        $this->assertSame($chars(255), $fresh->area_served);
        $this->assertSame('100.00', $fresh->price_per_day_long);
        $this->assertSame(365, $fresh->price_threshold_days);
        $this->assertSame('5.0', $fresh->average_rating);
        $this->assertSame($features, $fresh->features);
        $this->assertSame($this->ksortDeep($specs), $this->ksortDeep($fresh->metadata['specs']));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string|null, 2: array<string, mixed>}> [fields set, field carrying the error (null = accepted), what must then be persisted]
     */
    public static function interactingRules(): array
    {
        return [
            'long rate above the day rate is rejected' => [['price_per_day_long' => 120], 'price_per_day_long', []],
            'long rate without a threshold is rejected' => [['price_threshold_days' => null], 'price_threshold_days', []],
            'threshold without a long rate is rejected' => [['price_per_day_long' => null], 'price_per_day_long', []],
            'threshold 0 is rejected' => [['price_threshold_days' => 0], 'price_threshold_days', []],
            'quantity is required for a single-location tenant' => [['quantity_total' => null], 'quantity_total', []],
            'average_rating below 0 is rejected' => [['average_rating' => -0.1], 'average_rating', []],
            'threshold 1 is accepted' => [['price_threshold_days' => 1], null, ['price_threshold_days' => 1]],
            'long rate and threshold both empty are accepted' => [['price_per_day_long' => null, 'price_threshold_days' => null], null, ['price_per_day_long' => null, 'price_threshold_days' => null]],
        ];
    }

    /**
     * @param  array<string, mixed>  $sets
     * @param  array<string, mixed>  $persisted
     */
    #[DataProvider('interactingRules')]
    public function test_e3_interacting_rules(array $sets, ?string $errorOn, array $persisted): void
    {
        $service = $this->rentalService();
        $original = $this->snapshot($service);

        $component = $this->saveForm($this->setAll($this->openEdit($service), $sets));

        if ($errorOn === null) {
            $component->assertHasNoFormErrors();
            $fresh = $this->snapshot($service);
            foreach ($persisted as $column => $value) {
                $this->assertSame($value, $fresh[$column], "`{$column}` was not persisted");
            }

            return;
        }

        $this->assertTrue($this->hasErrorOn($component, 'data.'.$errorOn), "expected an error on data.{$errorOn}, got: ".implode(', ', $component->errors()->keys()));
        $this->assertSame($original, $this->snapshot($service), 'a rejected save persists none of its fields');
    }

    public function test_e3_time_slot_rules_reject_their_violations_in_one_pass(): void
    {
        $service = $this->timeSlotService();
        $original = $this->snapshot($service);

        $component = $this->saveForm($this->setAll($this->openEdit($service), [
            'price_from' => 'abc',
            'meta_title' => str_repeat('a', 61),
            'duration_hours' => 24,
            'duration_mins' => 60,
        ]));

        foreach (['price_from', 'meta_title', 'duration_hours', 'duration_mins'] as $field) {
            $this->assertTrue($this->hasErrorOn($component, 'data.'.$field), "no validation error on data.{$field}");
        }
        $this->assertSame($original, $this->snapshot($service));
    }

    public function test_e3_time_slot_boundaries_are_accepted_and_persisted(): void
    {
        $service = $this->timeSlotService();

        $this->saveForm($this->setAll($this->openEdit($service), [
            'duration_hours' => 23,
            'duration_mins' => 59,
            'meta_title' => str_repeat('a', 60),
            'price_from' => 0.5,
        ]))->assertHasNoFormErrors();

        $fresh = $this->reload($service);
        $this->assertSame(23 * 60 + 59, $fresh->duration_minutes);
        $this->assertSame(str_repeat('a', 60), $fresh->meta_title);
        $this->assertSame('0.50', $fresh->price_from);
    }

    // ---------------------------------------------------------------------------------------------
    // E4 - duration (time_slot)
    // ---------------------------------------------------------------------------------------------

    public function test_e4_duration_minutes_hydrate_into_hours_and_minutes_inputs(): void
    {
        $service = $this->timeSlotService(['duration_minutes' => 135]);

        $this->openEdit($service)
            ->assertSet('data.duration_hours', 2)
            ->assertSet('data.duration_mins', 15)
            ->assertSet('data.duration_minutes', 135)
            ->assertFormFieldIsDisabled('duration_days');
    }

    public function test_e4_setting_hours_and_minutes_saves_the_total_in_minutes(): void
    {
        $service = $this->timeSlotService(['duration_minutes' => 135]);

        $this->saveForm($this->openEdit($service)->set('data.duration_hours', 1)->set('data.duration_mins', 30))->assertHasNoFormErrors();

        $this->assertSame(90, $this->reload($service)->duration_minutes);
    }

    public function test_e4_changing_only_the_hours_keeps_the_hydrated_minutes(): void
    {
        $service = $this->timeSlotService(['duration_minutes' => 135]);

        $this->saveForm($this->openEdit($service)->set('data.duration_hours', 3))->assertHasNoFormErrors();

        $this->assertSame(195, $this->reload($service)->duration_minutes);
    }

    public function test_e4_minutes_are_not_forced_to_a_step_of_15(): void
    {
        // `->step(15)` is an HTML attribute only - there is no validation rule behind it.
        $service = $this->timeSlotService(['duration_minutes' => 120]);

        $this->saveForm($this->openEdit($service)->set('data.duration_mins', 20))->assertHasNoFormErrors();

        $this->assertSame(140, $this->reload($service)->duration_minutes);
    }

    public function test_e4_a_duration_of_zero_hydrates_blank_inputs_and_survives_a_noop_save(): void
    {
        $service = $this->timeSlotService(['duration_minutes' => 0]);

        $this->saveForm($this->openEdit($service)->assertSet('data.duration_hours', null)->assertSet('data.duration_mins', null))
            ->assertHasNoFormErrors();

        $this->assertSame(0, $this->reload($service)->duration_minutes);
    }

    public function test_e4_known_gap_clearing_both_duration_inputs_saves_zero_minutes_without_validation(): void
    {
        // KNOWN GAP (stage 1 will change this): nothing requires a non-zero duration, so a time_slot service
        // can be saved with 0 minutes (never NULL - `duration_minutes` is NOT NULL, the Hidden field is recomputed).
        $service = $this->timeSlotService(['duration_minutes' => 90]);

        $this->saveForm($this->openEdit($service)->set('data.duration_hours', null)->set('data.duration_mins', null))->assertHasNoFormErrors();

        $this->assertSame(0, $this->reload($service)->duration_minutes);
    }

    public function test_e4_a_rental_has_no_duration_inputs_and_keeps_its_stored_duration(): void
    {
        $service = $this->rentalService(['duration_minutes' => 0]);

        $this->saveForm(
            $this->openEdit($service)
                ->assertFormFieldIsHidden('duration_hours')
                ->assertFormFieldIsHidden('duration_mins')
        )->assertHasNoFormErrors();

        $this->assertSame(0, $this->reload($service)->duration_minutes);
    }

    // ---------------------------------------------------------------------------------------------
    // E5 / E5b / E6 - quantity_total and the stock anchor; lost updates
    // ---------------------------------------------------------------------------------------------

    public function test_e5_known_gap_a_stale_form_save_reverts_an_inline_stock_edit_made_meanwhile(): void
    {
        // KNOWN GAP (stage 1 will change this): "Ilość w magazynie" is hydrated once at mount. An inline stock
        // edit in the sibling LocationStocksRelationManager (a separate Livewire component) does not reach it,
        // so the next save of the page writes the OLD number back into the anchor row AND quantity_total.
        $service = $this->rentalService(['quantity_total' => 5]);
        $anchor = $this->setStock($service, $this->primary, 5);

        $page = $this->openEdit($service)->assertSet('data.quantity_total', 5);

        Livewire::test(LocationStocksRelationManager::class, ['ownerRecord' => $this->reload($service), 'pageClass' => EditService::class])
            ->call('updateTableColumnState', 'quantity', (string) $anchor->getKey(), 9);
        $this->assertSame([['location_id' => $this->primary->id, 'quantity' => 9, 'is_active' => true]], $this->stockRows($service));
        $this->assertSame(9, $this->reload($service)->quantity_total);

        $this->saveForm($page)->assertHasNoFormErrors();

        $this->assertSame([['location_id' => $this->primary->id, 'quantity' => 5, 'is_active' => true]], $this->stockRows($service), 'the inline edit (9) was reverted');
        $this->assertSame(5, $this->reload($service)->quantity_total);
    }

    public function test_e5_known_gap_a_stale_form_save_overwrites_counters_changed_meanwhile(): void
    {
        // KNOWN GAP (stage 1 will change this): same mechanism as above for the "Optymalizacja konwersji" fields -
        // every dehydrated field is written back from the state read at mount, so whatever changed in the database
        // meanwhile (an import, a review job, another admin) is overwritten by the stale value.
        $service = $this->rentalService(['booking_count_week' => 3, 'total_reviews' => 10, 'average_rating' => 4.0]);
        $page = $this->openEdit($service);

        Service::withoutGlobalScopes()->whereKey($service->id)->update(['booking_count_week' => 9, 'total_reviews' => 20, 'average_rating' => 4.8]);

        $this->saveForm($page)->assertHasNoFormErrors();

        $fresh = $this->reload($service);
        $this->assertSame(3, $fresh->booking_count_week, 'the newer value (9) was overwritten');
        $this->assertSame(10, $fresh->total_reviews, 'the newer value (20) was overwritten');
        $this->assertSame('4.0', $fresh->average_rating, 'the newer value (4.8) was overwritten');
    }

    public function test_e5_a_freshly_opened_form_after_an_inline_stock_edit_saves_without_reverting_it(): void
    {
        $service = $this->rentalService(['quantity_total' => 5]);
        $anchor = $this->setStock($service, $this->primary, 5);

        Livewire::test(LocationStocksRelationManager::class, ['ownerRecord' => $this->reload($service), 'pageClass' => EditService::class])
            ->call('updateTableColumnState', 'quantity', (string) $anchor->getKey(), 9);

        $this->saveForm($this->openEdit($service)->assertSet('data.quantity_total', 9))->assertHasNoFormErrors();

        $this->assertSame([['location_id' => $this->primary->id, 'quantity' => 9, 'is_active' => true]], $this->stockRows($service));
        $this->assertSame(9, $this->reload($service)->quantity_total);
    }

    public function test_e5_a_multi_location_save_does_not_clobber_the_stock_split_or_the_mirror(): void
    {
        $second = $this->addLocation('Oddział Północ');
        $service = $this->rentalService(['quantity_total' => 5]);
        $this->setStock($service, $this->primary, 2);
        $this->setStock($service, $second, 3);
        $rowsBefore = $this->stockRows($service);

        $this->saveForm($this->openEdit($service)->set('data.sort_order', 42))->assertHasNoFormErrors();

        $this->assertSame($rowsBefore, $this->stockRows($service));
        $this->assertSame(5, $this->reload($service)->quantity_total);
        $this->assertSame(42, $this->reload($service)->sort_order);
    }

    public function test_e5_a_save_does_not_absorb_stock_orphaned_at_a_deactivated_location(): void
    {
        $second = $this->addLocation('Oddział Północ');
        $service = $this->rentalService(['quantity_total' => 8]);
        $this->setStock($service, $this->primary, 5);
        $this->setStock($service, $second, 3);
        $second->update(['is_active' => false]);
        $rowsBefore = $this->stockRows($service);

        $this->saveForm($this->openEdit($service)->set('data.sort_order', 42))->assertHasNoFormErrors();

        $this->assertSame($rowsBefore, $this->stockRows($service));
        $this->assertSame(8, $this->reload($service)->quantity_total);
    }

    public function test_e5b_the_quantity_field_has_no_helper_text_when_it_is_directly_editable(): void
    {
        $service = $this->rentalService();

        $this->openEdit($service)
            ->assertFormFieldIsEnabled('quantity_total')
            ->assertDontSee('Brak aktywnego oddziału')
            ->assertDontSee('Ten produkt ma zarejestrowane egzemplarze')
            ->assertDontSee('Więcej niż jeden aktywny oddział');
    }

    public function test_e5b_helper_text_without_an_active_location(): void
    {
        $service = $this->rentalService();
        // The observer refuses to deactivate a tenant's only active location, so remove it underneath the model.
        DB::table('locations')->where('organization_id', $this->tenant->id)->delete();

        $this->openEdit($service)
            ->assertFormFieldIsDisabled('quantity_total')
            ->assertSee('Brak aktywnego oddziału — dodaj oddział w sekcji')
            ->assertDontSee('Ten produkt ma zarejestrowane egzemplarze')
            ->assertDontSee('Więcej niż jeden aktywny oddział');
    }

    public function test_e5b_helper_text_with_more_than_one_active_location(): void
    {
        $this->addLocation('Oddział Północ');
        $service = $this->rentalService();

        $this->openEdit($service)
            ->assertFormFieldIsDisabled('quantity_total')
            ->assertSee('Więcej niż jeden aktywny oddział, albo istnieje stan magazynowy na innym oddziale')
            ->assertDontSee('Brak aktywnego oddziału')
            ->assertDontSee('Ten produkt ma zarejestrowane egzemplarze');
    }

    public function test_e5b_helper_text_when_stock_is_orphaned_at_a_deactivated_location_is_the_same_as_for_multi_location(): void
    {
        $second = $this->addLocation('Oddział Północ');
        $service = $this->rentalService();
        $this->setStock($service, $second, 3);
        $second->update(['is_active' => false]);

        $this->openEdit($service)
            ->assertFormFieldIsDisabled('quantity_total')
            ->assertSee('Więcej niż jeden aktywny oddział, albo istnieje stan magazynowy na innym oddziale');
    }

    public function test_e5b_helper_text_when_the_primary_location_has_units(): void
    {
        $service = $this->rentalService(['quantity_total' => 0]);
        ServiceUnit::factory()->create([
            'organization_id' => $this->tenant->id,
            'service_id' => $service->id,
            'location_id' => $this->primary->id,
            'identifier' => 'WIERTARKA-1',
        ]);

        $this->openEdit($service)
            ->assertFormFieldIsDisabled('quantity_total')
            ->assertSee('Ten produkt ma zarejestrowane egzemplarze')
            ->assertDontSee('Brak aktywnego oddziału')
            ->assertDontSee('Więcej niż jeden aktywny oddział');
    }

    public function test_e6_saving_routes_quantity_total_into_the_primary_anchor_for_a_single_location_tenant(): void
    {
        $service = $this->rentalService(['quantity_total' => 5]);
        $this->setStock($service, $this->primary, 5);

        $this->saveForm($this->openEdit($service)->set('data.quantity_total', 11))->assertHasNoFormErrors();

        $this->assertSame([['location_id' => $this->primary->id, 'quantity' => 11, 'is_active' => true]], $this->stockRows($service));
        $this->assertSame(11, $this->reload($service)->quantity_total);
    }

    public function test_e6_saving_creates_the_missing_primary_anchor_row(): void
    {
        $service = $this->rentalService(['quantity_total' => 5]);
        $this->assertSame([], $this->stockRows($service));

        $this->saveUnchanged($service);

        $this->assertSame([['location_id' => $this->primary->id, 'quantity' => 5, 'is_active' => true]], $this->stockRows($service));
    }

    public function test_e6_refresh_quantity_total_field_reads_the_mirror_from_the_database(): void
    {
        $service = $this->rentalService(['quantity_total' => 3]);
        $page = $this->openEdit($service)->assertSet('data.quantity_total', 3);

        Service::withoutGlobalScopes()->whereKey($service->id)->update(['quantity_total' => 8]);

        $page->call('refreshQuantityTotalField')->assertSet('data.quantity_total', 8);

        Service::withoutGlobalScopes()->whereKey($service->id)->update(['quantity_total' => 6]);

        $page->dispatch('service-unit-stock-changed')->assertSet('data.quantity_total', 6);
    }

    public function test_e6_a_time_slot_save_creates_no_stock_rows(): void
    {
        $service = $this->timeSlotService(['quantity_total' => 4]);

        $this->saveForm($this->openEdit($service)->set('data.sort_order', 42))->assertHasNoFormErrors();

        $this->assertSame([], $this->stockRows($service));
    }

    // ---------------------------------------------------------------------------------------------
    // E7 - visibility matrix
    // ---------------------------------------------------------------------------------------------

    public function test_e7_visibility_of_every_field_on_a_rental(): void
    {
        $page = $this->openEdit($this->rentalService());

        foreach ([...self::FIELDS_FOR_BOTH_TYPES, ...self::RENTAL_ONLY_FIELDS] as $field) {
            $page->assertFormFieldIsVisible($field);
        }
        foreach (self::TIME_SLOT_ONLY_FIELDS as $field) {
            $page->assertFormFieldIsHidden($field);
        }
    }

    public function test_e7_visibility_of_every_field_on_a_time_slot(): void
    {
        $page = $this->openEdit($this->timeSlotService());

        foreach ([...self::FIELDS_FOR_BOTH_TYPES, ...self::TIME_SLOT_ONLY_FIELDS] as $field) {
            $page->assertFormFieldIsVisible($field);
        }
        foreach (self::RENTAL_ONLY_FIELDS as $field) {
            $page->assertFormFieldIsHidden($field);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // E8 - Builder blocks
    // ---------------------------------------------------------------------------------------------

    /**
     * One entry per block variant, minimal valid data - including the falsy values (`false`, `0`) a loose
     * comparison would not notice losing. `custom_html` is only offered to a super-admin.
     *
     * @return array<int, array{label: string, type: string, data: array<string, mixed>}>
     */
    private function blockCases(int $locationId): array
    {
        $cta = [['text' => 'Zadzwoń', 'url' => 'https://example.com/kontakt', 'style' => 'primary']];
        $features = [['icon' => 'star', 'title' => 'Tytuł', 'description' => 'Opis']];

        return [
            ['label' => 'text_block', 'type' => 'text_block', 'data' => ['content' => '<p>Tekst</p>']],
            ['label' => 'image', 'type' => 'image', 'data' => ['image' => 'services/images/x.jpg', 'alt' => 'Opis zdjęcia']],
            ['label' => 'gallery', 'type' => 'gallery', 'data' => ['images' => ['services/galleries/a.jpg', 'services/galleries/b.jpg']]],
            ['label' => 'video', 'type' => 'video', 'data' => ['url' => 'https://www.youtube.com/watch?v=abc123']],
            ['label' => 'quote', 'type' => 'quote', 'data' => ['quote' => 'Cytat', 'author' => 'Autor']],
            ['label' => 'cta', 'type' => 'cta', 'data' => ['heading' => 'Zadzwoń', 'button_url' => 'https://example.com']],
            ['label' => 'two_columns', 'type' => 'two_columns', 'data' => ['left_column' => '<p>Lewa</p>', 'right_column' => '<p>Prawa</p>']],
            ['label' => 'three_columns', 'type' => 'three_columns', 'data' => ['column_1' => '<p>Jeden</p>', 'column_2' => '<p>Dwa</p>', 'column_3' => '<p>Trzy</p>']],
            ['label' => 'hero (overlay_opacity 50)', 'type' => 'hero', 'data' => ['background_type' => 'gradient', 'title' => 'Tytuł', 'cta_buttons' => $cta, 'overlay_opacity' => 50]],
            ['label' => 'hero (overlay_opacity 0)', 'type' => 'hero', 'data' => ['background_type' => 'gradient', 'title' => 'Tytuł', 'cta_buttons' => $cta, 'overlay_opacity' => 0]],
            ['label' => 'content_grid (columns as string)', 'type' => 'content_grid', 'data' => ['content_type' => 'locations', 'content_items' => [$locationId], 'columns' => '3']],
            ['label' => 'content_grid (columns as int)', 'type' => 'content_grid', 'data' => ['content_type' => 'locations', 'content_items' => [$locationId], 'columns' => 3]],
            ['label' => 'feature_list', 'type' => 'feature_list', 'data' => ['features' => $features, 'layout' => 'grid']],
            ['label' => 'cta_banner (background_orbs true)', 'type' => 'cta_banner', 'data' => ['heading' => 'Nagłówek', 'cta_buttons' => $cta, 'background_orbs' => true]],
            ['label' => 'cta_banner (background_orbs false)', 'type' => 'cta_banner', 'data' => ['heading' => 'Nagłówek', 'cta_buttons' => $cta, 'background_orbs' => false]],
            ['label' => 'custom_html (container_wrapper true)', 'type' => 'custom_html', 'data' => ['html' => '<b>surowy html</b>', 'container_wrapper' => true]],
            ['label' => 'custom_html (container_wrapper false)', 'type' => 'custom_html', 'data' => ['html' => '<b>surowy html</b>', 'container_wrapper' => false]],
            ['label' => 'service_features', 'type' => 'service_features', 'data' => ['heading' => 'Co zawiera usługa', 'layout' => 'grid']],
        ];
    }

    public function test_e8_the_form_offers_exactly_the_blocks_this_suite_covers(): void
    {
        $offered = [];
        $this->actAsSuperAdmin(); // custom_html is only offered to a super-admin
        $this->openEdit($this->rentalService())->assertFormFieldExists('content', function (Builder $field) use (&$offered): bool {
            $offered = array_map(fn ($block): string => $block->getName(), array_values($field->getBlocks()));

            return true;
        });

        $registered = [...array_map(fn ($block): string => $block->getName(), BuilderBlocks::allBlocks('services')), 'service_features'];
        $covered = array_values(array_unique(array_column($this->blockCases($this->primary->id), 'type')));

        $this->assertEqualsCanonicalizing($registered, $offered);
        $this->assertEqualsCanonicalizing($covered, $offered, 'a block was added or removed - give it a round-trip case in blockCases()');
    }

    public function test_e8_every_block_variant_round_trips_in_order_with_its_values_intact(): void
    {
        $cases = $this->blockCases($this->primary->id);
        $service = $this->rentalService(['content' => array_map(fn (array $case): array => ['type' => $case['type'], 'data' => $case['data']], $cases)]);

        // custom_html is offered to super-admins only - but a block already stored must survive an admin's save
        // (see test_e8_a_custom_html_block_survives_...). Here everything is saved by a super-admin.
        $this->actAsSuperAdmin();
        $this->saveUnchanged($service);

        $after = $this->reload($service)->content;
        $this->assertSame(array_column($cases, 'type'), array_column($after, 'type'), 'block order changed');
        // Documented normalisation: a Select with numeric option keys returns an int (content_grid `columns`).
        $this->assertBlocksKeepTheirData($cases, $after, ['columns']);

        $snapshot = $this->snapshot($service);
        $this->saveUnchanged($service);

        $this->assertSame($snapshot, $this->snapshot($service), 'a second save changed the blocks again');
    }

    public function test_e8_a_custom_html_block_survives_a_save_by_an_admin_who_cannot_even_see_that_block_type(): void
    {
        $block = ['type' => 'custom_html', 'data' => ['html' => '<b>surowy html</b>']];
        $service = $this->rentalService(['content' => [$block]]);

        $this->saveUnchanged($service);

        $this->assertSame($this->ksortDeep([$block]), $this->ksortDeep($this->reload($service)->content));
    }

    public function test_e8_unbound_keys_inside_a_block_and_unregistered_block_types_survive_a_save(): void
    {
        $content = [
            ['type' => 'text_block', 'data' => ['content' => '<p>x</p>', 'unbound_key' => 'keep me']],
            ['type' => 'a_block_type_nobody_registered', 'data' => ['foo' => 'bar', 'nested' => ['a' => 1]]],
        ];
        $service = $this->rentalService(['content' => $content]);

        $this->saveUnchanged($service);

        $after = $this->reload($service)->content;
        $this->assertSame('keep me', $after[0]['data']['unbound_key']);
        $this->assertSame($this->ksortDeep($content[1]), $this->ksortDeep($after[1]), 'an unregistered block is carried through verbatim');
    }

    public function test_e8_a_blank_string_inside_a_block_comes_back_as_null(): void
    {
        // Not a defect worth a gap: Filament coerces every blank, non-array field state to null on read.
        $service = $this->rentalService(['content' => [['type' => 'image', 'data' => ['image' => 'services/images/x.jpg', 'alt' => '']]]]);

        $this->saveUnchanged($service);

        $this->assertNull($this->reload($service)->content[0]['data']['alt']);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: bool}> [block, stored data, the keys the first save ADDS with their values, needs super-admin]
     */
    public static function blocksGainingDefaultKeys(): array
    {
        $styling = ['background_type' => null, 'container_max_width' => null, 'vertical_padding' => null, 'css_id' => null, 'css_classes' => null];
        $cta = [['text' => 'Zadzwoń', 'url' => 'https://example.com', 'style' => 'primary']];

        return [
            'text_block' => ['text_block', ['content' => '<p>x</p>'], ['full_width' => false] + $styling, false],
            'image' => ['image', ['image' => 'services/images/x.jpg'], ['full_width' => false, 'alt' => null, 'caption' => null, 'size' => null] + $styling, false],
            'cta (button_text and style defaults are NOT applied)' => ['cta', ['heading' => 'H'], ['full_width' => false, 'description' => null, 'button_text' => null, 'button_url' => null, 'style' => null] + $styling, false],
            'hero (overlay_opacity default 50 is NOT applied)' => ['hero', ['background_type' => 'gradient', 'title' => 'T', 'cta_buttons' => $cta], ['overlay_opacity' => 0, 'full_width' => false, 'subtitle' => null, 'container_max_width' => null, 'vertical_padding' => null, 'css_id' => null, 'css_classes' => null], false],
            'cta_banner (background_orbs default true is NOT applied)' => ['cta_banner', ['heading' => 'H', 'cta_buttons' => $cta], ['background_orbs' => false, 'full_width' => false, 'subheading' => null, 'cta_container_bg_type' => null] + $styling, false],
            'custom_html (container_wrapper default true is NOT applied)' => ['custom_html', ['html' => '<b>x</b>'], ['container_wrapper' => false, 'full_width' => false] + $styling, true],
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $added
     */
    #[DataProvider('blocksGainingDefaultKeys')]
    public function test_e1_known_gap_builder_blocks_gain_default_keys_and_lose_their_form_defaults_on_the_first_save(string $type, array $stored, array $added, bool $needsSuperAdmin): void
    {
        // KNOWN GAP (stage 1 will change this): a block stored by a seeder / importer / API without its styling keys
        // is rewritten on the first form save with null / false for every key the editor binds, and the block's own
        // defaults (hero overlay 50, cta_banner orbs true, cta button text / style, custom_html wrapper true) are
        // NOT applied - they only apply to a block added in the editor.
        $service = $this->rentalService(['content' => [['type' => $type, 'data' => $stored]]]);

        if ($needsSuperAdmin) {
            $this->actAsSuperAdmin();
        }
        $this->saveUnchanged($service);

        $data = $this->reload($service)->content[0]['data'];
        $this->assertBlocksKeepTheirData([['type' => $type, 'data' => $stored]], $this->reload($service)->content);
        $this->assertSame($this->ksortDeep($added), $this->ksortDeep(array_diff_key($data, $stored)), "the keys the first save adds to `{$type}`");
    }

    public function test_e8_known_gap_an_image_block_whose_file_is_missing_on_disk_makes_the_whole_page_unsaveable(): void
    {
        // KNOWN GAP (stage 1 will change this): `image` is required and FileUpload drops a path with no file behind it,
        // so the block fails validation on every save and none of the page's other edits persist.
        $service = $this->rentalService(['content' => [['type' => 'image', 'data' => ['image' => 'services/images/gone.jpg']]]]);

        $component = $this->saveForm($this->openEdit($service)->set('data.sort_order', 88));

        $this->assertTrue($this->hasErrorOn($component, 'data.content'));
        $this->assertSame(5, $this->reload($service)->sort_order);
    }

    public function test_e8_known_gap_a_gallery_image_missing_on_disk_is_silently_dropped_on_save(): void
    {
        // KNOWN GAP (stage 1 will change this): unlike the single image block, a gallery keeps the files that
        // exist and drops the rest without a word.
        $service = $this->rentalService(['content' => [['type' => 'gallery', 'data' => ['images' => ['services/galleries/a.jpg', 'services/galleries/gone.jpg', 'services/galleries/b.jpg']]]]]);

        $this->saveUnchanged($service);

        $this->assertSame(['services/galleries/a.jpg', 'services/galleries/b.jpg'], $this->reload($service)->content[0]['data']['images']);
    }

    // ---------------------------------------------------------------------------------------------
    // E9 / E10 - features and body
    // ---------------------------------------------------------------------------------------------

    public function test_e9_four_features_are_kept_in_order(): void
    {
        $service = $this->rentalService();
        $features = ['Pierwsza', 'Druga', 'Trzecia', 'Czwarta'];

        $this->saveForm($this->openEdit($service)->set('data.features', array_map(fn (string $text): array => ['feature' => $text], $features)))
            ->assertHasNoFormErrors();

        $this->assertSame($features, $this->reload($service)->features);
    }

    public function test_e9_five_features_are_rejected_and_nothing_is_saved(): void
    {
        $service = $this->rentalService(['features' => ['Stara']]);

        $component = $this->saveForm(
            $this->openEdit($service)
                ->set('data.features', array_map(fn (string $text): array => ['feature' => $text], ['a', 'b', 'c', 'd', 'e']))
                ->set('data.sort_order', 42)
        );

        $this->assertTrue($this->hasErrorOn($component, 'data.features'));
        $this->assertSame(['Stara'], $this->reload($service)->features);
        $this->assertSame(5, $this->reload($service)->sort_order, 'a failed validation saves none of the other fields either');
    }

    public function test_e9_null_features_stay_null_and_emptying_the_list_collapses_to_null(): void
    {
        $service = $this->rentalService(['features' => null]);
        $this->saveUnchanged($service);
        $this->assertNull($this->reload($service)->features);

        $other = $this->rentalService(['slug' => 'inna-wiertarka', 'name' => 'Inna wiertarka', 'features' => ['Jedna']]);
        $this->saveForm($this->openEdit($other)->set('data.features', []))->assertHasNoFormErrors();
        $this->assertNull($this->reload($other)->features);
    }

    public function test_e10_an_empty_editor_state_is_stored_as_null_not_as_an_empty_paragraph(): void
    {
        $service = $this->rentalService(['body' => '<p>Stary opis</p>']);

        $this->saveForm($this->openEdit($service)->set('data.body', '<p></p>'))->assertHasNoFormErrors();

        $this->assertNull($this->reload($service)->body);
    }

    public function test_e10_an_image_only_paragraph_is_real_content_and_survives(): void
    {
        $service = $this->rentalService(['body' => null]);
        $html = '<p><img src="https://example.com/a.png"></p>';

        $this->saveForm($this->openEdit($service)->set('data.body', $html))->assertHasNoFormErrors();

        $this->assertSame($html, $this->reload($service)->body);
    }

    // ---------------------------------------------------------------------------------------------
    // E12 - relation managers
    // ---------------------------------------------------------------------------------------------

    public function test_e12_a_rental_edit_page_shows_the_stock_and_unit_tabs(): void
    {
        $this->openEdit($this->rentalService())
            ->assertSee('Stany magazynowe')
            ->assertSee('Egzemplarze')
            ->assertSeeLivewire(LocationStocksRelationManager::class);
    }

    public function test_e12_a_time_slot_edit_page_shows_neither(): void
    {
        $this->openEdit($this->timeSlotService())
            ->assertDontSee('Stany magazynowe')
            ->assertDontSee('Egzemplarze')
            ->assertDontSeeLivewire(LocationStocksRelationManager::class);
    }
}
