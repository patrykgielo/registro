<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\OrganizationLifecycleState;
use App\Exceptions\OrganizationHasActiveObligationsException;
use App\Exceptions\OrganizationNotClosedException;
use App\Models\Organization;
use App\Services\TenantObligationService;
use App\StateMachines\OrganizationLifecycleStateMachine;

class OrganizationObserver
{
    public function __construct(
        private readonly TenantObligationService $obligations,
        private readonly OrganizationLifecycleStateMachine $stateMachine,
    ) {}

    /**
     * Derives is_active from lifecycle_state on initial creation.
     *
     * No transition validation — any initial state is valid.
     * Defaults to Active when lifecycle_state is not explicitly set (mirrors DB default).
     */
    public function creating(Organization $org): void
    {
        $state = $org->lifecycle_state ?? OrganizationLifecycleState::Active;
        $org->is_active = ($state === OrganizationLifecycleState::Active);
    }

    /**
     * Validates lifecycle_state transitions before persisting.
     *
     * Guards (in order):
     * 1. Illegal transition → InvalidLifecycleTransitionException (from state machine)
     * 2. Closing/Closed with active obligations → OrganizationHasActiveObligationsException
     *    (bypassed by $org->forceLifecycleTransition = true)
     *
     * Side-effects set on the in-memory model (persisted with the same save()):
     * - is_active is derived from lifecycle_state (Active = true, all others = false)
     * - Lifecycle timestamps: closing_initiated_at, closed_at (W8)
     */
    public function updating(Organization $org): void
    {
        if (! $org->isDirty('lifecycle_state')) {
            return;
        }

        $original = $org->getOriginal('lifecycle_state');
        $from = is_string($original)
            ? OrganizationLifecycleState::from($original)
            : $original;

        $to = $org->lifecycle_state;

        // Guard 1: validate the transition is allowed (throws on illegal)
        $this->stateMachine->assertTransitionAllowed($from, $to);

        // Guard 2: block Closing/Closed when active obligations exist
        $blocksOnObligations = [OrganizationLifecycleState::Closing, OrganizationLifecycleState::Closed];

        if (in_array($to, $blocksOnObligations, true) && ! $org->forceLifecycleTransition) {
            $counts = $this->obligations->activeObligations($org);

            if ($counts['total'] > 0) {
                throw new OrganizationHasActiveObligationsException(
                    "Cannot transition organization [{$org->id}] to [{$to->value}]: "
                    ."{$counts['appointments']} active appointment(s), "
                    ."{$counts['orders']} active order(s), "
                    ."{$counts['rentals']} active rental(s). "
                    .'Resolve them first or set $forceLifecycleTransition = true.'
                );
            }
        }

        // F003: keep is_active in sync with lifecycle_state (derived field)
        $org->is_active = ($to === OrganizationLifecycleState::Active);

        // W8: lifecycle timestamps — set alongside the state change, same transaction
        if ($to === OrganizationLifecycleState::Closing) {
            $org->closing_initiated_at = now();
        } elseif ($to === OrganizationLifecycleState::Closed) {
            $org->closed_at = now();
        } elseif ($to === OrganizationLifecycleState::Active
            && $from === OrganizationLifecycleState::Closing
        ) {
            // Closing → Active (restore): clear closing timestamps
            $org->closing_initiated_at = null;
            $org->purge_after = null;
        }
    }

    /**
     * Resets the forceLifecycleTransition flag so it cannot leak to future saves
     * on the same model instance.
     */
    public function updated(Organization $org): void
    {
        $org->forceLifecycleTransition = false;
    }

    /**
     * Prevents hard-delete unless the organization is Closed and has no active obligations.
     *
     * Guards (in order):
     * 1. bypassDeleteGuard = true → skip all checks
     * 2. lifecycle_state !== Closed → OrganizationNotClosedException
     * 3. Active obligations exist → OrganizationHasActiveObligationsException
     *
     * Set $org->bypassDeleteGuard = true to skip all checks (CLI offboarding tools only).
     */
    public function deleting(Organization $org): void
    {
        if ($org->bypassDeleteGuard) {
            return;
        }

        if ($org->lifecycle_state !== OrganizationLifecycleState::Closed) {
            throw new OrganizationNotClosedException(
                "Cannot delete organization [{$org->id}]: "
                ."lifecycle_state must be 'closed' (current: '{$org->lifecycle_state?->value}'). "
                .'Initiate the closure process first.'
            );
        }

        $counts = $this->obligations->activeObligations($org);

        if ($counts['total'] > 0) {
            throw new OrganizationHasActiveObligationsException(
                "Cannot delete organization [{$org->id}]: resolve "
                ."{$counts['appointments']} appointment(s), "
                ."{$counts['orders']} order(s), "
                ."{$counts['rentals']} rental(s) first, "
                .'then initiate the closure process.'
            );
        }
    }

    /**
     * Resets the bypassDeleteGuard flag after deletion completes.
     */
    public function deleted(Organization $org): void
    {
        $org->bypassDeleteGuard = false;
    }
}
