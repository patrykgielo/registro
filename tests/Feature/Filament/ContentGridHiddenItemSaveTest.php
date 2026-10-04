<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\PageLayout;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Page;
use App\Models\User;
use App\Support\ContentGridResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The block's multi-select validates its stored ids against its options and rejects an id that is
 * not among them. Were the options only the visible set, a branch deactivated AFTER it was picked
 * would make the whole CMS page unsaveable — with an error naming no item (no chip to remove). The
 * picker therefore offers not-visible items too, marked, so the admin can see and remove them.
 */
class ContentGridHiddenItemSaveTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->organizations()->attach($this->org->id, ['role' => 'admin']);
    }

    private function pageWith(array $ids): Page
    {
        return Page::create([
            'organization_id' => $this->org->id,
            'title' => 'Oddziały',
            'slug' => 'oddzialy',
            'layout' => PageLayout::DEFAULT,
            'content' => [[
                'type' => 'content_grid',
                'data' => ['content_type' => 'locations', 'content_items' => $ids, 'columns' => '3'],
            ]],
        ]);
    }

    public function test_a_page_whose_block_holds_a_deactivated_branch_can_still_be_saved(): void
    {
        $open = Location::factory()->create(['organization_id' => $this->org->id, 'name' => 'Oddzial Otwarty']);
        $closed = Location::factory()->create(['organization_id' => $this->org->id, 'name' => 'Oddzial Zamkniety', 'is_active' => false]);
        $page = $this->pageWith([$open->id, $closed->id]);

        session(['tenant_id' => $this->org->id]);
        $this->actingAs($this->admin);

        Livewire::test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['meta_title' => 'Nowy tytuł'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nowy tytuł', $page->fresh()->meta_title);
        $this->assertEqualsCanonicalizing([$open->id, $closed->id], $page->fresh()->content[0]['data']['content_items']);
    }

    public function test_a_deactivated_branch_is_offered_and_marked_not_visible(): void
    {
        $closed = Location::factory()->create(['organization_id' => $this->org->id, 'name' => 'Oddzial Zamkniety', 'city' => 'Gdansk', 'is_active' => false]);

        session(['tenant_id' => $this->org->id]);
        $this->actingAs($this->admin);
        request()->attributes->set('tenant_resolution_attempted', true);
        request()->attributes->set('tenant', $this->org);

        $this->assertSame(
            'Oddzial Zamkniety (Gdansk)'.ContentGridResolver::NOT_VISIBLE_SUFFIX,
            ContentGridResolver::optionsForType('locations')[$closed->id]
        );
    }
}
