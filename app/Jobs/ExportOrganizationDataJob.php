<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Organization;
use App\Notifications\OrganizationDataExportReadyNotification;
use App\Services\Lifecycle\OrganizationDataExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Generates the data export ZIP for a closing organization and notifies the owner.
 *
 * Dispatched async by StartOrganizationOffboarding after the org transitions to Closing.
 * Also dispatchable synchronously (dispatchSync) from the CLI command.
 *
 * Legal: Art. 28(3)(g) RODO — processor returns all personal data to the controller
 * upon service termination.
 *
 * Guard: if owner is null, the export is still generated and logged, but the
 * notification is skipped gracefully — offboarding must not crash on missing owner.
 *
 * Queue: default
 */
class ExportOrganizationDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly Organization $org,
    ) {}

    public function handle(OrganizationDataExportService $exportService): void
    {
        $org = $this->org;
        $owner = $org->owner;

        Log::info('ExportOrganizationDataJob: start', [
            'org_id' => $org->id,
            'org_name' => $org->name,
            'owner_id' => $owner?->id,
        ]);

        try {
            $relativePath = $exportService->generate($org);
        } catch (\Throwable $e) {
            Log::error('ExportOrganizationDataJob: generate failed', [
                'org_id' => $org->id,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }

        // On a dedicated tenant stack the download route is not registered (see
        // routes/web.php — it is the one /platform route not owned by
        // PlatformPanelProvider, and an unauthenticated full-PII endpoint has no
        // place in a client container). The export itself still has to work there,
        // because `organizations:export-data` is reachable over `compose exec`;
        // whoever runs it already has shell access, so the path on disk is more
        // useful to them than a signed URL would be.
        // Keyed on the cause (this is a tenant stack), not on Route::has() — the
        // symptom. Gating on the missing route would also swallow a stale route
        // cache or a provider bug on the shared stack, turning a loud failure
        // into a silent "export done, owner never notified". Same failure class
        // as the dedupe bug in PR #141.
        if (filled(config('app.tenant_slug'))) {
            Log::info('ExportOrganizationDataJob: completed without a download link (tenant stack)', [
                'org_id' => $org->id,
                'path' => $relativePath,
            ]);

            return;
        }

        $signedUrl = URL::temporarySignedRoute(
            'platform.organization.data-export',
            now()->addDays(7),
            [
                'organization' => $org->id,
                'file' => $relativePath,
            ]
        );

        if ($owner !== null) {
            $owner->notify(
                new OrganizationDataExportReadyNotification($signedUrl, $org->name, $org->id)
            );
        } else {
            Log::warning('ExportOrganizationDataJob: owner is null, notification skipped', [
                'org_id' => $org->id,
            ]);
        }

        Log::info('ExportOrganizationDataJob: completed', [
            'org_id' => $org->id,
            'path' => $relativePath,
            'owner_notified_id' => $owner?->id,
            'owner_notified' => $owner !== null ? 'yes' : 'no',
            'link_expires_days' => 7,
        ]);
    }
}
