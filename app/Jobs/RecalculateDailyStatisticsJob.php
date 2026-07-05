<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recalculate daily statistics snapshots for one or all tenants.
 *
 * Uses DB::table() (not Eloquent) to bypass BelongsToOrganization global scopes
 * and avoid N+1 issues when processing all orgs in a single job.
 *
 * Revenue counting rules:
 *   orders:       status = 'paid',                 field = total_amount,           date = paid_at
 *   appointments: status IN (confirmed,completed),  field = service_price_at_booking, date = appointment_date
 *   rentals:      status IN (confirmed,active,returned), field = total_price,      date = start_date
 */
class RecalculateDailyStatisticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly Carbon $date,
        public readonly ?int $organizationId = null,
    ) {}

    public function handle(): void
    {
        $dateStr = $this->date->toDateString();

        $orgIds = $this->organizationId
            ? [$this->organizationId]
            : Organization::pluck('id')->all();

        foreach ($orgIds as $orgId) {
            $this->upsertForOrg($orgId, $dateStr);
        }

        Log::info('[RecalculateDailyStatisticsJob] Done', [
            'date' => $dateStr,
            'org_id' => $this->organizationId ?? 'all',
            'org_count' => count($orgIds),
        ]);
    }

    private function upsertForOrg(int $orgId, string $dateStr): void
    {
        $now = now();

        $ordersRevenue = DB::table('orders')
            ->where('organization_id', $orgId)
            ->where('status', 'paid')
            ->whereDate('paid_at', $dateStr)
            ->sum('total_amount');

        $ordersCount = DB::table('orders')
            ->where('organization_id', $orgId)
            ->where('status', 'paid')
            ->whereDate('paid_at', $dateStr)
            ->count();

        $appointmentsRevenue = DB::table('appointments')
            ->where('organization_id', $orgId)
            ->whereIn('status', ['confirmed', 'completed'])
            ->whereDate('appointment_date', $dateStr)
            ->sum('service_price_at_booking');

        $appointmentsCount = DB::table('appointments')
            ->where('organization_id', $orgId)
            ->whereIn('status', ['confirmed', 'completed'])
            ->whereDate('appointment_date', $dateStr)
            ->count();

        $rentalsRevenue = DB::table('rentals')
            ->where('organization_id', $orgId)
            ->whereIn('status', ['confirmed', 'active', 'returned'])
            ->whereDate('start_date', $dateStr)
            ->sum('total_price');

        $rentalsCount = DB::table('rentals')
            ->where('organization_id', $orgId)
            ->whereIn('status', ['confirmed', 'active', 'returned'])
            ->whereDate('start_date', $dateStr)
            ->count();

        $rows = [
            [
                'organization_id' => $orgId,
                'date' => $dateStr,
                'source' => 'orders',
                'revenue' => (float) $ordersRevenue,
                'count' => (int) $ordersCount,
                'computed_at' => $now,
            ],
            [
                'organization_id' => $orgId,
                'date' => $dateStr,
                'source' => 'appointments',
                'revenue' => (float) $appointmentsRevenue,
                'count' => (int) $appointmentsCount,
                'computed_at' => $now,
            ],
            [
                'organization_id' => $orgId,
                'date' => $dateStr,
                'source' => 'rentals',
                'revenue' => (float) $rentalsRevenue,
                'count' => (int) $rentalsCount,
                'computed_at' => $now,
            ],
        ];

        DB::table('statistics_daily_snapshots')->upsert(
            $rows,
            ['organization_id', 'date', 'source'],
            ['revenue', 'count', 'computed_at'],
        );
    }
}
