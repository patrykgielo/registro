<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use Illuminate\Console\Command;

class AuditInvalidStaffAssignments extends Command
{
    protected $signature = 'appointments:audit-staff';

    protected $description = 'Audit appointments for invalid staff assignments (non-staff roles)';

    public function handle(): int
    {
        $this->info('Auditing appointments for invalid staff assignments...');
        $this->newLine();

        $invalidAppointments = Appointment::with(['staff', 'staff.roles'])
            ->get()
            ->filter(function ($appointment) {
                return $appointment->staff && ! $appointment->staff->hasRole('staff');
            });

        if ($invalidAppointments->isEmpty()) {
            $this->info('✓ No invalid staff assignments found!');

            return Command::SUCCESS;
        }

        $this->error('✗ Found '.$invalidAppointments->count().' appointment(s) with invalid staff assignments:');
        $this->newLine();

        $this->table(
            ['ID', 'Date', 'Time', 'Staff ID', 'Staff Name', 'Staff Roles', 'Status'],
            $invalidAppointments->map(fn ($apt) => [
                $apt->id,
                $apt->appointment_date->format('Y-m-d'),
                $apt->start_time->format('H:i'),
                $apt->staff_id,
                $apt->staff->full_name ?? 'N/A',
                $apt->staff ? $apt->staff->roles->pluck('name')->join(', ') : 'N/A',
                $apt->status,
            ])
        );

        $this->newLine();
        $this->warn('Run "php artisan appointments:fix-staff --dry-run" to see proposed fixes.');

        return Command::FAILURE;
    }
}
