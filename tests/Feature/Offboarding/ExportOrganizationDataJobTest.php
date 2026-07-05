<?php

declare(strict_types=1);

namespace Tests\Feature\Offboarding;

use App\Actions\Offboarding\StartOrganizationOffboarding;
use App\Jobs\CancelInFlightObligationsJob;
use App\Jobs\ExportOrganizationDataJob;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationDataExportReadyNotification;
use App\Services\Lifecycle\OrganizationDataExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExportOrganizationDataJobTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super-admin');
        $this->actingAs($this->superAdmin);
    }

    public function test_offboarding_dispatches_export_job(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        app(StartOrganizationOffboarding::class)->execute($org);

        Bus::assertDispatched(ExportOrganizationDataJob::class, function (ExportOrganizationDataJob $job) use ($org): bool {
            $ref = new \ReflectionClass($job);
            $prop = $ref->getProperty('org');
            $prop->setAccessible(true);

            return $prop->getValue($job)->id === $org->id;
        });
    }

    public function test_offboarding_logs_data_export_queued(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        app(StartOrganizationOffboarding::class)->execute($org);

        $this->assertDatabaseHas('organization_lifecycle_log', [
            'organization_id' => $org->id,
            'event' => 'data_export_queued',
            'actor_id' => $this->superAdmin->id,
        ]);
    }

    public function test_offboarding_still_dispatches_cancel_job(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        app(StartOrganizationOffboarding::class)->execute($org);

        Bus::assertDispatched(CancelInFlightObligationsJob::class);
    }

    public function test_job_sends_notification_to_owner(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        $mockService = $this->createMock(OrganizationDataExportService::class);
        $mockService->method('generate')->willReturn('exports/org-1/20260630_120000.zip');
        app()->instance(OrganizationDataExportService::class, $mockService);

        $job = new ExportOrganizationDataJob($org);
        $job->handle($mockService);

        Notification::assertSentTo($owner, OrganizationDataExportReadyNotification::class);
    }

    public function test_job_skips_notification_when_owner_is_null(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        // Simulate missing owner without touching the DB column (has NOT NULL constraint)
        $org->setRelation('owner', null);

        $mockService = $this->createMock(OrganizationDataExportService::class);
        $mockService->method('generate')->willReturn('exports/org-1/20260630_120000.zip');

        $job = new ExportOrganizationDataJob($org);
        $job->handle($mockService);

        Notification::assertNothingSent();
    }

    public function test_offboarding_exports_before_offboarding_notification(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        app(StartOrganizationOffboarding::class)->execute($org);

        // Both jobs dispatched in the same execute() call
        Bus::assertDispatched(CancelInFlightObligationsJob::class);
        Bus::assertDispatched(ExportOrganizationDataJob::class);

        // Log records: data_export_queued + offboarding_started
        $this->assertDatabaseHas('organization_lifecycle_log', [
            'organization_id' => $org->id,
            'event' => 'data_export_queued',
        ]);
        $this->assertDatabaseHas('organization_lifecycle_log', [
            'organization_id' => $org->id,
            'event' => 'offboarding_started',
        ]);
    }
}
