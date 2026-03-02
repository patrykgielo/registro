<?php

namespace App\Console\Commands;

use App\Services\BookingStatsService;
use Illuminate\Console\Command;

class ResetMonthlyBookingStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'booking:reset-monthly-stats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset monthly booking statistics for all services';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Resetting monthly booking statistics...');

        BookingStatsService::resetMonthlyStats();

        $this->info('✓ Monthly statistics reset successfully!');
        $this->comment('- booking_count_month reset to 0');

        return Command::SUCCESS;
    }
}
