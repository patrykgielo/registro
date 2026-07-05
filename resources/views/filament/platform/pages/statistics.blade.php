<x-filament-panels::page>
    @php
        [$from, $to] = $this->periodToRange($this->period);
        $counts        = $this->getTenantCounts();
        $mrr           = $this->getMrr();
        $newRegs       = $this->getNewRegistrations($from, $to);
        $chartData     = $this->getChartData($from, $to);
        $tenants       = $this->getTenantsTable();
        $expiring      = $this->getExpiringTrials();
    @endphp

    {{-- Period selector — native Filament tabs (same component/markup as Resource list tabs),
         so this page's primary control looks identical to every other tabbed view in the panel. --}}
    <x-filament::tabs label="Wybierz okres" class="mb-6">
        @foreach($this->periodOptions() as $option)
            <x-filament::tabs.item
                wire:click="$set('period', '{{ $option['value'] }}')"
                :active="$this->period === $option['value']"
            >
                {{ $option['label'] }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{-- KPI Cards — reuses the exact fi-wi-stats-overview-stat markup/classes that
         PlatformOverviewWidget renders on the Dashboard, so both pages present KPIs
         identically instead of two different "card" languages. --}}
    <div class="fi-wi-stats-overview grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4 mb-6">
        <div class="fi-wi-stats-overview-stat">
            <div class="fi-wi-stats-overview-stat-content">
                <div class="fi-wi-stats-overview-stat-label-ctn">
                    <x-heroicon-o-building-office-2 class="fi-icon h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500" />
                    <span class="fi-wi-stats-overview-stat-label">Tenanci łącznie</span>
                </div>
                <div class="fi-wi-stats-overview-stat-value">{{ number_format($counts['total']) }}</div>
                <div class="fi-wi-stats-overview-stat-description">
                    <span>Wstrzymani: {{ $counts['paused'] }} · Anulowani: {{ $counts['cancelled'] }}</span>
                </div>
            </div>
        </div>

        <div class="fi-wi-stats-overview-stat">
            <div class="fi-wi-stats-overview-stat-content">
                <div class="fi-wi-stats-overview-stat-label-ctn">
                    <x-heroicon-o-check-badge class="fi-icon h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500" />
                    <span class="fi-wi-stats-overview-stat-label">Aktywni</span>
                </div>
                <div class="fi-wi-stats-overview-stat-value">{{ number_format($counts['active']) }}</div>
                <div class="fi-wi-stats-overview-stat-description">
                    <span>Płacące subskrypcje</span>
                </div>
            </div>
        </div>

        <div class="fi-wi-stats-overview-stat">
            <div class="fi-wi-stats-overview-stat-content">
                <div class="fi-wi-stats-overview-stat-label-ctn">
                    <x-heroicon-o-clock class="fi-icon h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500" />
                    <span class="fi-wi-stats-overview-stat-label">Na trialu</span>
                </div>
                <div class="fi-wi-stats-overview-stat-value">{{ number_format($counts['trial']) }}</div>
                @if($counts['expiringTrials'] > 0)
                    <div class="fi-wi-stats-overview-stat-description font-medium text-amber-600 dark:text-amber-400">
                        <span>{{ $counts['expiringTrials'] }} wygasa w ciągu 7 dni</span>
                    </div>
                @else
                    <div class="fi-wi-stats-overview-stat-description">
                        <span>Brak wygasających wkrótce</span>
                    </div>
                @endif
            </div>
        </div>

        <div class="fi-wi-stats-overview-stat">
            <div class="fi-wi-stats-overview-stat-content">
                <div class="fi-wi-stats-overview-stat-label-ctn">
                    <x-heroicon-o-banknotes class="fi-icon h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500" />
                    <span class="fi-wi-stats-overview-stat-label">MRR</span>
                </div>
                <div class="fi-wi-stats-overview-stat-value">
                    {{ number_format($mrr, 2, ',', ' ') }}
                    <span class="text-base font-normal text-gray-500 dark:text-gray-400">PLN</span>
                </div>
                <div class="fi-wi-stats-overview-stat-description">
                    <span>Nowe rejestracje: {{ $newRegs }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- New Registrations Chart — wrapped in the native Section component so its chrome
         (border, radius, shadow, dark tokens) matches every Section elsewhere in /platform. --}}
    <x-filament::section heading="Nowe rejestracje — wybrany okres" class="mb-6">
        <div
            wire:ignore
            class="h-72"
            x-data="revenueChart(@js($chartData['series']), @js($chartData['categories']), 'count')"
            x-on:chart-refresh.window="refreshChart($event.detail)"
        ></div>
    </x-filament::section>

    {{-- Expiring Trials (shown only when there are some) --}}
    @if($expiring->isNotEmpty())
        <x-filament::section
            heading="Wygasające triale (14 dni)"
            icon="heroicon-o-exclamation-triangle"
            icon-color="warning"
            class="mb-6"
        >
            <div class="-m-6 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Organizacja</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Właściciel</th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Trial wygasa</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach($expiring as $org)
                            <tr class="hover:bg-amber-50/40 dark:hover:bg-amber-400/5">
                                <td class="px-6 py-3.5">
                                    <div class="font-medium text-gray-950 dark:text-white">{{ $org->name }}</div>
                                    <div class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">{{ $org->slug }}</div>
                                </td>
                                <td class="px-6 py-3.5 text-gray-600 dark:text-gray-400">
                                    {{ $org->owner?->name ?? '—' }}
                                </td>
                                <td class="px-6 py-3.5 text-right tabular-nums">
                                    <span class="font-semibold text-amber-600 dark:text-amber-400">
                                        {{ $org->trial_ends_at?->format('d.m.Y') ?? '—' }}
                                    </span>
                                    @if($org->trial_ends_at)
                                        <div class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                                            za {{ (int) $org->trial_ends_at->diffInDays(now()) }} dni
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    {{-- All Tenants Table --}}
    <x-filament::section heading="Wszyscy tenanci">
        @if($tenants->isNotEmpty())
            <div class="-m-6 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Organizacja</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Miesięczna opłata</th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Aktywny od</th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Trial do</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach($tenants as $tenant)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="px-6 py-3.5">
                                    <div class="font-medium text-gray-950 dark:text-white">{{ $tenant->name }}</div>
                                    <div class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                                        {{ $tenant->owner?->name ?? '—' }} · {{ match($tenant->booking_type) {
                                            'time_slot' => 'Rezerwacje',
                                            'item_rental' => 'Wypożyczenia',
                                            'both' => 'Oba',
                                            default => $tenant->booking_type,
                                        } }}
                                    </div>
                                </td>
                                <td class="px-6 py-3.5">
                                    @php
                                        $statusColor = match($tenant->subscription_status) {
                                            'active' => 'success',
                                            'trial' => 'info',
                                            'paused' => 'warning',
                                            'cancelled' => 'danger',
                                            default => 'gray',
                                        };
                                        $statusLabel = match($tenant->subscription_status) {
                                            'active' => 'Aktywny',
                                            'trial' => 'Trial',
                                            'paused' => 'Wstrzymany',
                                            'cancelled' => 'Anulowany',
                                            default => $tenant->subscription_status,
                                        };
                                    @endphp
                                    <x-filament::badge :color="$statusColor">
                                        {{ $statusLabel }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-6 py-3.5 text-right tabular-nums text-gray-600 dark:text-gray-400">
                                    @if($tenant->monthly_fee !== null)
                                        {{ number_format((float) $tenant->monthly_fee, 2, ',', ' ') }} PLN
                                    @else
                                        <span class="text-xs italic text-gray-400 dark:text-gray-500">nie ustawiono</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 text-right tabular-nums text-gray-600 dark:text-gray-400">
                                    {{ $tenant->subscribed_at?->format('d.m.Y') ?? '—' }}
                                </td>
                                <td class="px-6 py-3.5 text-right tabular-nums text-gray-600 dark:text-gray-400">
                                    {{ $tenant->trial_ends_at?->format('d.m.Y') ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <x-filament::empty-state
                icon="heroicon-o-building-office-2"
                heading="Brak tenantów w systemie."
            />
        @endif
    </x-filament::section>
</x-filament-panels::page>
