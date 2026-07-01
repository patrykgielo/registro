<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Models\Organization;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * Platform-level statistics page — /platform/statystyki
 *
 * SaaS billing overview: tenant counts, MRR, subscription status breakdown,
 * new registration chart, expiring trials, and full tenant table.
 *
 * Does NOT depend on StatisticsService or statistics_daily_snapshots.
 */
class Statistics extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Statystyki';

    protected static ?string $title = 'Statystyki';

    protected static ?string $slug = 'statystyki';

    protected string $view = 'filament.platform.pages.statistics';

    #[Url]
    public string $period = 'this_month';

    public function mount(): void
    {
        if (! in_array($this->period, $this->validPeriods(), true)) {
            $this->period = 'this_month';
        }
    }

    public function getTitle(): string
    {
        return 'Statystyki platformy';
    }

    public static function getNavigationLabel(): string
    {
        return 'Statystyki';
    }

    public function updatedPeriod(): void
    {
        if (! in_array($this->period, $this->validPeriods(), true)) {
            $this->period = 'this_month';
        }

        [$from, $to] = $this->periodToRange($this->period);
        $chartData = $this->getChartData($from, $to);
        $this->dispatch('chart-refresh', series: $chartData['series'], categories: $chartData['categories']);
    }

    /**
     * KPI: tenant count breakdown by subscription_status.
     *
     * @return array{total: int, active: int, trial: int, paused: int, cancelled: int, expiringTrials: int}
     */
    public function getTenantCounts(): array
    {
        $total = Organization::count();
        $active = Organization::where('subscription_status', 'active')->count();
        $trial = Organization::where('subscription_status', 'trial')->count();
        $paused = Organization::where('subscription_status', 'paused')->count();
        $cancelled = Organization::where('subscription_status', 'cancelled')->count();

        $expiringTrials = Organization::where('subscription_status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now()->addDays(7))
            ->where('trial_ends_at', '>=', now())
            ->count();

        return compact('total', 'active', 'trial', 'paused', 'cancelled', 'expiringTrials');
    }

    /**
     * KPI: Monthly Recurring Revenue — sum of monthly_fee for active subscriptions.
     */
    public function getMrr(): float
    {
        return (float) Organization::where('subscription_status', 'active')
            ->whereNotNull('monthly_fee')
            ->sum('monthly_fee');
    }

    /**
     * KPI: new organizations registered within [from, to].
     */
    public function getNewRegistrations(Carbon $from, Carbon $to): int
    {
        return Organization::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count();
    }

    /**
     * Chart: new registrations per day in the selected period (single series).
     *
     * @return array{series: list<array{name: string, data: list<int>}>, categories: list<string>}
     */
    public function getChartData(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('organizations')
            ->selectRaw('DATE(created_at) as day, COUNT(*) as cnt')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $series = [];
        $categories = [];
        $current = $from->copy()->startOfDay();

        while ($current->lte($to->copy()->endOfDay())) {
            $key = $current->toDateString();
            $categories[] = $current->format('d.m');
            $series[] = (int) ($rows[$key]->cnt ?? 0);
            $current->addDay();
        }

        return [
            'series' => [['name' => 'Nowi tenanci', 'data' => $series]],
            'categories' => $categories,
        ];
    }

    /**
     * Table: all tenants ordered by status then creation date.
     *
     * @return Collection<int, Organization>
     */
    public function getTenantsTable(): Collection
    {
        return Organization::with('owner')
            ->orderByRaw("FIELD(subscription_status, 'active', 'trial', 'paused', 'cancelled')")
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Tenants whose trial expires within 14 days.
     *
     * @return Collection<int, Organization>
     */
    public function getExpiringTrials(): Collection
    {
        return Organization::with('owner')
            ->where('subscription_status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>=', now())
            ->where('trial_ends_at', '<=', now()->addDays(14))
            ->orderBy('trial_ends_at')
            ->get();
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
     * Convert a period key to a [Carbon $from, Carbon $to] tuple.
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
            'last_month' => [Carbon::now()->subMonth()->startOfMonth(), Carbon::now()->subMonth()->endOfMonth()],
            'last_year' => [Carbon::now()->subYear()->startOfYear(), Carbon::now()->subYear()->endOfYear()],
            default => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
        };
    }
}
