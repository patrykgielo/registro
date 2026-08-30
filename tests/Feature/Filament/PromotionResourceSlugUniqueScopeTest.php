<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Promotions\Pages\CreatePromotion;
use App\Filament\Resources\Promotions\Pages\EditPromotion;
use App\Models\Organization;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `promotions` has UNIQUE(organization_id, slug) — PromotionResource's slug field used a global
 * `->unique(ignoreRecord: true)`, stricter than the schema. Two tenants each running a "wyprzedaz"
 * promotion collide, and editing either promotion afterward — even without touching its slug —
 * is rejected. Same root cause as `LocationSlugUniqueScopeTest`.
 */
class PromotionResourceSlugUniqueScopeTest extends TestCase
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

    public function test_editing_a_promotion_without_changing_its_slug_succeeds_even_when_another_tenant_has_the_same_slug(): void
    {
        Promotion::create(['organization_id' => $this->tenantB->id, 'title' => 'Wyprzedaż', 'slug' => 'wyprzedaz', 'body' => 'Treść']);
        $promotionA = Promotion::create(['organization_id' => $this->tenantA->id, 'title' => 'Wyprzedaż', 'slug' => 'wyprzedaz', 'body' => 'Treść']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(EditPromotion::class, ['record' => $promotionA->getKey()])
            ->fillForm(['meta_title' => 'Zaktualizowany tytuł'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Zaktualizowany tytuł', $promotionA->fresh()->meta_title);
    }

    public function test_reusing_a_slug_already_taken_by_another_promotion_of_the_same_tenant_is_still_rejected(): void
    {
        Promotion::create(['organization_id' => $this->tenantA->id, 'title' => 'Rabat', 'slug' => 'rabat', 'body' => 'Treść']);
        $promotionA = Promotion::create(['organization_id' => $this->tenantA->id, 'title' => 'Wyprzedaż', 'slug' => 'wyprzedaz', 'body' => 'Treść']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(EditPromotion::class, ['record' => $promotionA->getKey()])
            ->fillForm(['slug' => 'rabat'])
            ->call('save')
            ->assertHasFormErrors(['slug' => 'unique']);
    }

    public function test_creating_a_promotion_with_a_slug_only_taken_by_another_tenant_succeeds(): void
    {
        Promotion::create(['organization_id' => $this->tenantB->id, 'title' => 'Wolny slug', 'slug' => 'wolny-slug', 'body' => 'Treść']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(CreatePromotion::class)
            ->fillForm([
                'title' => 'Nowa promocja',
                'slug' => 'wolny-slug',
                'body' => 'Treść nowej promocji',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('promotions', [
            'organization_id' => $this->tenantA->id,
            'slug' => 'wolny-slug',
        ]);
    }
}
