<x-filament-panels::page>
    @php
        $kpi          = $this->getKpiData();
        $chartData    = $this->getChartData();
        $topPages     = $this->getTopPages();
        $pageTypes    = $this->getPageTypeDistribution();
        $scrollDepth  = $this->getScrollDepth();
        $devices      = $this->getDeviceBreakdown();
        $maxPageViews = collect($pageTypes)->max('views') ?: 1;
        $maxScroll    = max($scrollDepth['25'] ?: 1, 1);
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

        {{-- Page Views --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 border-t-4 border-t-primary-500 dark:border-t-primary-400">
            <div class="flex items-center gap-3 mb-3">
                <div class="p-2 rounded-xl bg-primary-50 dark:bg-primary-900/30">
                    <x-heroicon-o-eye class="w-5 h-5 flex-shrink-0 text-primary-600 dark:text-primary-400" width="20" height="20" />
                </div>
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest">Odsłony</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                {{ number_format($kpi['page_views']) }}
            </div>
        </div>

        {{-- Unique Sessions --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 border-t-4 border-t-cyan-500 dark:border-t-cyan-400">
            <div class="flex items-center gap-3 mb-3">
                <div class="p-2 rounded-xl bg-cyan-50 dark:bg-cyan-900/30">
                    <x-heroicon-o-user-group class="w-5 h-5 flex-shrink-0 text-cyan-600 dark:text-cyan-400" width="20" height="20" />
                </div>
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest">Sesje</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                {{ number_format($kpi['unique_sessions']) }}
            </div>
        </div>

        {{-- Logged-in Users --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 border-t-4 border-t-green-500 dark:border-t-green-400">
            <div class="flex items-center gap-3 mb-3">
                <div class="p-2 rounded-xl bg-green-50 dark:bg-green-900/30">
                    <x-heroicon-o-identification class="w-5 h-5 flex-shrink-0 text-green-600 dark:text-green-400" width="20" height="20" />
                </div>
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest">Zalogowani</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                {{ number_format($kpi['unique_users']) }}
            </div>
        </div>

        {{-- Avg events per session --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 border-t-4 border-t-amber-500 dark:border-t-amber-400">
            <div class="flex items-center gap-3 mb-3">
                <div class="p-2 rounded-xl bg-amber-50 dark:bg-amber-900/30">
                    <x-heroicon-o-cursor-arrow-rays class="w-5 h-5 flex-shrink-0 text-amber-600 dark:text-amber-400" width="20" height="20" />
                </div>
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest">Zdarzenia/sesja</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                {{ number_format($kpi['avg_session_events'], 1, ',', ' ') }}
            </div>
        </div>

    </div>

    {{-- Page Views Chart --}}
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 mb-6">
        <div class="flex items-center justify-between mb-1">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Odsłony w czasie</h3>
        </div>
        <div
            wire:ignore
            class="h-72"
            x-data="revenueChart(@js($chartData['series']), @js($chartData['categories']), 'count')"
            x-on:analytics-chart-refresh.window="refreshChart($event.detail)"
        ></div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 mb-6">

        {{-- Page Type Distribution --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Typy stron</h3>
            </div>
            @if(count($pageTypes) > 0)
            <div class="p-5 space-y-3">
                @foreach($pageTypes as $pt)
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm text-gray-700 dark:text-gray-300 font-medium">{{ $pt['page_type'] }}</span>
                        <span class="text-sm text-gray-500 dark:text-gray-400 tabular-nums">{{ number_format($pt['views']) }}</span>
                    </div>
                    <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-2">
                        <div
                            class="bg-primary-500 h-2 rounded-full transition-all duration-300"
                            style="width: {{ $maxPageViews > 0 ? round($pt['views'] / $maxPageViews * 100) : 0 }}%"
                        ></div>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="py-10 text-center">
                <x-heroicon-o-chart-bar class="w-10 h-10 flex-shrink-0 text-gray-300 dark:text-gray-600 mx-auto mb-3" width="40" height="40" />
                <p class="text-sm text-gray-500 dark:text-gray-400">Brak danych w wybranym okresie.</p>
            </div>
            @endif
        </div>

        {{-- Devices --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Urządzenia</h3>
            </div>
            @php
                $deviceIcons = ['desktop' => 'heroicon-o-computer-desktop', 'mobile' => 'heroicon-o-device-phone-mobile', 'tablet' => 'heroicon-o-device-tablet'];
                $deviceLabels = ['desktop' => 'Komputer', 'mobile' => 'Telefon', 'tablet' => 'Tablet'];
                $totalDevices = collect($devices)->sum('count') ?: 1;
            @endphp
            @if(count($devices) > 0)
            <div class="p-5">
                <div class="grid grid-cols-3 gap-4 text-center">
                    @foreach(['desktop', 'mobile', 'tablet'] as $dt)
                        @php
                            $entry = collect($devices)->firstWhere('device', $dt);
                            $cnt = $entry ? $entry['count'] : 0;
                            $pct = $totalDevices > 0 ? round($cnt / $totalDevices * 100) : 0;
                        @endphp
                    <div class="flex flex-col items-center gap-2 p-3 rounded-xl bg-gray-50 dark:bg-gray-700/40">
                        <x-dynamic-component :component="$deviceIcons[$dt]" class="w-8 h-8 flex-shrink-0 text-gray-400 dark:text-gray-500" width="32" height="32" />
                        <div class="text-xl font-bold text-gray-900 dark:text-white tabular-nums">{{ $pct }}%</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $deviceLabels[$dt] }}</div>
                        <div class="text-xs text-gray-400 dark:text-gray-500 tabular-nums">{{ number_format($cnt) }}</div>
                    </div>
                    @endforeach
                </div>
            </div>
            @else
            <div class="py-10 text-center">
                <x-heroicon-o-device-phone-mobile class="w-10 h-10 flex-shrink-0 text-gray-300 dark:text-gray-600 mx-auto mb-3" width="40" height="40" />
                <p class="text-sm text-gray-500 dark:text-gray-400">Brak danych w wybranym okresie.</p>
            </div>
            @endif
        </div>

    </div>

    {{-- Scroll Depth --}}
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 mb-6">
        <div class="mb-4">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Głębokość scrollowania</h3>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Ile sesji dotarło do danego progu strony</p>
        </div>
        <div class="flex items-end gap-3 h-32">
            @foreach(['25', '50', '75', '90', '100'] as $depth)
                @php $count = $scrollDepth[$depth]; $barH = $maxScroll > 0 ? max(4, round($count / $maxScroll * 100)) : 4; @endphp
            <div class="flex-1 flex flex-col items-center gap-1">
                <span class="text-xs font-semibold text-gray-700 dark:text-gray-300 tabular-nums">{{ number_format($count) }}</span>
                <div class="w-full rounded-t-lg bg-primary-500 dark:bg-primary-400 transition-all duration-300" style="height: {{ $barH }}%"></div>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $depth }}%</span>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Top Pages --}}
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Najpopularniejsze strony (top 10)</h3>
        </div>
        @if(count($topPages) > 0)
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-700/40">
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider w-10">#</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">URL</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider w-24">Odsłony</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider w-24">Sesje</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                @foreach($topPages as $index => $page)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors duration-100">
                    <td class="px-5 py-3.5 text-gray-400 dark:text-gray-500 font-mono text-xs">{{ $index + 1 }}</td>
                    <td class="px-5 py-3.5 text-gray-900 dark:text-white font-medium max-w-xs truncate" title="{{ $page['url'] }}">{{ $page['url'] }}</td>
                    <td class="px-5 py-3.5 text-right text-gray-600 dark:text-gray-400 tabular-nums font-semibold">{{ number_format($page['views']) }}</td>
                    <td class="px-5 py-3.5 text-right text-gray-500 dark:text-gray-400 tabular-nums">{{ number_format($page['sessions']) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="py-12 text-center">
            <x-heroicon-o-globe-alt class="w-10 h-10 flex-shrink-0 text-gray-300 dark:text-gray-600 mx-auto mb-3" width="40" height="40" />
            <p class="text-sm text-gray-500 dark:text-gray-400">Brak danych o stronach w wybranym okresie.</p>
        </div>
        @endif
    </div>
</x-filament-panels::page>
