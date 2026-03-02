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
        $dayExceptions = StaffDateException::query()
            ->forUser($staff->id)
            ->onDate($dateTime)
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
        foreach ($exceptions as $exception) {
            // Check if exception applies to this time
            if ($exception->isAllDay()) {
                // All-day exception
                return $exception->isAvailable();
            } else {
                // Time-specific exception
                $exceptionStart = Carbon::parse($dateTime->format('Y-m-d').' '.$exception->start_time);
                $exceptionEnd = Carbon::parse($dateTime->format('Y-m-d').' '.$exception->end_time);

                if ($dateTime->between($exceptionStart, $exceptionEnd)) {
                    return $exception->isAvailable();
                }
            }
        }

        // No matching exception found, fall back to base schedule
        return $this->checkBaseSchedule($exceptions->first()->user, $dateTime);
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
        $exceptions = StaffDateException::query()
            ->forUser($staff->id)
            ->onDate($date)
            ->get();

        // Step 4: Generate slots based on schedule, applying exceptions
        foreach ($schedules as $schedule) {
            $slotTime = Carbon::parse($date->format('Y-m-d').' '.$schedule->start_time);
            $endTime = Carbon::parse($date->format('Y-m-d').' '.$schedule->end_time);

            while ($slotTime->copy()->addMinutes($serviceDurationMinutes)->lte($endTime)) {
                // Check if this slot is affected by an exception
                $affectedByException = false;

                foreach ($exceptions as $exception) {
                    if ($exception->isAllDay()) {
                        $affectedByException = true;
                        if (! $exception->isAvailable()) {
                            break 2; // Skip all slots for this schedule
                        }
                    } else {
                        $exceptionStart = Carbon::parse($date->format('Y-m-d').' '.$exception->start_time);
                        $exceptionEnd = Carbon::parse($date->format('Y-m-d').' '.$exception->end_time);

                        if ($slotTime->between($exceptionStart, $exceptionEnd)) {
                            $affectedByException = true;
                            if (! $exception->isAvailable()) {
                                $slotTime->addMinutes($slotIntervalMinutes);

                                continue 2; // Skip this slot
                            }
                        }
                    }
                }

                // Slot is available
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
