<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Support\TenantFeature;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Database\Query\Builder;
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
    public string $period = 'last_14_days';

    #[Url(as: 'from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'to', except: '')]
    public string $dateTo = '';

    #[Url(as: 'device', except: '')]
    public string $deviceParam = '';

    #[Url(as: 'utm', except: '')]
    public string $utmSourceParam = '';

    public static function canAccess(): bool
    {
        if (! TenantFeature::currentTenant()) {
            return false;
        }

        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }

    public function mount(): void
    {
        if (! in_array($this->period, $this->validPeriods(), true)) {
            $this->period = 'last_14_days';
        }
        if ($this->dateFrom && ! strtotime($this->dateFrom)) {
            $this->dateFrom = '';
        }
        if ($this->dateTo && ! strtotime($this->dateTo)) {
            $this->dateTo = '';
        }
    }

    public function updatedPeriod(): void
    {
        if (! in_array($this->period, $this->validPeriods(), true)) {
            $this->period = 'last_14_days';
        }
        if ($this->period !== 'custom') {
            $this->dateFrom = '';
            $this->dateTo = '';
        }
        $this->dispatch('analytics-chart-refresh', ...$this->getChartData());
    }

    public function getDeviceTypes(): array
    {
        return $this->deviceParam ? array_filter(explode(',', $this->deviceParam)) : [];
    }

    public function getUtmSources(): array
    {
        return $this->utmSourceParam ? array_filter(explode(',', $this->utmSourceParam)) : [];
    }

    public function hasActiveFilters(): bool
    {
        return $this->dateFrom !== '' || $this->dateTo !== ''
            || $this->deviceParam !== '' || $this->utmSourceParam !== '';
    }

    public function resetFilters(): void
    {
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->deviceParam = '';
        $this->utmSourceParam = '';
        $this->period = 'last_14_days';
        $this->dispatch('analytics-chart-refresh', ...$this->getChartData());
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function resolvedDateRange(): array
    {
        if ($this->dateFrom !== '' && $this->dateTo !== '') {
            return [
                Carbon::parse($this->dateFrom)->startOfDay(),
                Carbon::parse($this->dateTo)->endOfDay(),
            ];
        }

        if ($this->period === 'custom') {
            if ($this->dateFrom !== '') {
                return [Carbon::parse($this->dateFrom)->startOfDay(), Carbon::now()->endOfDay()];
            }
            if ($this->dateTo !== '') {
                return [Carbon::now()->startOfMonth()->startOfDay(), Carbon::parse($this->dateTo)->endOfDay()];
            }
        }

        return $this->periodToRange($this->period);
    }

    private function buildBaseQuery(): Builder
    {
        $tenant = TenantFeature::currentTenant();

        [$from, $to] = $this->resolvedDateRange();

        $query = DB::table('analytics_events')
            ->where('organization_id', $tenant->id)
            ->whereBetween('occurred_at', [$from, $to]);

        if ($this->getDeviceTypes()) {
            $query->whereIn('device_type', $this->getDeviceTypes());
        }
        if ($this->getUtmSources()) {
            $query->whereIn('utm_source', $this->getUtmSources());
        }

        return $query;
    }

    /**
     * @return array{page_views: int, unique_sessions: int, unique_users: int, avg_session_events: float, page_views_prev: int, unique_sessions_prev: int, unique_users_prev: int}
     */
    public function getKpiData(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return [
                'page_views' => 0, 'unique_sessions' => 0, 'unique_users' => 0, 'avg_session_events' => 0.0,
                'page_views_prev' => 0, 'unique_sessions_prev' => 0, 'unique_users_prev' => 0,
            ];
        }

        $base = $this->buildBaseQuery();

        $pageViews = (clone $base)->where('event', 'page_viewed')->count();
        $uniqueSessions = (clone $base)->whereNotNull('session_id')->distinct()->count('session_id');
        $uniqueUsers = (clone $base)->whereNotNull('user_id')->distinct()->count('user_id');
        $totalEvents = (clone $base)->count();
        $avgSessionEvents = $uniqueSessions > 0 ? round($totalEvents / $uniqueSessions, 1) : 0.0;

        [$from, $to] = $this->resolvedDateRange();
        $days = max(1, (int) $from->diffInDays($to));
        $prevFrom = $from->copy()->subDays($days);
        $prevTo = $from->copy()->subSecond();

        $prevBase = DB::table('analytics_events')
            ->where('organization_id', $tenant->id)
            ->whereBetween('occurred_at', [$prevFrom, $prevTo]);
        if ($this->getDeviceTypes()) {
            $prevBase->whereIn('device_type', $this->getDeviceTypes());
        }
        if ($this->getUtmSources()) {
            $prevBase->whereIn('utm_source', $this->getUtmSources());
        }

        return [
            'page_views' => $pageViews,
            'unique_sessions' => $uniqueSessions,
            'unique_users' => $uniqueUsers,
            'avg_session_events' => $avgSessionEvents,
            'page_views_prev' => (clone $prevBase)->where('event', 'page_viewed')->count(),
            'unique_sessions_prev' => (clone $prevBase)->whereNotNull('session_id')->distinct()->count('session_id'),
            'unique_users_prev' => (clone $prevBase)->whereNotNull('user_id')->distinct()->count('user_id'),
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

        return $this->buildBaseQuery()
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
     * @return list<array{url: string, path: string, views: int, sessions: int, avg_time_seconds: int|null, avg_scroll_pct: int|null, bounce_rate: float|null}>
     */
    public function getTopPages(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return [];
        }

        [$from, $to] = $this->resolvedDateRange();
        $orgId = $tenant->id;
        $deviceTypes = $this->getDeviceTypes();
        $utmSources = $this->getUtmSources();

        $baseRows = $this->buildBaseQuery()
            ->where('event', 'page_viewed')
            ->whereNotNull('url')
            ->selectRaw('url, COUNT(*) as views, COUNT(DISTINCT session_id) as sessions')
            ->groupBy('url')
            ->orderByDesc('views')
            ->limit(10)
            ->get()
            ->keyBy('url');

        if ($baseRows->isEmpty()) {
            return [];
        }

        $urls = $baseRows->keys()->all();

        // Avg time per page (MySQL-only JSON extraction)
        $timeByUrl = collect();
        if (DB::getDriverName() === 'mysql') {
            $tq = DB::table('analytics_events')
                ->where('organization_id', $orgId)
                ->whereBetween('occurred_at', [$from, $to])
                ->where('event', 'page.time_spent')
                ->whereNotNull('properties')
                ->whereIn('url', $urls);
            if ($deviceTypes) {
                $tq->whereIn('device_type', $deviceTypes);
            }
            if ($utmSources) {
                $tq->whereIn('utm_source', $utmSources);
            }
            $timeByUrl = $tq
                ->selectRaw("url, AVG(CAST(properties->>'$.seconds' AS UNSIGNED)) as avg_seconds")
                ->groupBy('url')
                ->get()
                ->keyBy('url');
        }

        // Avg scroll pct per page (max scroll per session → average per URL)
        $scrollByUrl = DB::table(function ($sub) use ($orgId, $from, $to, $deviceTypes, $utmSources, $urls) {
            $sub->from('analytics_events')
                ->where('organization_id', $orgId)
                ->whereBetween('occurred_at', [$from, $to])
                ->whereIn('event', ['scroll_25', 'scroll_50', 'scroll_75', 'scroll_90', 'scroll_100'])
                ->whereIn('url', $urls)
                ->select('url', 'session_id')
                ->selectRaw("MAX(CASE WHEN event = 'scroll_100' THEN 100 WHEN event = 'scroll_90' THEN 90 WHEN event = 'scroll_75' THEN 75 WHEN event = 'scroll_50' THEN 50 WHEN event = 'scroll_25' THEN 25 ELSE 0 END) as max_scroll")
                ->groupBy('url', 'session_id');
            if ($deviceTypes) {
                $sub->whereIn('device_type', $deviceTypes);
            }
            if ($utmSources) {
                $sub->whereIn('utm_source', $utmSources);
            }
        }, 'sub')
            ->selectRaw('url, ROUND(AVG(max_scroll)) as avg_scroll_pct')
            ->groupBy('url')
            ->get()
            ->keyBy('url');

        // Bounce rate per page (sessions with only 1 event total that visited this URL)
        $sessionCountsSub = DB::table('analytics_events')
            ->where('organization_id', $orgId)
            ->whereBetween('occurred_at', [$from, $to])
            ->whereNotNull('session_id')
            ->selectRaw('session_id, COUNT(*) as event_count')
            ->groupBy('session_id');
        if ($deviceTypes) {
            $sessionCountsSub->whereIn('device_type', $deviceTypes);
        }
        if ($utmSources) {
            $sessionCountsSub->whereIn('utm_source', $utmSources);
        }

        $bounceQuery = DB::table('analytics_events as pv')
            ->joinSub($sessionCountsSub, 'sc', 'sc.session_id', '=', 'pv.session_id')
            ->where('pv.organization_id', $orgId)
            ->whereBetween('pv.occurred_at', [$from, $to])
            ->where('pv.event', 'page_viewed')
            ->whereNotNull('pv.session_id')
            ->whereIn('pv.url', $urls);
        if ($deviceTypes) {
            $bounceQuery->whereIn('pv.device_type', $deviceTypes);
        }
        if ($utmSources) {
            $bounceQuery->whereIn('pv.utm_source', $utmSources);
        }
        $bounceByUrl = $bounceQuery
            ->selectRaw('pv.url as url, COUNT(DISTINCT pv.session_id) as total_sessions, COUNT(DISTINCT CASE WHEN sc.event_count = 1 THEN pv.session_id END) as bounced_sessions')
            ->groupBy('pv.url')
            ->get()
            ->keyBy('url');

        return $baseRows->map(function ($row) use ($timeByUrl, $scrollByUrl, $bounceByUrl) {
            $url = (string) $row->url;
            $path = parse_url($url, PHP_URL_PATH) ?: $url;

            $timeRow = $timeByUrl->get($url);
            $avgTime = $timeRow ? (int) round((float) $timeRow->avg_seconds) : null;

            $scrollRow = $scrollByUrl->get($url);
            $avgScroll = $scrollRow ? (int) $scrollRow->avg_scroll_pct : null;

            $bounceRow = $bounceByUrl->get($url);
            $bounceRate = null;
            if ($bounceRow && (int) $bounceRow->total_sessions >= 3) {
                $bounceRate = round((int) $bounceRow->bounced_sessions / (int) $bounceRow->total_sessions * 100, 1);
            }

            return [
                'url' => $url,
                'path' => $path,
                'views' => (int) $row->views,
                'sessions' => (int) $row->sessions,
                'avg_time_seconds' => $avgTime,
                'avg_scroll_pct' => $avgScroll,
                'bounce_rate' => $bounceRate,
            ];
        })->values()->all();
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

        $depths = ['25', '50', '75', '90', '100'];

        $counts = $this->buildBaseQuery()
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

        [$from, $to] = $this->resolvedDateRange();

        $rows = $this->buildBaseQuery()
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
     * Does NOT apply device filter — shows full breakdown regardless of active device filter.
     *
     * @return list<array{device: string, count: int}>
     */
    public function getDeviceBreakdown(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return [];
        }

        [$from, $to] = $this->resolvedDateRange();

        $query = DB::table('analytics_events')
            ->where('organization_id', $tenant->id)
            ->whereBetween('occurred_at', [$from, $to]);

        if ($this->getUtmSources()) {
            $query->whereIn('utm_source', $this->getUtmSources());
        }

        return $query
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
     * @return array{steps: list<array{name: string, label: string, count: int, pct: float}>}
     */
    public function getFunnelData(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return ['steps' => []];
        }

        $steps = [
            ['name' => 'page_viewed',        'label' => 'Wyświetlenia'],
            ['name' => 'product_viewed',     'label' => 'Produkty'],
            ['name' => 'add_to_cart',        'label' => 'Do koszyka'],
            ['name' => 'cart_viewed',        'label' => 'Koszyk'],
            ['name' => 'form_field_focused', 'label' => 'Checkout'],
        ];

        $counts = $this->buildBaseQuery()
            ->whereIn('event', array_column($steps, 'name'))
            ->selectRaw('event, COUNT(DISTINCT session_id) as sessions')
            ->groupBy('event')
            ->get()
            ->pluck('sessions', 'event');

        $top = (int) ($counts['page_viewed'] ?? 0);
        $result = [];
        foreach ($steps as $step) {
            $count = (int) ($counts[$step['name']] ?? 0);
            $result[] = [
                'name' => $step['name'],
                'label' => $step['label'],
                'count' => $count,
                'pct' => $top > 0 ? round($count / $top * 100, 1) : 0.0,
            ];
        }

        return ['steps' => $result];
    }

    /**
     * @return array{total_abandoned: int, add_to_cart: int, cart_viewed: int, top_fields: list<array{field: string, count: int}>}
     */
    public function getCartAbandonmentData(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return ['total_abandoned' => 0, 'add_to_cart' => 0, 'cart_viewed' => 0, 'top_fields' => []];
        }

        $base = $this->buildBaseQuery();

        $totalAbandoned = (clone $base)->where('event', 'form_abandoned')->count();
        $addToCartCount = (clone $base)->where('event', 'add_to_cart')->count();
        $cartViewed = (clone $base)->where('event', 'cart_viewed')->count();

        $topFields = [];
        if (DB::getDriverName() === 'mysql') {
            $topFields = (clone $base)
                ->where('event', 'form_abandoned')
                ->whereNotNull('properties')
                ->selectRaw("properties->>'$.last_field' as field, COUNT(*) as cnt")
                ->groupBy('field')
                ->orderByDesc('cnt')
                ->limit(5)
                ->get()
                ->filter(fn ($r) => $r->field !== null)
                ->map(fn ($r) => ['field' => (string) $r->field, 'count' => (int) $r->cnt])
                ->values()
                ->all();
        }

        return [
            'total_abandoned' => $totalAbandoned,
            'add_to_cart' => $addToCartCount,
            'cart_viewed' => $cartViewed,
            'top_fields' => $topFields,
        ];
    }

    /**
     * @return list<array{source: string, sessions: int, pct: float}>
     */
    public function getTrafficSources(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return [];
        }

        $rows = $this->buildBaseQuery()
            ->where('event', 'page_viewed')
            ->selectRaw("COALESCE(utm_source, 'direct') as source, COUNT(DISTINCT session_id) as sessions")
            ->groupBy('source')
            ->orderByDesc('sessions')
            ->limit(8)
            ->get();

        $total = $rows->sum('sessions') ?: 1;

        return $rows->map(fn ($r) => [
            'source' => (string) $r->source,
            'sessions' => (int) $r->sessions,
            'pct' => round($r->sessions / $total * 100, 1),
        ])->all();
    }

    /**
     * @return array{bounce_rate: float, avg_events: float, rage_clicks: int, avg_time_on_page: float}
     */
    public function getSessionQuality(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return ['bounce_rate' => 0.0, 'avg_events' => 0.0, 'rage_clicks' => 0, 'avg_time_on_page' => 0.0];
        }

        $base = $this->buildBaseQuery();

        $sessionCounts = (clone $base)
            ->whereNotNull('session_id')
            ->selectRaw('session_id, COUNT(*) as event_count')
            ->groupBy('session_id')
            ->get();

        $totalSessions = $sessionCounts->count();
        $bouncedSessions = $sessionCounts->where('event_count', 1)->count();
        $bounceRate = $totalSessions > 0 ? round($bouncedSessions / $totalSessions * 100, 1) : 0.0;
        $avgEvents = $totalSessions > 0 ? round($sessionCounts->avg('event_count'), 1) : 0.0;

        $rageClicks = (clone $base)->where('event', 'rage_click')->count();

        $avgTime = 0.0;
        if (DB::getDriverName() === 'mysql') {
            $avgTime = (float) ((clone $base)
                ->where('event', 'page.time_spent')
                ->whereNotNull('properties')
                ->selectRaw("AVG(CAST(properties->>'$.seconds' AS UNSIGNED)) as avg_seconds")
                ->value('avg_seconds') ?? 0);
        }

        return [
            'bounce_rate' => $bounceRate,
            'avg_events' => $avgEvents,
            'rage_clicks' => $rageClicks,
            'avg_time_on_page' => round($avgTime, 1),
        ];
    }

    /**
     * @return array{label: string, value: string}[]
     */
    public function periodOptions(): array
    {
        return [
            ['value' => 'today',        'label' => 'Dziś'],
            ['value' => 'last_14_days', 'label' => 'Ostatnie 14 dni'],
            ['value' => 'this_month',   'label' => 'Ten miesiąc'],
            ['value' => 'last_month',   'label' => 'Poprzedni miesiąc'],
            ['value' => 'custom',       'label' => 'Własny zakres'],
        ];
    }

    /**
     * @return list<string>
     */
    public function validPeriods(): array
    {
        return ['today', 'last_14_days', 'this_month', 'last_month', 'custom'];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodToRange(string $period): array
    {
        return match ($period) {
            'today' => [Carbon::today(), Carbon::now()],
            'last_14_days' => [Carbon::now()->subDays(13)->startOfDay(), Carbon::now()],
            'last_month' => [
                Carbon::now()->subMonthNoOverflow()->startOfMonth(),
                Carbon::now()->subMonthNoOverflow()->endOfMonth(),
            ],
            default => [Carbon::now()->startOfMonth(), Carbon::now()],
        };
    }
}
