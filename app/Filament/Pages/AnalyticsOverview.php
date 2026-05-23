<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Support\TenantFeature;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

class AnalyticsOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cursor-arrow-rays';

    protected static string|UnitEnum|null $navigationGroup = 'reports';

    protected static ?int $navigationSort = 100;

    protected static ?string $navigationLabel = 'Analityka';

    protected static ?string $slug = 'analityka';

    protected string $view = 'filament.pages.analytics-overview';

    #[Url]
    public string $period = 'this_week';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }

    public function mount(): void
    {
        if (! in_array($this->period, $this->validPeriods(), true)) {
            $this->period = 'this_week';
        }
    }

    public function updatedPeriod(): void
    {
        if (! in_array($this->period, $this->validPeriods(), true)) {
            $this->period = 'this_week';
        }
        $chartData = $this->getChartData();
        $this->dispatch('analytics-chart-refresh', series: $chartData['series'], categories: $chartData['categories']);
    }

    /**
     * @return array{page_views: int, unique_sessions: int, unique_users: int, avg_session_events: float}
     */
    public function getKpiData(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return ['page_views' => 0, 'unique_sessions' => 0, 'unique_users' => 0, 'avg_session_events' => 0.0];
        }

        [$from, $to] = $this->periodToRange($this->period);

        $base = DB::table('analytics_events')
            ->where('organization_id', $tenant->id)
            ->whereBetween('occurred_at', [$from, $to]);

        $pageViews = (clone $base)->where('event', 'page_viewed')->count();

        $uniqueSessions = (clone $base)
            ->whereNotNull('session_id')
            ->distinct()
            ->count('session_id');

        $uniqueUsers = (clone $base)
            ->whereNotNull('user_id')
            ->distinct()
            ->count('user_id');

        $totalEvents = (clone $base)->count();
        $avgSessionEvents = $uniqueSessions > 0
            ? round($totalEvents / $uniqueSessions, 1)
            : 0.0;

        return [
            'page_views' => $pageViews,
            'unique_sessions' => $uniqueSessions,
            'unique_users' => $uniqueUsers,
            'avg_session_events' => $avgSessionEvents,
        ];
    }

    /**
     * @return list<array{page_type: string, views: int}>
     */
    public function getPageTypeDistribution(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return [];
        }

        [$from, $to] = $this->periodToRange($this->period);

        return DB::table('analytics_events')
            ->where('organization_id', $tenant->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->where('event', 'page_viewed')
            ->whereNotNull('page_type')
            ->selectRaw('page_type, COUNT(*) as views')
            ->groupBy('page_type')
            ->orderByDesc('views')
            ->get()
            ->map(fn ($row) => [
                'page_type' => (string) $row->page_type,
                'views' => (int) $row->views,
            ])
            ->all();
    }

    /**
     * @return list<array{url: string, views: int, sessions: int}>
     */
    public function getTopPages(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return [];
        }

        [$from, $to] = $this->periodToRange($this->period);

        return DB::table('analytics_events')
            ->where('organization_id', $tenant->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->where('event', 'page_viewed')
            ->whereNotNull('url')
            ->selectRaw('url, COUNT(*) as views, COUNT(DISTINCT session_id) as sessions')
            ->groupBy('url')
            ->orderByDesc('views')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'url' => (string) $row->url,
                'views' => (int) $row->views,
                'sessions' => (int) $row->sessions,
            ])
            ->all();
    }

    /**
     * @return array{25: int, 50: int, 75: int, 90: int, 100: int}
     */
    public function getScrollDepth(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return ['25' => 0, '50' => 0, '75' => 0, '90' => 0, '100' => 0];
        }

        [$from, $to] = $this->periodToRange($this->period);

        $depths = ['25', '50', '75', '90', '100'];

        $counts = DB::table('analytics_events')
            ->where('organization_id', $tenant->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->whereIn('event', array_map(fn ($d) => 'scroll_'.$d, $depths))
            ->selectRaw('event, COUNT(*) as cnt')
            ->groupBy('event')
            ->get()
            ->pluck('cnt', 'event');

        $result = [];
        foreach ($depths as $depth) {
            $result[$depth] = (int) ($counts['scroll_'.$depth] ?? 0);
        }

        return $result;
    }

    /**
     * @return array{series: list<array{name: string, data: list<int>}>, categories: list<string>}
     */
    public function getChartData(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return ['series' => [], 'categories' => []];
        }

        [$from, $to] = $this->periodToRange($this->period);

        $rows = DB::table('analytics_events')
            ->where('organization_id', $tenant->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->where('event', 'page_viewed')
            ->selectRaw('DATE(occurred_at) as day, COUNT(*) as views')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $views = [];
        $categories = [];

        $current = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($current->lte($end)) {
            $key = $current->format('Y-m-d');
            $categories[] = $current->format('d.m');
            $views[] = isset($rows[$key]) ? (int) $rows[$key]->views : 0;
            $current->addDay();
        }

        return [
            'series' => [
                ['name' => 'Odsłony', 'data' => $views],
            ],
            'categories' => $categories,
        ];
    }

    /**
     * @return list<array{device: string, count: int}>
     */
    public function getDeviceBreakdown(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return [];
        }

        [$from, $to] = $this->periodToRange($this->period);

        return DB::table('analytics_events')
            ->where('organization_id', $tenant->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->whereNotNull('device_type')
            ->selectRaw('device_type as device, COUNT(*) as count')
            ->groupBy('device_type')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'device' => (string) $row->device,
                'count' => (int) $row->count,
            ])
            ->all();
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
            ['value' => 'last_month', 'label' => 'Poprzedni miesiąc'],
        ];
    }

    /**
     * @return list<string>
     */
    public function validPeriods(): array
    {
        return ['today', 'this_week', 'this_month', 'last_month'];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodToRange(string $period): array
    {
        return match ($period) {
            'today' => [Carbon::today(), Carbon::now()],
            'this_week' => [Carbon::now()->startOfWeek(), Carbon::now()],
            'last_month' => [
                Carbon::now()->subMonthNoOverflow()->startOfMonth(),
                Carbon::now()->subMonthNoOverflow()->endOfMonth(),
            ],
            default => [Carbon::now()->startOfMonth(), Carbon::now()], // this_month
        };
    }
}
