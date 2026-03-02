<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MaintenanceService;
use Illuminate\Console\Command;

/**
 * Disable Maintenance Mode Command
 *
 * CLI command to disable maintenance mode and restore normal site access.
 *
 * Usage:
 *   php artisan maintenance:disable
 *   php artisan maintenance:disable --force
 */
class MaintenanceDisableCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'maintenance:disable
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     */
    protected $description = 'Disable maintenance mode and restore normal site access';

    /**
     * Execute the console command.
     */
    public function handle(MaintenanceService $service): int
    {
        // Check if maintenance is active
        if (! $service->isActive()) {
            $this->info('✓ Maintenance mode is not active. Nothing to disable.');

            return self::SUCCESS;
        }

        $type = $service->getType();

        // Confirm action
        if (! $this->option('force')) {
            $this->warn("You are about to disable {$type->label()} maintenance mode.");
            $this->line('This will restore normal site access for all users.');

            if (! $this->confirm('Do you want to continue?', true)) {
                $this->line('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        // Disable maintenance
        try {
            $service->disable(user: null);

            $this->newLine();
            $this->line('┌──────────────────────────────────────────────────────┐');
            $this->line('│  <fg=green>✓</> Maintenance Mode Disabled Successfully         │');
            $this->line('└──────────────────────────────────────────────────────┘');
            $this->newLine();

            $this->info('Site is now accessible to all users.');
            $this->line("Previous mode: <fg=yellow>{$type->label()}</>");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to disable maintenance mode: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
