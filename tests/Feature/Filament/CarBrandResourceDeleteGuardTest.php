<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\CarBrandResource;
use App\Filament\Resources\CarBrandResource\Pages\ListCarBrands;
use App\Models\CarBrand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CarBrandResource::canViewAny() allows `admin`, but canDelete()/canCreate()/
 * canEdit() are super-admin only — the exact "canViewAny widened, mutation
 * left narrow" shape that made UserResource's canDelete() a dead guard before
 * BaseResource wired getDeleteAuthorizationResponse() to canDelete(). This is
 * the end-to-end proof that the fix generalizes to a resource that was never
 * individually patched (unlike UserResource, which got its own local fix
 * first).
 *
 * The exploitable path is specifically the LIST page's row-level DeleteAction
 * (CarBrandResource::table()), not EditCarBrand's header DeleteAction:
 * EditRecord::authorizeAccess() has always called canEdit() directly
 * (vendor/filament/filament/src/Resources/Pages/EditRecord.php:100) — that
 * was never the broken path — and canEdit() is ALSO super-admin only here,
 * so admin can never even mount the edit page. ListRecords only gates on
 * canViewAny() (which admin passes), so the row DeleteAction — driven
 * through getDeleteAuthorizationResponse(), the actually-broken path — is
 * what an admin could reach pre-fix.
 */
class CarBrandResourceDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super-admin', 'admin'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    public function test_a_tenant_admin_passes_can_view_any_but_is_denied_delete(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->assertTrue(CarBrandResource::canViewAny());
        $this->assertFalse(CarBrandResource::canDelete(new CarBrand));
        $this->assertFalse(CarBrandResource::getDeleteAuthorizationResponse(new CarBrand)->allowed());
    }

    public function test_a_tenant_admin_cannot_actually_delete_a_car_brand_from_the_list(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $carBrand = CarBrand::create(['name' => 'Ford', 'slug' => 'ford']);

        $this->actingAs($admin);

        try {
            Livewire::test(ListCarBrands::class)
                ->callTableAction('delete', $carBrand);
        } catch (\Throwable) {
            // Refusal is the expected outcome; the assertion below is the contract.
        }

        $this->assertDatabaseHas('car_brands', ['id' => $carBrand->id]);
    }

    public function test_a_super_admin_can_actually_delete_a_car_brand_from_the_list(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $carBrand = CarBrand::create(['name' => 'Ford', 'slug' => 'ford']);

        $this->actingAs($superAdmin);

        Livewire::test(ListCarBrands::class)
            ->callTableAction('delete', $carBrand);

        $this->assertDatabaseMissing('car_brands', ['id' => $carBrand->id]);
    }
}
