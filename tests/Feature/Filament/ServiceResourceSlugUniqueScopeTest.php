<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ServiceResource\Pages\CreateService;
use App\Filament\Resources\ServiceResource\Pages\EditService;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `services` has UNIQUE(organization_id, slug) (2026_06_29_120000_fix_tenant_scoped_unique_constraints)
 * — ServiceResource's slug field used a global `->unique(ignoreRecord: true)`, stricter than the
 * schema. Two equipment-rental tenants seeded from the same vertical catalogue routinely share a
 * slug (13 collisions measured on dev), so any edit to an existing service — even one that doesn't
 * touch the slug — was rejected. Same root cause as `LocationSlugUniqueScopeTest`.
 */
class ServiceResourceSlugUniqueScopeTest extends TestCase
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

    public function test_editing_a_service_without_changing_its_slug_succeeds_even_when_another_tenant_has_the_same_slug(): void
    {
        Service::factory()->create(['organization_id' => $this->tenantB->id, 'slug' => 'wiertarka-udarowa']);
        $serviceA = Service::factory()->create(['organization_id' => $this->tenantA->id, 'slug' => 'wiertarka-udarowa']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(EditService::class, ['record' => $serviceA->slug])
            ->fillForm(['sort_order' => 42])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(42, $serviceA->fresh()->sort_order);
    }

    public function test_reusing_a_slug_already_taken_by_another_service_of_the_same_tenant_is_still_rejected(): void
    {
        Service::factory()->create(['organization_id' => $this->tenantA->id, 'slug' => 'mlot-udarowy']);
        $serviceA = Service::factory()->create(['organization_id' => $this->tenantA->id, 'slug' => 'wiertarka-udarowa']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(EditService::class, ['record' => $serviceA->slug])
            ->fillForm(['slug' => 'mlot-udarowy'])
            ->call('save')
            ->assertHasFormErrors(['slug' => 'unique']);
    }

    public function test_creating_a_service_with_a_slug_only_taken_by_another_tenant_succeeds(): void
    {
        Service::factory()->create(['organization_id' => $this->tenantB->id, 'slug' => 'wolny-slug']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(CreateService::class)
            ->fillForm([
                'name' => 'Nowa usługa',
                'slug' => 'wolny-slug',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('services', [
            'organization_id' => $this->tenantA->id,
            'slug' => 'wolny-slug',
        ]);
    }
}
