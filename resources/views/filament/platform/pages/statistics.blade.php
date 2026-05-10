<x-filament-panels::page>
    @php
        $aggregateData = $this->getAggregateData();
        $perTenantData = $this->getPerTenantData();
        $chartData     = $this->getChartData();
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

    {{-- Aggregate KPI Cards --}}
    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4 mb-6">

        {{-- Total Revenue --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 border-t-4 border-t-primary-500 dark:border-t-primary-400">
            <div class="flex items-center gap-3 mb-3">
                <div class="p-2 rounded-xl bg-primary-50 dark:bg-primary-900/30">
                    <x-heroicon-o-banknotes class="w-5 h-5 flex-shrink-0 text-primary-600 dark:text-primary-400" width="20" height="20" />
                </div>
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest">Przychód łączny</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                {{ number_format($aggregateData['total_revenue'], 2, ',', ' ') }}
                <span class="text-base font-normal text-gray-500 dark:text-gray-400 ml-1">PLN</span>
            </div>
        </div>

        {{-- Orders --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 border-t-4 border-t-cyan-500 dark:border-t-cyan-400">
            <div class="flex items-center gap-3 mb-3">
                <div class="p-2 rounded-xl bg-cyan-50 dark:bg-cyan-900/30">
                    <x-heroicon-o-shopping-bag class="w-5 h-5 flex-shrink-0 text-cyan-600 dark:text-cyan-400" width="20" height="20" />
                </div>
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest">Zamówienia</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                {{ number_format($aggregateData['orders']['count']) }}
            </div>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1 tabular-nums">
                {{ number_format($aggregateData['orders']['revenue'], 2, ',', ' ') }} PLN
            </div>
        </div>

        {{-- Appointments --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 border-t-4 border-t-green-500 dark:border-t-green-400">
            <div class="flex items-center gap-3 mb-3">
                <div class="p-2 rounded-xl bg-green-50 dark:bg-green-900/30">
                    <x-heroicon-o-calendar-days class="w-5 h-5 flex-shrink-0 text-green-600 dark:text-green-400" width="20" height="20" />
                </div>
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest">Wizyty</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                {{ number_format($aggregateData['appointments']['count']) }}
            </div>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1 tabular-nums">
                {{ number_format($aggregateData['appointments']['revenue'], 2, ',', ' ') }} PLN
            </div>
        </div>

        {{-- Rentals --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 border-t-4 border-t-amber-500 dark:border-t-amber-400">
            <div class="flex items-center gap-3 mb-3">
                <div class="p-2 rounded-xl bg-amber-50 dark:bg-amber-900/30">
                    <x-heroicon-o-wrench-screwdriver class="w-5 h-5 flex-shrink-0 text-amber-600 dark:text-amber-400" width="20" height="20" />
                </div>
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest">Wypożyczenia</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                {{ number_format($aggregateData['rentals']['count']) }}
            </div>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1 tabular-nums">
                {{ number_format($aggregateData['rentals']['revenue'], 2, ',', ' ') }} PLN
            </div>
        </div>

    </div>

    {{-- Platform Revenue Chart --}}
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 mb-6">
        <div class="flex items-center justify-between mb-1">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Przychody platformy — wybrany okres</h3>
        </div>
        <div
            wire:ignore
            class="h-72"
            x-data="revenueChart(@js($chartData['series']), @js($chartData['categories']))"
            x-on:chart-refresh.window="refreshChart($event.detail)"
        ></div>
    </div>

    {{-- Per-tenant breakdown --}}
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Zestawienie wg organizacji</h3>
        </div>

        @if(count($perTenantData) > 0)
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-700/40">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Organizacja</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Zamówienia</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Wizyty</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Wypożyczenia</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Przychód łączny</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                    @foreach($perTenantData as $row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors duration-100">
                        <td class="px-5 py-3.5">
                            <div class="font-medium text-gray-900 dark:text-white">{{ $row['organization']->name }}</div>
                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $row['organization']->booking_type }}</div>
                        </td>
                        <td class="px-5 py-3.5 text-right text-gray-600 dark:text-gray-400 tabular-nums">
                            {{ number_format($row['orders']['count']) }}
                            <div class="text-xs text-gray-400 dark:text-gray-500">{{ number_format($row['orders']['revenue'], 2, ',', ' ') }} PLN</div>
                        </td>
                        <td class="px-5 py-3.5 text-right text-gray-600 dark:text-gray-400 tabular-nums">
                            {{ number_format($row['appointments']['count']) }}
                            <div class="text-xs text-gray-400 dark:text-gray-500">{{ number_format($row['appointments']['revenue'], 2, ',', ' ') }} PLN</div>
                        </td>
                        <td class="px-5 py-3.5 text-right text-gray-600 dark:text-gray-400 tabular-nums">
                            {{ number_format($row['rentals']['count']) }}
                            <div class="text-xs text-gray-400 dark:text-gray-500">{{ number_format($row['rentals']['revenue'], 2, ',', ' ') }} PLN</div>
                        </td>
                        <td class="px-5 py-3.5 text-right font-semibold text-gray-900 dark:text-white tabular-nums">
                            {{ number_format($row['total_revenue'], 2, ',', ' ') }} PLN
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div class="py-12 text-center">
            <x-heroicon-o-chart-bar class="w-10 h-10 text-gray-300 dark:text-gray-600 mx-auto mb-3" />
            <p class="text-sm text-gray-500 dark:text-gray-400">Brak danych statystycznych w wybranym okresie.</p>
        </div>
        @endif
    </div>
</x-filament-panels::page>
