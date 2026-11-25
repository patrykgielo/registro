<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ServiceResource\Pages\EditService;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\User;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * STAGE 5a of the product form redesign: the per-tab save ENGINE on the edit page (`SavesFormPerTab::saveTab()`),
 * the three data-loss fixes it needs, no UI. The owner's requirement: saving one tab leaves every column / JSON key
 * owned by another tab byte-identical in the DB, while the unsaved edits of the other tabs stay in the form.
 *
 * Tab keys are the relative keys Filament builds (`<slug of the label>::data::tab`, the value of `?tab=`).
 */
class ServiceFormPerTabSaveTest extends TestCase
{
    use RefreshDatabase, ServiceFormTestSupport;

    private const RENTAL_TABS = ['podstawowe', 'cena-i-magazyn', 'specyfikacja', 'tresc', 'seo-i-konwersja'];

    private const SLOT_TABS = ['podstawowe', 'cena-i-czas-trwania', 'tresc', 'seo-i-konwersja'];

    private const PODSTAWOWE_COLUMNS = ['name', 'slug', 'icon', 'is_active', 'sort_order', 'featured_image', 'hero_overlay_color', 'hero_overlay_opacity'];

    private const SEO_COLUMNS = ['meta_title', 'meta_description', 'area_served', 'published_at', 'average_rating', 'total_reviews', 'is_popular', 'booking_count_week'];

    /** Columns each tab persists, per type (`price` of a rental is derived from `price_per_day` by the model). */
    private const OWNED = [
        'rental' => [
            'podstawowe' => self::PODSTAWOWE_COLUMNS,
            'cena-i-magazyn' => ['price_per_day', 'price_per_hour', 'price_per_week', 'price_per_day_long', 'price_threshold_days', 'deposit_amount', 'price_on_request', 'quantity_total', 'rental_category_id', 'brand', 'price'],
            'specyfikacja' => ['metadata', 'features'],
            'tresc' => ['excerpt', 'body', 'content'],
            'seo-i-konwersja' => self::SEO_COLUMNS,
        ],
        'time_slot' => [
            'podstawowe' => self::PODSTAWOWE_COLUMNS,
            'cena-i-czas-trwania' => ['price', 'price_from', 'duration_minutes'],
            'tresc' => ['excerpt', 'body', 'content', 'features'],
            'seo-i-konwersja' => self::SEO_COLUMNS,
        ],
    ];

    /** Form state keys a tab owns that are not columns (display-only inputs). */
    private const EXTRA_STATE_KEYS = ['cena-i-czas-trwania' => ['duration_hours', 'duration_mins', 'duration_days']];

    private Location $primary;

    /** The category the edits move a rental to (created once: category slugs are unique per tenant). */
    private ?RentalCategory $otherCategory = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpServiceFormTenant();
        $this->primary = $this->addLocation();
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures and helpers
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function tabCases(): array
    {
        $cases = [];

        foreach (['rental' => self::RENTAL_TABS, 'time_slot' => self::SLOT_TABS] as $type => $tabs) {
            foreach ($tabs as $tab) {
                $cases["{$type} / {$tab}"] = [$type, $tab];
            }
        }

        return $cases;
    }

    /** @return array<string, array{0: string}> */
    public static function types(): array
    {
        return ['rental' => ['rental'], 'time_slot' => ['time_slot']];
    }

    private function key(string $tab): string
    {
        return $tab.'::data::tab';
    }

    /**
     * A service in the shape the first full save leaves it in (Builder blocks gain their default keys on the first
     * save), with a non-default value in every column no form field binds. Every value differs from `editSets()`.
     */
    private function baseline(string $type, string $suffix = 'a'): Service
    {
        $common = [
            'name' => "Produkt {$suffix}",
            'slug' => "produkt-{$suffix}",
            'icon' => 'sparkles',
            'excerpt' => 'Opis początkowy',
            'is_active' => true,
            'sort_order' => 3,
            'featured_image' => 'services/featured/a.jpg',
            'hero_overlay_color' => '#000000',
            'hero_overlay_opacity' => 85,
            'body' => '<p>Początkowy opis</p>',
            'content' => [
                ['type' => 'cta', 'data' => ['heading' => 'Zadzwoń', 'button_url' => 'https://example.com']],
                ['type' => 'quote', 'data' => ['quote' => 'Cytat', 'author' => 'Autor']],
            ],
            'meta_title' => 'Meta początkowy',
            'meta_description' => 'Opis meta początkowy',
            'area_served' => 'Poznań',
            'published_at' => '2026-01-02 03:04:05',
            'average_rating' => 4.5,
            'total_reviews' => 12,
            'is_popular' => false,
            'booking_count_week' => 3,
            'features' => ['Cecha 1'],
        ];

        if ($type === 'rental') {
            $service = $this->rentalService($common + [
                'price' => 100,
                'price_per_day' => 100,
                'price_per_hour' => 15.5,
                'price_per_week' => 450,
                'price_per_day_long' => 80,
                'price_threshold_days' => 5,
                'deposit_amount' => 300,
                'price_on_request' => false,
                'quantity_total' => 4,
                'brand' => 'Bosch',
                'rental_category_id' => $this->category()->id,
                'metadata' => [
                    'specs' => [['label' => 'Moc', 'value' => '800', 'unit' => 'W']],
                    'image_sources' => [['file' => 'services/featured/a.jpg', 'credit' => 'Jan Kowalski']],
                    'extra_key' => 'keep me',
                ],
            ]);
            $this->setStock($service, $this->primary, 4);
        } else {
            $service = $this->timeSlotService($common + [
                'duration_minutes' => 60,
                'price' => 200,
                'price_from' => 150,
                'metadata' => ['prices_by_size' => ['s' => 100, 'm' => 150], 'available_for_mobile' => true],
            ]);
        }

        $this->fillUnboundColumns($service);
        $this->saveUnchanged($service);

        return $service;
    }

    /**
     * One non-default edit per field of every tab, grouped by tab. `{first}` stands for the key of the first Builder block.
     *
     * @return array<string, array<string, mixed>>
     */
    private function editSets(string $type, string $suffix = 'a'): array
    {
        $doc = fn (string $text): array => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];

        $sets = [
            'podstawowe' => [
                'name' => "Zmieniona nazwa {$suffix}", // unique per organization at the DB level
                'slug' => "zmieniony-slug-{$suffix}",
                'icon' => 'star',
                'is_active' => false,
                'sort_order' => 9,
                'featured_image' => ['f-1' => 'services/featured/b.jpg'],
                'hero_overlay_color' => '#112233',
                'hero_overlay_opacity' => '70',
            ],
            'tresc' => [
                'excerpt' => 'Nowy krótki opis',
                'body' => $doc('Nowy opis'),
                'content.{first}.data.heading' => 'Nowy nagłówek',
            ],
            'seo-i-konwersja' => [
                'meta_title' => 'Nowy meta tytuł',
                'meta_description' => 'Nowy meta opis',
                'area_served' => 'Kraków',
                'published_at' => '2026-03-04 10:20:00',
                'average_rating' => 3.5,
                'total_reviews' => 99,
                'is_popular' => true,
                'booking_count_week' => 8,
            ],
        ];

        if ($type === 'rental') {
            $sets['cena-i-magazyn'] = [
                'price_per_day' => 150,
                'price_per_hour' => 20,
                'price_per_week' => 500,
                'price_per_day_long' => 90,
                'price_threshold_days' => 6,
                'deposit_amount' => 350,
                'price_on_request' => true,
                'quantity_total' => 9,
                'rental_category_id' => ($this->otherCategory ??= $this->category('Sprzęt ogrodniczy'))->id,
                'brand' => 'Makita',
            ];
            $sets['specyfikacja'] = [
                'metadata.specs' => [['label' => 'Waga', 'value' => '5', 'unit' => 'kg']],
                'features' => [['feature' => 'Nowa cecha']],
            ];
        } else {
            $sets['cena-i-czas-trwania'] = ['price' => 321, 'price_from' => 250.5, 'duration_hours' => 2, 'duration_mins' => 15];
            $sets['tresc']['features'] = [['feature' => 'Nowa cecha']];
        }

        return $sets;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sets
     */
    private function applyEdits(Testable $component, array $sets): Testable
    {
        $first = array_key_first($component->get('data.content') ?? []);

        foreach ($sets as $fields) {
            foreach ($fields as $path => $value) {
                $component->set('data.'.str_replace('{first}', (string) $first, $path), $value);
            }
        }

        return $component;
    }

    /** The row exactly as stored (no casts), minus `updated_at` - the byte-for-byte view. @return array<string, mixed> */
    private function rawRow(Service|int $service): array
    {
        $row = (array) DB::table('services')->where('id', $service instanceof Service ? $service->id : $service)->first();
        unset($row['updated_at']);

        return $row;
    }

    /** @return array<int, string> state keys a tab owns */
    private function ownedStateKeys(string $type, string $tab): array
    {
        return [...self::OWNED[$type][$tab], ...(self::EXTRA_STATE_KEYS[$tab] ?? [])];
    }

    /** JSON columns compared by content (MySQL reorders object keys), everything else as stored. */
    private function decoded(mixed $raw): mixed
    {
        $json = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($json) ? $this->ksortDeep($json) : $raw;
    }

    // ---------------------------------------------------------------------------------------------
    // The core guarantee
    // ---------------------------------------------------------------------------------------------

    #[DataProvider('tabCases')]
    public function test_saving_a_tab_writes_exactly_its_own_columns_and_keeps_the_other_tabs_edits_in_the_form_only(string $type, string $tab): void
    {
        $service = $this->baseline($type);
        $sets = $this->editSets($type);
        $component = $this->applyEdits($this->openEdit($service), $sets);

        $stateBefore = $component->get('data');
        $rawBefore = $this->rawRow($service);

        $component->call('saveTab', $this->key($tab))->assertHasNoFormErrors();

        $rawAfter = $this->rawRow($service);
        $owned = self::OWNED[$type][$tab];

        foreach ($owned as $column) {
            $this->assertNotSame($rawBefore[$column], $rawAfter[$column], "saving `{$tab}` did not persist its column `{$column}`");
        }

        $this->assertSame(
            array_diff_key($rawBefore, array_flip($owned)),
            array_diff_key($rawAfter, array_flip($owned)),
            "saving `{$tab}` changed a column that belongs to another tab (raw, byte for byte)",
        );

        // The other tabs' edits are still in the form (and, by the assertion above, not in the DB).
        $stateAfter = $component->get('data');
        $ownedKeys = $this->ownedStateKeys($type, $tab);
        $this->assertSame(
            json_encode(array_diff_key($stateBefore, array_flip($ownedKeys))),
            json_encode(array_diff_key($stateAfter, array_flip($ownedKeys))),
            "saving `{$tab}` rewrote the form state of another tab",
        );

        foreach ($sets as $otherTab => $fields) {
            if ($otherTab === $tab) {
                continue;
            }

            $path = array_key_first($fields);
            if (! str_contains($path, '{first}') && is_scalar($fields[$path])) {
                $this->assertEquals($fields[$path], $component->get('data.'.$path), "the unsaved edit of `{$otherTab}` ({$path}) is gone from the form");
            }
        }
    }

    #[DataProvider('tabCases')]
    public function test_a_tab_save_without_edits_leaves_the_row_byte_identical(string $type, string $tab): void
    {
        $service = $this->baseline($type);
        $stocks = $this->stockRows($service);
        $before = $this->rawRow($service);

        $this->openEdit($service)->call('saveTab', $this->key($tab))->assertHasNoFormErrors();

        $this->assertSame($before, $this->rawRow($service));
        $this->assertSame($stocks, $this->stockRows($service));
    }

    #[DataProvider('types')]
    public function test_saving_every_tab_one_by_one_equals_one_full_save(string $type): void
    {
        $tabs = $type === 'rental' ? self::RENTAL_TABS : self::SLOT_TABS;
        $one = $this->baseline($type, 'a');
        $twin = $this->baseline($type, 'b');

        $byTab = $this->applyEdits($this->openEdit($one), $this->editSets($type, 'x'));
        foreach ($tabs as $tab) {
            $byTab->call('saveTab', $this->key($tab))->assertHasNoFormErrors();
        }

        $this->saveForm($this->applyEdits($this->openEdit($twin), $this->editSets($type, 'y')))->assertHasNoFormErrors();

        $ignore = ['id', 'slug', 'name', 'created_at'];
        $this->assertSame(
            $this->decodedRow($this->rawRow($twin), $ignore),
            $this->decodedRow($this->rawRow($one), $ignore),
            'tab-by-tab saving must end in the same row as one full save',
        );
        $this->assertSame($this->stockRows($twin), $this->stockRows($one));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $ignore
     * @return array<string, mixed>
     */
    private function decodedRow(array $row, array $ignore): array
    {
        $row = array_diff_key($row, array_flip($ignore));

        return array_map(fn (mixed $value): mixed => $this->decoded($value), $row);
    }

    // ---------------------------------------------------------------------------------------------
    // Metadata (stage-1 merge) through a tab save
    // ---------------------------------------------------------------------------------------------

    public function test_the_specs_tab_keeps_every_other_metadata_key(): void
    {
        $service = $this->baseline('rental');
        $component = $this->openEdit($service)->set('data.metadata.specs', [['label' => 'Waga', 'value' => '5', 'unit' => 'kg']]);

        $component->call('saveTab', $this->key('specyfikacja'))->assertHasNoFormErrors();

        $this->assertSame(
            $this->ksortDeep([
                'specs' => [['label' => 'Waga', 'value' => '5', 'unit' => 'kg']],
                'image_sources' => [['file' => 'services/featured/a.jpg', 'credit' => 'Jan Kowalski']],
                'extra_key' => 'keep me',
            ]),
            $this->ksortDeep($this->reload($service)->metadata),
        );
    }

    public function test_clearing_the_specs_through_the_tab_still_clears_them_and_keeps_the_rest(): void
    {
        $service = $this->baseline('rental');

        $this->openEdit($service)->set('data.metadata.specs', [])->call('saveTab', $this->key('specyfikacja'))->assertHasNoFormErrors();

        $metadata = $this->reload($service)->metadata;
        $this->assertSame([], $metadata['specs']);
        $this->assertSame('keep me', $metadata['extra_key']);
        $this->assertArrayHasKey('image_sources', $metadata);
    }

    public function test_a_tab_that_does_not_send_metadata_never_touches_it_not_even_a_null(): void
    {
        $service = $this->baseline('rental');
        DB::table('services')->where('id', $service->id)->update(['metadata' => null]);

        $this->openEdit($service)->set('data.excerpt', 'Inny opis')->call('saveTab', $this->key('tresc'))->assertHasNoFormErrors();

        $this->assertNull(DB::table('services')->where('id', $service->id)->value('metadata'));
        $this->assertSame('Inny opis', $this->reload($service)->excerpt);
    }

    // ---------------------------------------------------------------------------------------------
    // Fix 1: duration_minutes belongs to "Cena i czas trwania"
    // ---------------------------------------------------------------------------------------------

    public function test_the_duration_is_saved_with_cena_i_czas_trwania_and_not_with_podstawowe(): void
    {
        $service = $this->baseline('time_slot');
        $component = $this->openEdit($service)
            ->set('data.duration_hours', 2)
            ->set('data.duration_mins', 30)
            ->set('data.name', 'Inna nazwa');
        $component->assertSet('data.duration_minutes', 150);

        $component->call('saveTab', $this->key('podstawowe'))->assertHasNoFormErrors();

        $this->assertSame('Inna nazwa', $this->reload($service)->name);
        $this->assertSame(60, $this->reload($service)->duration_minutes, 'a duration edited in another tab must not be persisted by Podstawowe');

        $component->call('saveTab', $this->key('cena-i-czas-trwania'))->assertHasNoFormErrors();

        $this->assertSame(150, $this->reload($service)->duration_minutes);
        $component->assertSet('data.duration_minutes', 150);
    }

    public function test_cena_i_czas_trwania_without_a_duration_edit_keeps_the_stored_duration(): void
    {
        $service = $this->baseline('time_slot');

        $this->openEdit($service)->set('data.price', 777)->call('saveTab', $this->key('cena-i-czas-trwania'))->assertHasNoFormErrors();

        $this->assertSame(60, $this->reload($service)->duration_minutes);
        $this->assertSame('777.00', (string) $this->reload($service)->price);
    }

    public function test_the_full_save_still_writes_the_duration_edited_in_the_other_tab(): void
    {
        $service = $this->baseline('time_slot');

        $this->saveForm($this->openEdit($service)->set('data.duration_hours', 2)->set('data.duration_mins', 15))->assertHasNoFormErrors();

        $this->assertSame(135, $this->reload($service)->duration_minutes, 'the ownership override must not apply to the full save()');
    }

    // ---------------------------------------------------------------------------------------------
    // Fix 2: stock routing only when quantity_total was part of the saved data
    // ---------------------------------------------------------------------------------------------

    public function test_a_tab_without_the_quantity_field_leaves_the_stock_rows_alone(): void
    {
        $service = $this->baseline('rental');
        $inactive = $this->setStock($service, $this->primary, 4);
        $inactive->forceFill(['is_active' => false])->save();
        $before = $this->stockRows($service);
        $this->assertFalse($before[0]['is_active']);

        foreach (['podstawowe', 'specyfikacja', 'tresc', 'seo-i-konwersja'] as $tab) {
            $this->openEdit($service)->set('data.sort_order', 11)->call('saveTab', $this->key($tab))->assertHasNoFormErrors();
        }

        $this->assertSame($before, $this->stockRows($service), 'only the tab that sends quantity_total may route it into stock (the routing re-activates the row)');
    }

    public function test_a_tab_without_the_quantity_field_does_not_create_the_missing_primary_stock_row(): void
    {
        $service = $this->baseline('rental');
        ServiceLocationStock::withoutGlobalScope('organization')->where('service_id', $service->id)->delete();

        $this->openEdit($service)->set('data.excerpt', 'Inny')->call('saveTab', $this->key('tresc'))->assertHasNoFormErrors();

        $this->assertSame([], $this->stockRows($service));
    }

    public function test_an_inline_stock_edit_made_while_the_form_was_open_survives_a_tab_save_that_is_not_about_stock(): void
    {
        $service = $this->baseline('rental');
        $component = $this->openEdit($service)->set('data.excerpt', 'Inny');

        // The mirror is deliberately NOT recalculated: the stock row is the only carrier of the edit, so a routing
        // of `quantity_total` (4) into it would visibly revert it to 4.
        ServiceLocationStock::withoutGlobalScope('organization')->where('service_id', $service->id)->update(['quantity' => 9]);

        $component->call('saveTab', $this->key('tresc'))->assertHasNoFormErrors();

        $this->assertSame(9, $this->stockRows($service)[0]['quantity']);
        $this->assertSame(4, $this->reload($service)->quantity_total);
    }

    public function test_the_tab_that_does_send_the_quantity_still_routes_it_into_stock(): void
    {
        $service = $this->baseline('rental');

        $this->openEdit($service)->set('data.quantity_total', 11)->call('saveTab', $this->key('cena-i-magazyn'))->assertHasNoFormErrors();

        $this->assertSame([['location_id' => $this->primary->id, 'quantity' => 11, 'is_active' => true]], $this->stockRows($service));
        $this->assertSame(11, $this->reload($service)->quantity_total);
    }

    // ---------------------------------------------------------------------------------------------
    // Derived price, category, uploads, blocks
    // ---------------------------------------------------------------------------------------------

    public function test_a_rental_price_follows_the_stored_daily_rate_until_cena_i_magazyn_is_saved(): void
    {
        $service = $this->baseline('rental');
        $component = $this->openEdit($service)->set('data.price_per_day', 150)->set('data.name', 'Inna nazwa');

        $component->call('saveTab', $this->key('podstawowe'))->assertHasNoFormErrors();

        $row = $this->reload($service);
        $this->assertSame('100.00', (string) $row->price_per_day);
        $this->assertSame('100.00', (string) $row->price, 'price tracks the STORED price_per_day, not the unsaved edit');

        $component->call('saveTab', $this->key('cena-i-magazyn'))->assertHasNoFormErrors();

        $row = $this->reload($service);
        $this->assertSame('150.00', (string) $row->price_per_day);
        $this->assertSame('150.00', (string) $row->price);
    }

    public function test_changing_and_clearing_the_category_persist_through_the_tab(): void
    {
        $service = $this->baseline('rental');
        $other = $this->category('Sprzęt ogrodniczy');

        $component = $this->openEdit($service)->set('data.rental_category_id', $other->id);
        $component->call('saveTab', $this->key('cena-i-magazyn'))->assertHasNoFormErrors();
        $this->assertSame($other->id, $this->reload($service)->rental_category_id);

        $component = $this->openEdit($service)->set('data.rental_category_id', null);
        $component->call('saveTab', $this->key('cena-i-magazyn'))->assertHasNoFormErrors();
        $this->assertNull($this->reload($service)->rental_category_id);
    }

    public function test_after_a_cross_tenant_category_failure_the_next_tab_save_does_not_write_the_category(): void
    {
        $service = $this->baseline('rental');
        $original = $this->reload($service)->rental_category_id;
        $foreign = RentalCategory::factory()->for(Organization::factory()->equipmentRental()->create(), 'organization')->create(['name' => 'Obca', 'slug' => 'obca']);

        $component = $this->openEdit($service)
            ->set('data.rental_category_id', $foreign->id)
            ->set('data.brand', 'Nie zapisana marka')
            ->set('data.name', 'Nazwa z drugiej zakładki');

        $component->call('saveTab', $this->key('cena-i-magazyn'));
        $this->assertTrue($this->hasErrorOn($component, 'data.rental_category_id'));
        $this->assertSame($original, $this->reload($service)->rental_category_id);
        $this->assertSame('Bosch', $this->reload($service)->brand, 'a rejected tab persists none of its fields');

        $component->call('saveTab', $this->key('podstawowe'))->assertHasNoFormErrors();

        $row = $this->reload($service);
        $this->assertSame('Nazwa z drugiej zakładki', $row->name);
        $this->assertSame($original, $row->rental_category_id, 'the rejected category must not ride along with another tab');
        $this->assertSame('Bosch', $row->brand);
    }

    public function test_an_upload_is_not_stored_until_its_own_tab_is_saved(): void
    {
        $service = $this->baseline('rental');
        DB::table('services')->where('id', $service->id)->update(['featured_image' => null]); // a first upload (replacing keeps the old path under set())
        $filesBefore = Storage::disk('public')->allFiles('services/featured');
        $component = $this->openEdit($service)
            ->set('data.featured_image', UploadedFile::fake()->image('hero.jpg', 1920, 1080))
            ->set('data.excerpt', 'Inny');

        $component->call('saveTab', $this->key('tresc'))->assertHasNoFormErrors();

        $this->assertNull($this->reload($service)->featured_image);
        $this->assertSame($filesBefore, Storage::disk('public')->allFiles('services/featured'), 'the upload must stay in temporary storage');

        $component->call('saveTab', $this->key('podstawowe'))->assertHasNoFormErrors();

        $stored = (string) $this->reload($service)->featured_image;
        $this->assertMatchesRegularExpression('#^services/featured/[^/]+\.jpg$#', $stored);
        Storage::disk('public')->assertExists($stored);
    }

    public function test_builder_blocks_round_trip_through_the_content_tab_like_a_full_save(): void
    {
        $cta = [['text' => 'Zadzwoń', 'url' => 'https://example.com/kontakt', 'style' => 'primary']];
        $blocks = [
            ['type' => 'text_block', 'data' => ['content' => '<p>Tekst</p>']],
            ['type' => 'image', 'data' => ['image' => 'services/images/x.jpg', 'alt' => 'Opis zdjęcia']],
            ['type' => 'gallery', 'data' => ['images' => ['services/galleries/a.jpg', 'services/galleries/b.jpg']]],
            ['type' => 'hero', 'data' => ['background_type' => 'gradient', 'title' => 'Tytuł', 'cta_buttons' => $cta, 'overlay_opacity' => 0]],
            ['type' => 'cta_banner', 'data' => ['heading' => 'Nagłówek', 'cta_buttons' => $cta, 'background_orbs' => false]],
            ['type' => 'service_features', 'data' => ['heading' => 'Co zawiera usługa', 'layout' => 'grid']],
            ['type' => 'text_block', 'data' => ['content' => '<p>x</p>', 'unbound_key' => 'keep me']],
            ['type' => 'a_block_type_nobody_registered', 'data' => ['foo' => 'bar', 'nested' => ['a' => 1]]],
        ];
        $viaTab = $this->rentalService(['name' => 'Blocks tab', 'slug' => 'blocks-tab', 'content' => $blocks]);
        $viaSave = $this->rentalService(['name' => 'Blocks save', 'slug' => 'blocks-save', 'content' => $blocks]);

        $this->openEdit($viaTab)->call('saveTab', $this->key('tresc'))->assertHasNoFormErrors();
        $this->saveUnchanged($viaSave);

        $after = $this->reload($viaTab)->content;
        $this->assertBlocksKeepTheirData($blocks, $after);
        $this->assertSame($this->ksortDeep($this->reload($viaSave)->content), $this->ksortDeep($after), 'a tab save and a full save must leave the same blocks');

        $stored = $this->rawRow($viaTab);
        $this->openEdit($viaTab)->call('saveTab', $this->key('tresc'))->assertHasNoFormErrors();
        $this->assertSame($stored, $this->rawRow($viaTab), 'the second save is a true no-op');
    }

    // ---------------------------------------------------------------------------------------------
    // Sequences, access, validation
    // ---------------------------------------------------------------------------------------------

    public function test_two_consecutive_tab_saves_on_one_page_both_persist(): void
    {
        $service = $this->baseline('rental');
        $component = $this->openEdit($service)->set('data.name', 'Pierwsza zmiana')->set('data.meta_title', 'Druga zmiana');

        $component->call('saveTab', $this->key('podstawowe'))->assertHasNoFormErrors();
        $component->call('saveTab', $this->key('seo-i-konwersja'))->assertHasNoFormErrors();

        $row = $this->reload($service);
        $this->assertSame('Pierwsza zmiana', $row->name);
        $this->assertSame('Druga zmiana', $row->meta_title);
    }

    public function test_a_tab_is_accepted_under_its_relative_absolute_and_short_key(): void
    {
        $service = $this->baseline('rental');

        foreach (['seo-i-konwersja::data::tab' => 'A', 'form.seo-i-konwersja::data::tab' => 'B', 'seo-i-konwersja' => 'C'] as $key => $title) {
            $this->openEdit($service)->set('data.meta_title', $title)->call('saveTab', $key)->assertHasNoFormErrors();
            $this->assertSame($title, $this->reload($service)->meta_title, "key `{$key}` was not accepted");
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedKeys(): array
    {
        return [
            'hidden for a time_slot: price tab of a rental' => ['time_slot', 'cena-i-magazyn::data::tab'],
            'hidden for a time_slot: specs' => ['time_slot', 'specyfikacja'],
            'hidden for a rental: duration tab' => ['rental', 'cena-i-czas-trwania::data::tab'],
            'unknown' => ['rental', 'nie-ma-takiej::data::tab'],
            'empty' => ['rental', ''],
            'a key of the form, not of a tab' => ['rental', 'formularz-produktu::data::tabs'],
        ];
    }

    #[DataProvider('refusedKeys')]
    public function test_a_hidden_or_unknown_tab_is_refused_with_403_and_nothing_is_written(string $type, string $key): void
    {
        $service = $this->baseline($type);
        $before = $this->rawRow($service);
        $stocks = $this->stockRows($service);

        $this->applyEdits($this->openEdit($service), $this->editSets($type))
            ->call('saveTab', $key)
            ->assertStatus(403);

        $this->assertSame($before, $this->rawRow($service));
        $this->assertSame($stocks, $this->stockRows($service));
    }

    public function test_the_tabs_inside_builder_blocks_are_not_tabs_of_the_form(): void
    {
        // Every block carries its own Treść / Wygląd / Zaawansowane tabs; their keys contain the (client-known) block uuid.
        $service = $this->baseline('rental');
        $component = $this->openEdit($service);
        $block = array_key_first($component->get('data.content'));
        $before = $this->rawRow($service);
        $component->set('data.excerpt', 'Nie zapisane');

        $component->call('saveTab', "tresc::data.content.{$block}.data::tab")->assertStatus(403);

        $this->assertSame($before, $this->rawRow($service));
        $this->assertSame(
            [$this->key('podstawowe'), $this->key('cena-i-magazyn'), $this->key('specyfikacja'), $this->key('tresc'), $this->key('seo-i-konwersja')],
            array_keys($this->openEdit($service)->instance()->getTabSaveMap()),
        );
    }

    public function test_only_the_save_tab_entry_point_is_reachable_from_the_browser(): void
    {
        $service = $this->baseline('rental');

        // One component per call: after an aborted request the test component has no valid snapshot left (a second call 404s).
        $this->openEdit($service)->call('saveFormComponentOnly', 'x')->assertStatus(403);
        $this->openEdit($service)->call('getTabSaveMap')->assertStatus(403);
        $this->openEdit($service)->call('saveTab', $this->key('tresc'))->assertStatus(200);
    }

    public function test_an_invalid_seo_field_does_not_block_saving_the_content_tab(): void
    {
        $service = $this->baseline('rental');
        $before = $this->rawRow($service);

        $component = $this->openEdit($service)
            ->set('data.meta_title', str_repeat('a', 61))
            ->set('data.excerpt', 'Poprawny opis');

        $component->call('saveTab', $this->key('tresc'))->assertHasNoFormErrors();

        $row = $this->rawRow($service);
        $this->assertSame('Poprawny opis', $row['excerpt']);
        $this->assertSame($before['meta_title'], $row['meta_title']);
    }

    public function test_a_tab_with_an_error_writes_nothing_and_reports_it_on_that_tab(): void
    {
        $service = $this->baseline('rental');
        $before = $this->rawRow($service);

        $component = $this->openEdit($service)
            ->set('data.meta_title', str_repeat('a', 61))
            ->set('data.meta_description', 'Poprawny meta opis')
            ->set('data.name', 'Inna nazwa');

        $component->call('saveTab', $this->key('seo-i-konwersja'));

        $this->assertTrue($this->hasErrorOn($component, 'data.meta_title'));
        $component->assertDispatched('form-validation-error');
        $component->assertNotDispatched('service-form-tab-saved');
        $component->assertNotNotified('Zapisano: SEO i konwersja');
        $this->assertSame($before, $this->rawRow($service), 'a valid field of the same tab must not be written either');
    }

    public function test_known_behaviour_a_successful_tab_save_clears_the_error_marks_of_the_other_tabs(): void
    {
        // Livewire's validate() resets the error bag for the whole page, so the red field of an unsaved tab turns
        // normal after another tab saved (the value is still invalid and still unsaved). Stage 5b/6 must not read
        // "no error mark" as "valid"; pinned so that changing it is a decision.
        $service = $this->baseline('rental');
        $component = $this->openEdit($service)->set('data.meta_title', str_repeat('a', 61));
        $component->call('saveTab', $this->key('seo-i-konwersja'));
        $this->assertTrue($this->hasErrorOn($component, 'data.meta_title'));

        $component->call('saveTab', $this->key('tresc'))->assertHasNoFormErrors();
    }

    // ---------------------------------------------------------------------------------------------
    // Feedback: notification, browser event, Halt
    // ---------------------------------------------------------------------------------------------

    public function test_a_successful_tab_save_notifies_and_announces_itself_to_the_browser(): void
    {
        $service = $this->baseline('rental');

        $this->openEdit($service)
            ->set('data.meta_title', 'Nowy')
            ->call('saveTab', $this->key('seo-i-konwersja'))
            ->assertNotified('Zapisano: SEO i konwersja')
            ->assertDispatched('service-form-tab-saved', function (string $event, array $params): bool {
                return $params['tab'] === 'seo-i-konwersja::data::tab'
                    && is_string($params['savedAt'])
                    && strtotime($params['savedAt']) !== false;
            });
    }

    public function test_the_notification_names_the_saved_tab(): void
    {
        $service = $this->baseline('time_slot');

        $this->openEdit($service)->call('saveTab', $this->key('cena-i-czas-trwania'))->assertNotified('Zapisano: Cena i czas trwania');
    }

    public function test_a_halted_tab_save_is_silent_and_writes_nothing(): void
    {
        $service = $this->baseline('rental');
        $before = $this->rawRow($service);

        Livewire::test(HaltingEditService::class, ['record' => $service->getRouteKey()])
            ->set('data.meta_title', 'Nigdy nie zapisane')
            ->call('saveTab', $this->key('seo-i-konwersja'))
            ->assertNotNotified('Zapisano: SEO i konwersja')
            ->assertNotDispatched('service-form-tab-saved');

        $this->assertSame($before, $this->rawRow($service));
    }

    // ---------------------------------------------------------------------------------------------
    // Fix 3: a failed save must not leave its values on the in-memory record
    // ---------------------------------------------------------------------------------------------

    public function test_a_failed_tab_save_does_not_leak_its_values_into_the_next_save_on_the_same_page(): void
    {
        $service = $this->baseline('rental');
        $component = $this->openEdit($service)->set('data.meta_title', 'BOOM')->set('data.name', 'Nazwa z drugiej zakładki');

        $thrown = false;
        Service::updating(function (Service $row) use (&$thrown): void {
            if (! $thrown && $row->meta_title === 'BOOM') {
                $thrown = true;

                throw new RuntimeException('simulated failure before the UPDATE');
            }
        });

        // Livewire re-reads the model on every request, so the leak only exists within ONE request: call the page object twice.
        $page = $component->instance();

        try {
            $page->saveTab($this->key('seo-i-konwersja'));
            $this->fail('the simulated failure did not surface');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse($page->getRecord()->isDirty(), 'the failed tab left its values on the in-memory record');

        $page->saveTab($this->key('podstawowe'));

        $row = $this->reload($service);
        $this->assertSame('Nazwa z drugiej zakładki', $row->name);
        $this->assertSame('Meta początkowy', $row->meta_title, 'the failed tab leaked into the next save');
        $this->assertSame('BOOM', $page->data['meta_title'], 'the form still holds the failed tab\'s edit');
    }

    public function test_after_a_failure_that_comes_after_the_update_the_record_matches_the_database_and_the_next_save_does_not_rewrite_it(): void
    {
        // EditService saves in a transaction, so the failed tab's UPDATE is rolled back. `saved` fires before Eloquent
        // syncs the original, so the record is dirty AND ahead of its "original" at the moment of the throw; the trait
        // reloads it from the DB, which is the truth (here: the row as it was before the failed save).
        // The rollback itself is proven in FilamentSaveTransactionsTest; this test is about memory matching the DB.
        $service = $this->baseline('rental');
        $component = $this->openEdit($service)->set('data.meta_title', 'BOOM')->set('data.name', 'Nazwa z drugiej zakładki');

        $thrown = false;
        Service::saved(function (Service $row) use (&$thrown): void {
            if (! $thrown && $row->meta_title === 'BOOM') {
                $thrown = true;

                throw new RuntimeException('simulated failure after the UPDATE');
            }
        });

        $page = $component->instance();

        try {
            $page->saveTab($this->key('seo-i-konwersja'));
            $this->fail('the simulated failure did not surface');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('Meta początkowy', $this->reload($service)->meta_title, 'the transaction did not roll the failed tab save back');
        $this->assertFalse($page->getRecord()->isDirty());
        $this->assertSame('Meta początkowy', $page->getRecord()->meta_title, 'the in-memory record must match the database after a failure');

        $updates = [];
        DB::listen(function ($query) use (&$updates): void {
            if (str_starts_with(strtolower($query->sql), 'update "services"')) {
                $updates[] = $query->sql;
            }
        });

        $page->saveTab($this->key('podstawowe'));

        $this->assertSame('Nazwa z drugiej zakładki', $this->reload($service)->name);
        $this->assertCount(1, $updates);
        $this->assertStringNotContainsString('meta_title', $updates[0], 'the next tab save rewrote the failed tab\'s column');
    }

    // ---------------------------------------------------------------------------------------------
    // Legacy rows, spoofed type, misconfiguration, access
    // ---------------------------------------------------------------------------------------------

    #[DataProvider('types')]
    public function test_a_tab_save_normalises_a_legacy_row_exactly_like_a_full_save(string $type): void
    {
        // Model `saving` hooks (NormalizesEmptyJsonToNull, SyncsRentalPriceWithDailyRate) act on the WHOLE model, so a row
        // written before them is normalised by ANY save - also by one for another tab. Same result as a full save.
        $legacy = function (string $suffix) use ($type): Service {
            $service = $this->baseline($type, $suffix);
            DB::table('services')->where('id', $service->id)->update(
                ['features' => '[]', 'body' => '<p></p>'] + ($type === 'rental' ? ['price' => 77] : []),
            );

            return $service;
        };
        $ignore = ['id', 'slug', 'name', 'created_at'];

        $full = $legacy('full');
        $this->saveForm($this->openEdit($full))->assertHasNoFormErrors();
        $expected = $this->decodedRow($this->rawRow($full), $ignore);

        $this->assertNull($expected['features'], 'precondition: a full save normalises the legacy features');
        $this->assertNull($expected['body']);
        if ($type === 'rental') {
            $this->assertEquals(100, $expected['price']);
        }

        foreach ($type === 'rental' ? self::RENTAL_TABS : self::SLOT_TABS as $tab) {
            $row = $legacy("tab-{$tab}");
            $this->openEdit($row)->call('saveTab', $this->key($tab))->assertHasNoFormErrors();

            $this->assertSame($expected, $this->decodedRow($this->rawRow($row), $ignore), "saving `{$tab}` on a legacy row differs from a full save");
        }
    }

    public function test_a_spoofed_service_type_cannot_unlock_the_other_types_tab_on_a_tab_save(): void
    {
        $rental = $this->baseline('rental', 'r');
        $rentalBefore = $this->rawRow($rental);
        $this->openEdit($rental)
            ->set('data.service_type', 'time_slot')
            ->set('data.price_from', 5)
            ->set('data.duration_hours', 3)
            ->call('saveTab', $this->key('cena-i-czas-trwania'))
            ->assertStatus(403);
        $this->assertSame($rentalBefore, $this->rawRow($rental), 'a rental spoofed as time_slot wrote the time-slot tab');

        $slot = $this->baseline('time_slot', 's');
        $slotBefore = $this->rawRow($slot);
        $this->openEdit($slot)
            ->set('data.service_type', 'item_rental')
            ->set('data.price_per_day', 5)
            ->call('saveTab', $this->key('cena-i-magazyn'))
            ->assertStatus(403);
        $this->assertSame($slotBefore, $this->rawRow($slot), 'a time_slot spoofed as item_rental wrote the rental tab');
    }

    public function test_a_spoofed_service_type_cannot_unlock_the_other_types_fields_on_the_full_save(): void
    {
        $rental = $this->baseline('rental');
        $before = $this->rawRow($rental);

        $this->saveForm(
            $this->openEdit($rental)->set('data.service_type', 'time_slot')->set('data.price_from', 5)->set('data.name', 'Nazwa po spoofie'),
        )->assertHasNoFormErrors();

        $after = $this->rawRow($rental);
        $this->assertSame('Nazwa po spoofie', $after['name']);
        $this->assertSame($before['price_from'], $after['price_from'], 'the full save wrote a time-slot-only field of a rental');
        $this->assertSame($before['service_type'], $after['service_type']);
    }

    public function test_an_ownership_override_naming_no_tab_fails_loudly(): void
    {
        $service = $this->baseline('rental');
        $before = $this->rawRow($service);
        $page = Livewire::test(MisnamedOwnerEditService::class, ['record' => $service->getRouteKey()])->instance();

        foreach ([fn () => $page->saveTab($this->key('podstawowe')), fn () => $page->getTabSaveMap()] as $call) {
            try {
                $call();
                $this->fail('a misnamed owner must not pass silently');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('cena-i-czas', $exception->getMessage());
            }
        }

        $this->assertSame($before, $this->rawRow($service));
    }

    public function test_a_tab_save_is_refused_for_a_record_of_another_tenant(): void
    {
        $foreign = Service::factory()->create([
            'organization_id' => Organization::factory()->equipmentRental()->create()->id,
            'name' => 'Cudza usługa',
            'slug' => 'cudza-usluga',
        ]);
        $before = $this->rawRow($foreign);

        try {
            Livewire::test(EditService::class, ['record' => $foreign->getRouteKey()])->call('saveTab', $this->key('seo-i-konwersja'));
            $this->fail('another tenant\'s record must not open');
        } catch (ModelNotFoundException|HttpException) {
            // refused at mount: the tenant scope hides the record
        }

        $this->assertSame($before, $this->rawRow($foreign));
    }

    public function test_a_tab_save_is_refused_once_the_user_lost_access_to_the_resource(): void
    {
        $service = $this->baseline('rental');
        $before = $this->rawRow($service);
        $component = $this->openEdit($service)->set('data.meta_title', 'Nowy');

        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $staff->organizations()->attach($this->tenant->id, ['role' => 'staff']);
        $this->actingAs($staff);

        $component->call('saveTab', $this->key('seo-i-konwersja'))->assertStatus(403);

        $this->assertSame($before, $this->rawRow($service));
    }

    // ---------------------------------------------------------------------------------------------
    // The tab map for the 5b UI
    // ---------------------------------------------------------------------------------------------

    #[DataProvider('types')]
    public function test_the_tab_save_map_lists_every_visible_tab_and_assigns_each_dehydrated_field_to_exactly_one(string $type): void
    {
        $service = $this->baseline($type);
        $page = $this->openEdit($service)->instance();
        $map = $page->getTabSaveMap();

        $expectedTabs = $type === 'rental' ? self::RENTAL_TABS : self::SLOT_TABS;
        $this->assertSame(array_map(fn (string $tab): string => $this->key($tab), $expectedTabs), array_keys($map));

        $all = array_merge(...array_column($map, 'paths'));
        $this->assertSame($all, array_values(array_unique($all)), 'a path is owned by two tabs');

        $fields = [];
        foreach ($page->getSchema('form')->getFlatFields(withHidden: false) as $name => $field) {
            if (! preg_match('/\.[0-9a-f]{8}-[0-9a-f]{4}-/', (string) $name) && $field->isDehydrated()) {
                $fields[] = (string) $name;
            }
        }

        // A rental's duration is edited nowhere: the Hidden in Podstawowe dehydrates, but no tab persists it.
        $expected = $type === 'rental' ? array_values(array_diff($fields, ['duration_minutes'])) : $fields;
        $this->assertEqualsCanonicalizing($expected, $all, 'a dehydrated field is owned by no tab (or a tab lists a path no field has)');

        $owner = fn (string $path): ?string => collect($map)->search(fn (array $entry): bool => in_array($path, $entry['paths'], true)) ?: null;
        $this->assertSame($type === 'rental' ? null : $this->key('cena-i-czas-trwania'), $owner('duration_minutes'), 'the ownership override moves duration_minutes out of Podstawowe');
        $this->assertSame('Treść', $map[$this->key('tresc')]['label']);
        $this->assertNotContains('duration_hours', $all, 'display-only inputs are not dehydrated');
    }

    public function test_the_rental_map_lists_the_repeater_not_its_rows(): void
    {
        $page = $this->openEdit($this->baseline('rental'))->instance();
        $paths = $page->getTabSaveMap()[$this->key('specyfikacja')]['paths'];

        $this->assertEqualsCanonicalizing(['metadata.specs', 'features'], $paths);
    }
}

/** Test double: an edit page whose `beforeSave` hook halts, like a hook that refuses the save would. */
class HaltingEditService extends EditService
{
    protected function beforeSave(): void
    {
        throw new Halt;
    }
}

/** Test double: an owner slug that matches no tab (a renamed label). */
class MisnamedOwnerEditService extends EditService
{
    protected function tabPathOwnershipOverrides(): array
    {
        return ['duration_minutes' => 'cena-i-czas'];
    }
}
