<?php

namespace App\Console\Commands;

use App\Services\BookingStatsService;
use Illuminate\Console\Command;

class ResetWeeklyBookingStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'booking:reset-weekly-stats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset weekly booking and view statistics for all services';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Resetting weekly booking statistics...');

        BookingStatsService::resetWeeklyStats();

        $this->info('✓ Weekly statistics reset successfully!');
        $this->comment('- booking_count_week reset to 0');
        $this->comment('- view_count_week reset to 0');
        $this->comment('- stats_reset_weekly updated to '.now()->format('Y-m-d'));

        return Command::SUCCESS;
    }
}
