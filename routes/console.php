<?php

use App\Jobs\Email\CleanupOldEmailLogsJob;
use App\Jobs\Email\SendAdminDigestJob;
use App\Jobs\Reminder\ProcessRemindersJob;
use App\Jobs\Sms\CleanupOldSmsLogsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Unified Reminder System
|--------------------------------------------------------------------------
|
| Single configurable job for all SMS + Email reminders and follow-ups.
| Configuration is managed via Admin Panel → Reminder Config.
|
*/

// Unified reminder processing (SMS + Email)
// Reads configuration from reminder_configs table
// Runs: Every hour
Schedule::job(new ProcessRemindersJob)
    ->hourly()
    ->withoutOverlapping()
    ->name('reminders:process')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Email System Scheduled Jobs
|--------------------------------------------------------------------------
*/

// Send daily admin digest with statistics
// Runs: Daily at 8:00 AM
Schedule::job(new SendAdminDigestJob)
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->name('email:admin-digest')
    ->onOneServer();

// Cleanup old email logs (GDPR 90-day retention)
// Runs: Daily at 2:00 AM
Schedule::job(new CleanupOldEmailLogsJob)
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->name('email:cleanup-logs')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| SMS System Scheduled Jobs
|--------------------------------------------------------------------------
*/

// Cleanup old SMS logs (GDPR 90-day retention)
// Runs: Daily at 2:30 AM (30 minutes after email cleanup)
Schedule::job(new CleanupOldSmsLogsJob)
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->name('sms:cleanup-logs')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Rental System
|--------------------------------------------------------------------------
*/

// Release expired rental holds to free up inventory
// Runs: Every 5 minutes
Schedule::command('rentals:release-expired-holds')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('rentals:release-expired-holds')
    ->onOneServer();
