<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\RentalCategoryResource\Pages\CreateRentalCategory;
use App\Filament\Resources\RentalCategoryResource\Pages\EditRentalCategory;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `rental_categories` has UNIQUE(organization_id, slug) — RentalCategoryResource's slug field used
 * a global `->unique(ignoreRecord: true)`, stricter than the schema. Every equipment-rental tenant
 * is seeded from the same vertical catalogue (7 collisions measured on dev), so editing an existing
 * rental category — even without touching its slug — was rejected. Same root cause as
 * `LocationSlugUniqueScopeTest`.
 */
class RentalCategoryResourceSlugUniqueScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenantA;

    private Organization $tenantB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->tenantA = Organization::factory()->equipmentRental()->create();
        $this->tenantB = Organization::factory()->equipmentRental()->create();

        $this->adminA = User::factory()->create();
        $this->adminA->assignRole('admin');
        $this->adminA->organizations()->attach($this->tenantA->id, ['role' => 'admin']);
    }

    public function test_editing_a_rental_category_without_changing_its_slug_succeeds_even_when_another_tenant_has_the_same_slug(): void
    {
        RentalCategory::factory()->create(['organization_id' => $this->tenantB->id, 'slug' => 'elektronarzedzia']);
        $categoryA = RentalCategory::factory()->create(['organization_id' => $this->tenantA->id, 'slug' => 'elektronarzedzia']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(EditRentalCategory::class, ['record' => $categoryA->slug])
            ->fillForm(['description' => 'Zaktualizowany opis'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Zaktualizowany opis', $categoryA->fresh()->description);
    }

    public function test_reusing_a_slug_already_taken_by_another_rental_category_of_the_same_tenant_is_still_rejected(): void
    {
        RentalCategory::factory()->create(['organization_id' => $this->tenantA->id, 'slug' => 'kompresory']);
        $categoryA = RentalCategory::factory()->create(['organization_id' => $this->tenantA->id, 'slug' => 'elektronarzedzia']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(EditRentalCategory::class, ['record' => $categoryA->slug])
            ->fillForm(['slug' => 'kompresory'])
            ->call('save')
            ->assertHasFormErrors(['slug' => 'unique']);
    }

    public function test_creating_a_rental_category_with_a_slug_only_taken_by_another_tenant_succeeds(): void
    {
        RentalCategory::factory()->create(['organization_id' => $this->tenantB->id, 'slug' => 'wolny-slug']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(CreateRentalCategory::class)
            ->fillForm([
                'name' => 'Nowa kategoria',
                'slug' => 'wolny-slug',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('rental_categories', [
            'organization_id' => $this->tenantA->id,
            'slug' => 'wolny-slug',
        ]);
    }
}
