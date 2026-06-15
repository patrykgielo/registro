<?php

use App\Jobs\Email\CleanupOldEmailLogsJob;
use App\Jobs\Email\SendAdminDigestJob;
use App\Jobs\MarkCartsAbandonedJob;
use App\Jobs\RecalculateDailyStatisticsJob;
use App\Jobs\Reminder\ProcessRemindersJob;
use App\Jobs\Sms\CleanupOldSmsLogsJob;
use Carbon\Carbon;
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

/*
|--------------------------------------------------------------------------
| Order System
|--------------------------------------------------------------------------
*/

// Cancel pending_payment orders past their expires_at TTL
// Runs: Every 5 minutes
Schedule::command('orders:cleanup-expired')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('orders:cleanup-expired')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Cart System
|--------------------------------------------------------------------------
*/

// Delete abandoned carts older than 7 days
// Runs: Daily at 2:00 AM (alongside GDPR email log cleanup)
Schedule::command('carts:cleanup-abandoned')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->name('carts:cleanup-abandoned')
    ->onOneServer();

// Mark active carts as abandoned after 30 min of inactivity + dispatch analytics event
// Runs: Every 5 minutes
Schedule::job(new MarkCartsAbandonedJob)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('carts:mark-abandoned')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Analytics System
|--------------------------------------------------------------------------
*/

// Delete analytics events older than 13 months (GDPR retention)
// Runs: Monthly (first of month at midnight)
Schedule::command('analytics:prune')
    ->monthly()
    ->withoutOverlapping()
    ->name('analytics:prune')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Statistics System
|--------------------------------------------------------------------------
*/

// Recalculate today + yesterday snapshots every hour
// Keeps statistics_daily_snapshots fresh for all UI consumers
// Runs: Hourly
Schedule::call(function () {
    dispatch(new RecalculateDailyStatisticsJob(Carbon::yesterday()));
    dispatch(new RecalculateDailyStatisticsJob(Carbon::today()));
})->hourly()->name('statistics-recalculate')->withoutOverlapping();
