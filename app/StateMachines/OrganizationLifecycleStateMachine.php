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
    public function transitions(): array
    {
        return [
            'active' => ['suspended', 'closing'],
            'suspended' => ['active', 'closing'],
            'closing' => ['active', 'closed'],
        ];
    }

    public function canTransition(
        string|OrganizationLifecycleState $from,
        string|OrganizationLifecycleState $to,
    ): bool {
        $fromValue = $from instanceof OrganizationLifecycleState ? $from->value : $from;
        $toValue = $to instanceof OrganizationLifecycleState ? $to->value : $to;

        return in_array($toValue, $this->transitions()[$fromValue] ?? [], true);
    }

    /**
     * Assert the transition is legal; throws on illegal transitions.
     * Does not persist anything — persistence is the caller's responsibility.
     *
     * @throws InvalidLifecycleTransitionException
     */
    public function transition(
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
