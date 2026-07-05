<?php

namespace App\Services;

use App\Models\StaffDateException;
use App\Models\StaffSchedule;
use App\Models\StaffVacationPeriod;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * StaffScheduleService
 *
 * Handles calendar-based staff availability checking using the new Option B architecture:
 * - Base schedules (staff_schedules): Recurring weekly patterns
 * - Date exceptions (staff_date_exceptions): Single-day overrides
 * - Vacation periods (staff_vacation_periods): Multi-day absences
 *
 * Priority order: Vacation → Exception → Base Schedule
 */
class StaffScheduleService
{
    /**
     * Check if a staff member is available on a specific date and time.
     *
     * Priority order:
     * 1. Check vacation periods (highest priority)
     * 2. Check date exceptions
     * 3. Check base schedule
     *
     * @param  User  $staff  Staff member to check
     * @param  Carbon  $dateTime  Date and time to check
     * @return bool True if staff is available, false otherwise
     */
    public function isStaffAvailable(User $staff, Carbon $dateTime): bool
    {
        // Step 1: Check if staff is on vacation (HIGHEST PRIORITY)
        $onVacation = StaffVacationPeriod::query()
            ->forUser($staff->id)
            ->approved()
            ->includesDate($dateTime)
            ->exists();

        if ($onVacation) {
            return false; // Staff is on vacation
        }

        // Step 2: Check for date exceptions on this specific date
        // Ordered defensively (time-specific first) even though resolveExceptionAvailability()
        // no longer depends on collection order — see its docblock.
        $dayExceptions = StaffDateException::query()
            ->forUser($staff->id)
            ->onDate($dateTime)
            ->orderByRaw('start_time is null, start_time asc')
            ->get();

        if ($dayExceptions->isNotEmpty()) {
            return $this->checkExceptions($dayExceptions, $dateTime);
        }

        // Step 3: Fall back to base schedule
        return $this->checkBaseSchedule($staff, $dateTime);
    }

    /**
     * Check availability based on date exceptions.
     *
     * @param  Collection  $exceptions  Collection of StaffDateException
     * @param  Carbon  $dateTime  Date and time to check
     * @return bool True if available
     */
    protected function checkExceptions(Collection $exceptions, Carbon $dateTime): bool
    {
        $availability = $this->resolveExceptionAvailability($exceptions, $dateTime);

        if ($availability !== null) {
            return $availability;
        }

        // No matching exception found, fall back to base schedule
        return $this->checkBaseSchedule($exceptions->first()->user, $dateTime);
    }

    /**
     * Resolve the availability decision from a day's date exceptions for a specific
     * date+time, applying explicit precedence: a time-specific exception (non-all-day)
     * whose range contains $checkTime always wins over an all-day exception for the
     * same date — e.g. an all-day "unavailable" exception can be punched through by a
     * more specific time-range "available" override (and vice versa).
     *
     * Order-independent by construction (two explicit passes: time-specific match
     * first, all-day fallback second) — callers additionally sort exceptions
     * deterministically at the query level as defense-in-depth.
     *
     * @param  Collection  $exceptions  Collection of StaffDateException for one date
     * @return bool|null True/false = explicit exception decision, null = no exception applies
     */
    protected function resolveExceptionAvailability(Collection $exceptions, Carbon $checkTime): ?bool
    {
        $timeSpecific = $exceptions->first(function (StaffDateException $exception) use ($checkTime) {
            if ($exception->isAllDay()) {
                return false;
            }

            $start = Carbon::parse($checkTime->format('Y-m-d').' '.$exception->start_time);
            $end = Carbon::parse($checkTime->format('Y-m-d').' '.$exception->end_time);

            return $checkTime->between($start, $end);
        });

        if ($timeSpecific) {
            return $timeSpecific->isAvailable();
        }

        $allDay = $exceptions->first(fn (StaffDateException $exception) => $exception->isAllDay());

        return $allDay?->isAvailable();
    }

    /**
     * Check availability based on base schedule.
     *
     * @param  User  $staff  Staff member
     * @param  Carbon  $dateTime  Date and time to check
     * @return bool True if available
     */
    protected function checkBaseSchedule(User $staff, Carbon $dateTime): bool
    {
        $dayOfWeek = $dateTime->dayOfWeek; // 0 = Sunday, 6 = Saturday

        $schedules = StaffSchedule::query()
            ->forUser($staff->id)
            ->forDay($dayOfWeek)
            ->active()
            ->effectiveOn($dateTime)
            ->get();

        if ($schedules->isEmpty()) {
            return false; // No schedule defined for this day
        }

        // Check if the time falls within any schedule
        foreach ($schedules as $schedule) {
            $scheduleStart = Carbon::parse($dateTime->format('Y-m-d').' '.$schedule->start_time);
            $scheduleEnd = Carbon::parse($dateTime->format('Y-m-d').' '.$schedule->end_time);

            if ($dateTime->between($scheduleStart, $scheduleEnd)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a staff member can perform a specific service.
     *
     * @param  User  $staff  Staff member
     * @param  int  $serviceId  Service ID
     * @return bool True if staff can perform this service
     */
    public function canPerformService(User $staff, int $serviceId): bool
    {
        return $staff->services()->where('service_id', $serviceId)->exists();
    }

    /**
     * Get all available time slots for a staff member on a given date.
     *
     * @param  User  $staff  Staff member
     * @param  Carbon  $date  Date to check
     * @param  int  $serviceDurationMinutes  Duration of service in minutes
     * @param  int  $slotIntervalMinutes  Interval between slots (default 30)
     * @return array Array of available time slots (Carbon instances)
     */
    public function getAvailableSlots(
        User $staff,
        Carbon $date,
        int $serviceDurationMinutes,
        int $slotIntervalMinutes = 30
    ): array {
        $availableSlots = [];
        $dayOfWeek = $date->dayOfWeek;

        // Step 1: Check if staff is on vacation
        $onVacation = StaffVacationPeriod::query()
            ->forUser($staff->id)
            ->approved()
            ->includesDate($date)
            ->exists();

        if ($onVacation) {
            return []; // No slots available on vacation
        }

        // Step 2: Get base schedule for this day
        $schedules = StaffSchedule::query()
            ->forUser($staff->id)
            ->forDay($dayOfWeek)
            ->active()
            ->effectiveOn($date)
            ->get();

        if ($schedules->isEmpty()) {
            return []; // No schedule for this day
        }

        // Step 3: Get date exceptions
        // Ordered defensively (time-specific first) — see resolveExceptionAvailability().
        $exceptions = StaffDateException::query()
            ->forUser($staff->id)
            ->onDate($date)
            ->orderByRaw('start_time is null, start_time asc')
            ->get();

        // Step 4: Generate slots based on schedule, applying exceptions
        // Each slot is resolved independently: a time-specific exception covering this
        // exact slot always takes precedence over an all-day exception for the date
        // (see resolveExceptionAvailability()) — an all-day "unavailable" day can be
        // punched through by a more specific "available" override, and vice versa.
        foreach ($schedules as $schedule) {
            $slotTime = Carbon::parse($date->format('Y-m-d').' '.$schedule->start_time);
            $endTime = Carbon::parse($date->format('Y-m-d').' '.$schedule->end_time);

            while ($slotTime->copy()->addMinutes($serviceDurationMinutes)->lte($endTime)) {
                if ($this->resolveExceptionAvailability($exceptions, $slotTime) === false) {
                    $slotTime->addMinutes($slotIntervalMinutes);

                    continue;
                }

                // Slot is available (no exception applies, or exception marks it available)
                $availableSlots[] = $slotTime->copy();
                $slotTime->addMinutes($slotIntervalMinutes);
            }
        }

        return $availableSlots;
    }

    /**
     * Get all staff members available for a service at a specific date/time.
     *
     * @param  int  $serviceId  Service ID
     * @param  Carbon  $dateTime  Date and time
     * @return Collection Collection of User models
     */
    public function getAvailableStaffForService(int $serviceId, Carbon $dateTime): Collection
    {
        // Get all staff who can perform this service
        $staffMembers = User::query()
            ->role('staff')
            ->whereHas('services', function ($query) use ($serviceId) {
                $query->where('service_id', $serviceId);
            })
            ->get();

        // Filter by availability
        return $staffMembers->filter(function ($staff) use ($dateTime) {
            return $this->isStaffAvailable($staff, $dateTime);
        });
    }
}
