<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ServiceType;
use App\Filament\Resources\ServiceResource\Pages\CreateService;
use App\Models\Service;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * STAGE C of the product form redesign: a rental's spec rows get a star ("show on the product tile",
 * `metadata.specs[].highlight`, max 4) and its `features` move to "Specyfikacja" as "Dodatkowe punkty na kafelku";
 * the tile prints the starred rows first. Nothing may be lost: an untouched product saves byte-identical.
 */
class ServiceFormTileHighlightsTest extends TestCase
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

    /** @return array<int, array{label: string, value: string, unit: ?string}> six rows, none starred */
    private function sixSpecs(): array
    {
        return [
            ['label' => 'Moc', 'value' => '800', 'unit' => 'W'],
            ['label' => 'Waga', 'value' => '2,4', 'unit' => 'kg'],
            ['label' => 'Rodzaj paliwa', 'value' => 'brak', 'unit' => null],
            ['label' => 'Napięcie', 'value' => '230', 'unit' => 'V'],
            ['label' => 'Ciśnienie', 'value' => '21', 'unit' => 'bar'],
            ['label' => 'Obroty', 'value' => '1500', 'unit' => 'obr/min'],
        ];
    }

    /** @return array<int, string> the repeater item keys of the open form, in row order */
    private function rowKeys(Testable $page): array
    {
        return array_keys($page->get('data.metadata.specs'));
    }

    /** @param  array<int, int>  $positions 0-based row numbers to switch on */
    private function star(Testable $page, array $positions): Testable
    {
        $keys = $this->rowKeys($page);

        foreach ($positions as $position) {
            $page->set("data.metadata.specs.{$keys[$position]}.highlight", true);
        }

        return $page;
    }

    /** The stored `metadata` exactly as the database holds it (a raw JSON string), not as the model casts it. */
    private function rawMetadata(Service $service): ?string
    {
        return $this->reload($service)->getRawOriginal('metadata');
    }

    /** @return array<int, string> */
    private function tileTexts(Service $service): array
    {
        $html = Blade::render('<x-ios.service-card :service="$service" />', ['service' => $this->reload($service)]);

        preg_match_all('#<span class="service-card__feature-text[^"]*">(.*?)</span>#su', $html, $matches);

        return array_map(fn (string $text): string => html_entity_decode(trim($text)), $matches[1]);
    }

    // ---------------------------------------------------------------------------------------------
    // Data: stars are stored only when on
    // ---------------------------------------------------------------------------------------------

    public function test_starring_a_row_stores_highlight_true_on_that_row_only(): void
    {
        $service = $this->rentalService(['metadata' => ['specs' => $this->sixSpecs()]]);

        $page = $this->star($this->openEdit($service), [1, 3]);
        $this->saveForm($page)->assertHasNoFormErrors();

        $specs = $this->reload($service)->metadata['specs'];
        $this->assertCount(6, $specs, 'no row lost');

        foreach ($specs as $i => $row) {
            if (in_array($i, [1, 3], true)) {
                $this->assertTrue($row['highlight'], "row {$i} is starred");
            } else {
                $this->assertArrayNotHasKey('highlight', $row, "row {$i} must have NO highlight key (not false, not null)");
            }
        }

        // The rest of each row is untouched.
        $this->assertSame(
            $this->ksortDeep($this->sixSpecs()),
            $this->ksortDeep(array_map(fn (array $row): array => array_diff_key($row, ['highlight' => true]), $specs)),
        );
    }

    public function test_unstarring_removes_the_key_instead_of_storing_false(): void
    {
        $starred = $this->sixSpecs();
        $starred[0]['highlight'] = true;
        $starred[2]['highlight'] = true;
        $service = $this->rentalService(['metadata' => ['specs' => $starred]]);

        $page = $this->openEdit($service);
        $keys = $this->rowKeys($page);
        $this->assertTrue($page->get("data.metadata.specs.{$keys[0]}.highlight"), 'a starred row opens with the star on');

        $page->set("data.metadata.specs.{$keys[0]}.highlight", false);
        $this->saveForm($page)->assertHasNoFormErrors();

        $specs = $this->reload($service)->metadata['specs'];
        $this->assertArrayNotHasKey('highlight', $specs[0]);
        $this->assertTrue($specs[2]['highlight'], 'the other star stays');
        $this->assertStringNotContainsString('"highlight":false', (string) $this->rawMetadata($service));
        $this->assertStringNotContainsString('"highlight":null', (string) $this->rawMetadata($service));
    }

    public function test_rows_saved_before_stage_c_open_with_the_star_off_and_save_byte_identical(): void
    {
        $service = $this->rentalService(['metadata' => [
            'specs' => $this->sixSpecs(),
            'image_sources' => [['path' => 'services/featured/a.jpg', 'credit' => 'Jan']],
        ]]);
        $before = $this->rawMetadata($service);

        $page = $this->openEdit($service);
        foreach ($this->rowKeys($page) as $key) {
            $this->assertFalse((bool) $page->get("data.metadata.specs.{$key}.highlight"), 'no key in the data = star off');
        }
        $this->saveForm($page)->assertHasNoFormErrors();

        $this->assertSame($before, $this->rawMetadata($service), 'an untouched product must not gain a highlight key or change otherwise');
    }

    public function test_the_other_metadata_keys_survive_a_save_that_stars_a_row(): void
    {
        $service = $this->rentalService(['metadata' => [
            'specs' => $this->sixSpecs(),
            'image_sources' => [['path' => 'services/featured/a.jpg', 'credit' => 'Jan']],
        ]]);

        $this->saveForm($this->star($this->openEdit($service), [0]))->assertHasNoFormErrors();

        $metadata = $this->reload($service)->metadata;
        $this->assertSame([['path' => 'services/featured/a.jpg', 'credit' => 'Jan']], $metadata['image_sources']);
        $this->assertTrue($metadata['specs'][0]['highlight']);
    }

    public function test_four_stars_are_accepted(): void
    {
        $service = $this->rentalService(['metadata' => ['specs' => $this->sixSpecs()]]);

        $this->saveForm($this->star($this->openEdit($service), [0, 1, 2, 3]))->assertHasNoFormErrors();

        $starred = array_filter($this->reload($service)->metadata['specs'], fn (array $row): bool => ($row['highlight'] ?? false) === true);
        $this->assertCount(4, $starred);
    }

    public function test_a_fifth_star_is_a_validation_error_and_nothing_is_saved(): void
    {
        $service = $this->rentalService(['name' => 'Przed', 'metadata' => ['specs' => $this->sixSpecs()]]);
        $before = $this->snapshot($service);

        $page = $this->star($this->openEdit($service), [0, 1, 2, 3, 4])
            ->set('data.name', 'Po')
            ->call('save');

        $this->assertTrue($this->hasErrorOn($page, 'data.metadata.specs'));
        $this->assertSame(
            ['Na kafelku mogą być maks. 4 parametry. Odznacz jeden, żeby wybrać inny.'],
            $page->errors()->get('data.metadata.specs'),
        );
        $this->assertSame($before, $this->snapshot($service), 'a rejected save writes nothing - not even the name');
    }

    public function test_the_cap_holds_even_when_the_disabled_toggles_are_bypassed(): void
    {
        // A crafted request sets a fifth star whatever the UI disabled: the rule is the guard, not the toggle.
        $service = $this->rentalService(['metadata' => ['specs' => $this->sixSpecs()]]);

        $page = $this->openEdit($service)
            ->set('data.metadata.specs', collect($this->sixSpecs())->map(fn (array $row, int $i): array => $row + ['highlight' => $i < 5])->all())
            ->call('save');

        $this->assertTrue($this->hasErrorOn($page, 'data.metadata.specs'));
        $this->assertArrayNotHasKey('highlight', $this->reload($service)->metadata['specs'][0]);
    }

    public function test_once_four_are_starred_the_other_toggles_are_disabled_and_the_starred_ones_stay_enabled(): void
    {
        $service = $this->rentalService(['metadata' => ['specs' => $this->sixSpecs()]]);

        $page = $this->openEdit($service);
        $keys = $this->rowKeys($page);

        $toggle = function (int $position) use ($page, $keys): Toggle {
            $found = null;
            $page->assertFormFieldExists("metadata.specs.{$keys[$position]}.highlight", function (Toggle $field) use (&$found): bool {
                $found = $field;

                return true;
            });

            return $found;
        };

        $this->assertFalse($toggle(5)->isDisabled(), 'with nothing starred every toggle is free');

        $this->star($page, [0, 1, 2]);
        $this->assertFalse($toggle(5)->isDisabled(), 'three stars: a fourth is still allowed');

        $this->star($page, [3]);
        $this->assertTrue($toggle(5)->isDisabled(), 'four stars: the rest are disabled');
        $this->assertTrue($toggle(4)->isDisabled());
        $this->assertFalse($toggle(0)->isDisabled(), 'a starred row can always be unstarred');
        $this->assertFalse($toggle(3)->isDisabled());

        $page->set("data.metadata.specs.{$keys[0]}.highlight", false);
        $this->assertFalse($toggle(5)->isDisabled(), 'unstarring frees the others again');
    }

    public function test_the_spec_template_action_still_replaces_every_row_and_sets_no_stars(): void
    {
        $starred = $this->sixSpecs();
        $starred[0]['highlight'] = true;
        $service = $this->rentalService(['metadata' => ['specs' => $starred]]);

        $page = $this->openEdit($service);
        $page->callAction(\Filament\Actions\Testing\TestAction::make('loadSpecTemplate')->schemaComponent('specyfikacja-techniczna::data::section'));
        $this->saveForm($page)->assertHasNoFormErrors();

        foreach ($this->reload($service)->metadata['specs'] as $row) {
            $this->assertArrayNotHasKey('highlight', $row);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Form: the star column, the counter, the hint
    // ---------------------------------------------------------------------------------------------

    public function test_the_spec_table_has_a_fourth_column_for_the_star(): void
    {
        $page = $this->openEdit($this->rentalService(['metadata' => ['specs' => $this->sixSpecs()]]));

        $page->assertFormFieldExists('metadata.specs', function (Repeater $specs): bool {
            $this->assertSame(
                ['Parametr', 'Wartość', 'Jednostka', 'Na kafelku'],
                array_map(fn ($column): string => (string) $column->getLabel(), $specs->getTableColumns()),
            );
            $this->assertSame(
                ['label', 'value', 'unit', 'highlight'],
                array_map(fn ($field): string => $field->getName(), $specs->getChildSchema()->getComponents()),
            );

            return true;
        });
    }

    public function test_each_star_names_its_parameter_for_screen_readers(): void
    {
        $page = $this->openEdit($this->rentalService(['metadata' => ['specs' => $this->sixSpecs()]]));
        $keys = $this->rowKeys($page);

        $page->assertFormFieldExists("metadata.specs.{$keys[0]}.highlight", function (Toggle $toggle): bool {
            $this->assertSame('Pokaż „Moc” na kafelku', (string) $toggle->getLabel());
            $this->assertSame(Heroicon::Star, $toggle->getOnIcon(), 'a star on the knob is the closest native look');
            $this->assertSame(Heroicon::OutlinedStar, $toggle->getOffIcon());

            return true;
        });
        $this->assertStringContainsString('Pokaż „Rodzaj paliwa” na kafelku', $page->html());
    }

    public function test_the_section_header_carries_a_live_counter_and_the_repeater_a_hint(): void
    {
        $page = $this->openEdit($this->rentalService(['metadata' => ['specs' => $this->sixSpecs()]]));
        $html = $page->html();

        // The explanation sits on the repeater's hint icon (a TableColumn has no tooltip); its text travels JS-escaped in the page.
        $page->assertFormFieldExists('metadata.specs', function (Repeater $specs): bool {
            $this->assertSame(Heroicon::InformationCircle, $specs->getHintIcon());
            $this->assertSame(
                'Zaznaczone parametry (maks. 4) pokazują się z ✓ na kafelku produktu: na liście wypożyczalni, w kategoriach i w blokach „Siatka treści”. Tabela na stronie produktu pokazuje zawsze wszystkie parametry.',
                $specs->getHintIconTooltip(),
            );

            return true;
        });

        // Client-side (no round-trip): counts the starred rows in the entangled state (the rest of the literal is JS-escaped in the page).
        $this->assertStringContainsString('row?.highlight === true', $html);

        $this->assertStringContainsString('Wyświetlane na stronie produktowej jako tabela specyfikacji', $html, 'the existing helper text stays');
    }

    // ---------------------------------------------------------------------------------------------
    // Form: where `features` lives, per type
    // ---------------------------------------------------------------------------------------------

    /** @return array<int, Section> */
    private function sectionsOf(Testable $page, string $tabLabel): array
    {
        $tab = collect($page->instance()->getSchema('form')->getFlatComponents(withHidden: true))
            ->first(fn ($component): bool => $component instanceof Tab && (string) $component->getLabel() === $tabLabel);
        $this->assertNotNull($tab, "tab `{$tabLabel}` not found");

        return array_values(array_filter(
            $tab->getChildSchema()->getComponents(withHidden: true),
            fn ($component): bool => $component instanceof Section,
        ));
    }

    /** @return array<int, string> headings of the sections of a tab that are visible right now */
    private function visibleHeadings(Testable $page, string $tabLabel): array
    {
        return array_values(array_map(
            fn (Section $section): string => (string) $section->getHeading(),
            array_filter($this->sectionsOf($page, $tabLabel), fn (Section $section): bool => $section->isVisible()),
        ));
    }

    public function test_a_rental_edits_features_in_specyfikacja_as_dodatkowe_punkty_na_kafelku(): void
    {
        $page = $this->openEdit($this->rentalService(['features' => ['Cicha praca']]));

        $this->assertSame(['Specyfikacja techniczna', 'Dodatkowe punkty na kafelku'], $this->visibleHeadings($page, 'Specyfikacja'));
        $this->assertSame(
            ['Krótki opis', 'Opis produktu', 'Zaawansowane bloki (0)'],
            $this->visibleHeadings($page, 'Treść'),
            '"Co zawiera usługa" is no longer in Treść for a rental',
        );

        $section = collect($this->sectionsOf($page, 'Specyfikacja'))->first(fn (Section $s): bool => (string) $s->getHeading() === 'Dodatkowe punkty na kafelku');
        $this->assertSame(
            'Dla cech, które nie są parametrem, np. „Cicha praca”. Kafelek dobiera je po wyróżnionych parametrach, razem maks. 4.',
            (string) $section->getDescription(),
        );

        $this->assertStringContainsString('Dodatkowe punkty na kafelku', $page->html());
        $this->assertStringContainsString('Cicha praca', json_encode($page->get('data.features'), JSON_UNESCAPED_UNICODE), 'the stored feature is in the form');
    }

    public function test_a_time_slot_keeps_features_in_tresc_as_co_zawiera_usluge(): void
    {
        $page = $this->openEdit($this->timeSlotService(['features' => ['Mycie', 'Woskowanie']]));

        $this->assertSame(
            ['Krótki opis', 'Opis produktu', 'Zaawansowane bloki (0)', 'Co zawiera usługa'],
            $this->visibleHeadings($page, 'Treść'),
        );
        $this->assertSame([], $this->visibleHeadings($page, 'Specyfikacja'), 'the whole Specyfikacja tab is rental-only');
        $this->assertStringNotContainsString('Dodatkowe punkty na kafelku', $page->html());

        $section = collect($this->sectionsOf($page, 'Treść'))->first(fn (Section $s): bool => (string) $s->getHeading() === 'Co zawiera usługa');
        $this->assertSame('Do 4 punktów z ✓ na kafelku produktu na liście.', (string) $section->getDescription());
    }

    public function test_features_round_trip_unchanged_for_both_types(): void
    {
        $rental = $this->rentalService(['features' => ['Walizka', 'Ładowarka', 'Cicha praca']]);
        $slot = $this->timeSlotService(['features' => ['Mycie', 'Woskowanie']]);
        $rentalBefore = $this->snapshot($rental);
        $slotBefore = $this->snapshot($slot);

        $this->saveUnchanged($rental);
        $this->saveUnchanged($slot);

        $this->assertSame($rentalBefore, $this->snapshot($rental));
        $this->assertSame($slotBefore, $this->snapshot($slot));
        $this->assertSame(['Walizka', 'Ładowarka', 'Cicha praca'], $this->reload($rental)->features);
        $this->assertSame(['Mycie', 'Woskowanie'], $this->reload($slot)->features);
    }

    public function test_features_edited_in_their_new_place_are_saved_for_both_types(): void
    {
        $rental = $this->rentalService(['features' => ['Stara']]);
        $slot = $this->timeSlotService(['features' => ['Stara']]);

        $this->saveForm($this->openEdit($rental)->fillForm(['features' => [['feature' => 'Nowa 1'], ['feature' => 'Nowa 2']]]))->assertHasNoFormErrors();
        $this->saveForm($this->openEdit($slot)->fillForm(['features' => [['feature' => 'Nowa 1'], ['feature' => 'Nowa 2']]]))->assertHasNoFormErrors();

        $this->assertSame(['Nowa 1', 'Nowa 2'], $this->reload($rental)->features);
        $this->assertSame(['Nowa 1', 'Nowa 2'], $this->reload($slot)->features);
    }

    public function test_the_features_rules_are_the_same_for_a_rental_in_its_new_place(): void
    {
        $rental = $this->rentalService(['features' => ['a']]);
        $feature = fn (string $text): array => ['feature' => $text];

        $tooMany = $this->openEdit($rental)->set('data.features', array_map($feature, ['a', 'b', 'c', 'd', 'e']))->call('save');
        $this->assertTrue($this->hasErrorOn($tooMany, 'data.features'), 'at most 4');

        $tooLong = $this->openEdit($rental)->set('data.features', [$feature(str_repeat('a', 101))])->call('save');
        $this->assertTrue($this->hasErrorOn($tooLong, 'data.features'), 'at most 100 characters');

        $blank = $this->openEdit($rental)->set('data.features', [$feature('')])->call('save');
        $this->assertTrue($this->hasErrorOn($blank, 'data.features'), 'required');

        $this->assertSame(['a'], $this->reload($rental)->features, 'nothing was saved');
    }

    // ---------------------------------------------------------------------------------------------
    // Tile
    // ---------------------------------------------------------------------------------------------

    public function test_the_tile_prints_the_starred_rows_first_then_the_features(): void
    {
        $service = $this->rentalService([
            'metadata' => ['specs' => $this->sixSpecs()],
            'features' => ['Walizka w komplecie', 'Cicha praca'],
        ]);

        $this->saveForm($this->star($this->openEdit($service), [0, 2, 4]))->assertHasNoFormErrors();

        $this->assertSame(
            ['Moc: 800 W', 'Rodzaj paliwa: brak', 'Ciśnienie: 21 bar', 'Walizka w komplecie'],
            $this->tileTexts($service),
            'label: value unit (unit left out when empty), 4 points in all',
        );
    }

    public function test_a_rental_with_nothing_starred_and_a_time_slot_render_only_their_features(): void
    {
        $rental = $this->rentalService(['metadata' => ['specs' => $this->sixSpecs()], 'features' => ['Walizka', 'Ładowarka']]);
        $slot = $this->timeSlotService(['features' => ['Mycie', 'Woskowanie']]);

        $this->assertSame(['Walizka', 'Ładowarka'], $this->tileTexts($rental));
        $this->assertSame(['Mycie', 'Woskowanie'], $this->tileTexts($slot));
    }

    public function test_the_tile_keeps_its_markup_and_hides_the_list_when_there_is_nothing_to_show(): void
    {
        $withPoints = $this->rentalService(['features' => ['Walizka']]);
        $html = Blade::render('<x-ios.service-card :service="$service" />', ['service' => $this->reload($withPoints)]);
        $this->assertStringContainsString('<li class="service-card__feature flex items-start gap-2 text-xs md:text-sm', $html);
        $this->assertStringContainsString('service-card__feature-icon', $html);

        $empty = $this->rentalService(['slug' => 'pusty', 'name' => 'Pusty', 'features' => null]);
        $this->assertStringNotContainsString('service-card__features', Blade::render('<x-ios.service-card :service="$service" />', ['service' => $this->reload($empty)]));
    }

    public function test_a_time_slot_never_shows_specs_on_its_tile(): void
    {
        $slot = $this->timeSlotService([
            'features' => ['Mycie'],
            'metadata' => ['specs' => [['label' => 'Moc', 'value' => '800', 'unit' => 'W', 'highlight' => true]]],
        ]);

        $this->assertSame(['Mycie'], $this->tileTexts($slot));
    }

    public function test_a_starred_label_and_value_are_escaped_in_the_tile_html(): void
    {
        $rental = $this->rentalService(['metadata' => ['specs' => [
            ['label' => '<script>alert(1)</script>', 'value' => '<img src=x onerror=alert(2)>', 'unit' => '<b>u</b>', 'highlight' => true],
        ]]]);

        $html = Blade::render('<x-ios.service-card :service="$service" />', ['service' => $this->reload($rental)]);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>u</b>', $html);
    }

    // ---------------------------------------------------------------------------------------------
    // `features` has two homes (two repeaters, one state path): it must come through every path as exact strings
    // ---------------------------------------------------------------------------------------------

    private function createdBySlug(string $slug): Service
    {
        return Service::withoutGlobalScopes()->where('slug', $slug)->firstOrFail();
    }

    /** @return array<int, array{feature: string}> */
    private function rows(string ...$texts): array
    {
        return array_map(fn (string $text): array => ['feature' => $text], $texts);
    }

    public function test_stored_features_with_gaps_open_showing_every_value_and_a_no_op_save_keeps_them(): void
    {
        foreach (['rental' => fn (): Service => $this->rentalService(), 'time_slot' => fn (): Service => $this->timeSlotService()] as $type => $make) {
            $service = $make();
            // A dict-shaped value, as an importer / API / old save could leave it: not a list, and not yet item rows.
            \Illuminate\Support\Facades\DB::table('services')->where('id', $service->id)->update(['features' => '{"0":"a","2":"b"}']);
            $this->assertSame('{"0":"a","2":"b"}', $this->reload($service)->getRawOriginal('features'));

            $page = $this->openEdit($service);
            $this->assertSame(['a', 'b'], array_values(array_column($page->get('data.features'), 'feature')), "{$type}: both values open as rows");

            $this->saveForm($page)->assertHasNoFormErrors();

            $this->assertSame(['a', 'b'], $this->reload($service)->features, "{$type}: no value lost");
            // What the old single repeater did too: a save rewrites the dict as a plain list.
            $this->assertSame('["a","b"]', $this->reload($service)->getRawOriginal('features'), "{$type}: stored back as a list");
        }
    }

    public function test_creating_a_rental_and_a_time_slot_with_features_stores_the_exact_strings(): void
    {
        $this->addLocation();

        Livewire::test(CreateService::class)
            ->fillForm([
                'service_type' => ServiceType::ItemRental->value,
                'name' => 'Młot',
                'slug' => 'mlot',
                'price_per_day' => 5,
                'quantity_total' => 4,
                'features' => $this->rows('Walizka', 'Cicha praca'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateService::class)
            ->fillForm([
                'service_type' => ServiceType::TimeSlot->value,
                'name' => 'Mycie',
                'slug' => 'mycie',
                'price' => 100,
                'features' => $this->rows('Woskowanie', 'Odkurzanie'),
            ])
            ->set('data.duration_hours', 1)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['Walizka', 'Cicha praca'], $this->createdBySlug('mlot')->features);
        $this->assertSame(['Woskowanie', 'Odkurzanie'], $this->createdBySlug('mycie')->features);
    }

    public function test_switching_the_type_on_the_create_form_neither_loses_nor_duplicates_features(): void
    {
        $this->addLocation();

        // time_slot -> item_rental -> time_slot, saved as a time slot.
        $page = Livewire::test(CreateService::class)
            ->fillForm(['name' => 'Mycie', 'slug' => 'mycie', 'price' => 100, 'features' => $this->rows('Woskowanie', 'Odkurzanie')])
            ->set('data.service_type', ServiceType::ItemRental->value)
            ->set('data.service_type', ServiceType::TimeSlot->value);
        $this->assertCount(2, $page->get('data.features'), 'the switch must not duplicate or drop rows');
        $page->set('data.duration_hours', 1)->call('create')->assertHasNoFormErrors();
        $this->assertSame(['Woskowanie', 'Odkurzanie'], $this->createdBySlug('mycie')->features);

        // time_slot -> item_rental, saved as a rental (the other repeater is the visible one now).
        $page = Livewire::test(CreateService::class)
            ->fillForm(['name' => 'Młot', 'slug' => 'mlot', 'features' => $this->rows('Walizka', 'Cicha praca')])
            ->set('data.service_type', ServiceType::ItemRental->value)
            ->fillForm(['price_per_day' => 5, 'quantity_total' => 4]);
        $this->assertCount(2, $page->get('data.features'));
        $page->call('create')->assertHasNoFormErrors();
        $this->assertSame(['Walizka', 'Cicha praca'], $this->createdBySlug('mlot')->features);
    }

    public function test_the_features_add_button_reads_dodaj_punkt_for_both_types(): void
    {
        foreach (['rental' => fn (): Service => $this->rentalService(), 'time_slot' => fn (): Service => $this->timeSlotService()] as $type => $make) {
            $this->openEdit($make())->assertFormFieldExists('features', function (Repeater $features) use ($type): bool {
                $this->assertSame('Dodaj punkt', (string) $features->getAddActionLabel(), "{$type}: add button label");

                return true;
            });
        }
    }

    public function test_the_add_action_of_the_features_repeater_works_for_both_types(): void
    {
        foreach (['rental' => fn (): Service => $this->rentalService(), 'time_slot' => fn (): Service => $this->timeSlotService()] as $type => $make) {
            $service = $make();
            $service->forceFill(['features' => ['A']])->saveQuietly();

            $page = $this->openEdit($service);
            $this->assertCount(1, $page->get('data.features'), "{$type}: opens with one row");

            $page->callAction(TestAction::make('add')->schemaComponent('features'));
            $rows = $page->get('data.features');
            $this->assertCount(2, $rows, "{$type}: `add` appended exactly one row");

            $newKey = array_key_last($rows);
            $page->set("data.features.{$newKey}.feature", 'B');
            $this->saveForm($page)->assertHasNoFormErrors();

            $this->assertSame(['A', 'B'], $this->reload($service)->features, "{$type}: strings, in order");
        }
    }
}
