<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MaintenanceType;
use App\Services\MaintenanceService;
use Illuminate\Console\Command;

/**
 * Enable Maintenance Mode Command
 *
 * CLI command to enable maintenance mode with various types and configurations.
 *
 * Usage:
 *   php artisan maintenance:enable --type=deployment
 *   php artisan maintenance:enable --type=prelaunch --message="Coming soon!" --launch-date="2025-12-01"
 */
class MaintenanceEnableCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'maintenance:enable
                            {--type=deployment : Maintenance type (deployment, scheduled, emergency, prelaunch)}
                            {--message= : Custom message to display to users}
                            {--duration= : Estimated duration (e.g., "15 minutes", "1 hour")}
                            {--launch-date= : Launch date for pre-launch mode (e.g., "2025-12-01")}
                            {--image= : Custom image URL for pre-launch page}';

    /**
     * The console command description.
     */
    protected $description = 'Enable maintenance mode with specified configuration';

    /**
     * Execute the console command.
     */
    public function handle(MaintenanceService $service): int
    {
        $typeInput = $this->option('type');

        // Validate type
        try {
            $type = MaintenanceType::from($typeInput);
        } catch (\ValueError $e) {
            $this->error("Invalid maintenance type: {$typeInput}");
            $this->line('Valid types: deployment, scheduled, emergency, prelaunch');

            return self::FAILURE;
        }

        // Build config
        $config = [];

        if ($message = $this->option('message')) {
            $config['message'] = $message;
        }

        if ($type === MaintenanceType::PRELAUNCH) {
            if ($launchDate = $this->option('launch-date')) {
                $config['launch_date'] = $launchDate;
            }
            if ($image = $this->option('image')) {
                $config['image_url'] = $image;
            }
        } else {
            if ($duration = $this->option('duration')) {
                $config['estimated_duration'] = $duration;
            }
        }

        // Confirm action
        $this->warn("⚠️  You are about to enable {$type->label()} maintenance mode.");

        if ($type === MaintenanceType::PRELAUNCH) {
            $this->error('   PRE-LAUNCH: NO BYPASS allowed - even admins will be blocked!');
        } else {
            $this->info('   Admins can bypass via role or secret token.');
        }

        if (! $this->confirm('Do you want to continue?', true)) {
            $this->line('Operation cancelled.');

            return self::SUCCESS;
        }

        // Enable maintenance
        try {
            $service->enable(
                type: $type,
                user: null,
                config: $config
            );

            $this->newLine();
            $this->line('┌──────────────────────────────────────────────────────┐');
            $this->line('│  <fg=green>✓</> Maintenance Mode Enabled Successfully           │');
            $this->line('└──────────────────────────────────────────────────────┘');
            $this->newLine();

            $this->table(
                ['Property', 'Value'],
                [
                    ['Type', $type->label()],
                    ['Can Bypass', $type->canBypass() ? 'Yes (admins + token)' : 'No'],
                    ['Retry After', $type->retryAfter().' seconds'],
                ]
            );

            // Show secret token for non-prelaunch modes
            if ($type !== MaintenanceType::PRELAUNCH) {
                $token = $service->getSecretToken();
                if ($token) {
                    $this->newLine();
                    $this->line('┌──────────────────────────────────────────────────────┐');
                    $this->line('│  Secret Bypass Token (share with authorized users): │');
                    $this->line('└──────────────────────────────────────────────────────┘');
                    $this->line("   <fg=yellow>{$token}</>");
                    $this->newLine();
                    $this->line("   URL: <fg=cyan>https://yourdomain.com?maintenance_token={$token}</>");
                    $this->newLine();
                }
            }

            // Show config if provided
            if (! empty($config)) {
                $this->newLine();
                $this->line('<fg=blue>Configuration:</>');
                foreach ($config as $key => $value) {
                    $this->line("   {$key}: {$value}");
                }
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to enable maintenance mode: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
