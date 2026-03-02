<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\AppointmentService;
use Illuminate\Console\Command;

class FixInvalidStaffAssignments extends Command
{
    protected $signature = 'appointments:fix-staff {--dry-run : Preview changes without applying them}';

    protected $description = 'Fix appointments with invalid staff assignments by reassigning to available staff';

    public function __construct(private AppointmentService $appointmentService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info($dryRun ? 'DRY RUN: Previewing fixes...' : 'Fixing invalid staff assignments...');
        $this->newLine();

        $invalidAppointments = Appointment::with(['staff', 'staff.roles', 'service'])
            ->get()
            ->filter(function ($appointment) {
                return $appointment->staff && ! $appointment->staff->hasRole('staff');
            });

        if ($invalidAppointments->isEmpty()) {
            $this->info('✓ No invalid staff assignments to fix!');

            return Command::SUCCESS;
        }

        $this->warn('Found '.$invalidAppointments->count().' appointment(s) to fix:');
        $this->newLine();

        $fixed = 0;
        $failed = 0;

        foreach ($invalidAppointments as $appointment) {
            $availableStaff = $this->appointmentService->findFirstAvailableStaff(
                $appointment->service_id,
                $appointment->appointment_date,
                $appointment->start_time,
                $appointment->end_time,
                $appointment->id
            );

            if ($availableStaff) {
                $oldStaffName = $appointment->staff->full_name ?? 'N/A';
                $newStaffName = \App\Models\User::find($availableStaff)->full_name ?? 'N/A';

                $this->line("Appointment #{$appointment->id}: {$oldStaffName} → {$newStaffName}");

                if (! $dryRun) {
                    $appointment->staff_id = $availableStaff;
                    $appointment->saveQuietly(); // Skip observer validation during fix
                }

                $fixed++;
            } else {
                $this->error("Appointment #{$appointment->id}: No available staff found!");
                $failed++;
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->info("DRY RUN COMPLETE: Would fix {$fixed} appointment(s), {$failed} would require manual intervention.");
            $this->warn('Run without --dry-run to apply changes.');
        } else {
            $this->info("COMPLETE: Fixed {$fixed} appointment(s), {$failed} require manual intervention.");
            $this->info('Run "php artisan appointments:audit-staff" to verify.');
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
