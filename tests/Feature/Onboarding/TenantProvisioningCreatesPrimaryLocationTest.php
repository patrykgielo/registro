<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\ServiceType;
use App\Filament\Resources\ServiceResource\Pages\CreateService;
use App\Http\Middleware\ResolveTenant;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\PendingCommand;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ClickUp 123k99cvc53 — a tenant provisioned via `registro:tenant-provision`
 * had NO Location at all, so ServiceResource's "Ilość w magazynie" field
 * silently disabled+un-dehydrated itself for every new product
 * (RouteQuantityFieldToPrimaryLocationStock::tenantHasExactlyOneActiveLocation()
 * === 0), saving quantity_total = NULL with no error anywhere. Fixed in
 * App\Actions\Onboarding\SeedOrganizationDefaults::seedPrimaryLocation().
 */
class TenantProvisioningCreatesPrimaryLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class]);

        foreach (['super-admin', 'admin', 'staff', 'customer'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function provision(array $options = []): PendingCommand
    {
        return $this->artisan('registro:tenant-provision', array_merge([
            '--slug' => 'newco-rentals',
            '--name' => 'NewCo Rentals',
            '--industry' => 'equipment_rental',
            '--owner-email' => 'owner@newco.test',
            '--owner-name' => 'Jan Kowalski',
            '--no-email' => true,
        ], $options));
    }

    public function test_provisioning_creates_a_primary_location_for_the_new_organization(): void
    {
        $this->provision()->assertSuccessful();

        $org = Organization::where('slug', 'newco-rentals')->firstOrFail();

        $locations = Location::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->get();

        $this->assertCount(1, $locations);
        $this->assertSame('Siedziba główna', $locations->first()->name);
        $this->assertTrue($locations->first()->is_active);
        $this->assertSame(1, $locations->first()->primary_slot);
    }

    public function test_re_running_provisioning_does_not_create_a_second_location(): void
    {
        $this->provision()->assertSuccessful();
        $this->provision()->assertSuccessful();

        $org = Organization::where('slug', 'newco-rentals')->firstOrFail();

        $this->assertSame(
            1,
            Location::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );
    }

    /**
     * Code review 2026-09-19: the original fix nested `seedPrimaryLocation()`
     * inside `SeedOrganizationDefaults::execute()`, which
     * `ProvisionTenantOrganization` only calls when `$orgWasCreated` — so
     * re-running the command against an EXISTING organization that already
     * has zero locations (any tenant provisioned in the regression window
     * between the Faza 1 backfill and this fix, or one created some other
     * way) could never be healed by re-running it, the exact idempotent-
     * repair path an operator would reach for. Simulates that pre-fix state
     * directly (NOT via this command, so the command's own location-seeding
     * never ran for it) and proves a plain re-run heals it.
     */
    public function test_re_running_provisioning_heals_an_existing_organization_that_has_zero_locations(): void
    {
        $org = Organization::factory()->equipmentRental()->create(['slug' => 'legacy-co']);

        $this->assertSame(
            0,
            Location::withoutGlobalScope('organization')->where('organization_id', $org->id)->count(),
            'sanity check: this organization must start with zero locations, matching a pre-fix tenant'
        );

        $this->provision([
            '--slug' => 'legacy-co',
            '--name' => $org->name,
            '--owner-email' => 'owner@legacy.test',
        ])->assertSuccessful();

        $locations = Location::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->get();

        $this->assertCount(1, $locations);
        $this->assertSame('Siedziba główna', $locations->first()->name);
        $this->assertSame(1, $locations->first()->primary_slot);
    }

    public function test_provisioning_a_second_tenant_does_not_touch_the_first_ones_location_count(): void
    {
        $this->provision()->assertSuccessful();
        $this->provision([
            '--slug' => 'second-co',
            '--name' => 'Second Co',
            '--owner-email' => 'owner@second.test',
        ])->assertSuccessful();

        $orgA = Organization::where('slug', 'newco-rentals')->firstOrFail();
        $orgB = Organization::where('slug', 'second-co')->firstOrFail();

        $this->assertSame(1, Location::withoutGlobalScope('organization')->where('organization_id', $orgA->id)->count());
        $this->assertSame(1, Location::withoutGlobalScope('organization')->where('organization_id', $orgB->id)->count());
    }

    /**
     * The full loop this bug actually broke: provision -> create a product
     * through the REAL Filament Create page -> add it to the cart through
     * the REAL public route. Before the fix, the "Ilość w magazynie" field
     * was disabled/un-dehydrated (zero locations), quantity_total saved as
     * NULL, and this add-to-cart would have been rejected as unavailable.
     */
    public function test_creating_a_product_through_the_panel_after_provisioning_is_immediately_rentable_via_the_real_cart_route(): void
    {
        $this->provision()->assertSuccessful();
        $org = Organization::where('slug', 'newco-rentals')->firstOrFail();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($org->id, ['role' => 'admin']);
        $this->actingAs($admin);
        session(['tenant_id' => $org->id]);

        $category = RentalCategory::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'name' => 'Elektronarzędzia',
            'is_active' => true,
        ]);

        Livewire::test(CreateService::class)
            ->fillForm([
                'service_type' => ServiceType::ItemRental->value,
                'name' => 'Wiertarka testowa',
                'slug' => 'wiertarka-testowa',
                'rental_category_id' => $category->id,
                'price_per_day' => 50,
                'quantity_total' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('slug', 'wiertarka-testowa')
            ->firstOrFail();

        $this->assertSame(3, $service->quantity_total, 'the quantity typed in the panel must not have been silently dropped to NULL');

        $customer = User::factory()->create();

        $response = $this->actingAs($customer)
            ->actingAsTenant($org)
            ->post(route('cart.add'), [
                'service_id' => $service->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(2)->toDateString(),
                'quantity' => 1,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('cart_items', [
            'service_id' => $service->id,
            'quantity' => 1,
        ]);
    }

    private function actingAsTenant(Organization $org): static
    {
        $this->app->bind(ResolveTenant::class, function () use ($org) {
            return new class($org)
            {
                public function __construct(private Organization $org) {}

                public function handle($request, $next)
                {
                    $request->attributes->set('tenant', $this->org);

                    return $next($request);
                }
            };
        });

        return $this;
    }
}
