<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MaintenanceEvent;
use App\Services\MaintenanceService;
use Illuminate\Console\Command;

/**
 * Maintenance Status Command
 *
 * CLI command to check current maintenance mode status and recent event history.
 *
 * Usage:
 *   php artisan maintenance:status
 *   php artisan maintenance:status --history
 */
class MaintenanceStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'maintenance:status
                            {--history : Show recent maintenance event history}';

    /**
     * The console command description.
     */
    protected $description = 'Display current maintenance mode status';

    /**
     * Execute the console command.
     */
    public function handle(MaintenanceService $service): int
    {
        $isActive = $service->isActive();
        $type = $service->getType();
        $config = $service->getConfig();
        $status = $service->getStatus();

        $this->newLine();
        $this->line('┌──────────────────────────────────────────────────────┐');
        $this->line('│  Maintenance Mode Status                            │');
        $this->line('└──────────────────────────────────────────────────────┘');
        $this->newLine();

        if ($isActive) {
            $this->line('   Status: <fg=red>● ACTIVE</>');
            $this->line("   Type: <fg=yellow>{$type->label()}</>");
            $this->line('   Can Bypass: '.($type->canBypass() ? '<fg=green>Yes (admins + token)</>' : '<fg=red>No</>'));
            $this->line("   Retry After: {$type->retryAfter()} seconds");

            if ($status['enabled_at']) {
                $this->line("   Enabled At: {$status['enabled_at']}");
            }

            // Show secret token for non-prelaunch modes
            if ($type !== \App\Enums\MaintenanceType::PRELAUNCH) {
                $token = $service->getSecretToken();
                if ($token) {
                    $this->newLine();
                    $this->line('   <fg=blue>Secret Bypass Token:</>');
                    $this->line("   <fg=yellow>{$token}</>");
                }
            }

            // Show configuration
            if (! empty($config)) {
                $this->newLine();
                $this->line('   <fg=blue>Configuration:</>');
                foreach ($config as $key => $value) {
                    if ($value) {
                        $this->line("   • {$key}: {$value}");
                    }
                }
            }
        } else {
            $this->line('   Status: <fg=green>● OPERATIONAL</>');
            $this->line('   Type: <fg=gray>None</>');
            $this->line('   Site is accessible to all users');
        }

        // Show recent event history if requested
        if ($this->option('history')) {
            $this->newLine();
            $this->line('┌──────────────────────────────────────────────────────┐');
            $this->line('│  Recent Maintenance Events (Last 10)                │');
            $this->line('└──────────────────────────────────────────────────────┘');
            $this->newLine();

            $events = MaintenanceEvent::with('user')
                ->latest()
                ->take(10)
                ->get();

            if ($events->isEmpty()) {
                $this->line('   <fg=gray>No events found</>');
            } else {
                $rows = $events->map(function ($event) {
                    return [
                        $event->id,
                        $event->type->label(),
                        ucfirst($event->action),
                        $event->user?->email ?? 'System',
                        $event->created_at->format('Y-m-d H:i:s'),
                    ];
                })->toArray();

                $this->table(
                    ['ID', 'Type', 'Action', 'User', 'Date/Time'],
                    $rows
                );
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
