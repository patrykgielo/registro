<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Organization;
use App\Services\Statistics\StatisticsService;
use App\Support\TenantFeature;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard overview widget for the /admin tenant panel.
 *
 * Displays current-month KPIs sourced from statistics_daily_snapshots.
 * Hides appointment / rental cards when the corresponding module is disabled.
 */
class TenantStatsOverviewWidget extends BaseWidget
{
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }

    protected function getStats(): array
    {
        $tenant = TenantFeature::currentTenant();
        if (! $tenant instanceof Organization) {
            return [];
        }

        /** @var StatisticsService $service */
        $service = app(StatisticsService::class);

        [$from, $to] = $service->periodToRange('this_month');
        $data = $service->forTenant($tenant, $from, $to);

        $stats = [
            Stat::make('Przychód łączny', $this->formatMoney($data['total_revenue']))
                ->description('Bieżący miesiąc')
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('primary'),
        ];

        // Orders (always visible — every org has the order pipeline)
        $stats[] = Stat::make('Zamówienia', $data['orders']['count'])
            ->description($this->formatMoney($data['orders']['revenue']))
            ->descriptionIcon('heroicon-o-shopping-cart')
            ->color('success');

        // Appointments — only if bookings module is active
        if ($tenant->hasModule('bookings')) {
            $stats[] = Stat::make('Wizyty', $data['appointments']['count'])
                ->description($this->formatMoney($data['appointments']['revenue']))
                ->descriptionIcon('heroicon-o-calendar-days')
                ->color('info');
        }

        // Rentals — only if rentals module is active
        if ($tenant->hasModule('rentals')) {
            $stats[] = Stat::make('Wypożyczenia', $data['rentals']['count'])
                ->description($this->formatMoney($data['rentals']['revenue']))
                ->descriptionIcon('heroicon-o-cube')
                ->color('warning');
        }

        return $stats;
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, ',', ' ').' PLN';
    }
}
