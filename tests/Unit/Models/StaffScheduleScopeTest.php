<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Organization;
use App\Models\StaffSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Boundary-day regression for scopeEffectiveOn() — sibling bug to
 * StaffDateException::scopeOnDate() (see that model's docblock): a raw string where()
 * against a `date`-cast column works on MySQL (native DATE column truncates on write)
 * but silently fails on SQLite (full `Y-m-d H:i:s` string stored), meaning a schedule was
 * NOT detected as effective starting exactly on its own effective_from date.
 */
class StaffScheduleScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_is_effective_starting_exactly_on_effective_from_date(): void
    {
        $org = Organization::factory()->autoDetailing()->create();
        $this->app['request']->attributes->set('tenant', $org);

        $staff = User::factory()->create();

        $schedule = StaffSchedule::create([
            'organization_id' => $org->id,
            'user_id' => $staff->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_active' => true,
            'effective_from' => '2026-08-10',
            'effective_until' => '2026-08-20',
        ]);

        $this->assertTrue(
            StaffSchedule::query()->forUser($staff->id)->effectiveOn(Carbon::parse('2026-08-10'))->whereKey($schedule->id)->exists()
        );

        $this->assertTrue(
            StaffSchedule::query()->forUser($staff->id)->effectiveOn(Carbon::parse('2026-08-20'))->whereKey($schedule->id)->exists()
        );

        $this->assertFalse(
            StaffSchedule::query()->forUser($staff->id)->effectiveOn(Carbon::parse('2026-08-09'))->whereKey($schedule->id)->exists()
        );

        $this->assertFalse(
            StaffSchedule::query()->forUser($staff->id)->effectiveOn(Carbon::parse('2026-08-21'))->whereKey($schedule->id)->exists()
        );
    }

    public function test_schedule_without_effective_bounds_is_always_effective(): void
    {
        $org = Organization::factory()->autoDetailing()->create();
        $this->app['request']->attributes->set('tenant', $org);

        $staff = User::factory()->create();

        $schedule = StaffSchedule::create([
            'organization_id' => $org->id,
            'user_id' => $staff->id,
            'day_of_week' => 2,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_active' => true,
        ]);

        $this->assertTrue(
            StaffSchedule::query()->forUser($staff->id)->effectiveOn(Carbon::parse('2030-01-01'))->whereKey($schedule->id)->exists()
        );
    }
}
