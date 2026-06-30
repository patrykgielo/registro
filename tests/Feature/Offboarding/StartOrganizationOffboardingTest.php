<?php

declare(strict_types=1);

namespace Tests\Feature\Offboarding;

use App\Actions\Offboarding\StartOrganizationOffboarding;
use App\Enums\OrganizationLifecycleState;
use App\Jobs\CancelInFlightObligationsJob;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationOffboardingStartedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StartOrganizationOffboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    }

    public function test_org_transitions_to_closing(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        app(StartOrganizationOffboarding::class)->execute($org);

        $this->assertSame(
            OrganizationLifecycleState::Closing,
            $org->fresh()->lifecycle_state
        );
    }

    public function test_closing_initiated_at_set_by_observer(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        app(StartOrganizationOffboarding::class)->execute($org);

        $this->assertNotNull($org->fresh()->closing_initiated_at);
    }

    public function test_is_active_becomes_false(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        app(StartOrganizationOffboarding::class)->execute($org);

        $this->assertFalse($org->fresh()->is_active);
    }

    public function test_cancel_in_flight_obligations_job_dispatched(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        app(StartOrganizationOffboarding::class)->execute($org);

        Bus::assertDispatched(CancelInFlightObligationsJob::class, function (CancelInFlightObligationsJob $job) use ($org): bool {
            // Access via reflection since properties are readonly private
            $reflection = new \ReflectionClass($job);
            $orgIdProp = $reflection->getProperty('organizationId');
            $orgIdProp->setAccessible(true);

            return $orgIdProp->getValue($job) === $org->id;
        });
    }

    public function test_owner_receives_offboarding_started_notification(): void
    {
        Bus::fake();
        Notification::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        app(StartOrganizationOffboarding::class)->execute($org);

        Notification::assertSentTo($owner, OrganizationOffboardingStartedNotification::class);
    }

    public function test_no_notification_when_org_has_no_owner(): void
    {
        Bus::fake();
        Notification::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        // Seed null into the already-loaded relationship cache without touching the DB column
        // (which has a NOT NULL constraint). StartOrganizationOffboarding accesses $org->owner
        // which reads from the cache, so it gets null → no notification sent.
        $org->setRelation('owner', null);

        // Should not throw, just log a warning
        app(StartOrganizationOffboarding::class)->execute($org);

        Notification::assertNothingSent();
    }

    public function test_regression_reactivate_closing_to_active_still_works(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);
        app(StartOrganizationOffboarding::class)->execute($org);

        $this->assertSame(OrganizationLifecycleState::Closing, $org->fresh()->lifecycle_state);

        // Restore: Closing → Active
        $org->lifecycle_state = OrganizationLifecycleState::Active;
        $org->save();

        $fresh = $org->fresh();
        $this->assertSame(OrganizationLifecycleState::Active, $fresh->lifecycle_state);
        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->closing_initiated_at);
        $this->assertNull($fresh->purge_after);
    }
}
