<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `locations` has UNIQUE(organization_id, slug) — LocationForm's slug field used a
 * global `->unique(ignoreRecord: true)` (no `organization_id` scoping), which is
 * STRICTER than the schema. 2026_08_27_120001_backfill_primary_location_for_organizations
 * gave every tenant's primary branch the same slug ("siedziba-glowna"), so every
 * tenant except the first one to save became unable to edit their own primary
 * location at all — the validation rejected a slug the tenant wasn't even changing.
 */
class LocationSlugUniqueScopeTest extends TestCase
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

    public function test_editing_a_location_without_changing_its_slug_succeeds_even_when_another_tenant_has_the_same_slug(): void
    {
        Location::factory()->for($this->tenantB, 'organization')->create(['slug' => 'siedziba-glowna']);
        $locationA = Location::factory()->for($this->tenantA, 'organization')->create(['slug' => 'siedziba-glowna']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(EditLocation::class, ['record' => $locationA->slug])
            ->fillForm(['street' => 'Nowa Testowa 1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nowa Testowa 1', $locationA->fresh()->street);
    }

    public function test_reusing_a_slug_already_taken_by_another_location_of_the_same_tenant_is_still_rejected(): void
    {
        Location::factory()->for($this->tenantA, 'organization')->create(['slug' => 'oddzial-krakow']);
        $locationA = Location::factory()->for($this->tenantA, 'organization')->create(['slug' => 'siedziba-glowna']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        $component = Livewire::test(EditLocation::class, ['record' => $locationA->slug])
            ->fillForm(['slug' => 'oddzial-krakow'])
            ->call('save')
            ->assertHasFormErrors(['slug' => 'unique']);

        // APP_FALLBACK_LOCALE=pl with no lang/pl/validation.php anywhere (app or
        // vendor) renders the raw "validation.unique" key instead of readable text
        // — same root cause across every resource, not something to fix per-field.
        // See .env.testing / .env.production.example / .env.local.example.
        $this->assertNotSame('validation.unique', $component->errors()->first('data.slug'));
    }

    public function test_creating_a_location_with_a_slug_only_taken_by_another_tenant_succeeds(): void
    {
        Location::factory()->for($this->tenantB, 'organization')->create(['slug' => 'wolny-slug']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(\App\Filament\Resources\Locations\Pages\CreateLocation::class)
            ->fillForm([
                'name' => 'Nowa lokalizacja',
                'slug' => 'wolny-slug',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('locations', [
            'organization_id' => $this->tenantA->id,
            'slug' => 'wolny-slug',
        ]);
    }
}
