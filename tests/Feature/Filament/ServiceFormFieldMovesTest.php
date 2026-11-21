<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ServiceType;
use App\Filament\Resources\ServiceResource\Pages\CreateService;
use App\Models\Service;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\JsContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * STAGE A of the product form redesign: fields move between tabs (price/price_from/duration to a time_slot-only
 * "Cena i czas trwania" tab, excerpt to a "Krótki opis" section and features to a "Co zawiera usługa" section,
 * both in "Treść"). Layout only - saved data must not change, with ONE approved exception: a rental's `price`
 * is no longer editable (hidden), so a rental save neither reads nor writes it.
 *
 * The existing ServiceForm* tests stay untouched; this file pins what stage A adds.
 */
class ServiceFormFieldMovesTest extends TestCase
{
    use RefreshDatabase, ServiceFormTestSupport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpServiceFormTenant();
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, Tab> every tab (hidden or not) by label, in form order */
    private function allTabs(Testable $component): array
    {
        $root = $component->instance()->getSchema('form')->getComponents(withHidden: true);
        $this->assertCount(1, $root);
        $this->assertInstanceOf(Tabs::class, $root[0]);

        $tabs = [];
        foreach ($root[0]->getChildSchema()->getComponents(withHidden: true) as $tab) {
            $tabs[(string) $tab->getLabel()] = $tab;
        }

        return $tabs;
    }

    /** @return array<int, string> */
    private function visibleTabLabels(Testable $component): array
    {
        return array_keys(array_filter($this->allTabs($component), fn (Tab $tab): bool => $tab->isVisible()));
    }

    /** @return array<int, string> top-level field names inside a tab, hidden or not (repeater rows excluded) */
    private function fieldNamesIn(Tab $tab): array
    {
        $names = [];

        foreach ($tab->getChildSchema()->getFlatFields(withHidden: true, withAbsoluteKeys: true) as $key => $field) {
            $name = preg_replace('/^form\./', '', (string) $key);

            if (! preg_match('/\.[0-9a-f]{8}-[0-9a-f]{4}-/', $name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @return array<int, string> */
    private function sectionHeadingsIn(Tab $tab): array
    {
        return array_map(
            fn (Section $section): string => (string) $section->getHeading(),
            array_values(array_filter(
                $tab->getChildSchema()->getComponents(withHidden: true),
                fn ($component): bool => $component instanceof Section,
            )),
        );
    }

    private function field(Testable $component, string $name): Field
    {
        return $component->instance()->getSchema('form')->getFlatFields(withHidden: true)[$name];
    }

    private function submitCreate(array $data): Testable
    {
        return Livewire::test(CreateService::class)->fillForm($data)->call('create');
    }

    private function createMinimalRental(array $extra = []): int
    {
        $this->addLocation();

        $this->submitCreate([
            'service_type' => ServiceType::ItemRental->value,
            'name' => 'Minimalna wiertarka',
            'slug' => 'minimalna-wiertarka',
            'price_per_day' => 10,
            ...$extra,
        ])->assertHasNoFormErrors();

        return (int) Service::withoutGlobalScopes()->where('slug', 'minimalna-wiertarka')->value('id');
    }

    // ---------------------------------------------------------------------------------------------
    // 1. The new "Cena i czas trwania" tab
    // ---------------------------------------------------------------------------------------------

    public function test_a_time_slot_shows_the_price_and_duration_tab_right_after_podstawowe(): void
    {
        $component = $this->openEdit($this->timeSlotService());

        $this->assertSame(['Podstawowe', 'Cena i czas trwania', 'Treść', 'SEO i konwersja'], $this->visibleTabLabels($component));
    }

    public function test_the_price_and_duration_tab_holds_one_section_with_exactly_the_moved_fields(): void
    {
        $tab = $this->allTabs($this->openEdit($this->timeSlotService()))['Cena i czas trwania'];

        $this->assertSame(['Cena i czas trwania'], $this->sectionHeadingsIn($tab));
        $this->assertEqualsCanonicalizing(
            ['price', 'price_from', 'duration_days', 'duration_hours', 'duration_mins'],
            $this->fieldNamesIn($tab),
        );
    }

    public function test_a_rental_does_not_see_the_price_and_duration_tab_but_keeps_its_own_order(): void
    {
        $component = $this->openEdit($this->rentalService());

        $this->assertSame(['Podstawowe', 'Cena i magazyn', 'Specyfikacja', 'Treść', 'SEO i konwersja'], $this->visibleTabLabels($component));
        $this->assertTrue($this->allTabs($component)['Cena i czas trwania']->isHidden());
    }

    public function test_the_tab_hides_exactly_what_its_section_hides(): void
    {
        foreach ([$this->rentalService(), $this->timeSlotService()] as $service) {
            $tab = $this->allTabs($this->openEdit($service))['Cena i czas trwania'];

            // `getComponents(withHidden: false)` would already drop the sections of a hidden tab, so compare each
            // component's OWN condition: Component::isHidden() looks only at the component's own hidden()/visible()
            // (the parent-aware variant lives on Schema, not on components). A section that lost its condition
            // stays visible inside a hidden tab and the two disagree.
            $sections = array_values(array_filter(
                $tab->getChildSchema()->getComponents(withHidden: true),
                fn ($component): bool => $component instanceof Section,
            ));
            $this->assertCount(1, $sections);

            $this->assertSame($tab->isHidden(), $sections[0]->isHidden(), "type {$service->service_type->value}");
        }
    }

    public function test_the_create_form_shows_and_hides_the_tab_with_the_type(): void
    {
        $component = Livewire::test(CreateService::class);
        $this->assertContains('Cena i czas trwania', $this->visibleTabLabels($component));

        $component->set('data.service_type', ServiceType::ItemRental->value);
        $this->assertNotContains('Cena i czas trwania', $this->visibleTabLabels($component));

        $component->set('data.service_type', ServiceType::TimeSlot->value);
        $this->assertContains('Cena i czas trwania', $this->visibleTabLabels($component));
    }

    public function test_podstawowe_keeps_only_its_own_fields_plus_the_hidden_duration_minutes(): void
    {
        $tab = $this->allTabs($this->openEdit($this->rentalService()))['Podstawowe'];
        $fields = $this->fieldNamesIn($tab);

        $this->assertEqualsCanonicalizing(
            ['service_type', 'name', 'slug', 'icon', 'duration_minutes', 'is_active', 'sort_order', 'featured_image', 'hero_overlay_color', 'hero_overlay_opacity'],
            $fields,
        );

        foreach (['excerpt', 'price', 'price_from', 'duration_days', 'duration_hours', 'duration_mins'] as $moved) {
            $this->assertNotContains($moved, $fields, "`{$moved}` left Podstawowe");
        }

        $this->assertSame(['Podstawowe informacje', 'Zdjęcie wyróżniające'], $this->sectionHeadingsIn($tab), 'existing headings of untouched sections');
    }

    // ---------------------------------------------------------------------------------------------
    // 2. duration_minutes must keep dehydrating for every type
    // ---------------------------------------------------------------------------------------------

    public function test_duration_minutes_is_an_always_visible_dehydrated_field_outside_every_conditional_tab(): void
    {
        foreach ([$this->rentalService(), $this->timeSlotService()] as $service) {
            $component = $this->openEdit($service);
            $field = $this->field($component, 'duration_minutes');

            $this->assertTrue($field->isVisible(), "{$service->service_type->value}: duration_minutes visible");
            $this->assertTrue($field->isDehydrated(), "{$service->service_type->value}: duration_minutes dehydrated");

            $tabs = array_filter(
                $this->allTabs($component),
                fn (Tab $tab): bool => in_array('duration_minutes', $this->fieldNamesIn($tab), true),
            );
            $this->assertSame(['Podstawowe'], array_keys($tabs), 'duration_minutes lives only in Podstawowe');
            $this->assertTrue($tabs['Podstawowe']->isVisible());
        }
    }

    public function test_a_rental_create_still_writes_duration_minutes_60_and_price_from_price_per_day(): void
    {
        $id = $this->createMinimalRental();

        $row = $this->snapshot($id);
        $this->assertSame(60, $row['duration_minutes']);
        $this->assertSame('10.00', $row['price'], 'stage B: a rental price follows price_per_day');
    }

    public function test_a_time_slot_duration_is_computed_from_hours_and_minutes_across_tabs_and_saved(): void
    {
        $service = $this->timeSlotService(['duration_minutes' => 60]);

        $component = $this->openEdit($service)
            ->assertSet('data.duration_hours', 1.0)
            ->assertSet('data.duration_mins', 0)
            ->set('data.duration_hours', 2)
            ->set('data.duration_mins', 15)
            ->assertSet('data.duration_minutes', 135);

        $this->saveForm($component)->assertHasNoFormErrors();

        $this->assertSame(135, $this->reload($service)->duration_minutes);
    }

    public function test_a_time_slot_create_computes_duration_minutes_from_the_moved_inputs(): void
    {
        $this->addLocation();

        Livewire::test(CreateService::class)
            ->fillForm(['service_type' => ServiceType::TimeSlot->value, 'name' => 'Woskowanie', 'slug' => 'woskowanie', 'price' => 120])
            ->set('data.duration_hours', 1)
            ->set('data.duration_mins', 45)
            ->call('create')
            ->assertHasNoFormErrors();

        $row = $this->snapshot((int) Service::withoutGlobalScopes()->where('slug', 'woskowanie')->value('id'));
        $this->assertSame(105, $row['duration_minutes']);
        $this->assertSame('120.00', $row['price']);
    }

    // ---------------------------------------------------------------------------------------------
    // 3. price: hidden for a rental (stage B: follows price_per_day); required and editable for a time_slot
    // ---------------------------------------------------------------------------------------------

    public function test_price_is_hidden_for_a_rental_and_visible_for_a_time_slot(): void
    {
        $this->openEdit($this->rentalService())->assertFormFieldIsHidden('price');
        $this->openEdit($this->timeSlotService())->assertFormFieldIsVisible('price');
    }

    public function test_a_price_forced_into_a_rental_state_is_ignored_and_price_per_day_wins(): void
    {
        // Stage B replaced "the stored rental price is kept" (stage A) with "it follows price_per_day":
        // ServiceFormRentalPriceTest covers the sync. This keeps the stage A guarantee that the hidden field
        // is never dehydrated, whatever its state holds.
        $service = $this->rentalService(['price_per_day' => 150]);

        $this->saveForm($this->openEdit($service)->set('data.price', 1))->assertHasNoFormErrors();
        $this->assertSame('150.00', $this->snapshot($service)['price']);
    }

    public function test_a_hidden_price_is_not_validated_on_a_rental(): void
    {
        $service = $this->rentalService(['price' => 100]);

        $this->saveForm($this->openEdit($service)->set('data.price', 'abc'))->assertHasNoFormErrors();
        $this->assertSame('100.00', $this->snapshot($service)['price']);
    }

    public function test_a_rental_create_ignores_a_typed_price_and_stores_price_per_day(): void
    {
        // A price forced into the (hidden) field's state is not dehydrated; stage B copies price_per_day (10) instead.
        $id = $this->createMinimalRental(['price' => 55]);

        $this->assertSame('10.00', $this->snapshot($id)['price']);
    }

    public function test_a_time_slot_still_saves_price_and_price_from(): void
    {
        $service = $this->timeSlotService();

        $this->saveForm($this->openEdit($service)->set('data.price', 321.5)->set('data.price_from', 99))->assertHasNoFormErrors();

        $row = $this->snapshot($service);
        $this->assertSame('321.50', $row['price']);
        $this->assertSame('99.00', $row['price_from']);
    }

    public function test_clearing_the_price_of_a_time_slot_is_a_validation_error_and_keeps_the_stored_price(): void
    {
        // Stage B closed the c8 gap: the time_slot price is required, so an empty field never reaches the NOT NULL column.
        $service = $this->timeSlotService(['price' => 100]);

        $this->saveForm($this->openEdit($service)->set('data.price', null))->assertHasFormErrors(['price' => 'required']);
        $this->assertSame('100.00', $this->reload($service)->price);
    }

    public function test_clearing_the_price_on_a_time_slot_create_is_a_validation_error_and_creates_nothing(): void
    {
        $this->addLocation();

        $this->submitCreate(['service_type' => ServiceType::TimeSlot->value, 'name' => 'Młot', 'slug' => 'mlot', 'price' => null])
            ->assertHasFormErrors(['price' => 'required']);
        $this->assertSame(0, Service::withoutGlobalScopes()->where('slug', 'mlot')->count());
    }

    // ---------------------------------------------------------------------------------------------
    // 4. The "Treść" tab
    // ---------------------------------------------------------------------------------------------

    public function test_the_tresc_tab_orders_its_sections_for_both_types(): void
    {
        foreach ([$this->rentalService(), $this->timeSlotService()] as $service) {
            $tab = $this->allTabs($this->openEdit($service))['Treść'];

            $this->assertSame(
                ['Krótki opis', 'Opis produktu', 'Zaawansowane bloki (0)', 'Co zawiera usługa'],
                $this->sectionHeadingsIn($tab),
                $service->service_type->value,
            );
            $this->assertSame(['excerpt', 'body', 'content', 'features'], $this->fieldNamesIn($tab));
            $this->assertTrue($tab->isVisible());
        }
    }

    public function test_the_section_descriptions_match_the_mockup(): void
    {
        $tab = $this->allTabs($this->openEdit($this->timeSlotService()))['Treść'];
        $sections = array_values(array_filter($tab->getChildSchema()->getComponents(withHidden: true), fn ($c): bool => $c instanceof Section));

        $this->assertSame('Na kafelku (2 linie) i w Google, gdy nie ma meta opisu.', (string) $sections[0]->getDescription());
        $this->assertSame('Do 4 punktów z ✓ na kafelku produktu na liście.', (string) $sections[3]->getDescription());
    }

    public function test_features_left_the_seo_tab_and_the_conversion_section_keeps_the_rest(): void
    {
        $tab = $this->allTabs($this->openEdit($this->rentalService()))['SEO i konwersja'];

        $this->assertSame(['SEO i publikacja', 'Optymalizacja konwersji'], $this->sectionHeadingsIn($tab));
        $this->assertEqualsCanonicalizing(
            ['meta_title', 'meta_description', 'area_served', 'published_at', 'average_rating', 'total_reviews', 'is_popular', 'booking_count_week'],
            $this->fieldNamesIn($tab),
        );
    }

    public function test_the_moved_fields_keep_their_rules_and_hide_their_duplicate_labels(): void
    {
        $component = $this->openEdit($this->timeSlotService());

        /** @var Textarea $excerpt */
        $excerpt = $this->field($component, 'excerpt');
        $this->assertSame(500, $excerpt->getMaxLength());
        $this->assertTrue($excerpt->isLabelHidden());
        $this->assertSame('Krótki opis', (string) $excerpt->getLabel(), 'hidden visually, still named for screen readers');

        $features = $this->field($component, 'features');
        $this->assertTrue($features->isLabelHidden());
        $this->assertSame('Co zawiera usługa', (string) $features->getLabel());
        $this->assertSame(4, $features->getMaxItems());
        $this->assertSame(0, $features->getMinItems());

        $html = $component->html();
        $this->assertStringNotContainsString('3-4 punkty wyświetlane na karcie usługi', $html, 'the old features helper duplicated the section description');
        $this->assertStringNotContainsString('max 500 znaków', $html, 'the old excerpt helper duplicated the section description');
    }

    public function test_the_excerpt_counter_runs_in_the_browser_only(): void
    {
        $component = $this->openEdit($this->timeSlotService());
        $excerpt = $this->field($component, 'excerpt');

        $this->assertFalse($excerpt->isLive(), 'no ->live(): typing causes no server round-trip');

        $texts = array_values(array_filter(
            $excerpt->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)->getComponents(),
            fn ($component): bool => $component instanceof Text,
        ));
        $this->assertCount(1, $texts);

        // The exact expression Alpine evaluates: code-point length of the entangled state (no server call), against the
        // field's own limit. JsContent renders it as `<span x-text="() => eval(...)">`, so compare with the same rendering.
        $content = $texts[0]->getContent();
        $this->assertInstanceOf(JsContent::class, $content);
        $this->assertSame(
            JsContent::make("[...String(\$wire.\$get('data.excerpt') ?? '')].length + ' / 500'")->toHtml(),
            $content->toHtml(),
        );
        $this->assertSame(500, $excerpt->getMaxLength(), 'the counter limit is the field limit');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function bothTypes(): array
    {
        return ['rental' => ['rental'], 'time_slot' => ['time_slot']];
    }

    #[DataProvider('bothTypes')]
    public function test_excerpt_and_features_survive_a_noop_save_unchanged(string $type): void
    {
        $attributes = ['excerpt' => 'Zażółć gęślą jaźń — krótki opis', 'features' => ['Pierwsza', 'Druga', 'Trzecia', 'Czwarta']];
        $service = $type === 'rental' ? $this->rentalService($attributes) : $this->timeSlotService($attributes);
        $before = $this->snapshot($service);

        $this->saveUnchanged($service);

        $after = $this->snapshot($service);
        $this->assertSame($attributes['excerpt'], $after['excerpt']);
        $this->assertSame($attributes['features'], $after['features']);
        $this->assertSame($before, $after, 'nothing else moved either');
    }

    #[DataProvider('bothTypes')]
    public function test_excerpt_and_features_round_trip_new_values_and_reject_the_same_violations(string $type): void
    {
        $service = $type === 'rental' ? $this->rentalService() : $this->timeSlotService();
        $items = static fn (array $texts): array => array_map(fn (string $text): array => ['feature' => $text], $texts);

        $this->saveForm($this->openEdit($service)
            ->set('data.excerpt', str_repeat('a', 500))
            ->set('data.features', $items(['a', 'b', 'c', str_repeat('x', 100)])))
            ->assertHasNoFormErrors();

        $row = $this->snapshot($service);
        $this->assertSame(str_repeat('a', 500), $row['excerpt']);
        $this->assertSame(['a', 'b', 'c', str_repeat('x', 100)], $row['features']);

        $component = $this->saveForm($this->openEdit($service)
            ->set('data.excerpt', str_repeat('a', 501))
            ->set('data.features', $items(['a', 'b', 'c', 'd', 'e'])));

        $this->assertTrue($this->hasErrorOn($component, 'data.excerpt'));
        $this->assertTrue($this->hasErrorOn($component, 'data.features'));
        $this->assertSame($row, $this->snapshot($service), 'a rejected save persists nothing');
    }

    public function test_create_round_trips_excerpt_and_features_for_both_types(): void
    {
        $this->addLocation();
        $features = array_map(fn (string $text): array => ['feature' => $text], ['A', 'B']);

        $this->submitCreate([
            'service_type' => ServiceType::TimeSlot->value, 'name' => 'Usługa z opisem', 'slug' => 'usluga-z-opisem',
            'excerpt' => 'Opis usługi', 'features' => $features,
        ])->assertHasNoFormErrors();

        $this->submitCreate([
            'service_type' => ServiceType::ItemRental->value, 'name' => 'Produkt z opisem', 'slug' => 'produkt-z-opisem',
            'price_per_day' => 10, 'excerpt' => 'Opis produktu', 'features' => $features,
        ])->assertHasNoFormErrors();

        foreach (['usluga-z-opisem' => 'Opis usługi', 'produkt-z-opisem' => 'Opis produktu'] as $slug => $excerpt) {
            $row = Service::withoutGlobalScopes()->where('slug', $slug)->firstOrFail();
            $this->assertSame($excerpt, $row->excerpt);
            $this->assertSame(['A', 'B'], $row->features);
        }
    }
}
