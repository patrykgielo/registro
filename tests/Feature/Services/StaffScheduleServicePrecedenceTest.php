<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Organization;
use App\Models\StaffDateException;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Services\StaffScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Precedence between an all-day StaffDateException and a time-specific override
 * on the same date is otherwise undefined (both rows are legal per the unique
 * constraint, and the original code short-circuited on whichever exception the
 * unordered query happened to return first). This test locks in the intended
 * rule: time-specific exceptions always win over all-day exceptions.
 */
class StaffScheduleServicePrecedenceTest extends TestCase
{
    use RefreshDatabase;

    private StaffScheduleService $service;

    private User $staff;

    private Organization $org;

    private Carbon $exceptionDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new StaffScheduleService;

        $this->org = Organization::factory()->autoDetailing()->create();
        $this->app['request']->attributes->set('tenant', $this->org);

        $this->staff = User::factory()->create();
        $this->staff->assignRole('staff');

        // Pick a fixed Monday far enough in the future to avoid edge effects.
        $this->exceptionDate = Carbon::parse('next monday')->startOfDay();

        // Base schedule: available every Monday 09:00-17:00.
        StaffSchedule::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->staff->id,
            'day_of_week' => $this->exceptionDate->dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_active' => true,
        ]);
    }

    public function test_time_specific_available_override_wins_over_all_day_unavailable(): void
    {
        // All-day "unavailable" exception for the whole date.
        StaffDateException::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->staff->id,
            'exception_date' => $this->exceptionDate->format('Y-m-d'),
            'exception_type' => StaffDateException::TYPE_UNAVAILABLE,
            'start_time' => null,
            'end_time' => null,
            'reason' => 'Dzień wolny',
        ]);

        // Time-specific "available" override for a 13:00-14:00 window.
        StaffDateException::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->staff->id,
            'exception_date' => $this->exceptionDate->format('Y-m-d'),
            'exception_type' => StaffDateException::TYPE_AVAILABLE,
            'start_time' => '13:00:00',
            'end_time' => '14:00:00',
            'reason' => 'Wyjątkowa dostępność',
        ]);

        // Outside the override window: blocked by the all-day exception.
        $this->assertFalse(
            $this->service->isStaffAvailable($this->staff, $this->exceptionDate->copy()->setTime(10, 0))
        );

        // Inside the override window: the specific override wins.
        $this->assertTrue(
            $this->service->isStaffAvailable($this->staff, $this->exceptionDate->copy()->setTime(13, 30))
        );

        $slots = $this->service->getAvailableSlots($this->staff, $this->exceptionDate, 30);
        $slotTimes = collect($slots)->map(fn (Carbon $slot) => $slot->format('H:i'))->all();

        $this->assertContains('13:00', $slotTimes);
        $this->assertContains('13:30', $slotTimes);
        // 14:00 is the (inclusive) upper bound of the override window, still available.
        $this->assertContains('14:00', $slotTimes);
        $this->assertNotContains('10:00', $slotTimes);
        // 14:30 is past the override window — the all-day exception blocks it again.
        $this->assertNotContains('14:30', $slotTimes);
    }

    public function test_time_specific_unavailable_override_wins_over_all_day_available(): void
    {
        // All-day "available" exception (e.g. explicitly opened up an otherwise-closed day).
        StaffDateException::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->staff->id,
            'exception_date' => $this->exceptionDate->format('Y-m-d'),
            'exception_type' => StaffDateException::TYPE_AVAILABLE,
            'start_time' => null,
            'end_time' => null,
            'reason' => 'Cały dzień dostępny',
        ]);

        // Time-specific "unavailable" override blocking a lunch window.
        StaffDateException::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->staff->id,
            'exception_date' => $this->exceptionDate->format('Y-m-d'),
            'exception_type' => StaffDateException::TYPE_UNAVAILABLE,
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
            'reason' => 'Przerwa',
        ]);

        $this->assertTrue(
            $this->service->isStaffAvailable($this->staff, $this->exceptionDate->copy()->setTime(10, 0))
        );

        $this->assertFalse(
            $this->service->isStaffAvailable($this->staff, $this->exceptionDate->copy()->setTime(12, 30))
        );

        $slots = $this->service->getAvailableSlots($this->staff, $this->exceptionDate, 30);
        $slotTimes = collect($slots)->map(fn (Carbon $slot) => $slot->format('H:i'))->all();

        $this->assertContains('10:00', $slotTimes);
        $this->assertNotContains('12:00', $slotTimes);
        $this->assertNotContains('12:30', $slotTimes);
        // 13:00 is the (inclusive) upper bound of the "unavailable" override window.
        $this->assertNotContains('13:00', $slotTimes);
        // 13:30 is past the override window — the all-day "available" exception applies again.
        $this->assertContains('13:30', $slotTimes);
    }
}
