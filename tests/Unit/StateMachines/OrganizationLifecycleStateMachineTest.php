<?php

namespace Tests\Unit\StateMachines;

use App\Enums\OrganizationLifecycleState;
use App\Exceptions\InvalidLifecycleTransitionException;
use App\StateMachines\OrganizationLifecycleStateMachine;
use PHPUnit\Framework\TestCase;

class OrganizationLifecycleStateMachineTest extends TestCase
{
    private OrganizationLifecycleStateMachine $machine;

    protected function setUp(): void
    {
        $this->machine = new OrganizationLifecycleStateMachine;
    }

    // --- Legal transitions ---

    public function test_active_can_transition_to_suspended(): void
    {
        $this->assertTrue($this->machine->canTransition(OrganizationLifecycleState::Active, OrganizationLifecycleState::Suspended));
        $this->machine->assertTransitionAllowed(OrganizationLifecycleState::Active, OrganizationLifecycleState::Suspended);
        $this->addToAssertionCount(1);
    }

    public function test_active_can_transition_to_closing(): void
    {
        $this->assertTrue($this->machine->canTransition(OrganizationLifecycleState::Active, OrganizationLifecycleState::Closing));
        $this->machine->assertTransitionAllowed(OrganizationLifecycleState::Active, OrganizationLifecycleState::Closing);
        $this->addToAssertionCount(1);
    }

    public function test_suspended_can_transition_to_active(): void
    {
        $this->assertTrue($this->machine->canTransition(OrganizationLifecycleState::Suspended, OrganizationLifecycleState::Active));
        $this->machine->assertTransitionAllowed(OrganizationLifecycleState::Suspended, OrganizationLifecycleState::Active);
        $this->addToAssertionCount(1);
    }

    public function test_suspended_can_transition_to_closing(): void
    {
        $this->assertTrue($this->machine->canTransition(OrganizationLifecycleState::Suspended, OrganizationLifecycleState::Closing));
        $this->machine->assertTransitionAllowed(OrganizationLifecycleState::Suspended, OrganizationLifecycleState::Closing);
        $this->addToAssertionCount(1);
    }

    public function test_closing_can_transition_to_active(): void
    {
        $this->assertTrue($this->machine->canTransition(OrganizationLifecycleState::Closing, OrganizationLifecycleState::Active));
        $this->machine->assertTransitionAllowed(OrganizationLifecycleState::Closing, OrganizationLifecycleState::Active);
        $this->addToAssertionCount(1);
    }

    public function test_closing_can_transition_to_closed(): void
    {
        $this->assertTrue($this->machine->canTransition(OrganizationLifecycleState::Closing, OrganizationLifecycleState::Closed));
        $this->machine->assertTransitionAllowed(OrganizationLifecycleState::Closing, OrganizationLifecycleState::Closed);
        $this->addToAssertionCount(1);
    }

    // --- Illegal transitions (terminal Closed) ---

    public function test_closed_cannot_transition_to_active(): void
    {
        $this->assertFalse($this->machine->canTransition(OrganizationLifecycleState::Closed, OrganizationLifecycleState::Active));
        $this->expectException(InvalidLifecycleTransitionException::class);
        $this->machine->assertTransitionAllowed(OrganizationLifecycleState::Closed, OrganizationLifecycleState::Active);
    }

    public function test_closed_cannot_transition_to_suspended(): void
    {
        $this->assertFalse($this->machine->canTransition(OrganizationLifecycleState::Closed, OrganizationLifecycleState::Suspended));
        $this->expectException(InvalidLifecycleTransitionException::class);
        $this->machine->assertTransitionAllowed(OrganizationLifecycleState::Closed, OrganizationLifecycleState::Suspended);
    }

    public function test_active_cannot_skip_closing_to_reach_closed(): void
    {
        $this->assertFalse($this->machine->canTransition(OrganizationLifecycleState::Active, OrganizationLifecycleState::Closed));
        $this->expectException(InvalidLifecycleTransitionException::class);
        $this->machine->assertTransitionAllowed(OrganizationLifecycleState::Active, OrganizationLifecycleState::Closed);
    }

    public function test_suspended_cannot_skip_closing_to_reach_closed(): void
    {
        $this->assertFalse($this->machine->canTransition(OrganizationLifecycleState::Suspended, OrganizationLifecycleState::Closed));
        $this->expectException(InvalidLifecycleTransitionException::class);
        $this->machine->assertTransitionAllowed(OrganizationLifecycleState::Suspended, OrganizationLifecycleState::Closed);
    }

    // --- String API ---

    public function test_accepts_string_values(): void
    {
        $this->assertTrue($this->machine->canTransition('active', 'suspended'));
        $this->assertFalse($this->machine->canTransition('closed', 'active'));
    }

    public function test_exception_message_includes_state_names(): void
    {
        $this->expectException(InvalidLifecycleTransitionException::class);
        $this->expectExceptionMessage('closed');
        $this->expectExceptionMessage('active');

        $this->machine->assertTransitionAllowed('closed', 'active');
    }

    public function test_invalid_from_state_string_throws_value_error(): void
    {
        $this->expectException(\ValueError::class);

        $this->machine->canTransition('typo_state', 'active');
    }
}
