<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Services\Statistics\StatisticsExportService;
use App\Services\Statistics\StatisticsService;
use App\Support\TenantFeature;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Tenant statistics page — /admin/statystyki
 *
 * KPI cards + revenue chart + top-10 services table.
 * Period selector via query string ?period=.
 * Reads from statistics_daily_snapshots for KPIs/chart; live join for top-services.
 */
class Statistics extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|UnitEnum|null $navigationGroup = 'reports';

    protected static ?int $navigationSort = 99;

    protected static ?string $navigationLabel = 'Statystyki';

    protected static ?string $title = 'Statystyki';

    protected static ?string $slug = 'statystyki';

    protected string $view = 'filament.pages.statistics';

    #[Url]
    public string $period = 'this_month';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }

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
                    $data = $this->getStatsData();

                    return app(StatisticsExportService::class)->toCsv($data, $this->period);
                }),

            Action::make('exportPdf')
                ->label('Eksport PDF')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->action(function () {
                    $data = $this->getStatsData();
                    $tenant = TenantFeature::currentTenant();

                    return app(StatisticsExportService::class)->toPdf($data, $tenant, $this->period);
                }),
        ];
    }

    /**
     * Get aggregated stats for the current period/tenant.
     *
     * @return array{
     *   orders: array{revenue: float, count: int},
     *   appointments: array{revenue: float, count: int},
     *   rentals: array{revenue: float, count: int},
     *   total_revenue: float,
     *   by_day: array<string, array{orders: float, appointments: float, rentals: float, total: float}>
     * }
     */
    public function getStatsData(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return $this->emptyData();
        }

        /** @var StatisticsService $service */
        $service = app(StatisticsService::class);

        [$from, $to] = $service->periodToRange($this->period);

        return $service->forTenant($tenant, $from, $to);
    }

    /**
     * Top-10 services by revenue (live query, bounded to 1 tenant).
     *
     * @return array<int, array{name: string, count: int, revenue: float}>
     */
    public function getTopServices(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return [];
        }

        /** @var StatisticsService $service */
        $service = app(StatisticsService::class);

        [$from, $to] = $service->periodToRange($this->period);

        return DB::table('order_items as oi')
            ->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->where('o.organization_id', $tenant->id)
            ->where('o.status', 'paid')
            ->whereBetween('o.paid_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('oi.service_name as name, SUM(oi.quantity) as cnt, SUM(oi.total_price) as revenue')
            ->groupBy('oi.service_name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'count' => (int) $row->cnt,
                'revenue' => (float) $row->revenue,
            ])
            ->all();
    }

    /**
     * Chart series and categories for the selected period (multi-series for ApexCharts).
     *
     * @return array{series: list<array{name: string, data: list<float>}>, categories: list<string>}
     */
    public function getChartData(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return ['series' => [], 'categories' => []];
        }

        /** @var StatisticsService $service */
        $service = app(StatisticsService::class);

        [$from, $to] = $service->periodToRange($this->period);
        $data = $service->forTenant($tenant, $from, $to);

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

    /**
     * @return array{orders: array{revenue: float, count: int}, appointments: array{revenue: float, count: int}, rentals: array{revenue: float, count: int}, total_revenue: float, by_day: array<never>}
     */
    private function emptyData(): array
    {
        return [
            'orders' => ['revenue' => 0.0, 'count' => 0],
            'appointments' => ['revenue' => 0.0, 'count' => 0],
            'rentals' => ['revenue' => 0.0, 'count' => 0],
            'total_revenue' => 0.0,
            'by_day' => [],
        ];
    }
}
