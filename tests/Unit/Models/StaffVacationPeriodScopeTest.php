<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Organization;
use App\Models\StaffVacationPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Boundary-day regression for scopeIncludesDate()/scopeOverlapping() — sibling bug to
 * StaffDateException::scopeOnDate() (see that model's docblock): raw string comparisons
 * against `date`-cast columns work on MySQL (native DATE column truncates on write) but
 * silently fail on SQLite (full `Y-m-d H:i:s` string stored, no truncation), meaning a
 * vacation period was NOT detected as covering its own start_date/end_date boundary days.
 */
class StaffVacationPeriodScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_includes_date_covers_its_own_start_and_end_date(): void
    {
        $org = Organization::factory()->autoDetailing()->create();
        $this->app['request']->attributes->set('tenant', $org);

        $staff = User::factory()->create();

        $vacation = StaffVacationPeriod::create([
            'organization_id' => $org->id,
            'user_id' => $staff->id,
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-14',
            'is_approved' => true,
        ]);

        $this->assertTrue(
            StaffVacationPeriod::query()->forUser($staff->id)->includesDate(Carbon::parse('2026-08-10'))->whereKey($vacation->id)->exists()
        );
        $this->assertTrue(
            StaffVacationPeriod::query()->forUser($staff->id)->includesDate(Carbon::parse('2026-08-14'))->whereKey($vacation->id)->exists()
        );
        $this->assertTrue(
            StaffVacationPeriod::query()->forUser($staff->id)->includesDate(Carbon::parse('2026-08-12'))->whereKey($vacation->id)->exists()
        );
        $this->assertFalse(
            StaffVacationPeriod::query()->forUser($staff->id)->includesDate(Carbon::parse('2026-08-09'))->whereKey($vacation->id)->exists()
        );
        $this->assertFalse(
            StaffVacationPeriod::query()->forUser($staff->id)->includesDate(Carbon::parse('2026-08-15'))->whereKey($vacation->id)->exists()
        );
    }

    public function test_overlapping_detects_a_range_that_starts_on_the_periods_end_date(): void
    {
        $org = Organization::factory()->autoDetailing()->create();
        $this->app['request']->attributes->set('tenant', $org);

        $staff = User::factory()->create();

        $vacation = StaffVacationPeriod::create([
            'organization_id' => $org->id,
            'user_id' => $staff->id,
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-14',
            'is_approved' => true,
        ]);

        $this->assertTrue(
            StaffVacationPeriod::query()
                ->forUser($staff->id)
                ->overlapping(Carbon::parse('2026-08-14'), Carbon::parse('2026-08-20'))
                ->whereKey($vacation->id)
                ->exists()
        );

        $this->assertFalse(
            StaffVacationPeriod::query()
                ->forUser($staff->id)
                ->overlapping(Carbon::parse('2026-08-15'), Carbon::parse('2026-08-20'))
                ->whereKey($vacation->id)
                ->exists()
        );
    }
}
