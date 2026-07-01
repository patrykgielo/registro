<x-filament-panels::page>
    @php
        $kpi       = $this->getKpiData();
        $chartData = $this->getChartData();
        $topPages  = $this->getTopPages();

        [$from, $to] = $this->resolvedDateRange();
        $periodDays = max(1, (int) $from->diffInDays($to));

        $calcChange = fn(int $curr, int $prev) => $prev > 0 ? round(($curr - $prev) / $prev * 100) : null;
        $pageViewsChange = $calcChange($kpi['page_views'], $kpi['page_views_prev'] ?? 0);
        $sessionsChange  = $calcChange($kpi['unique_sessions'], $kpi['unique_sessions_prev'] ?? 0);
        $usersChange     = $calcChange($kpi['unique_users'], $kpi['unique_users_prev'] ?? 0);

        $renderChange = function (?int $pct) use ($periodDays): string {
            if ($pct === null) {
                return '—';
            }
            if ($pct > 0) {
                return "↑ +{$pct}% vs poprzednie {$periodDays} dni";
            }
            if ($pct < 0) {
                return "↓ {$pct}% vs poprzednie {$periodDays} dni";
            }
            return "= 0% vs poprzednie {$periodDays} dni";
        };
        $changeColorClass = function (?int $pct): string {
            if ($pct === null) return 'text-gray-400 dark:text-gray-500';
            if ($pct > 0) return 'text-success-600 dark:text-success-400';
            if ($pct < 0) return 'text-danger-500 dark:text-danger-400';
            return 'text-gray-400 dark:text-gray-500';
        };

        $formatTime = fn(?int $sec) => $sec === null ? '—'
            : ($sec >= 60 ? floor($sec / 60) . ':' . str_pad($sec % 60, 2, '0', STR_PAD_LEFT) : $sec . 's');

        $scrollBadgeColor = function(?int $pct): string {
            if ($pct === null) return 'gray';
            return match(true) {
                $pct >= 70 => 'success',
                $pct >= 40 => 'warning',
                default    => 'danger',
            };
        };

        $bounceColorClass = function(?float $pct): string {
            if ($pct === null) return 'text-gray-400 dark:text-gray-500';
            return match(true) {
                $pct < 40  => 'text-success-600 dark:text-success-400',
                $pct <= 60 => 'text-warning-600 dark:text-warning-400',
                default    => 'text-danger-500 dark:text-danger-400',
            };
        };

        $sourceLabels = [
            'direct'    => 'Bezpośrednie',
            'google'    => 'Google',
            'facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            'organic'   => 'Wyszukiwarki',
        ];

        $fieldLabels = [
            'first_name'     => 'Imię',
            'last_name'      => 'Nazwisko',
            'email'          => 'E-mail',
            'phone'          => 'Telefon',
            'company_name'   => 'Nazwa firmy',
            'company_nip'    => 'NIP',
            'company_regon'  => 'REGON',
            'company_krs'    => 'KRS',
            'street'         => 'Ulica',
            'city'           => 'Miasto',
            'postal_code'    => 'Kod pocztowy',
            'pickup_person'  => 'Osoba odbioru',
            'signatory_name' => 'Osoba podpisująca',
        ];

        $deviceLabels = ['desktop' => 'Komputer', 'mobile' => 'Telefon', 'tablet' => 'Tablet'];
    @endphp

    {{-- Filter Bar --}}
    <div class="mb-6 space-y-3">

        {{-- Row 1: Period tabs + Reset --}}
        <div class="flex flex-wrap items-center gap-2">
            <x-filament::tabs contained label="Wybierz okres">
                @foreach($this->periodOptions() as $option)
                    <x-filament::tabs.item
                        wire:click="$set('period', '{{ $option['value'] }}')"
                        :active="$this->period === $option['value']"
                    >
                        {{ $option['label'] }}
                    </x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>

            @if($this->hasActiveFilters())
            <x-filament::button
                type="button"
                wire:click="resetFilters"
                color="gray"
                size="sm"
                icon="heroicon-o-x-circle"
                class="ms-auto"
            >
                Resetuj filtry
            </x-filament::button>
            @endif
        </div>

        {{-- Custom date range (shows only when 'custom' period selected) --}}
        @if($this->period === 'custom')
        <div class="flex flex-wrap items-center gap-3">
            <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Od:</label>
            <input
                type="date"
                wire:model.live="dateFrom"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-950 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
            />
            <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Do:</label>
            <input
                type="date"
                wire:model.live="dateTo"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-950 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
            />
        </div>
        @endif

        {{-- Row 2: Device filter --}}
        @php $activeDevices = $this->getDeviceTypes(); @endphp
        <div class="flex flex-wrap items-center gap-3" role="group" aria-label="Filtruj po urządzeniu">
            <div class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                <x-filament::icon icon="heroicon-o-device-phone-mobile" class="h-4 w-4" />
                <span class="font-medium">Urządzenie:</span>
            </div>
            @foreach($deviceLabels as $deviceKey => $deviceLabel)
                @php
                    $isDeviceActive = in_array($deviceKey, $activeDevices, true);
                    $newDeviceValue = $isDeviceActive
                        ? implode(',', array_values(array_filter($activeDevices, fn($d) => $d !== $deviceKey)))
                        : implode(',', [...$activeDevices, $deviceKey]);
                @endphp
                <button
                    type="button"
                    wire:click="$set('deviceParam', @js($newDeviceValue))"
                    aria-pressed="{{ $isDeviceActive ? 'true' : 'false' }}"
                    class="cursor-pointer"
                >
                    <x-filament::badge :color="$isDeviceActive ? 'primary' : 'gray'">
                        {{ $deviceLabel }}
                    </x-filament::badge>
                </button>
            @endforeach
        </div>

        {{-- Active filter chips --}}
        @if($this->hasActiveFilters())
        <div class="flex flex-wrap gap-2" aria-label="Aktywne filtry">
            @if($this->dateFrom || $this->dateTo)
            <x-filament::badge color="warning" icon="heroicon-o-calendar">
                {{ $this->dateFrom ?: '...' }} – {{ $this->dateTo ?: '...' }}
                <x-slot:deleteButton
                    label="Usuń filtr dat"
                    x-on:click="$wire.set('dateFrom', ''); $wire.set('dateTo', '')"
                ></x-slot:deleteButton>
            </x-filament::badge>
            @endif
            @foreach($activeDevices as $activeDevice)
                @php
                    $remainingDevices = implode(',', array_values(array_filter($activeDevices, fn($d) => $d !== $activeDevice)));
                    $deviceChipLabel = $deviceLabels[$activeDevice] ?? $activeDevice;
                @endphp
                <x-filament::badge color="primary">
                    {{ $deviceChipLabel }}
                    <x-slot:deleteButton
                        :label="'Usuń filtr '.$deviceChipLabel"
                        wire:click="$set('deviceParam', @js($remainingDevices))"
                    ></x-slot:deleteButton>
                </x-filament::badge>
            @endforeach
            @foreach($this->getUtmSources() as $activeUtm)
                @php $remainingUtm = implode(',', array_values(array_filter($this->getUtmSources(), fn($u) => $u !== $activeUtm))); @endphp
                <x-filament::badge color="success">
                    UTM: {{ $activeUtm }}
                    <x-slot:deleteButton
                        :label="'Usuń filtr UTM '.$activeUtm"
                        wire:click="$set('utmSourceParam', @js($remainingUtm))"
                    ></x-slot:deleteButton>
                </x-filament::badge>
            @endforeach
        </div>
        @endif

    </div>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4 mb-6">

        {{-- Page Views --}}
        <div class="fi-wi-stats-overview-stat">
            <div class="fi-wi-stats-overview-stat-content">
                <div class="fi-wi-stats-overview-stat-label-ctn">
                    <x-filament::icon icon="heroicon-o-eye" class="fi-icon h-5 w-5" />
                    <span class="fi-wi-stats-overview-stat-label">Odsłony</span>
                </div>
                <div class="fi-wi-stats-overview-stat-value tabular-nums">
                    {{ number_format($kpi['page_views']) }}
                </div>
                <p class="fi-wi-stats-overview-stat-description {{ $changeColorClass($pageViewsChange) }}">
                    {{ $renderChange($pageViewsChange) }}
                </p>
            </div>
        </div>

        {{-- Unique Sessions --}}
        <div class="fi-wi-stats-overview-stat">
            <div class="fi-wi-stats-overview-stat-content">
                <div class="fi-wi-stats-overview-stat-label-ctn">
                    <x-filament::icon icon="heroicon-o-user-group" class="fi-icon h-5 w-5" />
                    <span class="fi-wi-stats-overview-stat-label">Sesje</span>
                </div>
                <div class="fi-wi-stats-overview-stat-value tabular-nums">
                    {{ number_format($kpi['unique_sessions']) }}
                </div>
                <p class="fi-wi-stats-overview-stat-description {{ $changeColorClass($sessionsChange) }}">
                    {{ $renderChange($sessionsChange) }}
                </p>
            </div>
        </div>

        {{-- Logged-in Users --}}
        <div class="fi-wi-stats-overview-stat">
            <div class="fi-wi-stats-overview-stat-content">
                <div class="fi-wi-stats-overview-stat-label-ctn">
                    <x-filament::icon icon="heroicon-o-identification" class="fi-icon h-5 w-5" />
                    <span class="fi-wi-stats-overview-stat-label">Zalogowani</span>
                </div>
                <div class="fi-wi-stats-overview-stat-value tabular-nums">
                    {{ number_format($kpi['unique_users']) }}
                </div>
                <p class="fi-wi-stats-overview-stat-description {{ $changeColorClass($usersChange) }}">
                    {{ $renderChange($usersChange) }}
                </p>
            </div>
        </div>

        {{-- Avg events per session --}}
        <div class="fi-wi-stats-overview-stat">
            <div class="fi-wi-stats-overview-stat-content">
                <div class="fi-wi-stats-overview-stat-label-ctn">
                    <x-filament::icon icon="heroicon-o-cursor-arrow-rays" class="fi-icon h-5 w-5" />
                    <span class="fi-wi-stats-overview-stat-label">Aktywność</span>
                </div>
                <div class="fi-wi-stats-overview-stat-value tabular-nums">
                    {{ number_format($kpi['avg_session_events'], 1, ',', ' ') }}
                </div>
                <p class="fi-wi-stats-overview-stat-description">
                    zdarzeń na sesję (im więcej, tym lepiej)
                </p>
            </div>
        </div>

    </div>

    {{-- Page Views Chart --}}
    <x-filament::section
        heading="Odsłony w czasie"
        description="Liczba odwiedzin strony dzień po dniu"
        class="mb-6"
    >
        <div
            wire:ignore
            class="h-80"
            x-data="analyticsPageviewChart(@js($chartData['series']), @js($chartData['categories']))"
            x-on:analytics-chart-refresh.window="refreshPageviewChart($event.detail)"
        ></div>
    </x-filament::section>

    {{-- Top Pages --}}
    <x-filament::section
        heading="Najpopularniejsze strony (top 10)"
        description="Czas, zaangażowanie i współczynnik odrzuceń"
        class="mb-6"
    >
        @if(count($topPages) > 0)
        <div class="-m-6 overflow-x-auto">
            <table class="fi-ta-table w-full text-start">
                <thead class="divide-y divide-gray-200 dark:divide-white/10">
                    <tr class="bg-gray-50 dark:bg-white/5">
                        <th class="px-3 py-3.5 text-start w-10"><span class="text-sm font-semibold text-gray-950 dark:text-white">#</span></th>
                        <th class="px-3 py-3.5 text-start"><span class="text-sm font-semibold text-gray-950 dark:text-white">Strona</span></th>
                        <th class="px-3 py-3.5 text-end w-20"><span class="text-sm font-semibold text-gray-950 dark:text-white">Wizyty</span></th>
                        <th class="px-3 py-3.5 text-end w-24"><span class="text-sm font-semibold text-gray-950 dark:text-white">Śr. czas</span></th>
                        <th class="px-3 py-3.5 text-center w-20"><span class="text-sm font-semibold text-gray-950 dark:text-white">Scroll</span></th>
                        <th class="px-3 py-3.5 text-end w-20"><span class="text-sm font-semibold text-gray-950 dark:text-white">Bounce</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                    @foreach($topPages as $index => $page)
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition-colors duration-100">
                        <td class="px-3 py-3.5 text-gray-400 dark:text-gray-500 font-mono text-xs">{{ $index + 1 }}</td>
                        <td class="px-3 py-3.5 max-w-xs">
                            @if(str_starts_with($page['url'] ?? '', 'http'))
                                <a
                                    href="{{ $page['url'] }}"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex items-center gap-1 text-sm font-medium text-gray-950 hover:text-primary-600 dark:text-white dark:hover:text-primary-400 transition-colors duration-100"
                                    title="{{ $page['url'] }}"
                                >
                                    <span class="truncate max-w-[220px]">{{ $page['path'] }}</span>
                                    <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3 flex-shrink-0 text-gray-400" width="12" height="12" />
                                </a>
                            @else
                                <span class="inline-flex items-center truncate max-w-[220px] text-sm font-medium text-gray-950 dark:text-white">{{ $page['path'] }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-3.5 text-end text-sm font-semibold text-gray-700 dark:text-gray-300 tabular-nums">
                            {{ number_format($page['views']) }}
                        </td>
                        <td class="px-3 py-3.5 text-end text-sm text-gray-500 dark:text-gray-400 tabular-nums">
                            {{ $formatTime($page['avg_time_seconds']) }}
                        </td>
                        <td class="px-3 py-3.5 text-center">
                            @if($page['avg_scroll_pct'] !== null)
                                <x-filament::badge :color="$scrollBadgeColor($page['avg_scroll_pct'])" size="sm">
                                    {{ $page['avg_scroll_pct'] }}%
                                </x-filament::badge>
                            @else
                                <span class="text-gray-400 dark:text-gray-500">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-3.5 text-end tabular-nums">
                            @if($page['bounce_rate'] !== null)
                                <span class="text-sm font-semibold {{ $bounceColorClass($page['bounce_rate']) }}">{{ $page['bounce_rate'] }}%</span>
                            @else
                                <span class="text-gray-400 dark:text-gray-500">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <x-filament::empty-state
            icon="heroicon-o-globe-alt"
            heading="Brak danych o stronach"
            description="W wybranym okresie nie odnotowano żadnych odwiedzin."
        />
        @endif
    </x-filament::section>

    {{-- Conversion Funnel --}}
    @php $funnel = $this->getFunnelData(); @endphp
    <x-filament::section
        icon="heroicon-o-funnel"
        heading="Lejek konwersji"
        description="Procent sesji przechodzących każdy etap"
        class="mb-6"
    >
        @if(count($funnel['steps']) > 0 && ($funnel['steps'][0]['count'] ?? 0) > 0)
        <div class="space-y-3">
            @foreach($funnel['steps'] as $step)
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $step['label'] }}</span>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-500 dark:text-gray-400 tabular-nums">{{ number_format($step['count']) }} sesji</span>
                        <span class="text-xs font-bold text-primary-600 dark:text-primary-400 tabular-nums w-12 text-end">{{ $step['pct'] }}%</span>
                    </div>
                </div>
                <div class="w-full rounded-full bg-gray-100 h-2.5 dark:bg-gray-700">
                    <div
                        class="h-2.5 rounded-full bg-primary-500"
                        style="width: {{ max($step['pct'], 0.5) }}%"
                    ></div>
                </div>
            </div>
            @endforeach
        </div>
        @else
        <x-filament::empty-state
            icon="heroicon-o-funnel"
            heading="Brak danych o lejku"
            description="W wybranym okresie nie odnotowano danych lejka konwersji."
        />
        @endif
    </x-filament::section>

    {{-- Bottom row: Cart Abandonment + Traffic Sources --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- Cart Abandonment --}}
        @php $abandon = $this->getCartAbandonmentData(); @endphp
        <x-filament::section heading="Porzucenia" description="Checkout abandonment">
            <div class="space-y-4">
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
                        <div class="text-lg font-bold text-gray-950 dark:text-white tabular-nums">{{ number_format($abandon['add_to_cart']) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Do koszyka</div>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
                        <div class="text-lg font-bold text-gray-950 dark:text-white tabular-nums">{{ number_format($abandon['cart_viewed']) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Koszyk</div>
                    </div>
                    <div class="rounded-xl bg-danger-50 p-3 dark:bg-danger-500/10">
                        <div class="text-lg font-bold text-danger-600 dark:text-danger-400 tabular-nums">{{ number_format($abandon['total_abandoned']) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Porzucone</div>
                    </div>
                </div>
                @if(count($abandon['top_fields']) > 0)
                <div>
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Porzucone pola</p>
                    <div class="space-y-1.5">
                        @foreach($abandon['top_fields'] as $field)
                        @php $fieldName = $fieldLabels[$field['field']] ?? ucfirst(str_replace('_', ' ', $field['field'])); @endphp
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-gray-600 dark:text-gray-400">{{ $fieldName }}</span>
                            <span class="text-xs font-semibold text-gray-700 dark:text-gray-300 tabular-nums">{{ $field['count'] }}×</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
        </x-filament::section>

        {{-- Traffic Sources --}}
        @php $sources = $this->getTrafficSources(); @endphp
        <x-filament::section heading="Źródła ruchu" description="Skąd przychodzą odwiedzający">
            @if(count($sources) > 0)
            <div class="space-y-3">
                @foreach($sources as $src)
                @php $srcLabel = $sourceLabels[$src['source']] ?? ucfirst($src['source']); @endphp
                <div>
                    <div class="flex items-start justify-between mb-1">
                        <div>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $srcLabel }}</span>
                            @if($src['source'] === 'direct')
                            <p class="text-xs text-gray-400 dark:text-gray-500">wejścia bezpośrednie: zakładki, wpisany adres</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 shrink-0 ms-2 mt-0.5">
                            <span class="text-xs text-gray-500 dark:text-gray-400 tabular-nums">{{ number_format($src['sessions']) }}</span>
                            <span class="text-xs font-bold text-success-600 dark:text-success-400 tabular-nums w-10 text-end">{{ $src['pct'] }}%</span>
                        </div>
                    </div>
                    <div class="w-full rounded-full bg-gray-100 h-1.5 dark:bg-gray-700">
                        <div class="h-1.5 rounded-full bg-success-500 dark:bg-success-400" style="width: {{ $src['pct'] }}%"></div>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <x-filament::empty-state
                icon="heroicon-o-globe-alt"
                heading="Brak danych UTM"
            />
            @endif
        </x-filament::section>

    </div>

</x-filament-panels::page>
