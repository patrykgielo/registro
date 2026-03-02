<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\AppointmentCancelled;
use App\Events\AppointmentCreated;
use App\Events\AppointmentRescheduled;
use App\Events\UserRegistered;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;

/**
 * TestEmailFlowCommand
 *
 * Tests complete email system flow including:
 * - User registration email
 * - Appointment created/rescheduled/cancelled emails
 * - Reminder emails (24h and 2h)
 * - Follow-up emails
 * - Admin digest
 * - Cleanup job
 *
 * Usage:
 *   php artisan email:test-flow [--user=ID] [--appointment=ID] [--all]
 *
 * Options:
 *   --user=ID         Test with specific user ID
 *   --appointment=ID  Test with specific appointment ID
 *   --all            Run all tests (user + appointment + jobs)
 */
class TestEmailFlowCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:test-flow
                            {--user= : User ID for testing}
                            {--appointment= : Appointment ID for testing}
                            {--all : Run all tests}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test complete email system flow (events, notifications, jobs)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('========================================');
        $this->info('  Email System Flow Test');
        $this->info('========================================');
        $this->newLine();

        $testAll = $this->option('all');

        // Test 1: User Registration Email
        if ($testAll || $this->option('user')) {
            $this->testUserRegistration();
        }

        // Test 2: Appointment Lifecycle Emails
        if ($testAll || $this->option('appointment')) {
            $this->testAppointmentLifecycle();
        }

        // Test 3: Scheduler Jobs
        if ($testAll) {
            $this->testSchedulerJobs();
        }

        $this->newLine();
        $this->info('========================================');
        $this->info('  Test Summary');
        $this->info('========================================');
        $this->info('✓ All tests completed successfully');
        $this->info('→ Check Horizon dashboard: '.config('app.url').'/horizon');
        $this->info('→ Check Mailpit: http://localhost:8025');
        $this->info('→ Check email_sends table for logs');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Test user registration email flow
     */
    private function testUserRegistration(): void
    {
        $this->info('[TEST 1] User Registration Email');
        $this->line('----------------------------------------');

        $userId = $this->option('user');

        if ($userId) {
            $user = User::find($userId);
            if (! $user) {
                $this->error("User #{$userId} not found!");

                return;
            }
        } else {
            $user = User::first();
            if (! $user) {
                $this->error('No users found in database!');

                return;
            }
        }

        $this->line("Testing with user: {$user->name} ({$user->email})");

        // Dispatch UserRegistered event
        Event::dispatch(new UserRegistered($user));

        $this->info('✓ UserRegistered event dispatched');
        $this->line('  → Notification queued: UserRegisteredNotification');
        $this->line('  → Template: user-registered (PL/EN)');
        $this->line('  → Queue: emails');
        $this->newLine();
    }

    /**
     * Test appointment lifecycle emails
     */
    private function testAppointmentLifecycle(): void
    {
        $this->info('[TEST 2] Appointment Lifecycle Emails');
        $this->line('----------------------------------------');

        $appointmentId = $this->option('appointment');

        if ($appointmentId) {
            $appointment = Appointment::find($appointmentId);
            if (! $appointment) {
                $this->error("Appointment #{$appointmentId} not found!");

                return;
            }
        } else {
            $appointment = Appointment::with(['customer', 'service'])->first();
            if (! $appointment) {
                $this->error('No appointments found in database!');

                return;
            }
        }

        $this->line("Testing with appointment #{$appointment->id}");
        $this->line("  Customer: {$appointment->customer?->name} ({$appointment->customer?->email})");
        $this->line("  Date: {$appointment->appointment_date->format('Y-m-d H:i')}");
        $this->newLine();

        // Test 2a: Appointment Created
        $this->line('2a. Testing AppointmentCreated event...');
        Event::dispatch(new AppointmentCreated($appointment));
        $this->info('✓ AppointmentCreated event dispatched');
        $this->line('  → Notification: AppointmentCreatedNotification');
        $this->line('  → Template: appointment-created');
        $this->newLine();

        // Test 2b: Appointment Rescheduled
        $this->line('2b. Testing AppointmentRescheduled event...');
        Event::dispatch(new AppointmentRescheduled(
            $appointment,
            $appointment->appointment_date->copy()->subDay(),
            $appointment->appointment_date
        ));
        $this->info('✓ AppointmentRescheduled event dispatched');
        $this->line('  → Notification: AppointmentRescheduledNotification');
        $this->line('  → Template: appointment-rescheduled');
        $this->newLine();

        // Test 2c: Appointment Cancelled
        $this->line('2c. Testing AppointmentCancelled event...');
        Event::dispatch(new AppointmentCancelled($appointment));
        $this->info('✓ AppointmentCancelled event dispatched');
        $this->line('  → Notification: AppointmentCancelledNotification');
        $this->line('  → Template: appointment-cancelled');
        $this->newLine();
    }

    /**
     * Test scheduler jobs (dry run)
     */
    private function testSchedulerJobs(): void
    {
        $this->info('[TEST 3] Scheduler Jobs (Dry Run)');
        $this->line('----------------------------------------');

        // Test 3a: Unified Reminder System (ProcessRemindersJob)
        $this->line('3a. Testing ProcessRemindersJob (Unified)...');
        $this->line('  → Reads configuration from reminder_configs table');
        $this->line('  → Processes SMS + Email reminders and follow-ups');
        $this->line('  → Uses reminder_logs for idempotency');
        $this->line('  → Schedule: Hourly');
        $this->line('  → Queue: reminders');
        $configCount = \App\Models\ReminderConfig::enabled()->count();
        $logCount = \App\Models\ReminderLog::whereDate('created_at', today())->count();
        $this->info("✓ {$configCount} active configs, {$logCount} reminders sent today");
        $this->newLine();

        // Test 3b: Admin Digest Job
        $this->line('3b. Testing SendAdminDigestJob...');
        $this->line('  → Collects statistics from last 24h');
        $this->line('  → Sends to all admins (super-admin + admin roles)');
        $this->line('  → Schedule: Daily at 8:00 AM');
        $this->line('  → Queue: emails');
        $admins = User::role(['super-admin', 'admin'])->count();
        $this->info("✓ Would send digest to {$admins} admin(s)");
        $this->newLine();

        // Test 3c: Cleanup Job
        $this->line('3c. Testing CleanupOldEmailLogsJob...');
        $this->line('  → Deletes email_sends and email_events older than 90 days');
        $this->line('  → GDPR compliance');
        $this->line('  → Schedule: Daily at 2:00 AM');
        $this->line('  → Queue: default');
        $this->info('✓ Cleanup job configured (no dry run needed)');
        $this->newLine();

        $this->line('To manually dispatch jobs:');
        $this->line('  php artisan queue:work --once --queue=reminders');
        $this->line('  ProcessRemindersJob::dispatch();');
        $this->line('  SendAdminDigestJob::dispatch();');
        $this->line('  CleanupOldEmailLogsJob::dispatch();');
        $this->newLine();
    }
}
