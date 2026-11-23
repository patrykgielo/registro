<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ServiceType;
use App\Filament\Resources\ServiceResource\Pages\CreateService;
use DOMDocument;
use DOMXPath;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * STAGE 2 of the product form redesign: the ServiceResource form is split into five tabs. LAYOUT ONLY - what
 * each field saves is pinned by ServiceForm{Edit,Create}CharacterizationTest, which stay unmodified. These
 * tests pin the layout itself: which tabs exist, which hide for a time_slot, that no field escaped the tabs
 * or sits in two of them, and what the form does on a validation error in a tab the user is not looking at.
 *
 * Slow tier: skipped by the post-agent hook's fast scope; CI runs it. List: Tests\Unit\SlowGroupIsDeclaredTest.
 */
#[Group('slow')]
class ServiceFormTabsTest extends TestCase
{
    use RefreshDatabase, ServiceFormTestSupport;

    /** Every tab the form defines (stage A: a time_slot-only price tab right after Podstawowe). */
    private const ALL_TABS = ['Podstawowe', 'Cena i czas trwania', 'Cena i magazyn', 'Specyfikacja', 'Treść', 'SEO i konwersja'];

    private const RENTAL_TABS = ['Podstawowe', 'Cena i magazyn', 'Specyfikacja', 'Treść', 'SEO i konwersja'];

    private const TIME_SLOT_TABS = ['Podstawowe', 'Cena i czas trwania', 'Treść', 'SEO i konwersja'];

    /** Section headings per tab - the approved mapping. */
    private const SECTIONS_BY_TAB = [
        'Podstawowe' => ['Podstawowe informacje', 'Zdjęcie wyróżniające'],
        'Cena i czas trwania' => ['Cena i czas trwania'],
        'Cena i magazyn' => ['Cennik wypożyczenia', 'Magazyn i identyfikacja'],
        // Stage C: the rental's features ("Dodatkowe punkty na kafelku") moved here.
        'Specyfikacja' => ['Specyfikacja techniczna', 'Dodatkowe punkty na kafelku'],
        // Stage 3: renamed per the approved mockup; the fixture rental has no blocks, hence "(0)".
        // Stage A: Krótki opis and Co zawiera usługa moved here.
        'Treść' => ['Krótki opis', 'Opis produktu', 'Zaawansowane bloki (0)', 'Co zawiera usługa'],
        'SEO i konwersja' => ['SEO i publikacja', 'Optymalizacja konwersji'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpServiceFormTenant();
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    private function tabs(Testable $component): Tabs
    {
        $root = $component->instance()->getSchema('form')->getComponents(withHidden: true);

        $this->assertCount(1, $root, 'the form must be ONE Tabs component - nothing may sit beside it');
        $this->assertInstanceOf(Tabs::class, $root[0]);

        return $root[0];
    }

    /**
     * @return array<int, string> labels of the tabs the form would render right now, in order
     */
    private function visibleTabLabels(Testable $component): array
    {
        return array_map(
            fn (Tab $tab): string => (string) $tab->getLabel(),
            $this->tabs($component)->getChildSchema()->getComponents(withHidden: false),
        );
    }

    /**
     * @return array<string, Tab> every tab, hidden or not, by label
     */
    private function allTabs(Testable $component): array
    {
        $tabs = [];

        foreach ($this->tabs($component)->getChildSchema()->getComponents(withHidden: true) as $tab) {
            $this->assertInstanceOf(Tab::class, $tab);
            $tabs[(string) $tab->getLabel()] = $tab;
        }

        return $tabs;
    }

    /**
     * Field names inside one tab, as the characterization support names them: top-level fields only
     * (`metadata.specs`, not the rows inside the repeater), hidden or not.
     *
     * @return array<int, string>
     */
    private function fieldNamesIn(Tab $tab): array
    {
        $names = [];

        foreach ($tab->getChildSchema()->getFlatFields(withHidden: true, withAbsoluteKeys: true) as $key => $field) {
            $name = preg_replace('/^form\./', '', (string) $key);

            if (preg_match('/\.[0-9a-f]{8}-[0-9a-f]{4}-/', $name)) {
                continue; // a field inside one repeater row / builder block
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * @return array<int, string> headings of the Sections directly inside a tab
     */
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

    /** @return array<int, string> ids of the tab PANELS (not the tab buttons) that contain a field with a validation error */
    private function tabPanelsWithErrors(Testable $component): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><body>'.$component->html().'</body>');
        libxml_clear_errors();

        $ids = [];

        foreach ((new DOMXPath($dom))->query("//*[@role='tabpanel'][.//*[@data-validation-error]]") as $panel) {
            $ids[] = $panel->getAttribute('id');
        }

        return $ids;
    }

    // ---------------------------------------------------------------------------------------------
    // Which tabs exist
    // ---------------------------------------------------------------------------------------------

    public function test_a_rental_form_has_exactly_the_five_tabs_in_order(): void
    {
        $component = $this->openEdit($this->rentalService());

        $this->assertSame(self::RENTAL_TABS, $this->visibleTabLabels($component));
        $this->assertSame(self::ALL_TABS, array_keys($this->allTabs($component)), 'a hidden tab must still be defined in the same place');
    }

    public function test_a_time_slot_form_hides_the_two_rental_only_tabs(): void
    {
        $component = $this->openEdit($this->timeSlotService());

        $this->assertSame(self::TIME_SLOT_TABS, $this->visibleTabLabels($component));

        $hidden = array_filter($this->allTabs($component), fn (Tab $tab): bool => $tab->isHidden());
        $this->assertSame(['Cena i magazyn', 'Specyfikacja'], array_keys($hidden));
    }

    public function test_a_hidden_tab_hides_exactly_what_its_sections_hide_themselves(): void
    {
        // The tab's own condition must mirror the sections': it may never hide a tab that still has a visible
        // section (a field the user could need), nor leave a tab visible whose sections are all hidden.
        foreach ([$this->rentalService(), $this->timeSlotService()] as $service) {
            $component = $this->openEdit($service);

            foreach ($this->allTabs($component) as $label => $tab) {
                $visibleSections = array_filter(
                    $tab->getChildSchema()->getComponents(withHidden: false),
                    fn ($component): bool => $component instanceof Section,
                );

                $this->assertSame(
                    $tab->isVisible(),
                    $visibleSections !== [],
                    "tab `{$label}` of a {$service->service_type->value}: tab visibility and 'has a visible section' disagree",
                );
            }
        }
    }

    public function test_the_create_form_adds_the_rental_tabs_when_the_type_is_switched(): void
    {
        $component = Livewire::test(CreateService::class);

        $this->assertSame(self::TIME_SLOT_TABS, $this->visibleTabLabels($component), 'default type is time_slot');

        $component->set('data.service_type', ServiceType::ItemRental->value);
        $this->assertSame(self::RENTAL_TABS, $this->visibleTabLabels($component));

        $component->set('data.service_type', ServiceType::TimeSlot->value);
        $this->assertSame(self::TIME_SLOT_TABS, $this->visibleTabLabels($component));
    }

    // ---------------------------------------------------------------------------------------------
    // Where every field sits
    // ---------------------------------------------------------------------------------------------

    public function test_every_field_of_the_form_sits_in_exactly_one_tab(): void
    {
        $inventory = $this->formFieldInventory()['all'];
        $this->assertNotEmpty($inventory);

        $component = $this->openEdit($this->rentalService());
        $owners = [];

        foreach ($this->allTabs($component) as $label => $tab) {
            foreach ($this->fieldNamesIn($tab) as $name) {
                $owners[$name][] = $label;
            }
        }

        foreach ($inventory as $name) {
            $this->assertArrayHasKey($name, $owners, "field `{$name}` is in no tab");
            // Stage C: `features` is deliberately in two tabs (Specyfikacja for a rental, Treść for a
            // time slot); their visibility is mutually exclusive, so only one is ever shown and saved.
            $this->assertCount($name === 'features' ? 2 : 1, $owners[$name], "field `{$name}` is in several tabs: ".implode(', ', $owners[$name]));
        }

        $this->assertSame(['Specyfikacja', 'Treść'], $owners['features'], 'features: rental copy in Specyfikacja, time slot copy in Treść');

        $this->assertEqualsCanonicalizing($inventory, array_keys($owners), 'the tabs hold a field the form inventory does not know about');

        // Nothing sits beside the Tabs component (this is what `tabs()` asserts), and the root schema's own
        // field list is exactly the union of the tabs'.
        $rootNames = array_map(
            fn (string $key): string => preg_replace('/^form\./', '', $key),
            array_keys($component->instance()->getSchema('form')->getFlatFields(withHidden: true, withAbsoluteKeys: true)),
        );
        $rootNames = array_filter($rootNames, fn (string $name): bool => ! preg_match('/\.[0-9a-f]{8}-[0-9a-f]{4}-/', $name));

        $this->assertEqualsCanonicalizing(array_keys($owners), array_values($rootNames));
    }

    public function test_the_sections_sit_in_the_approved_tabs(): void
    {
        $tabs = $this->allTabs($this->openEdit($this->rentalService()));

        foreach (self::SECTIONS_BY_TAB as $label => $headings) {
            $this->assertArrayHasKey($label, $tabs);
            $this->assertSame($headings, $this->sectionHeadingsIn($tabs[$label]), "sections of tab `{$label}`");
        }
    }

    public function test_spot_checked_fields_sit_in_their_tabs(): void
    {
        $tabs = $this->allTabs($this->openEdit($this->rentalService()));

        $expected = [
            'Podstawowe' => ['service_type', 'name', 'slug', 'is_active', 'featured_image', 'hero_overlay_color'],
            'Cena i czas trwania' => ['price', 'price_from', 'duration_days', 'duration_hours', 'duration_mins'],
            'Cena i magazyn' => ['price_per_day', 'price_per_day_long', 'deposit_amount', 'price_on_request', 'quantity_total', 'rental_category_id', 'brand'],
            'Specyfikacja' => ['metadata.specs'],
            'Treść' => ['excerpt', 'body', 'content', 'features'],
            'SEO i konwersja' => ['meta_title', 'published_at', 'average_rating', 'is_popular'],
        ];

        foreach ($expected as $label => $fields) {
            $actual = $this->fieldNamesIn($tabs[$label]);

            foreach ($fields as $field) {
                $this->assertContains($field, $actual, "`{$field}` should be in tab `{$label}`");
            }
        }

        $this->assertSame(['metadata.specs', 'features'], $this->fieldNamesIn($tabs['Specyfikacja']), 'the specification tab holds the specs repeater plus the rental\'s features');
    }

    // ---------------------------------------------------------------------------------------------
    // Rule 3: an error in a tab the user is not looking at
    // ---------------------------------------------------------------------------------------------

    /**
     * Filament 4.11.5 already handles this in the browser: on a failed save the server dispatches
     * `form-validation-error`, the root schema's `handleFormValidationError` finds the FIRST
     * `[data-validation-error]` in the DOM and fires an `expand` event on it and each ancestor, and every tab
     * panel answers `x-on:expand` with `tab = <its key>`. JavaScript cannot run here, so the three server-side
     * halves of that contract are pinned instead: the event is dispatched, the error marker is rendered INSIDE
     * the right panel (and only that one), and the panel/root carry the listeners.
     */
    public function test_a_validation_error_in_an_inactive_tab_is_rendered_inside_that_tab_and_signalled(): void
    {
        $service = $this->rentalService();

        $component = $this->openEdit($service)
            ->set('data.meta_title', str_repeat('x', 61)) // `SEO i konwersja`, max 60
            ->call('save')
            ->assertHasFormErrors(['meta_title'])
            ->assertDispatched('form-validation-error');

        $tabs = $this->allTabs($component);
        $seo = $tabs['SEO i konwersja']->getId();

        $this->assertSame([$seo], $this->tabPanelsWithErrors($component), 'the error must sit in the SEO panel and in no other');

        $html = $component->html();
        $this->assertStringContainsString('handleFormValidationError', $html, 'root schema listener for form-validation-error');
        $this->assertSame(
            count($this->visibleTabLabels($component)), // a hidden tab renders no panel
            substr_count($html, 'x-on:expand="tab ='),
            'every rendered tab panel must answer the `expand` event by activating itself',
        );
    }

    public function test_errors_in_two_tabs_are_each_rendered_in_their_own_panel(): void
    {
        $component = $this->openEdit($this->rentalService())
            ->set('data.sort_order', '')                         // Podstawowe (required)
            ->set('data.price_per_day', '')                      // Cena i magazyn (required)
            ->set('data.meta_description', str_repeat('x', 161)) // SEO i konwersja (max 160)
            ->call('save')
            ->assertHasFormErrors(['sort_order', 'price_per_day', 'meta_description']);

        $tabs = $this->allTabs($component);

        $this->assertEqualsCanonicalizing(
            [$tabs['Podstawowe']->getId(), $tabs['Cena i magazyn']->getId(), $tabs['SEO i konwersja']->getId()],
            $this->tabPanelsWithErrors($component),
        );
    }

    public function test_a_failed_save_in_a_hidden_tab_cannot_happen_for_a_time_slot(): void
    {
        // A time_slot never validates the rental-only fields (the tab is hidden, and Filament skips hidden
        // components entirely), so a rental-only rule can never block a time_slot save from a tab the user
        // cannot even reach: `price_per_day` is `required` for rentals.
        $component = $this->openEdit($this->timeSlotService())
            ->set('data.price_per_day', '')
            ->call('save');

        $this->assertFalse($this->hasErrorOn($component, 'data.price_per_day'));
    }

    public function test_the_active_tab_is_persisted_in_the_query_string(): void
    {
        $tabs = $this->tabs($this->openEdit($this->rentalService()));

        $this->assertTrue($tabs->isTabPersistedInQueryString());
        $this->assertSame('tab', $tabs->getTabQueryStringKey());
    }
}
