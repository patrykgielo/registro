<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Enums\PageLayout;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Page;
use App\Models\PortfolioItem;
use App\Models\Post;
use App\Models\Promotion;
use App\Models\Service;
use App\Models\User;
use App\Support\ContentGridResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ClickUp 123k99cu26t — a "Siatka treści" block kept rendering an item after the admin
 * deactivated it, because ContentGridResolver::resolveItems() had no visibility filter at all.
 *
 * Goes through the real entry point: GET of a CMS page on a tenant host, real ResolveTenant,
 * real block component. Each type is asserted against the rule ITS OWN public route enforces
 * (see ContentGridResolver::visibleQuery()), not a blanket is_active.
 */
class ContentGridVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://demo.registro.local';

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);

        $this->org = Organization::create([
            'name' => 'Demo Rental',
            'slug' => 'demo',
            'booking_type' => 'item_rental',
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function pageWithGrid(string $type, array $ids, string $heading = 'Nagłówek siatki'): Page
    {
        return Page::create([
            'organization_id' => $this->org->id,
            'title' => 'Strona testowa',
            'slug' => 'strona-testowa',
            'body' => 'Body',
            'layout' => PageLayout::DEFAULT,
            'published_at' => now()->subDay(),
            'content' => [[
                'type' => 'content_grid',
                'data' => [
                    'content_type' => $type,
                    'content_items' => $ids,
                    'columns' => '3',
                    'heading' => $heading,
                ],
            ]],
        ]);
    }

    private function render(): string
    {
        // php-fpm builds a fresh container per request; the test app is reused, and the header's
        // scoped LocationContext would otherwise serve the first request's branch list again.
        $this->app->forgetScopedInstances();

        return $this->get(self::HOST.'/strona-testowa')->assertOk()->getContent();
    }

    /**
     * @param  array<int, string>  $names  expected in this order
     */
    private function assertInOrder(string $html, array $names): void
    {
        // The header's branch switcher also lists branch names, in its own order.
        $html = substr($html, (int) strpos($html, 'lg:grid-cols-3 gap-8'));
        $last = -1;
        foreach ($names as $name) {
            $pos = strpos($html, $name);
            $this->assertNotFalse($pos, "{$name} should be rendered");
            $this->assertGreaterThan($last, $pos, "{$name} is out of the admin's chosen order");
            $last = $pos;
        }
    }

    private function rental(string $name, array $attributes = []): Service
    {
        return Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ] + $attributes);
    }

    public function test_services_hide_inactive_and_unpublished_and_keep_the_admins_order(): void
    {
        $a = $this->rental('Wiertarka Alfa');
        $b = $this->rental('Szlifierka Beta');
        $inactive = $this->rental('Pila Nieaktywna', ['is_active' => false]);
        $draft = Service::factory()->create([
            'organization_id' => $this->org->id,
            'name' => 'Usluga Robocza',
            'slug' => 'usluga-robocza',
            'is_active' => true,
            'published_at' => null,
        ]);
        $scheduled = Service::factory()->create([
            'organization_id' => $this->org->id,
            'name' => 'Usluga Zaplanowana',
            'slug' => 'usluga-zaplanowana',
            'is_active' => true,
            'published_at' => now()->addWeek(),
        ]);
        $published = Service::factory()->create([
            'organization_id' => $this->org->id,
            'name' => 'Usluga Opublikowana',
            'slug' => 'usluga-opublikowana',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);

        $this->pageWithGrid('services', [$b->id, $inactive->id, $draft->id, $published->id, $scheduled->id, $a->id]);

        $html = $this->render();

        $this->assertInOrder($html, ['Szlifierka Beta', 'Usluga Opublikowana', 'Wiertarka Alfa']);
        foreach (['Pila Nieaktywna', 'Usluga Robocza', 'Usluga Zaplanowana'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html);
        }
    }

    public function test_posts_hide_drafts_and_scheduled(): void
    {
        $make = fn (string $title, $publishedAt) => Post::create([
            'organization_id' => $this->org->id,
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'body' => 'Tresc',
            'published_at' => $publishedAt,
        ]);

        $one = $make('Wpis Pierwszy', now()->subDays(2));
        $draft = $make('Wpis Szkic', null);
        $scheduled = $make('Wpis Zaplanowany', now()->addDay());
        $two = $make('Wpis Drugi', now()->subHour());

        $this->pageWithGrid('posts', [$two->id, $draft->id, $scheduled->id, $one->id]);

        $html = $this->render();

        $this->assertInOrder($html, ['Wpis Drugi', 'Wpis Pierwszy']);
        $this->assertStringNotContainsString('Wpis Szkic', $html);
        $this->assertStringNotContainsString('Wpis Zaplanowany', $html);
    }

    public function test_promotions_hide_inactive_expired_and_not_yet_valid(): void
    {
        $make = fn (string $title, array $attributes) => Promotion::create([
            'organization_id' => $this->org->id,
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'body' => 'Tresc',
        ] + $attributes);

        $open = $make('Promocja Otwarta', ['active' => true]);
        $windowed = $make('Promocja W Oknie', ['active' => true, 'valid_from' => now()->subDay(), 'valid_until' => now()->addDay()]);
        $off = $make('Promocja Wylaczona', ['active' => false]);
        $expired = $make('Promocja Wygasla', ['active' => true, 'valid_until' => now()->subDay()]);
        $future = $make('Promocja Przyszla', ['active' => true, 'valid_from' => now()->addDay()]);

        $this->pageWithGrid('promotions', [$windowed->id, $off->id, $expired->id, $future->id, $open->id]);

        $html = $this->render();

        $this->assertInOrder($html, ['Promocja W Oknie', 'Promocja Otwarta']);
        foreach (['Promocja Wylaczona', 'Promocja Wygasla', 'Promocja Przyszla'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html);
        }
    }

    public function test_portfolio_hides_drafts_and_scheduled(): void
    {
        $make = fn (string $title, $publishedAt) => PortfolioItem::create([
            'organization_id' => $this->org->id,
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'body' => 'Tresc',
            'published_at' => $publishedAt,
        ]);

        $one = $make('Realizacja Pierwsza', now()->subDays(3));
        $draft = $make('Realizacja Szkic', null);
        $scheduled = $make('Realizacja Zaplanowana', now()->addDay());
        $two = $make('Realizacja Druga', now()->subDay());

        $this->pageWithGrid('portfolio', [$two->id, $scheduled->id, $draft->id, $one->id]);

        $html = $this->render();

        $this->assertInOrder($html, ['Realizacja Druga', 'Realizacja Pierwsza']);
        $this->assertStringNotContainsString('Realizacja Szkic', $html);
        $this->assertStringNotContainsString('Realizacja Zaplanowana', $html);
    }

    public function test_a_deactivated_branch_disappears_and_comes_back_when_reactivated(): void
    {
        $open = Location::factory()->create(['organization_id' => $this->org->id, 'name' => 'Oddzial Otwarty', 'city' => 'Poznan']);
        $closed = Location::factory()->create(['organization_id' => $this->org->id, 'name' => 'Oddzial Zamkniety', 'city' => 'Gdansk']);
        $page = $this->pageWithGrid('locations', [$closed->id, $open->id]);

        $this->assertInOrder($this->render(), ['Oddzial Zamkniety', 'Oddzial Otwarty']);

        $closed->update(['is_active' => false]);

        $html = $this->render();
        $this->assertStringContainsString('Oddzial Otwarty', $html);
        $this->assertStringNotContainsString('Oddzial Zamkniety', $html);

        $this->assertSame([$closed->id, $open->id], $page->fresh()->content[0]['data']['content_items'],
            'hiding is done at render — the block must keep the ids the admin picked');

        $closed->update(['is_active' => true]);
        $this->assertInOrder($this->render(), ['Oddzial Zamkniety', 'Oddzial Otwarty']);
    }

    public function test_an_item_of_another_tenant_is_never_rendered(): void
    {
        $other = Organization::create([
            'name' => 'Other',
            'slug' => 'other',
            'booking_type' => 'item_rental',
            'owner_id' => User::factory()->create()->id,
        ]);
        $foreign = Location::factory()->create(['organization_id' => $other->id, 'name' => 'Oddzial Cudzy']);
        $own = Location::factory()->create(['organization_id' => $this->org->id, 'name' => 'Oddzial Wlasny']);

        $this->pageWithGrid('locations', [$foreign->id, $own->id]);

        $html = $this->render();
        $this->assertStringContainsString('Oddzial Wlasny', $html);
        $this->assertStringNotContainsString('Oddzial Cudzy', $html);
    }

    public function test_when_every_picked_item_is_hidden_the_block_renders_nothing(): void
    {
        $closed = Location::factory()->create(['organization_id' => $this->org->id, 'name' => 'Oddzial Zamkniety', 'is_active' => false]);
        $this->pageWithGrid('locations', [$closed->id], 'Znajdz nasz oddzial');

        $html = $this->render();

        $this->assertStringNotContainsString('Znajdz nasz oddzial', $html, 'a heading over an empty grid');
        $this->assertStringNotContainsString('Oddzial Zamkniety', $html);
        $this->assertStringNotContainsString('lg:grid-cols-3', $html, 'an empty grid shell');
        $this->assertStringNotContainsString('bg-yellow-50', $html, 'the editor warning box must not reach the public');
    }

    public function test_a_block_with_no_picked_items_renders_nothing(): void
    {
        $this->pageWithGrid('services', [], 'Pusta siatka');

        $this->assertStringNotContainsString('Pusta siatka', $this->render());
    }

    /**
     * Stored block data is untrusted at render. A legacy / hand-edited row must not turn a public
     * page into a 500.
     */
    public function test_malformed_stored_block_data_renders_nothing_instead_of_failing(): void
    {
        foreach (['not-a-list', 5, true, ['x' => ['nested']]] as $broken) {
            $this->pageWithGrid('locations', [], 'Siatka testowa');
            $page = Page::where('slug', 'strona-testowa')->firstOrFail();
            $content = $page->content;
            $content[0]['data']['content_items'] = $broken;
            $page->update(['content' => $content]);

            $html = $this->render();
            $this->assertStringNotContainsString('Siatka testowa', $html, 'content_items='.json_encode($broken));
            $page->forceDelete();
        }

        $this->assertCount(0, ContentGridResolver::resolveItems(['array'], [1]), 'non-string content_type');
        $this->assertCount(0, ContentGridResolver::resolveItems('locations', null));
    }

    public function test_an_oversized_id_list_is_capped_not_passed_to_the_database(): void
    {
        $first = Location::factory()->create(['organization_id' => $this->org->id, 'name' => 'Oddzial W Limicie']);
        $beyond = Location::factory()->create(['organization_id' => $this->org->id, 'name' => 'Oddzial Za Limitem']);

        $ids = [$first->id, ...range(900000, 900000 + ContentGridResolver::MAX_ITEMS - 2), $beyond->id];
        $this->assertGreaterThan(ContentGridResolver::MAX_ITEMS, count($ids));

        $this->assertSame([$first->id], ContentGridResolver::resolveItems('locations', $ids)->pluck('id')->all());

        // Far beyond any database's bound-parameter limit (MySQL: 65,535): must not throw.
        $this->assertCount(0, ContentGridResolver::resolveItems('locations', range(1_000_000, 1_070_000)));
    }

    /**
     * Contract: everything the renderer shows is offered unmarked; whatever is not visible yet is
     * offered too (preparing a page in advance) but marked; permanently gone rows — another
     * tenant's, a deleted one — are not offered at all.
     */
    public function test_the_picker_offers_visible_plain_and_not_visible_marked_and_nothing_gone(): void
    {
        $this->actingAsTenantContext();
        $other = Organization::create([
            'name' => 'Other', 'slug' => 'other', 'booking_type' => 'item_rental',
            'owner_id' => User::factory()->create()->id,
        ]);

        $service = fn (string $n, array $a, ?Organization $o = null) => Service::factory()->itemRental()->create(['organization_id' => ($o ?? $this->org)->id, 'name' => $n, 'slug' => str($n)->slug()->toString()] + $a);
        $post = fn (string $t, $at, ?Organization $o = null) => Post::create(['organization_id' => ($o ?? $this->org)->id, 'title' => $t, 'slug' => str($t)->slug()->toString(), 'body' => 'x', 'published_at' => $at]);
        $promo = fn (string $t, array $a, ?Organization $o = null) => Promotion::create(['organization_id' => ($o ?? $this->org)->id, 'title' => $t, 'slug' => str($t)->slug()->toString(), 'body' => 'x'] + $a);
        $item = fn (string $t, $at, ?Organization $o = null) => PortfolioItem::create(['organization_id' => ($o ?? $this->org)->id, 'title' => $t, 'slug' => str($t)->slug()->toString(), 'body' => 'x', 'published_at' => $at]);
        $branch = fn (string $n, array $a, ?Organization $o = null) => Location::factory()->create(['organization_id' => ($o ?? $this->org)->id, 'name' => $n, 'city' => null] + $a);

        // [type => [visible, [not-visible...], foreign, deleted]]
        $cases = [
            'services' => [$service('S1', []), [$service('S2', ['is_active' => false])], $service('S9', [], $other), $service('S8', [])],
            'posts' => [$post('P1', now()->subDay()), [$post('P2', now()->addDay()), $post('P3', null)], $post('P9', now()->subDay(), $other), $post('P8', now()->subDay())],
            'promotions' => [$promo('R1', ['active' => true]), [$promo('R2', ['active' => true, 'valid_from' => now()->addDay()]), $promo('R3', ['active' => true, 'valid_until' => now()->subDay()]), $promo('R4', ['active' => false])], $promo('R9', ['active' => true], $other), $promo('R8', ['active' => true])],
            'portfolio' => [$item('F1', now()->subDay()), [$item('F2', now()->addDay()), $item('F3', null)], $item('F9', now()->subDay(), $other), $item('F8', now()->subDay())],
            'locations' => [$branch('B1', []), [$branch('B2', ['is_active' => false])], $branch('B9', [], $other), $branch('B8', [])],
        ];

        foreach ($cases as $type => [$visible, $notVisible, $foreign, $deleted]) {
            $deletedId = $deleted->getKey();
            $deleted->forceDelete();

            $options = ContentGridResolver::optionsForType($type);
            $allIds = [$visible->getKey(), ...array_map(fn ($m) => $m->getKey(), $notVisible), $foreign->getKey(), $deletedId];

            $shown = ContentGridResolver::resolveItems($type, $allIds)->pluck('id')->all();
            $this->assertSame([$visible->getKey()], $shown, "{$type}: fixture should render exactly one item");

            foreach ($shown as $id) {
                $this->assertArrayHasKey($id, $options, "{$type}: everything the renderer shows must be offered");
                $this->assertStringNotContainsString(ContentGridResolver::NOT_VISIBLE_SUFFIX, $options[$id], "{$type}: a visible item must not be marked");
            }
            foreach ($notVisible as $m) {
                $this->assertArrayHasKey($m->getKey(), $options, "{$type}: a not-visible item must still be pickable");
                $this->assertStringEndsWith(ContentGridResolver::NOT_VISIBLE_SUFFIX, $options[$m->getKey()], "{$type}: a not-visible item must be marked");
            }
            $this->assertArrayNotHasKey($foreign->getKey(), $options, "{$type}: another tenant's row must never be offered");
            $this->assertArrayNotHasKey($deletedId, $options, "{$type}: a deleted row must never be offered");
            $this->assertCount(1 + count($notVisible), $options, "{$type}: nothing else may be offered");
        }
    }

    /**
     * Filament calls the options closure several times per request (render, `in` validation,
     * chip labels) per block, so its cost is multiplied. One query per type, narrow columns,
     * no per-row placeholder list — a tenant with thousands of rows must not hit MySQL's
     * 65,535-placeholder cap.
     */
    public function test_the_picker_costs_exactly_one_query_per_type(): void
    {
        $this->actingAsTenantContext();

        Service::factory()->itemRental()->create(['organization_id' => $this->org->id, 'name' => 'S1', 'slug' => 's1']);
        Service::factory()->itemRental()->create(['organization_id' => $this->org->id, 'name' => 'S2', 'slug' => 's2', 'is_active' => false]);
        Post::create(['organization_id' => $this->org->id, 'title' => 'P1', 'slug' => 'p1', 'body' => 'x', 'published_at' => now()->subDay()]);
        Post::create(['organization_id' => $this->org->id, 'title' => 'P2', 'slug' => 'p2', 'body' => 'x', 'published_at' => null]);
        Promotion::create(['organization_id' => $this->org->id, 'title' => 'R1', 'slug' => 'r1', 'body' => 'x', 'active' => true]);
        PortfolioItem::create(['organization_id' => $this->org->id, 'title' => 'F1', 'slug' => 'f1', 'body' => 'x', 'published_at' => now()->addDay()]);
        Location::factory()->create(['organization_id' => $this->org->id]);

        foreach (['services', 'posts', 'promotions', 'portfolio', 'locations'] as $type) {
            $queries = [];
            DB::listen(function ($query) use (&$queries) {
                $queries[] = $query->sql;
            });

            ContentGridResolver::optionsForType($type);

            $this->assertCount(1, $queries, "{$type}: optionsForType() must be one query, got:\n".implode("\n", $queries));
            $this->assertStringNotContainsString(' not in (', strtolower($queries[0]), "{$type}: no per-row placeholder list");
            $this->assertStringNotContainsString('select *', strtolower($queries[0]), "{$type}: select only the label/visibility columns");
            $this->assertStringNotContainsString('"body"', $queries[0], "{$type}: big columns must not be selected");
            $this->assertStringNotContainsString('"content"', $queries[0], "{$type}: big columns must not be selected");

            DB::flushQueryLog();
            $this->flushDbListeners();
        }
    }

    private function flushDbListeners(): void
    {
        // Illuminate\Database\Connection has no public "forget listeners"; the dispatcher does.
        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);
    }

    public function test_each_picker_group_is_sorted_by_label_visible_first(): void
    {
        $this->actingAsTenantContext();
        $post = fn (string $t, $at) => Post::create(['organization_id' => $this->org->id, 'title' => $t, 'slug' => str($t)->slug()->toString(), 'body' => 'x', 'published_at' => $at]);
        $post('Zebra', now()->subDay());
        $post('Alfa', now()->subDay());
        $post('Pozniej B', now()->addDay());
        $post('Pozniej A', null);

        $this->assertSame([
            'Alfa',
            'Zebra',
            'Pozniej A'.ContentGridResolver::NOT_VISIBLE_SUFFIX,
            'Pozniej B'.ContentGridResolver::NOT_VISIBLE_SUFFIX,
        ], array_values(ContentGridResolver::optionsForType('posts')));
    }

    public function test_a_marked_item_picked_in_advance_stays_off_the_public_page_until_it_becomes_visible(): void
    {
        $this->actingAsTenantContext();
        $next = Promotion::create([
            'organization_id' => $this->org->id, 'title' => 'Promocja Na Przyszly Tydzien', 'slug' => 'promocja-przyszla',
            'body' => 'x', 'active' => true, 'valid_from' => now()->addWeek(),
        ]);

        $options = ContentGridResolver::optionsForType('promotions');
        $this->assertSame('Promocja Na Przyszly Tydzien'.ContentGridResolver::NOT_VISIBLE_SUFFIX, $options[$next->id]);

        $this->pageWithGrid('promotions', [$next->id], 'Nadchodzace promocje');
        $this->assertStringNotContainsString('Promocja Na Przyszly Tydzien', $this->render());

        $next->update(['valid_from' => now()->subMinute()]);
        $this->assertStringContainsString('Promocja Na Przyszly Tydzien', $this->render());
    }

    private function actingAsTenantContext(): void
    {
        request()->attributes->set('tenant_resolution_attempted', true);
        request()->attributes->set('tenant', $this->org);
    }
}
