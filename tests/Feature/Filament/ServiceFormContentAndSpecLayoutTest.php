<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Support\BuilderBlockSummary;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Tests\TestCase;

/**
 * STAGE 3 of the product form redesign: the `Specyfikacja` tab becomes a compact table of rows, the `Treść`
 * tab gets a titled editor section and an always-open "Zaawansowane bloki" section with a block-count heading
 * and collapsed, reorderable, summarised block items. PRESENTATION ONLY - what is saved is pinned by
 * ServiceForm{Edit,Create}CharacterizationTest; the round-trips here only prove that the new presentation
 * (table mode, label closures, move buttons) hands the same data to the save.
 */
class ServiceFormContentAndSpecLayoutTest extends TestCase
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

    /** The mounted form component bound to `$name` (Filament's own `assertFormFieldExists` hands it to a closure). */
    private function field(Testable $page, string $name): object
    {
        $found = null;

        $page->assertFormFieldExists($name, function (object $field) use (&$found): bool {
            $found = $field;

            return true;
        });

        return $found;
    }

    private function contentSection(Testable $page): Section
    {
        $tab = collect($page->instance()->getSchema('form')->getFlatComponents(withHidden: true))
            ->first(fn ($component): bool => $component instanceof Tab && (string) $component->getLabel() === 'Treść');
        $this->assertNotNull($tab, 'tab `Treść` not found');

        $sections = array_values(array_filter(
            $tab->getChildSchema()->getComponents(withHidden: true),
            fn ($component): bool => $component instanceof Section,
        ));
        // Stage A: Krótki opis, Opis produktu, Zaawansowane bloki, Co zawiera usługa.
        $this->assertCount(4, $sections, 'tab `Treść` holds four sections');

        return $sections[2];
    }

    /** @return array<int, array{type: string, data: array<string, mixed>}> */
    private function threeBlocks(): array
    {
        return [
            ['type' => 'text_block', 'data' => ['content' => '<p>Pierwszy <strong>akapit</strong> opisu.</p>']],
            ['type' => 'quote', 'data' => ['quote' => 'Najlepsza wiertarka w mieście', 'author' => 'Jan']],
            ['type' => 'service_features', 'data' => ['layout' => 'simple']],
        ];
    }

    /** @return array<int, array{label: string, value: string, unit: ?string}> */
    private function threeSpecs(): array
    {
        return [
            ['label' => 'Moc', 'value' => '800', 'unit' => 'W'],
            ['label' => 'Waga', 'value' => '2,4', 'unit' => 'kg'],
            ['label' => 'Rodzaj paliwa', 'value' => 'brak', 'unit' => null],
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Specyfikacja: compact table rows
    // ---------------------------------------------------------------------------------------------

    public function test_the_spec_repeater_is_a_four_column_table_that_is_not_collapsible(): void
    {
        /** @var Repeater $specs */
        $specs = $this->field($this->openEdit($this->rentalService()), 'metadata.specs');

        $this->assertInstanceOf(Repeater::class, $specs);
        $this->assertTrue($specs->isTable());
        $this->assertSame(
            ['Parametr', 'Wartość', 'Jednostka', 'Na kafelku'],
            array_map(fn ($column): string => (string) $column->getLabel(), $specs->getTableColumns()),
            'column headings, in order (stage C added "Na kafelku")',
        );
        $this->assertSame(
            ['label', 'value', 'unit', 'highlight'],
            array_map(fn ($field): string => $field->getName(), $specs->getChildSchema()->getComponents()),
            'the table columns line up with the same four fields, same order',
        );
        $this->assertFalse($specs->isCollapsible(), 'no collapse/expand');
        $this->assertFalse($specs->hasItemLabels(), 'no item label');
        $this->assertTrue($specs->isReorderable());
        $this->assertTrue($specs->isReorderableWithDragAndDrop());
        $this->assertSame('Dodaj parametr', $specs->getAddActionLabel());
        $this->assertSame('filament-forms::components.repeater.table', $specs->getDefaultView());
    }

    public function test_the_spec_rows_render_as_a_table_with_headings_once_and_no_item_headers(): void
    {
        $html = $this->openEdit($this->rentalService(['metadata' => ['specs' => $this->threeSpecs()]]))->html();

        $this->assertStringContainsString('fi-fo-table-repeater', $html, 'rendered as a table repeater');
        $this->assertStringNotContainsString('fi-fo-repeater-item-header', $html, 'no per-item header');

        $withoutComments = str_replace(['<!--[if BLOCK]><![endif]-->', '<!--[if ENDBLOCK]><![endif]-->'], '', $html); // Livewire's block markers
        foreach (['Parametr', 'Wartość', 'Jednostka'] as $heading) {
            $this->assertSame(1, preg_match_all('#<th\b[^>]*>\s*'.preg_quote($heading, '#').'(?![\p{L}])#u', $withoutComments), "heading `{$heading}` appears once, in the table head");
        }

        $this->assertSame(3, substr_count($html, 'placeholder="np. W"'), 'one unit input per row keeps its placeholder');
        $this->assertSame(3, substr_count($html, 'placeholder="np. Moc"'));
        $this->assertSame(3, substr_count($html, 'placeholder="np. 800"'));
        $this->assertStringContainsString('Dodaj parametr', $html);
    }

    public function test_the_spec_section_keeps_its_heading_helper_text_and_template_action(): void
    {
        $page = $this->openEdit($this->rentalService());

        $html = $page->html();
        $this->assertStringContainsString('Specyfikacja techniczna', $html);
        $this->assertStringContainsString('Wyświetlane na stronie produktowej jako tabela specyfikacji', $html);
        $this->assertStringContainsString('Wstaw z szablonu branżowego', $html);

        // Same action key the characterization test calls (ServiceFormEditCharacterizationTest::test_e3_the_spec_template_...).
        $page->assertActionExists(TestAction::make('loadSpecTemplate')->schemaComponent('specyfikacja-techniczna::data::section'));
    }

    public function test_spec_rows_round_trip_unchanged_through_the_table_layout(): void
    {
        $service = $this->rentalService(['metadata' => ['specs' => $this->threeSpecs()]]);

        $this->saveUnchanged($service);

        $this->assertSame($this->threeSpecs(), $this->reload($service)->metadata['specs']);
    }

    public function test_spec_rows_can_be_reordered_and_saved_in_the_new_order(): void
    {
        $service = $this->rentalService(['metadata' => ['specs' => $this->threeSpecs()]]);

        $page = $this->openEdit($service);
        $keys = array_keys($page->get('data.metadata.specs'));
        $this->assertCount(3, $keys);

        $page->callAction(
            TestAction::make('reorder')->schemaComponent('metadata.specs')->arguments(['items' => array_reverse($keys)]),
        );
        $this->saveForm($page)->assertHasNoFormErrors();

        $this->assertSame(array_reverse($this->threeSpecs()), $this->reload($service)->metadata['specs']);
    }

    public function test_the_spec_row_rules_still_apply_inside_the_table(): void
    {
        $page = $this->openEdit($this->rentalService())
            ->set('data.metadata.specs', [['label' => str_repeat('a', 101), 'value' => 'x', 'unit' => str_repeat('c', 21)]])
            ->call('save');

        $this->assertTrue($this->hasErrorOn($page, 'data.metadata.specs'));
        $this->assertCount(2, array_filter(array_keys($page->errors()->toArray()), fn (string $key): bool => str_starts_with($key, 'data.metadata.specs.')));
    }

    // ---------------------------------------------------------------------------------------------
    // Treść: editor section
    // ---------------------------------------------------------------------------------------------

    public function test_the_body_editor_sits_in_the_opis_produktu_section_and_keeps_its_height(): void
    {
        $page = $this->openEdit($this->rentalService());

        $tab = collect($page->instance()->getSchema('form')->getFlatComponents(withHidden: true))
            ->first(fn ($component): bool => $component instanceof Tab && (string) $component->getLabel() === 'Treść');
        // Stage A: "Krótki opis" comes first, the body editor section is second.
        $first = array_values(array_filter($tab->getChildSchema()->getComponents(withHidden: true), fn ($c): bool => $c instanceof Section))[1];

        $this->assertSame('Opis produktu', (string) $first->getHeading());
        $this->assertSame('Główny tekst strony produktu. Obrazy i galerie dodajesz blokami poniżej.', (string) $first->getDescription());
        $this->assertFalse($first->isCollapsed());

        /** @var RichEditor $body */
        $body = $this->field($page, 'body');
        $this->assertSame('min-height: 30rem;', $body->getExtraInputAttributeBag()->get('style'), 'editor min-height');
        $this->assertTrue($body->isLabelHidden(), 'the heading already names the field');
        $this->assertSame('Opis produktu', (string) $body->getLabel(), 'but the label stays for screen readers');

        $this->assertStringContainsString('min-height: 30rem;', $page->html());
    }

    // ---------------------------------------------------------------------------------------------
    // Treść: advanced blocks section
    // ---------------------------------------------------------------------------------------------

    public function test_the_blocks_section_is_open_and_its_heading_counts_the_blocks(): void
    {
        $empty = $this->openEdit($this->rentalService());
        $section = $this->contentSection($empty);

        $this->assertFalse($section->isCollapsed(), 'inside a tab, collapsing only hides content');
        $this->assertFalse($section->isCollapsible());
        $this->assertSame('Zaawansowane bloki (0)', (string) $section->getHeading());
        $this->assertSame('Wyświetlane pod opisem, w tej kolejności. Zapisują się razem z opisem.', (string) $section->getDescription());

        $page = $this->openEdit($this->rentalService(['slug' => 'z-blokami', 'name' => 'Wiertarka z blokami', 'content' => $this->threeBlocks()]));

        $this->assertSame('Zaawansowane bloki (3)', (string) $this->contentSection($page)->getHeading());
        $this->assertStringContainsString('Zaawansowane bloki (3)', $page->html());
    }

    public function test_the_heading_count_follows_the_builder_after_add_and_delete(): void
    {
        $page = $this->openEdit($this->rentalService(['content' => $this->threeBlocks()]));
        $this->assertStringContainsString('Zaawansowane bloki (3)', $page->html());

        $page->callAction(TestAction::make('add')->schemaComponent('content')->arguments(['block' => 'quote']));
        $this->assertCount(4, $page->get('data.content'));
        $this->assertStringContainsString('Zaawansowane bloki (4)', $page->html());

        $first = array_key_first($page->get('data.content'));
        $page->callAction(TestAction::make('delete')->schemaComponent('content')->arguments(['item' => $first]));
        $this->assertCount(3, $page->get('data.content'));
        $this->assertStringContainsString('Zaawansowane bloki (3)', $page->html());
    }

    public function test_the_section_heading_does_not_leak_into_field_names_or_keys(): void
    {
        $page = $this->openEdit($this->rentalService(['content' => $this->threeBlocks()]));
        $schema = $page->instance()->getSchema('form');

        $this->assertArrayHasKey('content', $schema->getFlatFields(withHidden: true));
        $this->assertSame('form.advanced-blocks-section', $this->contentSection($page)->getKey());
    }

    public function test_builder_items_are_collapsed_and_reorderable_with_buttons_and_a_block_grid_picker(): void
    {
        /** @var Builder $builder */
        $builder = $this->field($this->openEdit($this->rentalService()), 'content');

        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertTrue($builder->isCollapsible());
        $this->assertTrue($builder->isCollapsed(), 'items start collapsed');
        $this->assertTrue($builder->isReorderableWithButtons(), 'up/down buttons');
        $this->assertTrue($builder->isReorderableWithDragAndDrop(), 'drag still works next to the buttons');
        $this->assertTrue($builder->isDeletable());
        $this->assertTrue($builder->hasBlockLabels());
        $this->assertFalse($builder->hasBlockNumbers());
        $this->assertSame('Dodaj blok', $builder->getAddActionLabel());
        $this->assertSame(['default' => 1, 'sm' => 2, 'lg' => 3], $builder->getBlockPickerColumns());
        $this->assertFalse($builder->shouldPartiallyRenderAfterActionsCalled(), 'the section heading shows the count, so the whole form re-renders');
    }

    public function test_rendered_block_items_are_collapsed_and_show_type_plus_summary(): void
    {
        $page = $this->openEdit($this->rentalService(['content' => $this->threeBlocks()]));
        $html = $page->html();

        // Only the Builder's own items listen for `builder-expand` (a nested Repeater uses another event).
        $this->assertSame(3, preg_match_all('/isCollapsed:\s+true\s*,\s*\}"\s*x-on:builder-expand\.window/', $html), 'all three items start collapsed');
        $this->assertSame(0, preg_match_all('/isCollapsed:\s+false\s*,\s*\}"\s*x-on:builder-expand\.window/', $html), 'none starts open');
        $this->assertStringContainsString('Blok tekstowy — Pierwszy akapit opisu.', $html);
        $this->assertStringContainsString('Cytat — Najlepsza wiertarka w mieście', $html);
        $this->assertStringContainsString('fi-fo-builder-item-header-start-actions', $html, 'move buttons / handle');
    }

    public function test_block_labels_carry_a_type_and_a_summary_and_fall_back_to_the_type_alone(): void
    {
        /** @var Builder $builder */
        $builder = $this->field($this->openEdit($this->rentalService()), 'content');

        // With data: type + summary.
        $this->assertSame('Blok tekstowy — Tekst', $builder->getBlock('text_block')->getLabel(['content' => '<p>Tekst</p>']));
        $this->assertSame('Call to Action — Zadzwoń do nas', $builder->getBlock('cta')->getLabel(['heading' => 'Zadzwoń do nas', 'button_text' => 'x']));
        $this->assertSame('Galeria — 2 zdjęcia', $builder->getBlock('gallery')->getLabel(['images' => ['a.jpg', 'b.jpg']]));
        $this->assertSame('Cechy usługi (automatyczne) — Co zawiera usługa', $builder->getBlock('service_features')->getLabel(['heading' => 'Co zawiera usługa', 'layout' => 'grid']));

        // Empty data: the type alone, no dangling separator.
        $this->assertSame('Zdjęcie', $builder->getBlock('image')->getLabel([]));
        $this->assertSame('Hero Section', $builder->getBlock('hero')->getLabel(['title' => null, 'subtitle' => '']));

        // The block picker asks without state: plain type names.
        $this->assertSame('Blok tekstowy', $builder->getBlock('text_block')->getLabel());
        $this->assertSame('Zdjęcie', $builder->getBlock('image')->getLabel(null));
    }

    public function test_a_long_summary_is_truncated_and_html_and_whitespace_are_stripped(): void
    {
        $long = '<p>'.str_repeat('Bardzo długi tekst ', 20).'</p>';
        $summary = BuilderBlockSummary::summarize('text_block', ['content' => $long]);

        $this->assertNotNull($summary);
        $this->assertLessThanOrEqual(63, mb_strlen($summary), '60 characters plus the ellipsis');
        $this->assertStringEndsWith('...', $summary);
        $this->assertStringNotContainsString('<', $summary);

        $this->assertSame('a b', BuilderBlockSummary::summarize('text_block', ['content' => "<p>a</p>\n<p>  b </p>"]));
        $this->assertSame('5 zdjęć', BuilderBlockSummary::summarize('gallery', ['images' => ['1', '2', '3', '4', '5']]));
        $this->assertSame('12 zdjęć', BuilderBlockSummary::summarize('gallery', ['images' => range(1, 12)]));
        $this->assertSame('22 zdjęcia', BuilderBlockSummary::summarize('gallery', ['images' => range(1, 22)]));
        $this->assertSame('1 zdjęcie', BuilderBlockSummary::summarize('gallery', ['images' => ['1']]));
        $this->assertNull(BuilderBlockSummary::summarize('gallery', ['images' => []]));
        $this->assertSame('Pierwsza cecha', BuilderBlockSummary::summarize('feature_list', ['features' => [['title' => 'Pierwsza cecha']]]));
        // On an open form FileUpload keeps `[uuid => path]`, not a bare string.
        $this->assertSame('x.jpg', BuilderBlockSummary::summarize('image', ['image' => ['uuid-1' => 'services/images/x.jpg']]));
    }

    public function test_summaries_cope_with_uuid_keyed_uploads_nulls_and_nested_values(): void
    {
        $this->assertSame('2 zdjęcia', BuilderBlockSummary::summarize('gallery', ['images' => ['uuid-1' => 'a.jpg', 'uuid-2' => 'b.jpg']]));
        $this->assertNull(BuilderBlockSummary::summarize('gallery', ['images' => null]));
        $this->assertNull(BuilderBlockSummary::summarize('gallery', ['images' => 'not-an-array']));

        $this->assertSame('a.jpg', BuilderBlockSummary::summarize('image', ['image' => ['uuid-1' => 'services/images/a.jpg', 'uuid-2' => 'services/images/b.jpg']]));
        $this->assertNull(BuilderBlockSummary::summarize('image', ['image' => []]));
        $this->assertNull(BuilderBlockSummary::summarize('image', ['image' => null]));
        $this->assertNull(BuilderBlockSummary::summarize('image', ['image' => ['uuid-1' => new \stdClass]]), 'an object (pending upload) is ignored, not stringified');
        $this->assertSame('Podpis', BuilderBlockSummary::summarize('image', ['caption' => 'Podpis', 'image' => ['uuid-1' => 'x.jpg']]), 'caption wins over the file name');

        $this->assertNull(BuilderBlockSummary::summarize('text_block', ['content' => null]));
        $this->assertNull(BuilderBlockSummary::summarize('text_block', ['content' => []]));
        $this->assertNull(BuilderBlockSummary::summarize('text_block', ['content' => ['unexpected' => ['nested' => 1]]]));
        $this->assertNull(BuilderBlockSummary::summarize('feature_list', ['features' => [['title' => null]]]));
        $this->assertNull(BuilderBlockSummary::summarize('text_block', null));
    }

    public function test_a_polish_summary_is_cut_on_character_boundaries(): void
    {
        $text = str_repeat('ąęłżźćśóń', 8); // 72 multibyte characters
        $summary = BuilderBlockSummary::summarize('text_block', ['content' => "<p>{$text}</p>"]);

        $this->assertNotNull($summary);
        $this->assertTrue(mb_check_encoding($summary, 'UTF-8'), 'no half character');
        $this->assertSame(63, mb_strlen($summary), '60 characters plus the ellipsis');
        $this->assertSame(mb_substr($text, 0, 60).'...', $summary);
    }

    public function test_script_and_style_bodies_never_become_a_summary(): void
    {
        $this->assertSame('Treść po stylu', BuilderBlockSummary::summarize('custom_html', ['html' => '<style>.a { color: red }</style><script type="text/javascript">alert(1)</script><p>Treść po stylu</p>']));
        $this->assertNull(BuilderBlockSummary::summarize('custom_html', ['html' => '<script>alert(1)</script>']));
    }

    public function test_tiptap_text_nodes_in_one_paragraph_join_without_a_space(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Super'],
                ['type' => 'text', 'text' => 'cena', 'marks' => [['type' => 'bold']]],
            ]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Dwa']]],
        ]];

        $this->assertSame('Supercena Dwa', BuilderBlockSummary::summarize('text_block', ['content' => $doc]));
    }

    public function test_a_stored_script_tag_is_rendered_escaped_in_the_item_header(): void
    {
        $html = $this->openEdit($this->rentalService(['content' => [
            ['type' => 'quote', 'data' => ['quote' => 'Cytat <b>"x"</b> & <img src=x onerror=alert(1)>']],
            ['type' => 'text_block', 'data' => ['content' => '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>']],
            ['type' => 'cta', 'data' => ['heading' => '<script>alert(1)</script>Naglowek']],
        ]]))->html();

        $this->assertStringNotContainsString('<script>alert', $html, 'no raw script tag from a block summary');
        $this->assertStringNotContainsString('<img src=x', $html);
        // The text a summary carries is escaped by Blade on output.
        $this->assertStringContainsString('Blok tekstowy — &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('Call to Action — Naglowek', $html, 'a script tag is removed with its body');
    }

    public function test_a_tiptap_document_in_a_rich_text_block_is_summarised_too(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Jeden']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Dwa']]],
        ]];

        $this->assertSame('Jeden Dwa', BuilderBlockSummary::summarize('text_block', ['content' => $doc]));
    }

    // ---------------------------------------------------------------------------------------------
    // Blocks keep their data through the new presentation
    // ---------------------------------------------------------------------------------------------

    public function test_blocks_round_trip_unchanged_through_the_label_closures(): void
    {
        $blocks = $this->threeBlocks();
        $service = $this->rentalService(['content' => $blocks]);

        $this->saveUnchanged($service);

        $after = $this->reload($service)->content;
        $this->assertSame(array_column($blocks, 'type'), array_column($after, 'type'));
        $this->assertBlocksKeepTheirData($blocks, $after);
    }

    public function test_moving_a_block_down_with_the_header_button_saves_the_new_order_with_its_data_intact(): void
    {
        $blocks = $this->threeBlocks();
        $service = $this->rentalService(['content' => $blocks]);

        $page = $this->openEdit($service);
        $keys = array_keys($page->get('data.content'));

        $page->callAction(TestAction::make('moveDown')->schemaComponent('content')->arguments(['item' => $keys[0]]));
        $this->saveForm($page)->assertHasNoFormErrors();

        $after = $this->reload($service)->content;
        $this->assertSame(['quote', 'text_block', 'service_features'], array_column($after, 'type'));
        $this->assertBlocksKeepTheirData([$blocks[1], $blocks[0], $blocks[2]], $after);
    }

    public function test_deleting_a_block_from_its_header_removes_only_that_block(): void
    {
        $blocks = $this->threeBlocks();
        $service = $this->rentalService(['content' => $blocks]);

        $page = $this->openEdit($service);
        $keys = array_keys($page->get('data.content'));

        $page->callAction(TestAction::make('delete')->schemaComponent('content')->arguments(['item' => $keys[1]]));
        $this->saveForm($page)->assertHasNoFormErrors();

        $after = $this->reload($service)->content;
        $this->assertSame(['text_block', 'service_features'], array_column($after, 'type'));
        $this->assertBlocksKeepTheirData([$blocks[0], $blocks[2]], $after);
    }
}
