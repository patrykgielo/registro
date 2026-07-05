<?php

namespace Tests\Unit\Enums;

use App\Enums\OrganizationLifecycleState;
use PHPUnit\Framework\TestCase;

class OrganizationLifecycleStateTest extends TestCase
{
    public function test_all_cases_exist(): void
    {
        $cases = OrganizationLifecycleState::cases();

        $this->assertCount(4, $cases);
        $this->assertContains(OrganizationLifecycleState::Active, $cases);
        $this->assertContains(OrganizationLifecycleState::Suspended, $cases);
        $this->assertContains(OrganizationLifecycleState::Closing, $cases);
        $this->assertContains(OrganizationLifecycleState::Closed, $cases);
    }

    public function test_values(): void
    {
        $this->assertSame('active', OrganizationLifecycleState::Active->value);
        $this->assertSame('suspended', OrganizationLifecycleState::Suspended->value);
        $this->assertSame('closing', OrganizationLifecycleState::Closing->value);
        $this->assertSame('closed', OrganizationLifecycleState::Closed->value);
    }

    public function test_labels(): void
    {
        $this->assertSame('Aktywna', OrganizationLifecycleState::Active->label());
        $this->assertSame('Zawieszona', OrganizationLifecycleState::Suspended->label());
        $this->assertSame('W trakcie zamknięcia', OrganizationLifecycleState::Closing->label());
        $this->assertSame('Zamknięta', OrganizationLifecycleState::Closed->label());
    }

    public function test_allows_public_site_only_for_active(): void
    {
        $this->assertTrue(OrganizationLifecycleState::Active->allowsPublicSite());
        $this->assertFalse(OrganizationLifecycleState::Suspended->allowsPublicSite());
        $this->assertFalse(OrganizationLifecycleState::Closing->allowsPublicSite());
        $this->assertFalse(OrganizationLifecycleState::Closed->allowsPublicSite());
    }

    public function test_allows_new_bookings_only_for_active(): void
    {
        $this->assertTrue(OrganizationLifecycleState::Active->allowsNewBookings());
        $this->assertFalse(OrganizationLifecycleState::Suspended->allowsNewBookings());
        $this->assertFalse(OrganizationLifecycleState::Closing->allowsNewBookings());
        $this->assertFalse(OrganizationLifecycleState::Closed->allowsNewBookings());
    }

    public function test_is_terminal_only_for_closed(): void
    {
        $this->assertFalse(OrganizationLifecycleState::Active->isTerminal());
        $this->assertFalse(OrganizationLifecycleState::Suspended->isTerminal());
        $this->assertFalse(OrganizationLifecycleState::Closing->isTerminal());
        $this->assertTrue(OrganizationLifecycleState::Closed->isTerminal());
    }
}
