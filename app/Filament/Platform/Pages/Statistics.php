<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Services\Statistics\StatisticsExportService;
use App\Services\Statistics\StatisticsService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Platform-level statistics page — /platform/statystyki
 *
 * Cross-tenant aggregate KPIs + per-tenant breakdown table.
 * Period selector via query string ?period=.
 */
class Statistics extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Statystyki';

    protected static ?string $slug = 'statystyki';

    protected string $view = 'filament.platform.pages.statistics';

    #[Url]
    public string $period = 'this_month';

    public function mount(): void
    {
        // #[Url] handles syncing from query string; validate on mount
        if (! in_array($this->period, $this->validPeriods(), true)) {
            $this->period = 'this_month';
        }
    }

    public function updatedPeriod(): void
    {
        if (! in_array($this->period, $this->validPeriods(), true)) {
            $this->period = 'this_month';
        }
        $chartData = $this->getChartData();
        $this->dispatch('chart-refresh', series: $chartData['series'], categories: $chartData['categories']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Eksport CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    /** @var StatisticsService $service */
                    $service = app(StatisticsService::class);
                    [$from, $to] = $service->periodToRange($this->period);
                    $data = $service->platformAggregate($from, $to);

                    return app(StatisticsExportService::class)->toCsv($data, $this->period);
                }),
        ];
    }

    /**
     * Cross-tenant aggregate for KPI cards.
     *
     * @return array{
     *   orders: array{revenue: float, count: int},
     *   appointments: array{revenue: float, count: int},
     *   rentals: array{revenue: float, count: int},
     *   total_revenue: float,
     *   by_day: array<string, array{orders: float, appointments: float, rentals: float, total: float}>
     * }
     */
    public function getAggregateData(): array
    {
        /** @var StatisticsService $service */
        $service = app(StatisticsService::class);
        [$from, $to] = $service->periodToRange($this->period);

        return $service->platformAggregate($from, $to);
    }

    /**
     * Per-tenant breakdown for the table.
     *
     * @return array<int, array{organization: \App\Models\Organization, orders: array{revenue: float, count: int}, appointments: array{revenue: float, count: int}, rentals: array{revenue: float, count: int}, total_revenue: float}>
     */
    public function getPerTenantData(): array
    {
        /** @var StatisticsService $service */
        $service = app(StatisticsService::class);
        [$from, $to] = $service->periodToRange($this->period);

        return $service->perTenant($from, $to)->all();
    }

    /**
     * Chart series and categories for the selected period (multi-series for ApexCharts).
     *
     * @return array{series: list<array{name: string, data: list<float>}>, categories: list<string>}
     */
    public function getChartData(): array
    {
        /** @var StatisticsService $service */
        $service = app(StatisticsService::class);

        [$from, $to] = $service->periodToRange($this->period);
        $data = $service->platformAggregate($from, $to);

        $orders = [];
        $appointments = [];
        $rentals = [];
        $categories = [];

        foreach ($data['by_day'] as $date => $row) {
            $categories[] = Carbon::parse($date)->format('d.m');
            $orders[] = round($row['orders'] ?? 0.0, 2);
            $appointments[] = round($row['appointments'] ?? 0.0, 2);
            $rentals[] = round($row['rentals'] ?? 0.0, 2);
        }

        return [
            'series' => [
                ['name' => 'Zamówienia',   'data' => $orders],
                ['name' => 'Wizyty',       'data' => $appointments],
                ['name' => 'Wypożyczenia', 'data' => $rentals],
            ],
            'categories' => $categories,
        ];
    }

    /**
     * @return list<string>
     */
    public function validPeriods(): array
    {
        return ['today', 'this_week', 'this_month', 'this_year', 'last_month', 'last_year'];
    }

    /**
     * @return array{label: string, value: string}[]
     */
    public function periodOptions(): array
    {
        return [
            ['value' => 'today', 'label' => 'Dziś'],
            ['value' => 'this_week', 'label' => 'Ten tydzień'],
            ['value' => 'this_month', 'label' => 'Ten miesiąc'],
            ['value' => 'this_year', 'label' => 'Ten rok'],
            ['value' => 'last_month', 'label' => 'Poprzedni miesiąc'],
            ['value' => 'last_year', 'label' => 'Poprzedni rok'],
        ];
    }
}
