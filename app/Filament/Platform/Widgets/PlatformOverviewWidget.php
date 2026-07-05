<?php

declare(strict_types=1);

namespace App\Filament\Platform\Widgets;

use App\Enums\OrganizationLifecycleState;
use App\Models\Organization;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * KPI overview for the /platform dashboard (super-admin).
 *
 * Reads lifecycle_state / closure_requested_at / trial_ends_at directly off
 * Organization — same fields OrganizationResource's table already filters on.
 * Organization carries no tenant global scope, so no withoutGlobalScope() needed here.
 */
class PlatformOverviewWidget extends BaseWidget
{
    protected ?string $pollingInterval = null;

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    protected function getStats(): array
    {
        $total = Organization::count();
        $active = Organization::where('lifecycle_state', OrganizationLifecycleState::Active)->count();
        $suspendedOrClosing = Organization::whereIn('lifecycle_state', [
            OrganizationLifecycleState::Suspended,
            OrganizationLifecycleState::Closing,
        ])->count();
        $openClosureRequests = Organization::whereNotNull('closure_requested_at')->count();
        $expiringTrials = Organization::whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>=', now())
            ->where('trial_ends_at', '<=', now()->addDays(14))
            ->count();

        return [
            Stat::make('Organizacje', (string) $total)
                ->description('Wszystkie zarejestrowane tenanty')
                ->descriptionIcon('heroicon-o-building-office-2')
                ->color('gray'),

            Stat::make('Aktywne', (string) $active)
                ->description('W stanie Aktywna')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Zawieszone / w zamykaniu', (string) $suspendedOrClosing)
                ->description('Wymagają uwagi')
                ->descriptionIcon('heroicon-o-pause-circle')
                ->color($suspendedOrClosing > 0 ? 'warning' : 'gray'),

            Stat::make('Wnioski o zamknięcie', (string) $openClosureRequests)
                ->description('Oczekują na decyzję')
                ->descriptionIcon('heroicon-o-x-circle')
                ->color($openClosureRequests > 0 ? 'danger' : 'gray'),

            Stat::make('Wygasające triale', (string) $expiringTrials)
                ->description('W ciągu 14 dni')
                ->descriptionIcon('heroicon-o-clock')
                ->color($expiringTrials > 0 ? 'warning' : 'gray'),
        ];
    }
}
