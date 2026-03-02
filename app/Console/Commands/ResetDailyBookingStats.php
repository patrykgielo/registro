<?php

namespace App\Console\Commands;

use App\Services\BookingStatsService;
use Illuminate\Console\Command;

class ResetDailyBookingStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'booking:reset-daily-stats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset daily booking and view statistics for all services';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Resetting daily booking statistics...');

        BookingStatsService::resetDailyStats();

        $this->info('✓ Daily statistics reset successfully!');
        $this->comment('- booking_count_today reset to 0');
        $this->comment('- view_count_today reset to 0');
        $this->comment('- stats_reset_daily updated to '.now()->format('Y-m-d'));

        return Command::SUCCESS;
    }
}
