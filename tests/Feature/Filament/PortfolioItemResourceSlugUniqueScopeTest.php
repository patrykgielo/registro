<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\PortfolioItems\Pages\CreatePortfolioItem;
use App\Filament\Resources\PortfolioItems\Pages\EditPortfolioItem;
use App\Models\Organization;
use App\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `portfolio_items` has UNIQUE(organization_id, slug) — PortfolioItemResource's slug field used a
 * global `->unique(ignoreRecord: true)`, stricter than the schema. Two tenants each adding a
 * portfolio item with the same title collide, and editing either item afterward — even without
 * touching its slug — is rejected. Same root cause as `LocationSlugUniqueScopeTest`.
 */
class PortfolioItemResourceSlugUniqueScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenantA;

    private Organization $tenantB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->tenantA = Organization::factory()->create();
        $this->tenantB = Organization::factory()->create();

        $this->adminA = User::factory()->create();
        $this->adminA->assignRole('admin');
        $this->adminA->organizations()->attach($this->tenantA->id, ['role' => 'admin']);
    }

    public function test_editing_a_portfolio_item_without_changing_its_slug_succeeds_even_when_another_tenant_has_the_same_slug(): void
    {
        PortfolioItem::create(['organization_id' => $this->tenantB->id, 'title' => 'Realizacja', 'slug' => 'realizacja']);
        $itemA = PortfolioItem::create(['organization_id' => $this->tenantA->id, 'title' => 'Realizacja', 'slug' => 'realizacja']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(EditPortfolioItem::class, ['record' => $itemA->getKey()])
            ->fillForm(['meta_title' => 'Zaktualizowany tytuł'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Zaktualizowany tytuł', $itemA->fresh()->meta_title);
    }

    public function test_reusing_a_slug_already_taken_by_another_portfolio_item_of_the_same_tenant_is_still_rejected(): void
    {
        PortfolioItem::create(['organization_id' => $this->tenantA->id, 'title' => 'Projekt A', 'slug' => 'projekt-a']);
        $itemA = PortfolioItem::create(['organization_id' => $this->tenantA->id, 'title' => 'Realizacja', 'slug' => 'realizacja']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(EditPortfolioItem::class, ['record' => $itemA->getKey()])
            ->fillForm(['slug' => 'projekt-a'])
            ->call('save')
            ->assertHasFormErrors(['slug' => 'unique']);
    }

    public function test_creating_a_portfolio_item_with_a_slug_only_taken_by_another_tenant_succeeds(): void
    {
        PortfolioItem::create(['organization_id' => $this->tenantB->id, 'title' => 'Wolny slug', 'slug' => 'wolny-slug']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(CreatePortfolioItem::class)
            ->fillForm([
                'title' => 'Nowa realizacja',
                'slug' => 'wolny-slug',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('portfolio_items', [
            'organization_id' => $this->tenantA->id,
            'slug' => 'wolny-slug',
        ]);
    }
}
