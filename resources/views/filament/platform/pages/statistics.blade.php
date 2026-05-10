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

    {{-- Period selector --}}
    <div class="flex flex-wrap gap-2 mb-6" role="group" aria-label="Wybierz okres">
        @foreach($this->periodOptions() as $option)
            <button
                type="button"
                wire:click="$set('period', '{{ $option['value'] }}')"
                @class([
                    'inline-flex items-center px-4 py-2 rounded-full text-sm font-medium transition-all duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 cursor-pointer',
                    'bg-primary-600 text-white shadow-sm' => $this->period === $option['value'],
                    'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 hover:border-primary-300 dark:hover:border-primary-700 hover:text-primary-700 dark:hover:text-primary-400' => $this->period !== $option['value'],
                ])
                aria-pressed="{{ $this->period === $option['value'] ? 'true' : 'false' }}"
            >
                {{ $option['label'] }}
            </button>
        @endforeach
    </div>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4 mb-6">

        {{-- Total tenants --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 border-t-4 border-t-primary-500 dark:border-t-primary-400">
            <div class="flex items-center gap-3 mb-3">
                <div class="p-2 rounded-xl bg-primary-50 dark:bg-primary-900/30">
                    <x-heroicon-o-building-office-2 class="w-5 h-5 flex-shrink-0 text-primary-600 dark:text-primary-400" width="20" height="20" />
                </div>
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest">Tenanci łącznie</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                {{ number_format($counts['total']) }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Wstrzymani: {{ $counts['paused'] }} · Anulowani: {{ $counts['cancelled'] }}
            </div>
        </div>

        {{-- Active subscriptions --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 border-t-4 border-t-green-500 dark:border-t-green-400">
            <div class="flex items-center gap-3 mb-3">
                <div class="p-2 rounded-xl bg-green-50 dark:bg-green-900/30">
                    <x-heroicon-o-check-badge class="w-5 h-5 flex-shrink-0 text-green-600 dark:text-green-400" width="20" height="20" />
                </div>
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest">Aktywni</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                {{ number_format($counts['active']) }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">płacące subskrypcje</div>
        </div>

        {{-- Trial tenants --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 border-t-4 border-t-cyan-500 dark:border-t-cyan-400">
            <div class="flex items-center gap-3 mb-3">
                <div class="p-2 rounded-xl bg-cyan-50 dark:bg-cyan-900/30">
                    <x-heroicon-o-clock class="w-5 h-5 flex-shrink-0 text-cyan-600 dark:text-cyan-400" width="20" height="20" />
                </div>
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest">Na trialu</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                {{ number_format($counts['trial']) }}
            </div>
            @if($counts['expiringTrials'] > 0)
            <div class="text-xs text-amber-600 dark:text-amber-400 mt-1 font-medium">
                {{ $counts['expiringTrials'] }} wygasa w ciągu 7 dni
            </div>
            @else
            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">brak wygasających wkrótce</div>
            @endif
        </div>

        {{-- MRR --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 border-t-4 border-t-amber-500 dark:border-t-amber-400">
            <div class="flex items-center gap-3 mb-3">
                <div class="p-2 rounded-xl bg-amber-50 dark:bg-amber-900/30">
                    <x-heroicon-o-banknotes class="w-5 h-5 flex-shrink-0 text-amber-600 dark:text-amber-400" width="20" height="20" />
                </div>
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest">MRR</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                {{ number_format($mrr, 2, ',', ' ') }}
                <span class="text-base font-normal text-gray-500 dark:text-gray-400 ml-1">PLN</span>
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Nowe rejestracje: {{ $newRegs }}
            </div>
        </div>

    </div>

    {{-- New Registrations Chart --}}
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 mb-6">
        <div class="flex items-center justify-between mb-1">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Nowe rejestracje — wybrany okres</h3>
        </div>
        <div
            wire:ignore
            class="h-72"
            x-data="revenueChart(@js($chartData['series']), @js($chartData['categories']), 'count')"
            x-on:chart-refresh.window="refreshChart($event.detail)"
        ></div>
    </div>

    {{-- Expiring Trials (shown only when there are some) --}}
    @if($expiring->isNotEmpty())
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-amber-200 dark:border-amber-800 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-amber-100 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 flex items-center gap-2">
            <x-heroicon-o-exclamation-triangle class="w-4 h-4 flex-shrink-0 text-amber-600 dark:text-amber-400" width="16" height="16" />
            <h3 class="text-sm font-semibold text-amber-700 dark:text-amber-400">Wygasające triale (14 dni)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-700/40">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Organizacja</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Właściciel</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Trial wygasa</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                    @foreach($expiring as $org)
                    <tr class="hover:bg-amber-50/40 dark:hover:bg-amber-900/10 transition-colors duration-100">
                        <td class="px-5 py-3.5">
                            <div class="font-medium text-gray-900 dark:text-white">{{ $org->name }}</div>
                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $org->slug }}</div>
                        </td>
                        <td class="px-5 py-3.5 text-gray-600 dark:text-gray-400">
                            {{ $org->owner?->name ?? '—' }}
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums">
                            <span class="font-semibold text-amber-600 dark:text-amber-400">
                                {{ $org->trial_ends_at?->format('d.m.Y') ?? '—' }}
                            </span>
                            @if($org->trial_ends_at)
                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                                za {{ (int) $org->trial_ends_at->diffInDays(now()) }} dni
                            </div>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- All Tenants Table --}}
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Wszyscy tenanci</h3>
        </div>

        @if($tenants->isNotEmpty())
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-700/40">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Organizacja</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Miesięczna opłata</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aktywny od</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Trial do</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                    @foreach($tenants as $tenant)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors duration-100">
                        <td class="px-5 py-3.5">
                            <div class="font-medium text-gray-900 dark:text-white">{{ $tenant->name }}</div>
                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                                {{ $tenant->owner?->name ?? '—' }} · {{ $tenant->booking_type }}
                            </div>
                        </td>
                        <td class="px-5 py-3.5">
                            @php
                                $statusConfig = match($tenant->subscription_status) {
                                    'active'    => ['bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400', 'Aktywny'],
                                    'trial'     => ['bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-400', 'Trial'],
                                    'paused'    => ['bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400', 'Wstrzymany'],
                                    'cancelled' => ['bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400', 'Anulowany'],
                                    default     => ['bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-400', $tenant->subscription_status],
                                };
                            @endphp
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $statusConfig[0] }}">
                                {{ $statusConfig[1] }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-right text-gray-600 dark:text-gray-400 tabular-nums">
                            @if($tenant->monthly_fee !== null)
                                {{ number_format((float) $tenant->monthly_fee, 2, ',', ' ') }} PLN
                            @else
                                <span class="text-gray-400 dark:text-gray-500 italic text-xs">nie ustawiono</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right text-gray-600 dark:text-gray-400 tabular-nums">
                            {{ $tenant->subscribed_at?->format('d.m.Y') ?? '—' }}
                        </td>
                        <td class="px-5 py-3.5 text-right text-gray-600 dark:text-gray-400 tabular-nums">
                            {{ $tenant->trial_ends_at?->format('d.m.Y') ?? '—' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div class="py-12 text-center">
            <x-heroicon-o-building-office-2 class="w-10 h-10 flex-shrink-0 text-gray-300 dark:text-gray-600 mx-auto mb-3" width="40" height="40" />
            <p class="text-sm text-gray-500 dark:text-gray-400">Brak tenantów w systemie.</p>
        </div>
        @endif
    </div>
</x-filament-panels::page>
