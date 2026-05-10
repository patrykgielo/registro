<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Organization;
use App\Services\Statistics\StatisticsService;
use App\Support\TenantFeature;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

/**
 * Last-30-days revenue line chart for the /admin tenant dashboard.
 *
 * Data source: statistics_daily_snapshots (never raw tables).
 */
class RevenueChartWidget extends ChartWidget
{
    protected ?string $heading = 'Przychód — ostatnie 30 dni';

    protected ?string $description = 'Łączny przychód dzienny ze wszystkich źródeł';

    protected ?string $pollingInterval = null;

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }

    protected function getData(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return ['datasets' => [], 'labels' => []];
        }

        /** @var StatisticsService $service */
        $service = app(StatisticsService::class);

        $from = Carbon::today()->subDays(29);
        $to = Carbon::today();
        $data = $service->forTenant($tenant, $from, $to);

        $labels = [];
        $totals = [];

        foreach ($data['by_day'] as $date => $row) {
            $labels[] = Carbon::parse($date)->format('d.m');
            $totals[] = round($row['total'], 2);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Przychód (PLN)',
                    'data' => $totals,
                    'borderColor' => '#3D8A94',
                    'backgroundColor' => 'rgba(61,138,148,0.12)',
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
