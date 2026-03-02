<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use App\Support\Settings\SettingsManager;
use Carbon\Carbon;

class AppointmentService
{
    public function __construct(
        protected SettingsManager $settings,
        protected StaffScheduleService $staffScheduleService
    ) {}

    /**
     * Check if staff member is available for given time slot
     *
     * Uses new calendar-based availability system (Option B):
     * - Checks vacation periods
     * - Checks date exceptions
     * - Falls back to base schedule
     * - Checks for appointment conflicts
     */
    public function checkStaffAvailability(
        int $staffId,
        int $serviceId,
        Carbon $date,
        Carbon $startTime,
        Carbon $endTime,
        ?int $excludeAppointmentId = null
    ): bool {
        $staff = User::find($staffId);

        if (! $staff) {
            return false;
        }

        // Step 1: Check if staff can perform this service
        if (! $this->staffScheduleService->canPerformService($staff, $serviceId)) {
            return false;
        }

        // Step 2: Check staff availability using new calendar-based system
        $startDateTime = Carbon::parse($date->format('Y-m-d').' '.$startTime->format('H:i:s'));

        if (! $this->staffScheduleService->isStaffAvailable($staff, $startDateTime)) {
            return false;
        }

        // Step 3: Check for conflicting appointments
        $hasConflict = Appointment::query()
            ->where('staff_id', $staffId)
            ->where('appointment_date', $date->format('Y-m-d'))
            ->whereIn('status', ['pending', 'confirmed'])
            ->when($excludeAppointmentId, fn ($q) => $q->where('id', '!=', $excludeAppointmentId))
            ->where(function ($query) use ($startTime, $endTime) {
                $query->where(function ($q) use ($startTime) {
                    // New appointment starts during existing appointment
                    $q->whereTime('start_time', '<=', $startTime->format('H:i:s'))
                        ->whereTime('end_time', '>', $startTime->format('H:i:s'));
                })->orWhere(function ($q) use ($endTime) {
                    // New appointment ends during existing appointment
                    $q->whereTime('start_time', '<', $endTime->format('H:i:s'))
                        ->whereTime('end_time', '>=', $endTime->format('H:i:s'));
                })->orWhere(function ($q) use ($startTime, $endTime) {
                    // New appointment completely contains existing appointment
                    $q->whereTime('start_time', '>=', $startTime->format('H:i:s'))
                        ->whereTime('end_time', '<=', $endTime->format('H:i:s'));
                });
            })
            ->exists();

        return ! $hasConflict;
    }

    /**
     * Check if ANY staff member is available for the given time slot
     *
     * Uses new calendar-based system with service_staff pivot table
     */
    public function isAnyStaffAvailable(
        int $serviceId,
        Carbon $date,
        Carbon $startTime,
        Carbon $endTime,
        ?int $excludeAppointmentId = null
    ): bool {
        // Get all staff members who can perform this service (using new pivot table)
        $staffMembers = User::whereHas('roles', function ($query) {
            $query->where('name', 'staff');
        })->whereHas('services', function ($query) use ($serviceId) {
            $query->where('service_id', $serviceId);
        })->get();

        // Check if at least one staff member is available
        foreach ($staffMembers as $staff) {
            if ($this->checkStaffAvailability(
                $staff->id,
                $serviceId,
                $date,
                $startTime,
                $endTime,
                $excludeAppointmentId
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the first available staff member for the given time slot
     *
     * Uses new calendar-based system with service_staff pivot table
     *
     * @return int|null Staff ID if available, null if no staff available
     */
    public function findFirstAvailableStaff(
        int $serviceId,
        Carbon $date,
        Carbon $startTime,
        Carbon $endTime,
        ?int $excludeAppointmentId = null
    ): ?int {
        // Get all staff members who can perform this service (using new pivot table)
        $staffMembers = User::whereHas('roles', function ($query) {
            $query->where('name', 'staff');
        })->whereHas('services', function ($query) use ($serviceId) {
            $query->where('service_id', $serviceId);
        })->get();

        // Find first available staff member
        foreach ($staffMembers as $staff) {
            if ($this->checkStaffAvailability(
                $staff->id,
                $serviceId,
                $date,
                $startTime,
                $endTime,
                $excludeAppointmentId
            )) {
                return $staff->id;
            }
        }

        return null;
    }

    /**
     * Find the best available staff member for the given service and datetime
     *
     * This is a convenience method for the booking wizard that takes a single dateTime
     * and duration, then calculates the time range and finds the best staff member.
     *
     * "Best" strategy: Currently uses "first available" but can be enhanced with:
     * - Workload balancing (least appointments today)
     * - Skill matching (staff expertise level)
     * - Customer preferences (favorite staff member)
     *
     * @param  int  $serviceId  Service to be performed
     * @param  Carbon  $dateTime  Appointment start date and time
     * @param  int  $durationMinutes  Service duration in minutes
     * @return User|null Staff member model if available, null if no staff available
     */
    public function findBestAvailableStaff(
        int $serviceId,
        Carbon $dateTime,
        int $durationMinutes
    ): ?User {
        // Calculate end time based on duration
        $startTime = $dateTime->copy();
        $endTime = $dateTime->copy()->addMinutes($durationMinutes);
        $date = $dateTime->copy()->startOfDay();

        // Use existing method to find first available staff
        $staffId = $this->findFirstAvailableStaff(
            $serviceId,
            $date,
            $startTime,
            $endTime
        );

        // Return User model instead of just ID
        return $staffId ? User::find($staffId) : null;
    }

    /**
     * Get available time slots across ALL staff members for a service on a specific date
     *
     * Uses new calendar-based system to find slots where at least one staff member is available
     */
    public function getAvailableSlotsAcrossAllStaff(
        int $serviceId,
        Carbon $date,
        int $serviceDurationMinutes
    ): array {
        // Get all staff members who can perform this service (using new pivot table)
        $staffMembers = User::whereHas('roles', function ($query) {
            $query->where('name', 'staff');
        })->whereHas('services', function ($query) use ($serviceId) {
            $query->where('service_id', $serviceId);
        })->get();

        if ($staffMembers->isEmpty()) {
            return [];
        }

        $allSlots = [];
        $slotInterval = $this->settings->slotIntervalMinutes();
        $businessHours = $this->settings->bookingBusinessHours();
        $businessStart = Carbon::parse($date->format('Y-m-d').' '.$businessHours['start']);
        $businessEnd = Carbon::parse($date->format('Y-m-d').' '.$businessHours['end']);

        // Generate all possible slots within business hours
        $currentSlot = $businessStart->copy();

        while ($currentSlot->copy()->addMinutes($serviceDurationMinutes)->lte($businessEnd)) {
            $slotEnd = $currentSlot->copy()->addMinutes($serviceDurationMinutes);

            // Check if this slot is within business hours completely
            if (! $this->isWithinBusinessHours($currentSlot, $slotEnd)) {
                $currentSlot->addMinutes($slotInterval);

                continue;
            }

            // Check if ANY staff member is available for this slot
            if ($this->isAnyStaffAvailable($serviceId, $date, $currentSlot, $slotEnd)) {
                $slotKey = $currentSlot->format('H:i');

                // Avoid duplicate slots
                if (! isset($allSlots[$slotKey])) {
                    $allSlots[$slotKey] = [
                        'time' => $currentSlot->format('H:i'), // Frontend expects 'time' key
                        'start' => $currentSlot->format('H:i'),
                        'end' => $slotEnd->format('H:i'),
                        'datetime_start' => $date->format('Y-m-d').' '.$currentSlot->format('H:i'),
                        'datetime_end' => $date->format('Y-m-d').' '.$slotEnd->format('H:i'),
                        'available' => true, // Frontend expects 'available' boolean
                    ];
                }
            }

            $currentSlot->addMinutes($slotInterval);
        }

        return array_values($allSlots);
    }

    /**
     * Check if the given time range is within business hours
     */
    public function isWithinBusinessHours(Carbon $startTime, Carbon $endTime): bool
    {
        $businessHours = $this->settings->bookingBusinessHours();
        $businessStart = Carbon::parse($startTime->format('Y-m-d').' '.$businessHours['start']);
        $businessEnd = Carbon::parse($startTime->format('Y-m-d').' '.$businessHours['end']);

        return $startTime->gte($businessStart) && $endTime->lte($businessEnd);
    }

    /**
     * Check if the appointment meets the advance booking requirement (24h minimum)
     */
    public function meetsAdvanceBookingRequirement(Carbon $appointmentDateTime): bool
    {
        $advanceHours = $this->settings->advanceBookingHours();
        $minimumDateTime = now()->addHours($advanceHours);

        return $appointmentDateTime->gte($minimumDateTime);
    }

    /**
     * Validate appointment booking
     */
    public function validateAppointment(
        int $staffId,
        int $serviceId,
        string $appointmentDate,
        string $startTime,
        string $endTime,
        ?int $excludeAppointmentId = null
    ): array {
        $errors = [];

        $date = Carbon::parse($appointmentDate);
        $start = Carbon::parse($appointmentDate.' '.$startTime);
        $end = Carbon::parse($appointmentDate.' '.$endTime);

        // Check if date is in the past
        if ($date->isPast() && ! $date->isToday()) {
            $errors[] = 'Nie można zarezerwować wizyty w przeszłości.';
        }

        // Check 24-hour advance booking requirement
        $advanceHours = $this->settings->advanceBookingHours();
        if (! $this->meetsAdvanceBookingRequirement($start)) {
            $errors[] = sprintf(
                'Rezerwacja musi być dokonana co najmniej %d godzin przed terminem wizyty.',
                $advanceHours
            );
        }

        // Check if within business hours
        if (! $this->isWithinBusinessHours($start, $end)) {
            $businessHours = $this->settings->bookingBusinessHours();
            $errors[] = sprintf(
                'Wizyta musi się odbywać w godzinach pracy: %s - %s.',
                $businessHours['start'],
                $businessHours['end']
            );
        }

        // Check if start time is before end time
        if ($start->gte($end)) {
            $errors[] = 'Czas rozpoczęcia musi być przed czasem zakończenia.';
        }

        // Check staff availability
        if (! $this->checkStaffAvailability($staffId, $serviceId, $date, $start, $end, $excludeAppointmentId)) {
            $errors[] = 'Wybrany termin nie jest dostępny. Personel jest zajęty lub nie pracuje w tym czasie.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get bulk availability for date range (optimized for calendar loading)
     *
     * Instead of calling getAvailableSlotsAcrossAllStaff() 60 times (one per day),
     * this method fetches all necessary data upfront and processes in memory.
     *
     * Performance: ~3-5 queries total vs 60 × N queries
     *
     * @return array ['2025-12-15' => 'available'|'limited'|'unavailable', ...]
     */
    public function getBulkAvailability(int $serviceId, Carbon $startDate, Carbon $endDate): array
    {
        // Query 1: Get all staff members who can perform this service (with pivot data eager loaded)
        $staffMembers = User::whereHas('roles', function ($query) {
            $query->where('name', 'staff');
        })->whereHas('services', function ($query) use ($serviceId) {
            $query->where('service_id', $serviceId);
        })->with('services')->get();

        if ($staffMembers->isEmpty()) {
            // No staff can perform this service - all dates unavailable
            $availability = [];
            $currentDate = $startDate->copy();
            while ($currentDate->lte($endDate)) {
                $availability[$currentDate->format('Y-m-d')] = 'unavailable';
                $currentDate->addDay();
            }

            return $availability;
        }

        $staffIds = $staffMembers->pluck('id')->toArray();

        // Query 2: Get all appointments in date range (bulk fetch)
        $appointments = Appointment::query()
            ->whereIn('staff_id', $staffIds)
            ->whereBetween('appointment_date', [
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d'),
            ])
            ->whereIn('status', ['pending', 'confirmed'])
            ->get()
            ->groupBy(function ($appointment) {
                return $appointment->appointment_date->format('Y-m-d');
            });

        // Query 3: Bulk fetch ALL vacation periods for all staff in date range
        $vacationPeriods = \App\Models\StaffVacationPeriod::query()
            ->whereIn('user_id', $staffIds)
            ->where('is_approved', true)
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                    ->orWhereBetween('end_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                    ->orWhere(function ($q) use ($startDate, $endDate) {
                        $q->where('start_date', '<=', $startDate->format('Y-m-d'))
                            ->where('end_date', '>=', $endDate->format('Y-m-d'));
                    });
            })
            ->get()
            ->groupBy('user_id');

        // Query 4: Bulk fetch ALL date exceptions for all staff in date range
        $dateExceptions = \App\Models\StaffDateException::query()
            ->whereIn('user_id', $staffIds)
            ->whereBetween('exception_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get()
            ->groupBy('user_id');

        // Query 5: Bulk fetch ALL base schedules for all staff
        $baseSchedules = \App\Models\StaffSchedule::query()
            ->whereIn('user_id', $staffIds)
            ->where('is_active', true)
            ->where(function ($query) use ($startDate) {
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $startDate->format('Y-m-d'));
            })
            ->where(function ($query) use ($endDate) {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $endDate->format('Y-m-d'));
            })
            ->get()
            ->groupBy('user_id');

        // Get business hours and slot interval settings (ONCE, outside loop)
        $businessHours = $this->settings->bookingBusinessHours();
        $slotInterval = $this->settings->slotIntervalMinutes();

        // Get advance booking requirement (ONCE, outside loop)
        $advanceHours = $this->settings->advanceBookingHours();
        $minimumBookingDateTime = now()->addHours($advanceHours);

        // Get service details (ONCE)
        $service = \App\Models\Service::find($serviceId);
        if (! $service) {
            // Service not found - all dates unavailable
            $availability = [];
            $currentDate = $startDate->copy();
            while ($currentDate->lte($endDate)) {
                $availability[$currentDate->format('Y-m-d')] = 'unavailable';
                $currentDate->addDay();
            }

            return $availability;
        }

        // Process each date
        $availability = [];
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->format('Y-m-d');
            $dayAppointments = $appointments->get($dateStr, collect());

            // Calculate available slots for this day (using pre-fetched data)
            $availableSlotCount = $this->calculateAvailableSlotsForDay(
                $staffMembers,
                $currentDate,
                $service,
                $businessHours,
                $slotInterval,
                $dayAppointments,
                $vacationPeriods,
                $dateExceptions,
                $baseSchedules,
                $minimumBookingDateTime
            );

            // Categorize availability
            if ($availableSlotCount === 0) {
                $availability[$dateStr] = 'unavailable';
            } elseif ($availableSlotCount <= 3) {
                $availability[$dateStr] = 'limited';
            } else {
                $availability[$dateStr] = 'available';
            }

            $currentDate->addDay();
        }

        return $availability;
    }

    /**
     * Calculate available slots for a specific day (helper method for bulk processing)
     *
     * ⚠️ ZERO DATABASE QUERIES - Works entirely with pre-fetched data
     *
     * @param  \Illuminate\Support\Collection  $staffMembers
     * @param  \App\Models\Service  $service
     * @param  \Illuminate\Support\Collection  $dayAppointments
     * @param  \Illuminate\Support\Collection  $vacationPeriods  Grouped by user_id
     * @param  \Illuminate\Support\Collection  $dateExceptions  Grouped by user_id
     * @param  \Illuminate\Support\Collection  $baseSchedules  Grouped by user_id
     * @param  Carbon  $minimumBookingDateTime  Minimum allowed booking datetime
     * @return int Number of available slots
     */
    protected function calculateAvailableSlotsForDay(
        $staffMembers,
        Carbon $date,
        $service,
        array $businessHours,
        int $slotInterval,
        $dayAppointments,
        $vacationPeriods,
        $dateExceptions,
        $baseSchedules,
        Carbon $minimumBookingDateTime
    ): int {
        $serviceDurationMinutes = $service->duration_minutes;
        $serviceId = $service->id;
        $businessStart = Carbon::parse($date->format('Y-m-d').' '.$businessHours['start']);
        $businessEnd = Carbon::parse($date->format('Y-m-d').' '.$businessHours['end']);

        // Check if date meets advance booking requirement (using pre-calculated minimum)
        $earliestSlotDateTime = Carbon::parse($date->format('Y-m-d').' '.$businessHours['start']);
        if ($earliestSlotDateTime->lt($minimumBookingDateTime)) {
            return 0;
        }

        $availableSlots = 0;
        $currentSlot = $businessStart->copy();

        while ($currentSlot->copy()->addMinutes($serviceDurationMinutes)->lte($businessEnd)) {
            $slotEnd = $currentSlot->copy()->addMinutes($serviceDurationMinutes);

            // Check if ANY staff member is available for this slot
            $isAnyStaffAvailable = false;

            foreach ($staffMembers as $staff) {
                // Check if staff can perform this service (using eager-loaded relation)
                $canPerformService = $staff->services->contains('id', $serviceId);
                if (! $canPerformService) {
                    continue;
                }

                // Check if staff is on vacation
                $staffVacations = $vacationPeriods->get($staff->id, collect());
                $isOnVacation = $staffVacations->contains(function ($vacation) use ($date) {
                    return $date->between($vacation->start_date, $vacation->end_date);
                });
                if ($isOnVacation) {
                    continue;
                }

                // Check date exceptions
                $staffExceptions = $dateExceptions->get($staff->id, collect());
                $dateException = $staffExceptions->firstWhere('exception_date', $date->format('Y-m-d'));

                if ($dateException) {
                    // If exception exists, check if staff is available during this time
                    if (! $dateException->is_available) {
                        continue; // Staff marked as unavailable this day
                    }

                    // Check time range if available
                    if ($dateException->start_time && $dateException->end_time) {
                        $exceptionStart = Carbon::parse($date->format('Y-m-d').' '.$dateException->start_time);
                        $exceptionEnd = Carbon::parse($date->format('Y-m-d').' '.$dateException->end_time);

                        // Slot must be completely within exception hours
                        if ($currentSlot->lt($exceptionStart) || $slotEnd->gt($exceptionEnd)) {
                            continue;
                        }
                    }
                } else {
                    // No exception - check base schedule
                    $dayOfWeek = $date->dayOfWeek; // 0 = Sunday, 6 = Saturday
                    $staffBaseSchedules = $baseSchedules->get($staff->id, collect());
                    $daySchedule = $staffBaseSchedules->firstWhere('day_of_week', $dayOfWeek);

                    if (! $daySchedule) {
                        continue; // No schedule for this day of week
                    }

                    // Check if slot falls within base schedule hours
                    $scheduleStart = Carbon::parse($date->format('Y-m-d').' '.$daySchedule->start_time);
                    $scheduleEnd = Carbon::parse($date->format('Y-m-d').' '.$daySchedule->end_time);

                    if ($currentSlot->lt($scheduleStart) || $slotEnd->gt($scheduleEnd)) {
                        continue; // Slot outside staff working hours
                    }
                }

                // Check for appointment conflicts for this staff
                $hasConflict = $dayAppointments->contains(function ($appointment) use ($staff, $currentSlot, $slotEnd, $date) {
                    if ($appointment->staff_id !== $staff->id) {
                        return false;
                    }

                    // FIXED: Extract only time portion from datetime fields (start_time/end_time are cast as datetime)
                    $appointmentStart = Carbon::parse($date->format('Y-m-d').' '.$appointment->start_time->format('H:i:s'));
                    $appointmentEnd = Carbon::parse($date->format('Y-m-d').' '.$appointment->end_time->format('H:i:s'));

                    // Check for time overlap
                    return
                        ($currentSlot->gte($appointmentStart) && $currentSlot->lt($appointmentEnd)) || // Starts during appointment
                        ($slotEnd->gt($appointmentStart) && $slotEnd->lte($appointmentEnd)) || // Ends during appointment
                        ($currentSlot->lte($appointmentStart) && $slotEnd->gte($appointmentEnd));    // Contains appointment
                });

                if (! $hasConflict) {
                    $isAnyStaffAvailable = true;
                    break; // Found at least one available staff
                }
            }

            if ($isAnyStaffAvailable) {
                $availableSlots++;
            }

            $currentSlot->addMinutes($slotInterval);
        }

        return $availableSlots;
    }
}
