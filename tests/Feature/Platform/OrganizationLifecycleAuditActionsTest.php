<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\OrganizationLifecycleState;
use App\Filament\Platform\Resources\OrganizationResource\Pages\ListOrganizations;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrganizationLifecycleAuditActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super-admin');

        Filament::setCurrentPanel(Filament::getPanel('platform'));
    }

    public function test_suspend_action_writes_lifecycle_log(): void
    {
        $this->actingAs($this->superAdmin);

        $org = Organization::factory()->create(['lifecycle_state' => OrganizationLifecycleState::Active]);

        Livewire::test(ListOrganizations::class)
            ->callTableAction('suspend', $org);

        $this->assertSame(OrganizationLifecycleState::Suspended, $org->fresh()->lifecycle_state);

        $this->assertDatabaseHas('organization_lifecycle_log', [
            'organization_id' => $org->id,
            'organization_name' => $org->name,
            'event' => 'suspended',
            'actor_id' => $this->superAdmin->id,
            'actor_label' => $this->superAdmin->email,
        ]);

        $this->assertDatabaseCount('organization_lifecycle_log', 1);
    }

    public function test_reactivate_action_writes_lifecycle_log(): void
    {
        $this->actingAs($this->superAdmin);

        $org = Organization::factory()->create();
        $org->lifecycle_state = OrganizationLifecycleState::Suspended;
        $org->save();

        Livewire::test(ListOrganizations::class)
            ->callTableAction('reactivate', $org);

        $this->assertSame(OrganizationLifecycleState::Active, $org->fresh()->lifecycle_state);

        $this->assertDatabaseHas('organization_lifecycle_log', [
            'organization_id' => $org->id,
            'organization_name' => $org->name,
            'event' => 'reactivated',
            'actor_id' => $this->superAdmin->id,
            'actor_label' => $this->superAdmin->email,
        ]);

        $this->assertDatabaseCount('organization_lifecycle_log', 1);
    }
}
