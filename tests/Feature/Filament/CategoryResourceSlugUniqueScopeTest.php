<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `categories` has UNIQUE(organization_id, slug) — CategoryResource's slug field used a global
 * `->unique(ignoreRecord: true)`, stricter than the schema. Two tenants creating a "kontakt" or
 * "o-nas" category (routine, not seeded — will happen the moment a second tenant mirrors the
 * first's content structure) collide, and editing either category afterward — even without
 * touching its slug — is rejected. Same root cause as `LocationSlugUniqueScopeTest`.
 *
 * CategoryResource has no dedicated Edit/Create pages (single `ManageCategories` page, modal
 * table actions), so this test drives `callTableAction('edit'|'create', ...)` instead of
 * `Livewire::test(EditRecord::class)`.
 */
class CategoryResourceSlugUniqueScopeTest extends TestCase
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

    public function test_editing_a_category_without_changing_its_slug_succeeds_even_when_another_tenant_has_the_same_slug(): void
    {
        Category::create(['organization_id' => $this->tenantB->id, 'name' => 'Kontakt', 'slug' => 'kontakt', 'type' => 'post']);
        $categoryA = Category::create(['organization_id' => $this->tenantA->id, 'name' => 'Kontakt', 'slug' => 'kontakt', 'type' => 'post']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(ManageCategories::class)
            ->callTableAction('edit', $categoryA, data: ['description' => 'Zaktualizowany opis'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Zaktualizowany opis', $categoryA->fresh()->description);
    }

    public function test_reusing_a_slug_already_taken_by_another_category_of_the_same_tenant_is_still_rejected(): void
    {
        Category::create(['organization_id' => $this->tenantA->id, 'name' => 'O nas', 'slug' => 'o-nas', 'type' => 'post']);
        $categoryA = Category::create(['organization_id' => $this->tenantA->id, 'name' => 'Kontakt', 'slug' => 'kontakt', 'type' => 'post']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(ManageCategories::class)
            ->callTableAction('edit', $categoryA, data: ['name' => 'Kontakt', 'slug' => 'o-nas', 'type' => 'post'])
            ->assertHasTableActionErrors(['slug' => 'unique']);
    }

    public function test_creating_a_category_with_a_slug_only_taken_by_another_tenant_succeeds(): void
    {
        Category::create(['organization_id' => $this->tenantB->id, 'name' => 'Wolny slug', 'slug' => 'wolny-slug', 'type' => 'post']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(ManageCategories::class)
            ->callAction('create', data: [
                'name' => 'Nowa kategoria',
                'slug' => 'wolny-slug',
                'type' => 'post',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('categories', [
            'organization_id' => $this->tenantA->id,
            'slug' => 'wolny-slug',
        ]);
    }
}
