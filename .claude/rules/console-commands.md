---
paths:
  - "app/Console/Commands/**"
---

# Console Commands Rules

## Basic Structure

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FixInvalidStaffAssignments extends Command
{
    protected $signature = 'appointments:fix-staff
        {--dry-run : Show what would be changed without making changes}
        {--force : Run without confirmation}';

    protected $description = 'Fix appointments with invalid staff assignments';

    public function handle(): int
    {
        // Command logic
        return Command::SUCCESS;
    }
}
```

## Return Codes

```php
// ✅ Zawsze używaj stałych Command
return Command::SUCCESS;   // 0 - sukces
return Command::FAILURE;   // 1 - błąd
return Command::INVALID;   // 2 - nieprawidłowe argumenty
```

## Dry-Run Support (CRITICAL dla destructive operations)

```php
protected $signature = 'appointments:fix-staff
    {--dry-run : Show what would be changed without making changes}';

public function handle(): int
{
    $dryRun = $this->option('dry-run');

    $appointments = $this->findInvalidAppointments();

    foreach ($appointments as $appointment) {
        if ($dryRun) {
            $this->info("[DRY-RUN] Would fix: {$appointment->id}");
        } else {
            $this->fixAppointment($appointment);
            $this->info("Fixed: {$appointment->id}");
        }
    }

    return Command::SUCCESS;
}
```

## Table Output

```php
$this->table(
    ['ID', 'Customer', 'Date', 'Status'],
    $appointments->map(fn ($a) => [
        $a->id,
        $a->customer->name,
        $a->appointment_date,
        $a->status,
    ])
);
```

## Progress Bar

```php
$appointments = Appointment::all();
$bar = $this->output->createProgressBar($appointments->count());

foreach ($appointments as $appointment) {
    $this->processAppointment($appointment);
    $bar->advance();
}

$bar->finish();
$this->newLine();
```

## Confirmation for Destructive Operations

```php
if (!$this->option('force') && !$this->confirm('Are you sure you want to proceed?')) {
    $this->info('Operation cancelled.');
    return Command::SUCCESS;
}
```

## Service Injection

```php
public function __construct(
    protected AppointmentService $appointmentService
) {
    parent::__construct();
}
```

## Input/Output Methods

```php
// Output
$this->info('Informational message');
$this->warn('Warning message');
$this->error('Error message');
$this->line('Plain text');
$this->newLine(2);

// Input
$name = $this->ask('What is your name?');
$password = $this->secret('Enter password');
$choice = $this->choice('Select option', ['A', 'B', 'C']);
```

## Signature Syntax

```php
// Argumenty
{user}          // wymagany
{user?}         // opcjonalny
{user=default}  // z default value

// Opcje
{--queue}       // boolean flag
{--queue=}      // z wartością
{--Q|queue}     // z aliasem
{--queue=*}     // array
```

## Error Handling

```php
try {
    $this->processData();
} catch (\Exception $e) {
    $this->error("Error: {$e->getMessage()}");
    return Command::FAILURE;
}
```

## Scheduling (Kernel)

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('appointments:send-reminders')
        ->dailyAt('08:00')
        ->timezone('Europe/Warsaw');
}
```

## Istniejące Commands (reference)

- `FixInvalidStaffAssignments` - napraw błędne przypisania staff
- `MaintenanceEnableCommand` - włącz maintenance mode
- `MaintenanceDisableCommand` - wyłącz maintenance mode
- `MaintenanceStatusCommand` - status maintenance
- `TestEmailFlowCommand` - testuj flow emaili
- `Reset*BookingStats` - resetuj statystyki
