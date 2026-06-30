<?php

declare(strict_types=1);

namespace App\Actions\Offboarding;

use App\Enums\OrganizationLifecycleState;
use App\Jobs\CancelInFlightObligationsJob;
use App\Models\Organization;
use App\Notifications\OrganizationOffboardingStartedNotification;
use Illuminate\Support\Facades\Log;

/**
 * Initiates graceful offboarding for a closing organization.
 *
 * Flow:
 * 1. Dispatch CancelInFlightObligationsJob (async) — cancels all in-flight obligations
 *    and sends cancellation notifications to affected customers.
 * 2. Transition org → Closing (forceLifecycleTransition bypasses obligation guard
 *    because the job handles them asynchronously).
 * 3. Notify org owner about the offboarding process and the restore grace window.
 * 4. Audit log.
 *
 * Security: only callable from super-admin Filament actions or CLI (no HTTP route).
 * The org observer sets closing_initiated_at as a side-effect of the Closing transition.
 */
class StartOrganizationOffboarding
{
    public function execute(Organization $org): void
    {
        // Step 1: async cancellation of in-flight obligations (fires customer notifications)
        CancelInFlightObligationsJob::dispatch($org->id, 'Zamknięcie działalności');

        // Step 2: transition to Closing — bypass obligation guard (job handles them)
        $org->forceLifecycleTransition = true;
        $org->lifecycle_state = OrganizationLifecycleState::Closing;
        $org->save();

        // Step 3: notify org owner
        if ($org->owner) {
            $org->owner->notify(new OrganizationOffboardingStartedNotification($org));
        } else {
            Log::warning('StartOrganizationOffboarding: org has no owner, skipping notification', [
                'organization_id' => $org->id,
            ]);
        }

        // Step 4: audit
        Log::info('StartOrganizationOffboarding: offboarding initiated', [
            'organization_id' => $org->id,
            'organization_name' => $org->name,
            'closing_initiated_at' => $org->fresh()->closing_initiated_at?->toIso8601String(),
        ]);
    }
}
