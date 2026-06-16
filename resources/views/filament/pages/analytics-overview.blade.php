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

    {{-- Filter Bar --}}
    <div class="mb-6 space-y-3">

        {{-- Row 1: Period buttons + Reset --}}
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Wybierz okres">
            @foreach($this->periodOptions() as $option)
                <button
                    type="button"
                    wire:click="$set('period', '{{ $option['value'] }}')"
                    @class([
                        'inline-flex items-center px-4 py-2 rounded-full text-sm font-medium transition-all duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 cursor-pointer',
                        'bg-primary-600 text-white shadow-sm' => $this->period === $option['value'],
                        'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 hover:border-primary-300 hover:text-primary-700' => $this->period !== $option['value'],
                    ])
                    aria-pressed="{{ $this->period === $option['value'] ? 'true' : 'false' }}"
                >
                    {{ $option['label'] }}
                </button>
            @endforeach

            @if($this->hasActiveFilters())
            <button
                type="button"
                wire:click="resetFilters"
                class="ml-auto inline-flex items-center gap-1.5 px-3 py-2 text-sm text-gray-500 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition-colors duration-150 cursor-pointer"
            >
                <x-heroicon-o-x-circle class="w-4 h-4 flex-shrink-0" width="16" height="16" />
                Resetuj filtry
            </button>
            @endif
        </div>

        {{-- Custom date range (shows only when 'custom' period selected) --}}
        @if($this->period === 'custom')
        <div class="flex flex-wrap items-center gap-3">
            <label class="text-sm text-gray-500 dark:text-gray-400 font-medium">Od:</label>
            <input
                type="date"
                wire:model.live="dateFrom"
                class="text-sm border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-1.5 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500"
            />
            <label class="text-sm text-gray-500 dark:text-gray-400 font-medium">Do:</label>
            <input
                type="date"
                wire:model.live="dateTo"
                class="text-sm border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-1.5 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500"
            />
        </div>
        @endif

        {{-- Row 2: Device filter --}}
        @php
            $activeDevices = $this->getDeviceTypes();
        @endphp
        <div class="flex flex-wrap items-center gap-3" role="group" aria-label="Filtruj po urządzeniu">
            <div class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                <x-heroicon-o-device-phone-mobile class="w-4 h-4 flex-shrink-0" width="16" height="16" />
                <span class="font-medium">Urządzenie:</span>
            </div>
            @foreach(['desktop' => 'Komputer', 'mobile' => 'Telefon', 'tablet' => 'Tablet'] as $deviceKey => $deviceLabel)
                @php
                    $isDeviceActive = in_array($deviceKey, $activeDevices, true);
                    $newDeviceValue = $isDeviceActive
                        ? implode(',', array_values(array_filter($activeDevices, fn($d) => $d !== $deviceKey)))
                        : implode(',', [...$activeDevices, $deviceKey]);
                @endphp
                <button
                    type="button"
                    wire:click="$set('deviceParam', @js($newDeviceValue))"
                    @class([
                        'inline-flex items-center px-3 py-1.5 rounded-full text-xs font-medium transition-all duration-150 border cursor-pointer',
                        'bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300 border-primary-300 dark:border-primary-700' => $isDeviceActive,
                        'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-400 border-gray-200 dark:border-gray-700 hover:border-primary-300' => !$isDeviceActive,
                    ])
                    aria-pressed="{{ $isDeviceActive ? 'true' : 'false' }}"
                >
                    {{ $deviceLabel }}
                </button>
            @endforeach
        </div>

        {{-- Active filter chips --}}
        @if($this->hasActiveFilters())
        <div class="flex flex-wrap gap-2" aria-label="Aktywne filtry">
            @if($this->dateFrom || $this->dateTo)
            <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 text-xs rounded-full border border-amber-200 dark:border-amber-800">
                <x-heroicon-o-calendar class="w-3 h-3 flex-shrink-0" width="12" height="12" />
                {{ $this->dateFrom ?: '...' }} – {{ $this->dateTo ?: '...' }}
                <button
                    type="button"
                    @click="$wire.set('dateFrom', ''); $wire.set('dateTo', '')"
                    class="ml-0.5 hover:text-amber-900 dark:hover:text-amber-200 cursor-pointer"
                    aria-label="Usuń filtr dat"
                >
                    <x-heroicon-o-x-mark class="w-3 h-3 flex-shrink-0" width="12" height="12" />
                </button>
            </span>
            @endif
            @foreach($activeDevices as $activeDevice)
                @php
                    $remainingDevices = implode(',', array_values(array_filter($activeDevices, fn($d) => $d !== $activeDevice)));
                    $deviceChipLabel = ['desktop' => 'Komputer', 'mobile' => 'Telefon', 'tablet' => 'Tablet'][$activeDevice] ?? $activeDevice;
                @endphp
                <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-400 text-xs rounded-full border border-primary-200 dark:border-primary-800">
                    {{ $deviceChipLabel }}
                    <button
                        type="button"
                        wire:click="$set('deviceParam', @js($remainingDevices))"
                        class="ml-0.5 hover:text-primary-900 dark:hover:text-primary-200 cursor-pointer"
                        aria-label="Usuń filtr {{ $deviceChipLabel }}"
                    >
                        <x-heroicon-o-x-mark class="w-3 h-3 flex-shrink-0" width="12" height="12" />
                    </button>
                </span>
            @endforeach
            @foreach($this->getUtmSources() as $activeUtm)
                @php
                    $remainingUtm = implode(',', array_values(array_filter($this->getUtmSources(), fn($u) => $u !== $activeUtm)));
                @endphp
                <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 text-xs rounded-full border border-green-200 dark:border-green-800">
                    UTM: {{ $activeUtm }}
                    <button
                        type="button"
                        wire:click="$set('utmSourceParam', @js($remainingUtm))"
                        class="ml-0.5 hover:text-green-900 dark:hover:text-green-200 cursor-pointer"
                        aria-label="Usuń filtr UTM {{ $activeUtm }}"
                    >
                        <x-heroicon-o-x-mark class="w-3 h-3 flex-shrink-0" width="12" height="12" />
                    </button>
                </span>
            @endforeach
        </div>
        @endif

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
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Odsłony w czasie</h3>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Liczba odwiedzin strony dzień po dniu</p>
            </div>
        </div>
        <div
            wire:ignore
            class="h-72"
            x-data="analyticsPageviewChart(@js($chartData['series']), @js($chartData['categories']))"
            x-on:analytics-chart-refresh.window="refreshPageviewChart($event.detail)"
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
                            class="bg-primary-500 h-2 rounded-full"
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
        @if(array_sum($scrollDepth) > 0)
        <div class="flex items-end gap-3 h-32">
            @foreach(['25', '50', '75', '90', '100'] as $depth)
                @php $count = $scrollDepth[$depth]; $barH = $maxScroll > 0 ? max(4, round($count / $maxScroll * 100)) : 4; @endphp
            <div class="flex-1 flex flex-col items-center gap-1">
                <span class="text-xs font-semibold text-gray-700 dark:text-gray-300 tabular-nums">{{ number_format($count) }}</span>
                <div class="w-full rounded-t-lg bg-primary-500 dark:bg-primary-400" style="height: {{ $barH }}%"></div>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $depth }}%</span>
            </div>
            @endforeach
        </div>
        @else
        <div class="py-6 text-center">
            <p class="text-sm text-gray-500 dark:text-gray-400">Brak danych o scrollowaniu w wybranym okresie.</p>
        </div>
        @endif
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

    {{-- Conversion Funnel --}}
    @php $funnel = $this->getFunnelData(); @endphp
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden mt-6">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Lejek konwersji</h3>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Procent sesji przechodzących każdy etap</p>
            </div>
            <x-heroicon-o-funnel class="w-5 h-5 flex-shrink-0 text-gray-300 dark:text-gray-600" width="20" height="20" />
        </div>
        @if(count($funnel['steps']) > 0 && ($funnel['steps'][0]['count'] ?? 0) > 0)
        <div class="p-5 space-y-3">
            @foreach($funnel['steps'] as $step)
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-sm text-gray-700 dark:text-gray-300 font-medium">{{ $step['label'] }}</span>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-500 dark:text-gray-400 tabular-nums">{{ number_format($step['count']) }} sesji</span>
                        <span class="text-xs font-bold text-primary-600 dark:text-primary-400 tabular-nums w-12 text-right">{{ $step['pct'] }}%</span>
                    </div>
                </div>
                <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-2.5">
                    <div
                        class="h-2.5 rounded-full bg-gradient-to-r from-primary-500 to-violet-500 transition-opacity duration-500"
                        style="width: {{ max($step['pct'], 0.5) }}%"
                    ></div>
                </div>
            </div>
            @endforeach
        </div>
        @else
        <div class="py-10 text-center">
            <x-heroicon-o-funnel class="w-10 h-10 flex-shrink-0 text-gray-300 dark:text-gray-600 mx-auto mb-3" width="40" height="40" />
            <p class="text-sm text-gray-500 dark:text-gray-400">Brak danych o lejku w wybranym okresie.</p>
        </div>
        @endif
    </div>

    {{-- Bottom row: Cart Abandonment + Traffic Sources + Session Quality --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 mt-6">

        {{-- Cart Abandonment --}}
        @php $abandon = $this->getCartAbandonmentData(); @endphp
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Porzucenia</h3>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Checkout abandonment</p>
            </div>
            <div class="p-5 space-y-4">
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-700/40">
                        <div class="text-lg font-bold text-gray-900 dark:text-white tabular-nums">{{ number_format($abandon['add_to_cart']) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Do koszyka</div>
                    </div>
                    <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-700/40">
                        <div class="text-lg font-bold text-gray-900 dark:text-white tabular-nums">{{ number_format($abandon['cart_viewed']) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Koszyk</div>
                    </div>
                    <div class="p-3 rounded-xl bg-red-50 dark:bg-red-900/20">
                        <div class="text-lg font-bold text-red-600 dark:text-red-400 tabular-nums">{{ number_format($abandon['total_abandoned']) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Porzucone</div>
                    </div>
                </div>
                @if(count($abandon['top_fields']) > 0)
                <div>
                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Porzucone pola</p>
                    <div class="space-y-1.5">
                        @foreach($abandon['top_fields'] as $field)
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-gray-600 dark:text-gray-400 font-mono">{{ $field['field'] }}</span>
                            <span class="text-xs font-semibold text-gray-700 dark:text-gray-300 tabular-nums">{{ $field['count'] }}×</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
        </div>

        {{-- Traffic Sources --}}
        @php $sources = $this->getTrafficSources(); @endphp
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Źródła ruchu</h3>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">UTM source</p>
            </div>
            @if(count($sources) > 0)
            <div class="p-5 space-y-2.5">
                @foreach($sources as $src)
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm text-gray-700 dark:text-gray-300 font-medium capitalize">{{ $src['source'] }}</span>
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-500 dark:text-gray-400 tabular-nums">{{ number_format($src['sessions']) }}</span>
                            <span class="text-xs font-bold text-green-600 dark:text-green-400 tabular-nums w-10 text-right">{{ $src['pct'] }}%</span>
                        </div>
                    </div>
                    <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-1.5">
                        <div class="h-1.5 rounded-full bg-green-500 dark:bg-green-400" style="width: {{ $src['pct'] }}%"></div>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="py-8 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">Brak danych UTM.</p>
            </div>
            @endif
        </div>

        {{-- Session Quality --}}
        @php $quality = $this->getSessionQuality(); @endphp
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Jakość sesji</h3>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Zaangażowanie użytkowników</p>
            </div>
            <div class="p-5 grid grid-cols-2 gap-4">
                <div class="text-center p-3 rounded-xl bg-gray-50 dark:bg-gray-700/40">
                    <div class="text-xl font-bold text-gray-900 dark:text-white tabular-nums">{{ $quality['bounce_rate'] }}%</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Bounce rate</div>
                </div>
                <div class="text-center p-3 rounded-xl bg-gray-50 dark:bg-gray-700/40">
                    <div class="text-xl font-bold text-gray-900 dark:text-white tabular-nums">{{ number_format($quality['avg_events'], 1, ',', ' ') }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Zdarzeń/sesja</div>
                </div>
                <div class="text-center p-3 rounded-xl bg-gray-50 dark:bg-gray-700/40">
                    <div class="text-xl font-bold text-gray-900 dark:text-white tabular-nums">{{ $quality['rage_clicks'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Rage clicks</div>
                </div>
                <div class="text-center p-3 rounded-xl bg-gray-50 dark:bg-gray-700/40">
                    <div class="text-xl font-bold text-gray-900 dark:text-white tabular-nums">{{ number_format($quality['avg_time_on_page'], 0, ',', ' ') }}s</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Śr. czas</div>
                </div>
            </div>
        </div>

    </div>

</x-filament-panels::page>
