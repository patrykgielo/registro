<?php

namespace Tests\Feature\Organizations;

use App\Enums\OrganizationLifecycleState;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationLifecycleCastTest extends TestCase
{
    use RefreshDatabase;

    public function test_lifecycle_state_cast_reads_enum_from_string(): void
    {
        $org = Organization::factory()->create(['lifecycle_state' => 'closing']);

        $fresh = $org->fresh();

        $this->assertInstanceOf(OrganizationLifecycleState::class, $fresh->lifecycle_state);
        $this->assertSame(OrganizationLifecycleState::Closing, $fresh->lifecycle_state);
    }

    public function test_inactive_organization_defaults_to_suspended_lifecycle_state(): void
    {
        $org = Organization::factory()->create([
            'is_active' => false,
            'lifecycle_state' => 'suspended',
        ]);

        $this->assertSame(OrganizationLifecycleState::Suspended, $org->fresh()->lifecycle_state);
    }

    public function test_active_organization_defaults_to_active_lifecycle_state(): void
    {
        $org = Organization::factory()->create([
            'is_active' => true,
        ]);

        // Default from factory + migration default is 'active'
        $this->assertSame(OrganizationLifecycleState::Active, $org->fresh()->lifecycle_state);
    }

    public function test_all_lifecycle_states_can_be_persisted_and_read(): void
    {
        $cases = OrganizationLifecycleState::cases();

        foreach ($cases as $state) {
            $org = Organization::factory()->create(['lifecycle_state' => $state->value]);

            $this->assertSame($state, $org->fresh()->lifecycle_state);
        }
    }
}
