<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ServiceAreaWaitlists\ServiceAreaWaitlistResource;
use App\Models\Organization;
use App\Models\ServiceAreaWaitlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ServiceAreaWaitlist has no organization_id / BelongsToOrganization — a single
 * submission can be "outside area" for several nearby tenants at once, so it
 * deliberately has no single tenant owner (see
 * app/docs/security/vulnerabilities/VULN-003-root-domain-tenant-bypass.md).
 *
 * Before this fix, ServiceAreaWaitlistResource was gated only by the
 * `service_area` $module flag — any tenant admin who enabled that module could
 * browse EVERY tenant's waitlist submissions (name, email, phone, address,
 * GPS coordinates). Fixed by restricting canViewAny/canView/canEdit/canDelete
 * to super-admin, mirroring AuditLogResource / MaintenanceEventResource /
 * EmailEventResource / SmsEventResource.
 */
class ServiceAreaWaitlistResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    private function makeWaitlistEntry(): ServiceAreaWaitlist
    {
        return ServiceAreaWaitlist::create([
            'email' => 'jan.kowalski@gmail.com',
            'name' => 'Jan Kowalski',
            'phone' => '+48123456789',
            'requested_address' => 'Stary Rynek 1, Poznań',
            'requested_latitude' => 52.4064,
            'requested_longitude' => 16.9252,
            'nearest_area_city' => 'Warszawa',
            'distance_to_nearest_area_km' => 250.0,
            'status' => 'pending',
        ]);
    }

    public function test_tenant_admin_cannot_view_any_waitlist_entries(): void
    {
        $org = Organization::factory()->create();
        $tenantAdmin = User::factory()->create();
        $tenantAdmin->assignRole('admin');
        $tenantAdmin->organizations()->attach($org->id);

        $this->actingAs($tenantAdmin);

        $this->assertFalse(ServiceAreaWaitlistResource::canViewAny());
    }

    public function test_tenant_admin_cannot_view_individual_waitlist_entry(): void
    {
        $org = Organization::factory()->create();
        $tenantAdmin = User::factory()->create();
        $tenantAdmin->assignRole('admin');
        $tenantAdmin->organizations()->attach($org->id);

        $entry = $this->makeWaitlistEntry();

        $this->actingAs($tenantAdmin);

        $this->assertFalse(ServiceAreaWaitlistResource::canView($entry));
    }

    public function test_tenant_admin_cannot_edit_or_delete_waitlist_entry(): void
    {
        $org = Organization::factory()->create();
        $tenantAdmin = User::factory()->create();
        $tenantAdmin->assignRole('admin');
        $tenantAdmin->organizations()->attach($org->id);

        $entry = $this->makeWaitlistEntry();

        $this->actingAs($tenantAdmin);

        $this->assertFalse(ServiceAreaWaitlistResource::canEdit($entry));
        $this->assertFalse(ServiceAreaWaitlistResource::canDelete($entry));
    }

    public function test_navigation_badge_hidden_for_tenant_admin(): void
    {
        $org = Organization::factory()->create();
        $tenantAdmin = User::factory()->create();
        $tenantAdmin->assignRole('admin');
        $tenantAdmin->organizations()->attach($org->id);

        $this->makeWaitlistEntry();

        $this->actingAs($tenantAdmin);

        $this->assertNull(ServiceAreaWaitlistResource::getNavigationBadge());
    }

    public function test_super_admin_can_view_any_waitlist_entries(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $this->actingAs($superAdmin);

        $this->assertTrue(ServiceAreaWaitlistResource::canViewAny());
    }

    public function test_super_admin_can_view_edit_and_delete_individual_entry(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $entry = $this->makeWaitlistEntry();

        $this->actingAs($superAdmin);

        $this->assertTrue(ServiceAreaWaitlistResource::canView($entry));
        $this->assertTrue(ServiceAreaWaitlistResource::canEdit($entry));
        $this->assertTrue(ServiceAreaWaitlistResource::canDelete($entry));
    }

    public function test_navigation_badge_visible_for_super_admin(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $this->makeWaitlistEntry();

        $this->actingAs($superAdmin);

        $this->assertSame('1', ServiceAreaWaitlistResource::getNavigationBadge());
    }

    public function test_guest_cannot_view_any_waitlist_entries(): void
    {
        $this->assertFalse(ServiceAreaWaitlistResource::canViewAny());
    }

    /**
     * Primary end-to-end regression guard for the actual PII leak: a tenant
     * admin hitting the REAL registered route directly (bypassing the
     * sidebar's $module gate entirely) must be blocked at the HTTP layer,
     * not just at the canViewAny() method level.
     */
    public function test_tenant_admin_gets_forbidden_response_on_real_route(): void
    {
        $org = Organization::factory()->create();
        $org->enableModule('service_area');

        $tenantAdmin = User::factory()->create();
        $tenantAdmin->assignRole('admin');
        $tenantAdmin->organizations()->attach($org->id);

        $this->makeWaitlistEntry();

        $response = $this->actingAs($tenantAdmin)
            ->get("http://{$org->slug}.registro.local/admin/service-area-waitlists");

        $response->assertForbidden();
    }

    /**
     * Positive control: a super-admin (the still-supported access path) must
     * NOT be blocked by the same fix.
     */
    public function test_super_admin_gets_non_forbidden_response_on_real_route(): void
    {
        $org = Organization::factory()->create();
        $org->enableModule('service_area');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $this->makeWaitlistEntry();

        $response = $this->actingAs($superAdmin)
            ->get("http://{$org->slug}.registro.local/admin/service-area-waitlists");

        $response->assertOk();
    }
}
