<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Filament\Platform\Resources\OrganizationLifecycleLogResource;
use App\Models\Organization;
use App\Models\OrganizationLifecycleLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrganizationLifecycleLogResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $regularUser;

    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super-admin');

        $this->regularUser = User::factory()->create();
        $this->regularUser->assignRole('admin');

        $this->org = Organization::factory()->create(['owner_id' => $this->superAdmin->id]);
    }

    public function test_super_admin_can_view_any(): void
    {
        $this->actingAs($this->superAdmin);

        $this->assertTrue(OrganizationLifecycleLogResource::canViewAny());
    }

    public function test_non_super_admin_cannot_view_any(): void
    {
        $this->actingAs($this->regularUser);

        $this->assertFalse(OrganizationLifecycleLogResource::canViewAny());
    }

    public function test_cannot_create(): void
    {
        $this->actingAs($this->superAdmin);

        $this->assertFalse(OrganizationLifecycleLogResource::canCreate());
    }

    public function test_cannot_edit(): void
    {
        $this->actingAs($this->superAdmin);

        $log = OrganizationLifecycleLog::record($this->org, 'offboarding_started', $this->superAdmin);

        $this->assertFalse(OrganizationLifecycleLogResource::canEdit($log));
    }

    public function test_cannot_delete(): void
    {
        $this->actingAs($this->superAdmin);

        $log = OrganizationLifecycleLog::record($this->org, 'offboarding_started', $this->superAdmin);

        $this->assertFalse(OrganizationLifecycleLogResource::canDelete($log));
    }

    public function test_cannot_delete_any(): void
    {
        $this->actingAs($this->superAdmin);

        $this->assertFalse(OrganizationLifecycleLogResource::canDeleteAny());
    }

    public function test_can_view_individual_record(): void
    {
        $this->actingAs($this->superAdmin);

        $log = OrganizationLifecycleLog::record($this->org, 'closure_requested', $this->superAdmin);

        $this->assertTrue(OrganizationLifecycleLogResource::canView($log));
    }

    public function test_log_entries_are_queryable(): void
    {
        OrganizationLifecycleLog::record($this->org, 'offboarding_started', $this->superAdmin, ['key' => 'val']);
        OrganizationLifecycleLog::record($this->org, 'data_export_queued', $this->superAdmin);

        $this->assertDatabaseCount('organization_lifecycle_log', 2);

        $query = OrganizationLifecycleLog::query()->orderBy('created_at', 'desc');
        $this->assertEquals(2, $query->count());
    }

    public function test_resource_model_is_lifecycle_log(): void
    {
        $this->assertSame(
            OrganizationLifecycleLog::class,
            OrganizationLifecycleLogResource::getModel()
        );
    }

    public function test_no_updated_at_on_model(): void
    {
        $log = OrganizationLifecycleLog::record($this->org, 'test_event');

        $this->assertNull(OrganizationLifecycleLog::UPDATED_AT);
        $this->assertNotNull($log->created_at);
    }

    public function test_view_page_renders_infolist(): void
    {
        $this->actingAs($this->superAdmin);

        $log = OrganizationLifecycleLog::record($this->org, 'offboarding_started', $this->superAdmin, ['note' => 'verify']);

        $this->get(OrganizationLifecycleLogResource::getUrl('view', ['record' => $log], panel: 'platform'))
            ->assertOk();
    }

    public function test_view_page_renders_infolist_without_context(): void
    {
        $this->actingAs($this->superAdmin);

        $log = OrganizationLifecycleLog::record($this->org, 'suspended', $this->superAdmin);

        $this->get(OrganizationLifecycleLogResource::getUrl('view', ['record' => $log], panel: 'platform'))
            ->assertOk();
    }
}
