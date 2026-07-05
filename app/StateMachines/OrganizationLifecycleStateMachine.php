<?php

declare(strict_types=1);

namespace App\StateMachines;

use App\Enums\OrganizationLifecycleState;
use App\Exceptions\InvalidLifecycleTransitionException;

class OrganizationLifecycleStateMachine
{
    /**
     * Allowed transitions keyed by $from state value.
     * Closed is terminal — no outgoing transitions.
     */
    private function transitions(): array
    {
        return [
            'active' => ['suspended', 'closing'],
            'suspended' => ['active', 'closing'],
            'closing' => ['active', 'closed'],
        ];
    }

    /**
     * Returns true when the transition $from → $to is in the allowed set.
     *
     * @throws \ValueError when $from is not a valid OrganizationLifecycleState value
     */
    public function canTransition(
        string|OrganizationLifecycleState $from,
        string|OrganizationLifecycleState $to,
    ): bool {
        $fromEnum = $from instanceof OrganizationLifecycleState
            ? $from
            : (OrganizationLifecycleState::tryFrom($from)
                ?? throw new \ValueError("Invalid lifecycle state value: '{$from}'"));

        $toValue = $to instanceof OrganizationLifecycleState ? $to->value : $to;

        return in_array($toValue, $this->transitions()[$fromEnum->value] ?? [], true);
    }

    /**
     * Assert the transition is legal; throws on illegal transitions.
     * Does NOT persist anything — persistence is the caller's responsibility.
     *
     * @throws InvalidLifecycleTransitionException
     * @throws \ValueError when $from is not a valid OrganizationLifecycleState value
     */
    public function assertTransitionAllowed(
        string|OrganizationLifecycleState $from,
        string|OrganizationLifecycleState $to,
    ): void {
        if (! $this->canTransition($from, $to)) {
            $fromValue = $from instanceof OrganizationLifecycleState ? $from->value : $from;
            $toValue = $to instanceof OrganizationLifecycleState ? $to->value : $to;

            throw new InvalidLifecycleTransitionException(
                "Cannot transition organization lifecycle from [{$fromValue}] to [{$toValue}]."
            );
        }
    }
}
