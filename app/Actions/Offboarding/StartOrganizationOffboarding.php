<?php

declare(strict_types=1);

namespace App\Actions\Offboarding;

use App\Enums\OrganizationLifecycleState;
use App\Jobs\CancelInFlightObligationsJob;
use App\Jobs\ExportOrganizationDataJob;
use App\Models\Organization;
use App\Models\OrganizationLifecycleLog;
use App\Models\User;
use App\Notifications\OrganizationOffboardingStartedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Initiates graceful offboarding for a closing organization.
 *
 * Flow:
 * 1. Authorize: actor must be super-admin (defense-in-depth on top of Filament authorize).
 * 2. Transition org → Closing inside a DB transaction (forceLifecycleTransition bypasses
 *    obligation guard because the job handles them asynchronously). Org is Closing in DB
 *    before the job is dispatched — prevents new bookings in the race window.
 * 3. Dispatch CancelInFlightObligationsJob (async) — cancels all in-flight obligations
 *    and sends cancellation notifications to affected customers. Dispatched AFTER the
 *    transaction commits so workers see org as Closing.
 * 4. Notify org owner about the offboarding process and the restore grace window.
 * 5. Audit log.
 *
 * Security: only callable from super-admin Filament actions or CLI (no HTTP route).
 * The org observer sets closing_initiated_at as a side-effect of the Closing transition.
 */
class StartOrganizationOffboarding
{
    public function execute(Organization $org, ?User $actor = null): void
    {
        $actor ??= Auth::user();
        if (! $actor?->hasRole('super-admin')) {
            throw new AuthorizationException(
                'Only super-admins can initiate organization offboarding.'
            );
        }

        // Step 1: transition to Closing inside a transaction — org is Closing before job runs
        DB::transaction(function () use ($org): void {
            $org->forceLifecycleTransition = true;
            $org->lifecycle_state = OrganizationLifecycleState::Closing;
            $org->save();
        });

        // Step 2: async cancellation of in-flight obligations (fires customer notifications).
        // Dispatched outside the transaction — workers are guaranteed to see org as Closing.
        CancelInFlightObligationsJob::dispatch($org->id, 'Zamknięcie działalności');

        // Step 3: queue data export (Art. 28(3)(g) RODO — processor returns data to controller).
        // Dispatched async; gracefully skips notification when owner is null (see job guard).
        ExportOrganizationDataJob::dispatch($org);
        OrganizationLifecycleLog::record($org, 'data_export_queued', $actor);

        // Step 4: notify org owner
        if ($org->owner) {
            $org->owner->notify(new OrganizationOffboardingStartedNotification($org));
        } else {
            Log::warning('StartOrganizationOffboarding: org has no owner, skipping notification', [
                'organization_id' => $org->id,
            ]);
        }

        // Step 5: durable audit log + application log
        $fresh = $org->fresh();
        OrganizationLifecycleLog::record($org, 'offboarding_started', $actor, [
            'closing_initiated_at' => $fresh->closing_initiated_at?->toIso8601String(),
        ]);

        Log::info('StartOrganizationOffboarding: offboarding initiated', [
            'organization_id' => $org->id,
            'organization_name' => $org->name,
            'initiated_by' => $actor->id,
            'closing_initiated_at' => $fresh->closing_initiated_at?->toIso8601String(),
        ]);
    }
}
