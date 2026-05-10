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

    protected static ?string $slug = 'statystyki';

    protected string $view = 'filament.pages.statistics';

    public string $period = 'this_month';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }

    public function mount(): void
    {
        $this->period = request()->query('period', 'this_month');

        if (! in_array($this->period, $this->validPeriods(), true)) {
            $this->period = 'this_month';
        }
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
     * Chart labels and datasets for last 30 days.
     *
     * @return array{labels: list<string>, totals: list<float>}
     */
    public function getChartData(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return ['labels' => [], 'totals' => []];
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

        return ['labels' => $labels, 'totals' => $totals];
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
