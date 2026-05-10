<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Models\Organization;
use App\Models\StatisticsSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Statistics aggregation service.
 *
 * All reads go through the statistics_daily_snapshots table.
 * Live fallback queries raw tables only when today's snapshot is stale (>2h).
 *
 * Revenue counting rules:
 *   Orders       → status = 'paid',              field = total_amount
 *   Appointments → status IN (confirmed,completed), field = service_price_at_booking
 *   Rentals      → status IN (confirmed,active,returned), field = total_price
 */
class StatisticsService
{
    /**
     * Aggregate statistics for a single tenant over a date range.
     *
     * Falls back to a live query for today when the snapshot is stale (>2h).
     *
     * @return array{
     *   orders: array{revenue: float, count: int},
     *   appointments: array{revenue: float, count: int},
     *   rentals: array{revenue: float, count: int},
     *   total_revenue: float,
     *   by_day: array<string, array{orders: float, appointments: float, rentals: float, total: float}>
     * }
     */
    public function forTenant(Organization $org, Carbon $from, Carbon $to): array
    {
        $rows = StatisticsSnapshot::where('organization_id', $org->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->toBase(); // plain Collection — allows merging stdClass from liveToCollection()

        // Use live data for today if snapshot is stale or missing
        $today = Carbon::today();
        if ($from->lte($today) && $to->gte($today)) {
            $todaySnapshot = $rows->filter(fn ($r) => (
                $r->date instanceof \Illuminate\Support\Carbon
                    ? $r->date->toDateString()
                    : (string) $r->date
            ) === $today->toDateString());
            $needsLive = $todaySnapshot->isEmpty()
                || $todaySnapshot->min('computed_at') < now()->subHours(2);

            if ($needsLive) {
                $liveRows = $this->liveForDate($org, $today);
                // Remove stale today rows and replace with live data
                $rows = $rows->filter(fn ($r) => $r->date->toDateString() !== $today->toDateString());
                $rows = $rows->values()->merge($this->liveToCollection($liveRows, $org->id, $today));
            }
        }

        return $this->aggregateRows($rows, $from, $to);
    }

    /**
     * Live fallback query — hits raw tables directly.
     * Called when today's snapshot is missing or older than 2 hours.
     *
     * @return array{
     *   orders: array{revenue: float, count: int},
     *   appointments: array{revenue: float, count: int},
     *   rentals: array{revenue: float, count: int}
     * }
     */
    public function liveForDate(Organization $org, Carbon $date): array
    {
        $orgId = $org->id;
        $dateStr = $date->toDateString();

        $orders = DB::table('orders')
            ->where('organization_id', $orgId)
            ->where('status', 'paid')
            ->whereDate('paid_at', $dateStr)
            ->selectRaw('SUM(total_amount) as revenue, COUNT(*) as cnt')
            ->first();

        $appointments = DB::table('appointments')
            ->where('organization_id', $orgId)
            ->whereIn('status', ['confirmed', 'completed'])
            ->whereDate('appointment_date', $dateStr)
            ->selectRaw('SUM(service_price_at_booking) as revenue, COUNT(*) as cnt')
            ->first();

        $rentals = DB::table('rentals')
            ->where('organization_id', $orgId)
            ->whereIn('status', ['confirmed', 'active', 'returned'])
            ->whereDate('start_date', $dateStr)
            ->selectRaw('SUM(total_price) as revenue, COUNT(*) as cnt')
            ->first();

        return [
            'orders' => [
                'revenue' => (float) ($orders->revenue ?? 0),
                'count' => (int) ($orders->cnt ?? 0),
            ],
            'appointments' => [
                'revenue' => (float) ($appointments->revenue ?? 0),
                'count' => (int) ($appointments->cnt ?? 0),
            ],
            'rentals' => [
                'revenue' => (float) ($rentals->revenue ?? 0),
                'count' => (int) ($rentals->cnt ?? 0),
            ],
        ];
    }

    /**
     * Convert a period string to [Carbon $from, Carbon $to].
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodToRange(string $period): array
    {
        return match ($period) {
            'today' => [Carbon::today(), Carbon::today()],
            'this_week' => [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()],
            'this_month' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
            'this_year' => [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()],
            'last_month' => [
                Carbon::now()->subMonth()->startOfMonth(),
                Carbon::now()->subMonth()->endOfMonth(),
            ],
            'last_year' => [
                Carbon::now()->subYear()->startOfYear(),
                Carbon::now()->subYear()->endOfYear(),
            ],
            default => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
        };
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Aggregate a collection of snapshot rows into the canonical return shape.
     *
     * @param  Collection<int, StatisticsSnapshot|\stdClass>  $rows
     * @return array{
     *   orders: array{revenue: float, count: int},
     *   appointments: array{revenue: float, count: int},
     *   rentals: array{revenue: float, count: int},
     *   total_revenue: float,
     *   by_day: array<string, array{orders: float, appointments: float, rentals: float, total: float}>
     * }
     */
    private function aggregateRows(Collection $rows, Carbon $from, Carbon $to): array
    {
        $totals = [
            'orders' => ['revenue' => 0.0, 'count' => 0],
            'appointments' => ['revenue' => 0.0, 'count' => 0],
            'rentals' => ['revenue' => 0.0, 'count' => 0],
        ];

        $byDay = [];

        foreach ($rows as $row) {
            $source = $row->source;
            $dateKey = $row->date instanceof \Illuminate\Support\Carbon
                ? $row->date->toDateString()
                : (string) $row->date;

            if (! isset($totals[$source])) {
                continue;
            }

            $totals[$source]['revenue'] += (float) $row->revenue;
            $totals[$source]['count'] += (int) $row->count;

            $byDay[$dateKey][$source] = ($byDay[$dateKey][$source] ?? 0.0) + (float) $row->revenue;
        }

        // Ensure every day in range appears (fill gaps with zeros)
        $current = $from->copy();
        while ($current->lte($to)) {
            $key = $current->toDateString();
            if (! isset($byDay[$key])) {
                $byDay[$key] = [];
            }
            $byDay[$key]['orders'] = $byDay[$key]['orders'] ?? 0.0;
            $byDay[$key]['appointments'] = $byDay[$key]['appointments'] ?? 0.0;
            $byDay[$key]['rentals'] = $byDay[$key]['rentals'] ?? 0.0;
            $byDay[$key]['total'] = $byDay[$key]['orders'] + $byDay[$key]['appointments'] + $byDay[$key]['rentals'];
            $current->addDay();
        }

        ksort($byDay);

        // Defensive: ensure any entries outside the gap-fill range also have total
        foreach ($byDay as $key => $entry) {
            if (! isset($byDay[$key]['total'])) {
                $byDay[$key]['total'] = ($byDay[$key]['orders'] ?? 0.0)
                    + ($byDay[$key]['appointments'] ?? 0.0)
                    + ($byDay[$key]['rentals'] ?? 0.0);
            }
        }

        $totalRevenue = $totals['orders']['revenue']
            + $totals['appointments']['revenue']
            + $totals['rentals']['revenue'];

        return [
            'orders' => $totals['orders'],
            'appointments' => $totals['appointments'],
            'rentals' => $totals['rentals'],
            'total_revenue' => $totalRevenue,
            'by_day' => $byDay,
        ];
    }

    /**
     * Convert live query result array into a mock collection of snapshot-like objects.
     *
     * @param  array{orders: array{revenue: float, count: int}, appointments: array{...}, rentals: array{...}}  $live
     * @return Collection<int, \stdClass>
     */
    private function liveToCollection(array $live, int $orgId, Carbon $date): Collection
    {
        $items = [];
        foreach (['orders', 'appointments', 'rentals'] as $source) {
            $obj = new \stdClass;
            $obj->organization_id = $orgId;
            $obj->date = $date->copy();
            $obj->source = $source;
            $obj->revenue = $live[$source]['revenue'];
            $obj->count = $live[$source]['count'];
            $obj->computed_at = now();
            $items[] = $obj;
        }

        return collect($items);
    }
}
