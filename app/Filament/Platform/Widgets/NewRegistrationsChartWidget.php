<?php

declare(strict_types=1);

namespace App\Filament\Platform\Widgets;

use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * Last-30-days new tenant registrations for the /platform dashboard.
 *
 * Single grouped query over organizations.created_at — cheap, no snapshot
 * table dependency (unlike the tenant-side RevenueChartWidget).
 */
class NewRegistrationsChartWidget extends ChartWidget
{
    protected ?string $heading = 'Nowe organizacje — ostatnie 30 dni';

    protected ?string $pollingInterval = null;

    protected static ?int $sort = -5;

    public static function canView(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    protected function getData(): array
    {
        $from = Carbon::today()->subDays(29);
        $to = Carbon::today();

        $rows = DB::table('organizations')
            ->selectRaw('DATE(created_at) as day, COUNT(*) as cnt')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $labels = [];
        $totals = [];
        $current = $from->copy();

        while ($current->lte($to)) {
            $key = $current->toDateString();
            $labels[] = $current->format('d.m');
            $totals[] = (int) ($rows[$key]->cnt ?? 0);
            $current->addDay();
        }

        return [
            'datasets' => [
                [
                    'label' => 'Nowi tenanci',
                    'data' => $totals,
                    'borderColor' => '#6366F1',
                    'backgroundColor' => 'rgba(99,102,241,0.12)',
                    'fill' => true,
                    'tension' => 0.4,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
