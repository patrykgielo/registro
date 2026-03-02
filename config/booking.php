<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Business Hours Configuration
    |--------------------------------------------------------------------------
    |
    | Define the operating hours for the car detailing business.
    | All appointments must fall within these hours.
    |
    */

    'business_hours' => [
        'start' => env('BOOKING_BUSINESS_HOURS_START', '09:00'),
        'end' => env('BOOKING_BUSINESS_HOURS_END', '18:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Advance Booking Requirement
    |--------------------------------------------------------------------------
    |
    | Minimum number of hours in advance that a booking must be made.
    | Default is 24 hours to ensure proper preparation time.
    |
    */

    'advance_booking_hours' => env('BOOKING_ADVANCE_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Cancellation Policy
    |--------------------------------------------------------------------------
    |
    | Minimum number of hours before appointment start that cancellation
    | is allowed. Default is 24 hours to minimize impact on schedule.
    |
    */

    'cancellation_hours' => env('BOOKING_CANCELLATION_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Timezone used for all booking calculations and display.
    | Must match the application timezone in config/app.php.
    |
    */

    'timezone' => env('BOOKING_TIMEZONE', 'Europe/Warsaw'),

    /*
    |--------------------------------------------------------------------------
    | Time Slot Interval
    |--------------------------------------------------------------------------
    |
    | The interval in minutes between available time slots.
    | Default is 15 minutes for flexible scheduling.
    |
    */

    'slot_interval_minutes' => env('BOOKING_SLOT_INTERVAL', 15),

    /*
    |--------------------------------------------------------------------------
    | Maximum Service Duration
    |--------------------------------------------------------------------------
    |
    | Maximum duration for a single service in minutes.
    | Default is 540 minutes (9 hours - single working day).
    | This prevents multi-day services which are not currently supported.
    |
    */

    'max_service_duration_minutes' => env('BOOKING_MAX_DURATION', 540),

    /*
    |--------------------------------------------------------------------------
    | Treated Statuses for Availability
    |--------------------------------------------------------------------------
    |
    | Appointment statuses that should be treated as "booked" when
    | calculating staff availability. Both pending and confirmed
    | appointments block time slots.
    |
    */

    'blocking_statuses' => ['pending', 'confirmed'],
];
