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

## Destructive Commands Pattern (MANDATORY)

For commands that permanently delete or overwrite business data, ALWAYS implement all five:

1. **`--dry-run`** — show what would change; if org has data and `--force` is absent, return FAILURE (mirrors real outcome)
2. **Confirm gate** — `$this->input->isInteractive() && !$this->confirm(...)` before any purge; skipped when `--no-interaction` / piped input
3. **Audit log** — `Log::info(start)` + `Log::warning(before purge, include 'interactive' => $this->input->isInteractive())` + `Log::info(completed)` — distinguishes operator-confirmed from non-interactive automation (GDPR art. 5(1)(f))
4. **Transaction with try/catch** — wrap purge + seed/write in `DB::transaction()`; catch `\Throwable`, `Log::error` with org_id + message, `$this->error(...)`, return `FAILURE`
5. **Guard before purge** — validate dependent objects (e.g. seeder implements interface) BEFORE any destructive step; a broken dependency must never trigger data loss

```php
try {
    DB::transaction(function () use ($org, $seeder, $needsPurge) {
        if ($needsPurge) { $this->purgeExistingData($org); }
        $seeder->seed($org);
    });
} catch (\Throwable $e) {
    Log::error('command transaction failed — rolled back', ['org_id' => $org->id, 'exception' => $e->getMessage()]);
    $this->error('Seed nie powiódł się (rollback): '.$e->getMessage());
    return self::FAILURE;
}
Log::info('command completed', ['org_id' => $org->id, 'purge_done' => $needsPurge]);
```

Tests: use `->expectsConfirmation('exact question string', 'yes/no')` — PendingCommand uses EXACT match, not substring. Keep the confirm question static (no dynamic content) so it can be matched in tests.

Reference implementation: `onboarding:seed-vertical` (`app/Console/Commands/SeedVerticalDataCommand.php`)

## Istniejące Commands (reference)

- `FixInvalidStaffAssignments` - napraw błędne przypisania staff
- `MaintenanceEnableCommand` - włącz maintenance mode
- `MaintenanceDisableCommand` - wyłącz maintenance mode
- `MaintenanceStatusCommand` - status maintenance
- `TestEmailFlowCommand` - testuj flow emaili
- `Reset*BookingStats` - resetuj statystyki
- `SeedVerticalDataCommand` - ręczne seedowanie danych branżowych (dry-run + confirm + audit log)
- `SeedWebsiteCommand` (`onboarding:seed-website`) - ręczne seedowanie uniwersalnej strony głównej + minimalnego menu (dane z organizacji w czasie działania, nie hardcoded) — `app/docs/features/tenant-website-seeder.md`
- `ProvisionTenantCommand` (`registro:tenant-provision`) - provisionuje org+ownera dla dedykowanego tenant-stacka; global seedery (role/settings/e-mail templates) tylko raz per stack, gated przez `TenantProvisioningState` — patrz `app/docs/features/tenant-stack-provisioning.md`
- `TenantProvisioningStatusCommand` (`registro:tenant-provisioned`) - bezstanowy check dla shell tooling, exit code only
- `ResendPasswordSetupLinkCommand` (`registro:password-setup-link {email}`) - generuje nowy link do ustawienia hasła gdy pierwszy (z `registro:tenant-provision`/UserResource) wygasł; drukuje link zawsze (jak `registro:tenant-provision`), dispatch `AdminCreatedUser` best-effort z `--no-email` do pominięcia; odmawia kontu z już ustawionym hasłem bez `--force` (link resetowałby hasło bez znajomości starego) — operator runbook: `app/docs/deployment/instalacja-tenanta-od-zera.md` krok 4.1
