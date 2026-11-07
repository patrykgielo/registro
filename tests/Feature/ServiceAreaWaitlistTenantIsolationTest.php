<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\ServiceAreaWaitlists\Pages\ListServiceAreaWaitlists;
use App\Filament\Resources\ServiceAreaWaitlists\ServiceAreaWaitlistResource;
use App\Models\Organization;
use App\Models\ServiceArea;
use App\Models\ServiceAreaWaitlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * service_area_waitlist (singular) never got an organization_id: the
 * 2026_03_08_000003 migration targeted `service_area_waitlists` (plural), and
 * its Schema::hasTable() guard skipped the typo silently — so every tenant on
 * a shared stack read and wrote ONE list (name, email, phone, address, GPS).
 *
 * Needs outbound DNS (the controller validates `email:rfc,dns`) — run with
 * network access, same as ServiceAreaWaitlistTest.
 */
class ServiceAreaWaitlistTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenantA;

    private Organization $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (['super-admin', 'admin'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->tenantA = Organization::factory()->create();
        $this->tenantB = Organization::factory()->create();
    }

    private function serviceAreaFor(Organization $org, string $city, float $lat, float $lng): ServiceArea
    {
        return ServiceArea::factory()->create([
            'organization_id' => $org->id,
            'city_name' => $city,
            'latitude' => $lat,
            'longitude' => $lng,
            'radius_km' => 50,
            'is_active' => true,
        ]);
    }

    /**
     * Poznań — outside a 50 km Warsaw area.
     *
     * @return array<string, mixed>
     */
    private function poznanPayload(string $email = 'jan.kowalski@gmail.com'): array
    {
        return [
            'email' => $email,
            'name' => 'Jan Kowalski',
            'address' => 'Stary Rynek 1, Poznań',
            'latitude' => 52.4064,
            'longitude' => 16.9252,
        ];
    }

    private function submitOn(Organization $org, array $payload)
    {
        return $this->postJson("http://{$org->slug}.registro.local/api/service-area/waitlist", $payload);
    }

    /**
     * organization_id is deliberately NOT mass-assignable, so fixtures go
     * through forceCreate() with the global scope off.
     */
    private function entryFor(Organization $org, string $email, string $status = 'pending'): ServiceAreaWaitlist
    {
        return ServiceAreaWaitlist::withoutGlobalScopes()->forceCreate([
            'organization_id' => $org->id,
            'email' => $email,
            'requested_address' => 'Stary Rynek 1, Poznań',
            'requested_latitude' => 52.4064,
            'requested_longitude' => 16.9252,
            'nearest_area_city' => 'Warszawa',
            'status' => $status,
        ]);
    }

    private function actAsTenant(Organization $org): void
    {
        session(['tenant_id' => $org->id]);
    }

    public function test_submission_on_a_tenant_subdomain_stamps_that_tenants_organization_id(): void
    {
        $this->serviceAreaFor($this->tenantA, 'Warszawa', 52.2297, 21.0122);

        $this->submitOn($this->tenantA, $this->poznanPayload())->assertCreated();

        $this->assertDatabaseHas('service_area_waitlist', [
            'email' => 'jan.kowalski@gmail.com',
            'organization_id' => $this->tenantA->id,
        ]);
    }

    public function test_submission_without_a_resolved_tenant_is_rejected_and_stores_nothing(): void
    {
        $this->postJson('http://registro.local/api/service-area/waitlist', $this->poznanPayload())
            ->assertNotFound();

        $this->assertSame(0, ServiceAreaWaitlist::withoutGlobalScopes()->count());
    }

    public function test_a_tenants_entries_are_invisible_to_another_tenant(): void
    {
        $entryA = $this->entryFor($this->tenantA, 'a@gmail.com');
        $entryB = $this->entryFor($this->tenantB, 'b@gmail.com');

        $this->actAsTenant($this->tenantB);

        $this->assertSame(['b@gmail.com'], ServiceAreaWaitlist::pluck('email')->all());
        $this->assertNull(ServiceAreaWaitlist::find($entryA->id));
        $this->assertNotNull(ServiceAreaWaitlist::find($entryB->id));

        $this->actAsTenant($this->tenantA);

        $this->assertSame(['a@gmail.com'], ServiceAreaWaitlist::pluck('email')->all());
    }

    public function test_the_same_person_can_join_the_waitlist_of_two_tenants_but_only_once_per_tenant(): void
    {
        $this->serviceAreaFor($this->tenantA, 'Warszawa', 52.2297, 21.0122);
        $this->serviceAreaFor($this->tenantB, 'Warszawa', 52.2297, 21.0122);

        $this->submitOn($this->tenantA, $this->poznanPayload())->assertCreated();
        $this->submitOn($this->tenantB, $this->poznanPayload())->assertCreated();
        $this->submitOn($this->tenantA, $this->poznanPayload())->assertStatus(409);

        $this->assertSame(2, ServiceAreaWaitlist::withoutGlobalScopes()->count());
        $this->assertSame(
            [$this->tenantA->id, $this->tenantB->id],
            ServiceAreaWaitlist::withoutGlobalScopes()->orderBy('organization_id')->pluck('organization_id')->all(),
        );
    }

    public function test_an_integrity_error_that_is_not_the_duplicate_unique_is_not_reported_as_409(): void
    {
        $this->serviceAreaFor($this->tenantA, 'Warszawa', 52.2297, 21.0122);

        // Registered after the trait's own creating hook (the model is booted first), so it
        // wins: the INSERT hits the NOT NULL on organization_id (SQLSTATE 23000, like the
        // unique, but not a duplicate).
        new ServiceAreaWaitlist;
        ServiceAreaWaitlist::creating(function (ServiceAreaWaitlist $entry) {
            $entry->organization_id = null;
        });

        $this->withoutExceptionHandling();
        $this->expectException(\Illuminate\Database\QueryException::class);

        try {
            $this->submitOn($this->tenantA, $this->poznanPayload());
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $this->fail('NOT NULL violation was mistaken for a unique violation.');
        }
    }

    public function test_sign_up_is_validated_against_the_requesting_tenants_own_service_areas(): void
    {
        // Tenant A serves Poznań; tenant B only Warsaw. The route used to run
        // with no tenant at all, so B's visitor was checked against A's areas
        // (and bounced with "already available").
        $this->serviceAreaFor($this->tenantA, 'Poznań', 52.4064, 16.9252);
        $this->serviceAreaFor($this->tenantB, 'Warszawa', 52.2297, 21.0122);

        $this->submitOn($this->tenantB, $this->poznanPayload())->assertCreated();

        $entry = ServiceAreaWaitlist::withoutGlobalScopes()->sole();
        $this->assertSame($this->tenantB->id, $entry->organization_id);
        $this->assertSame('Warszawa', $entry->nearest_area_city);

        $this->submitOn($this->tenantA, $this->poznanPayload('inna@gmail.com'))->assertStatus(400);
        $this->assertSame(1, ServiceAreaWaitlist::withoutGlobalScopes()->count());
    }

    public function test_panel_list_shows_only_the_current_tenants_entries(): void
    {
        $entryA = $this->entryFor($this->tenantA, 'a@gmail.com');
        $entryB = $this->entryFor($this->tenantB, 'b@gmail.com');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');
        $this->actingAs($superAdmin);
        $this->actAsTenant($this->tenantB);

        Livewire::test(ListServiceAreaWaitlists::class)
            ->assertCanSeeTableRecords([$entryB])
            ->assertCanNotSeeTableRecords([$entryA]);
    }

    public function test_navigation_badge_counts_only_the_current_tenants_pending_entries(): void
    {
        $this->entryFor($this->tenantA, 'a1@gmail.com');
        $this->entryFor($this->tenantA, 'a2@gmail.com');
        $this->entryFor($this->tenantB, 'b1@gmail.com');
        $this->entryFor($this->tenantB, 'b2@gmail.com', 'declined');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');
        $this->actingAs($superAdmin);
        $this->actAsTenant($this->tenantB);

        $this->assertSame('1', ServiceAreaWaitlistResource::getNavigationBadge());
    }

    public function test_service_area_waitlist_counter_ignores_other_tenants_entries_for_the_same_city(): void
    {
        $areaA = $this->serviceAreaFor($this->tenantA, 'Warszawa', 52.2297, 21.0122);
        $this->entryFor($this->tenantA, 'a@gmail.com');
        $this->entryFor($this->tenantB, 'b@gmail.com'); // same nearest_area_city = Warszawa

        $this->actAsTenant($this->tenantA);

        $this->assertSame(1, ServiceArea::withCount('waitlistEntries')->findOrFail($areaA->id)->waitlist_entries_count);
    }
}
