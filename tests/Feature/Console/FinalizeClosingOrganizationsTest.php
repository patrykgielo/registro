<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\OrganizationLifecycleState;
use App\Jobs\CancelInFlightObligationsJob;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinalizeClosingOrganizationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Config::set('retention.closing_grace_days', 14);
    }

    private function makeClosingOrg(int $daysAgo): Organization
    {
        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        // Directly set to Closing via observer (no obligation check since empty)
        $org->lifecycle_state = OrganizationLifecycleState::Closing;
        $org->save();

        // Backdate closing_initiated_at to simulate elapsed time
        $org->closing_initiated_at = now()->subDays($daysAgo);
        $org->saveQuietly();

        return $org;
    }

    public function test_closing_past_grace_transitions_to_closed(): void
    {
        Bus::fake();

        $org = $this->makeClosingOrg(15); // past 14-day grace

        $this->artisan('organizations:finalize-closing --force')
            ->assertExitCode(0);

        $this->assertSame(OrganizationLifecycleState::Closed, $org->fresh()->lifecycle_state);
    }

    public function test_closed_at_set_on_finalization(): void
    {
        Bus::fake();

        $org = $this->makeClosingOrg(15);

        $this->artisan('organizations:finalize-closing --force')
            ->assertExitCode(0);

        $this->assertNotNull($org->fresh()->closed_at);
    }

    public function test_purge_after_set_on_finalization(): void
    {
        Bus::fake();

        $org = $this->makeClosingOrg(15);

        $this->artisan('organizations:finalize-closing --force')
            ->assertExitCode(0);

        $this->assertNotNull($org->fresh()->purge_after);
    }

    public function test_cancel_in_flight_obligations_job_dispatched_defensively(): void
    {
        Bus::fake();

        $org = $this->makeClosingOrg(15);

        $this->artisan('organizations:finalize-closing --force')
            ->assertExitCode(0);

        Bus::assertDispatched(CancelInFlightObligationsJob::class);
    }

    public function test_closing_within_grace_not_finalized(): void
    {
        Bus::fake();

        $org = $this->makeClosingOrg(5); // within 14-day grace

        $this->artisan('organizations:finalize-closing --force')
            ->assertExitCode(0);

        $this->assertSame(OrganizationLifecycleState::Closing, $org->fresh()->lifecycle_state);
    }

    public function test_non_closing_org_not_touched(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);
        // Default is Active

        $this->artisan('organizations:finalize-closing --force')
            ->assertExitCode(0);

        $this->assertSame(OrganizationLifecycleState::Active, $org->fresh()->lifecycle_state);
    }

    public function test_suspended_org_not_touched(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->inactive()->create(['owner_id' => $owner->id]);

        $this->artisan('organizations:finalize-closing --force')
            ->assertExitCode(0);

        $this->assertSame(OrganizationLifecycleState::Suspended, $org->fresh()->lifecycle_state);
    }

    public function test_dry_run_does_not_change_state(): void
    {
        Bus::fake();

        $org = $this->makeClosingOrg(15);

        $this->artisan('organizations:finalize-closing --dry-run')
            ->assertExitCode(0);

        $this->assertSame(OrganizationLifecycleState::Closing, $org->fresh()->lifecycle_state);
        Bus::assertNotDispatched(CancelInFlightObligationsJob::class);
    }

    public function test_multiple_eligible_orgs_all_finalized(): void
    {
        Bus::fake();

        $org1 = $this->makeClosingOrg(20);
        $org2 = $this->makeClosingOrg(16);

        $this->artisan('organizations:finalize-closing --force')
            ->assertExitCode(0);

        $this->assertSame(OrganizationLifecycleState::Closed, $org1->fresh()->lifecycle_state);
        $this->assertSame(OrganizationLifecycleState::Closed, $org2->fresh()->lifecycle_state);
    }

    public function test_regression_reactivate_after_offboarding_still_works(): void
    {
        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        // Enter Closing
        $org->lifecycle_state = OrganizationLifecycleState::Closing;
        $org->save();
        $this->assertNotNull($org->fresh()->closing_initiated_at);

        // Reactivate: Closing → Active
        $org->lifecycle_state = OrganizationLifecycleState::Active;
        $org->save();

        $fresh = $org->fresh();
        $this->assertSame(OrganizationLifecycleState::Active, $fresh->lifecycle_state);
        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->closing_initiated_at);
        $this->assertNull($fresh->purge_after);
    }
}
