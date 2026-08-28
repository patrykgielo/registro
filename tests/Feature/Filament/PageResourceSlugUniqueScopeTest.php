<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Organization;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `pages` has UNIQUE(organization_id, slug) — PageResource's slug field used a global
 * `->unique(ignoreRecord: true)`, stricter than the schema. Two tenants each creating a
 * "nasza-oferta" or "o-nas" page collide, and editing either page afterward — even without
 * touching its slug — is rejected. Same root cause as `LocationSlugUniqueScopeTest`.
 */
class PageResourceSlugUniqueScopeTest extends TestCase
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

    public function test_editing_a_page_without_changing_its_slug_succeeds_even_when_another_tenant_has_the_same_slug(): void
    {
        Page::create(['organization_id' => $this->tenantB->id, 'title' => 'Nasza oferta', 'slug' => 'nasza-oferta']);
        $pageA = Page::create(['organization_id' => $this->tenantA->id, 'title' => 'Nasza oferta', 'slug' => 'nasza-oferta']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(EditPage::class, ['record' => $pageA->getKey()])
            ->fillForm(['meta_title' => 'Zaktualizowany tytuł'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Zaktualizowany tytuł', $pageA->fresh()->meta_title);
    }

    public function test_reusing_a_slug_already_taken_by_another_page_of_the_same_tenant_is_still_rejected(): void
    {
        Page::create(['organization_id' => $this->tenantA->id, 'title' => 'O nas', 'slug' => 'o-nas']);
        $pageA = Page::create(['organization_id' => $this->tenantA->id, 'title' => 'Nasza oferta', 'slug' => 'nasza-oferta']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(EditPage::class, ['record' => $pageA->getKey()])
            ->fillForm(['slug' => 'o-nas'])
            ->call('save')
            ->assertHasFormErrors(['slug' => 'unique']);
    }

    public function test_creating_a_page_with_a_slug_only_taken_by_another_tenant_succeeds(): void
    {
        Page::create(['organization_id' => $this->tenantB->id, 'title' => 'Wolny slug', 'slug' => 'wolny-slug']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(CreatePage::class)
            ->fillForm([
                'title' => 'Nowa strona',
                'slug' => 'wolny-slug',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('pages', [
            'organization_id' => $this->tenantA->id,
            'slug' => 'wolny-slug',
        ]);
    }
}
